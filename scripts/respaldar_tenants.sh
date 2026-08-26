#!/usr/bin/env bash
#
# respaldar_tenants.sh -- Un respaldo por CLIENTE, cada madrugada.
#
# ===========================================================================
# QUE RESPALDA, Y POR QUE NO ES UN mysqldump POR BASE
# ===========================================================================
# Este SaaS no tiene una base por cliente: es multi-tenant POR FILA. Las
# empresas comparten las mismas ~40 tablas y lo unico que las separa es un
# WHERE. Asi que "la base de cada cliente" hay que construirla: para cada tabla,
# quedarse solo con las filas de esa empresa.
#
# QUE FILAS SON DE QUIEN NO SE DECIDE AQUI. Lo decide
# scripts/plan_respaldo_tenants.php, que recorre las claves foraneas de la base
# y devuelve, por cliente y por tabla, el WHERE exacto. Se hace alla y no aca
# porque esa logica en bash seria injustificable de leer e imposible de probar;
# alla tiene tests (PlanRespaldoTest). Este script se ocupa de lo que el host
# sabe hacer: volcar, comprimir, verificar, rotar y subir.
#
# NO REEMPLAZA AL RESPALDO COMPLETO de /data/backups/backup_mysql.sh (03:17),
# que sigue siendo el que se usa para levantar el sistema entero. Este responde
# otra pregunta: "devolveme los datos de ESTA empresa", que con un volcado de
# toda la base obliga a restaurar todo en otro lado y recortar a mano.
#
# ===========================================================================
# LO QUE ESTE SCRIPT SE NIEGA A HACER EN SILENCIO
# ===========================================================================
# Un respaldo que falla callado es peor que no tener respaldo: da tranquilidad
# sin dar cobertura. En este VPS ya paso -- el respaldo general fallo 85 noches
# seguidas y nadie se entero (ver la cabecera de /data/backups/backup_mysql.sh).
# De ahi salen estas reglas, que son las mismas de aquel:
#
#   VERIFICA LO QUE ESCRIBE. Un .sql.gz que no pasa "gzip -t" o al que le falta
#   su linea de cierre cuenta como FALLO. Un volcado truncado que se da por
#   bueno es lo peor de los dos mundos: gzip -t da por VALIDO un flujo cortado a
#   la mitad, porque el flujo se cierra bien aunque mysqldump haya muerto.
#
#   NO ROTA SI LA CORRIDA FALLO. Ni local ni en Nextcloud. Borrar copias buenas
#   por haber escrito una mala es como se pierde todo de verdad.
#
#   AVISA POR CORREO. Reusa el canal de Brevo que ya existe en sinergia.env.
#
#   DENUNCIA LO QUE NO PUDO RECORTAR. Si el plan trae tablas 'sin_mapa' -- con
#   datos de un contribuyente y sin forma de saber de cual --, salen en el log,
#   en el correo y en el estado. Un respaldo incompleto que se calla es
#   exactamente el problema que este script existe para no tener.
#
# ===========================================================================
# EL TOPE DE 85 MB
# ===========================================================================
# Es el limite del destino en Nextcloud. Cuando un cliente lo pasa NO se corta
# ni se parte el archivo: se guarda igual, se sube igual si el servidor lo
# acepta, y queda una ALERTA. Partir un respaldo en trozos sin que nadie lo haya
# decidido convierte la restauracion en un rompecabezas justo el dia peor.
#
# ===========================================================================
# CONSISTENCIA: LO QUE ESTE RESPALDO SI Y NO GARANTIZA
# ===========================================================================
# Cada tabla se vuelca en su propia transaccion (--single-transaction), porque
# mysqldump no admite un WHERE distinto por tabla en una sola invocacion. A las
# 03:40 no hay emision, pero si la hubiera, un documento creado entre el volcado
# de dos tablas podria quedar a medias. Para una foto perfectamente consistente
# de todo esta el respaldo completo de las 03:17. Queda escrito para que nadie
# lo descubra restaurando.
#
# ===========================================================================
# USO
#   scripts/respaldar_tenants.sh            respaldo normal (lo del cron)
#   scripts/respaldar_tenants.sh --probar   verifica accesos y el canal de
#                                           aviso SIN escribir, subir ni borrar
#
# Sale 0 si todo salio bien, 1 si algo fallo.
#
# RESTAURAR el respaldo de un cliente en una base vacia:
#   gunzip -c /data/backups/sinergia-tenants/7-comercial-andes/20260827.sql.gz \
#     | docker exec -i sinergia_mysql mysql -uroot -p'<clave>' <base_destino>
# (el volcado trae los CREATE TABLE y desactiva las claves foraneas mientras
#  carga, para que el orden de las tablas no importe)
# ===========================================================================

set -euo pipefail

# ---- Config ---------------------------------------------------------------
# Sobreescribibles por entorno UNICAMENTE para poder probar contra directorios
# de juguete. En la corrida del cron no se define ninguna.
BACKUP_ROOT="${BACKUP_ROOT:-/data/backups/sinergia-tenants}"
LOG_FILE="${LOG_FILE:-/var/log/sinergia_respaldos.log}"
ESTADO_FILE="${ESTADO_FILE:-$BACKUP_ROOT/estado.json}"
LOCK_FILE="${LOCK_FILE:-/var/lock/sinergia_respaldos.lock}"

COPIAS_LOCALES="${COPIAS_LOCALES:-5}"
COPIAS_REMOTAS="${COPIAS_REMOTAS:-10}"
TOPE_BYTES="${TOPE_BYTES:-89128960}"          # 85 MB

CONTENEDOR_MYSQL="${CONTENEDOR_MYSQL:-sinergia_mysql}"
CONTENEDOR_PANEL="${CONTENEDOR_PANEL:-sinergia_panel}"

# Las credenciales de Nextcloud NO viajan en git (*.env esta en .gitignore) y
# no se escriben aqui. Si el fichero falta, el respaldo LOCAL se hace igual y
# lo que se pierde es la copia remota -- eso queda en el log y en el estado, no
# en silencio.
NEXTCLOUD_ENV="${NEXTCLOUD_ENV:-/data/sinergia/facturacion/nextcloud.env}"
AVISO_ENV="${AVISO_ENV:-/data/sinergia/facturacion/sinergia.env}"
# ---------------------------------------------------------------------------

MODO_PRUEBA=0
[[ "${1:-}" == "--probar" ]] && MODO_PRUEBA=1

FECHA="$(date +%Y%m%d)"
INICIO="$(date +%s)"

mkdir -p "$BACKUP_ROOT" "$(dirname "$LOG_FILE")"

log() { printf '%s %s\n' "$(date +'%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG_FILE"; }

FALLOS=()
ALERTAS=()
fallo()  { FALLOS+=("$1");  log "FALLO: $1"; }
alerta() { ALERTAS+=("$1"); log "ALERTA: $1"; }

# ---------------------------------------------------------------------------
#  Aviso por correo (Brevo via curl). El host no tiene sendmail; curl es lo que
#  hay. El cuerpo se arma con jq y NO concatenando cadenas: lleva mensajes de
#  error con comillas y saltos de linea, y un JSON roto significa que el aviso
#  del fallo tambien falla -- justo el dia que importa.
# ---------------------------------------------------------------------------
avisar() {
  local asunto="$1" cuerpo="$2" api remitente destino http

  if [[ ! -f "$AVISO_ENV" ]]; then
    log "AVISO NO ENVIADO: no existe $AVISO_ENV"
    return 0
  fi

  api="$(grep -E '^BREVO_API_KEY=' "$AVISO_ENV" | head -1 | cut -d= -f2- | tr -d '\r\n')"
  remitente="$(grep -E '^CORREO_REMITENTE=' "$AVISO_ENV" | head -1 | cut -d= -f2- | tr -d '\r\n')"
  destino="$(grep -E '^VEREDICTO_AVISO_EMAIL=' "$AVISO_ENV" | head -1 | cut -d= -f2- | tr -d '\r\n')"

  if [[ -z "$api" || -z "$remitente" || -z "$destino" ]]; then
    log "AVISO NO ENVIADO: falta BREVO_API_KEY, CORREO_REMITENTE o VEREDICTO_AVISO_EMAIL en $AVISO_ENV"
    return 0
  fi

  http="$(jq -nc --arg s "$asunto" --arg c "$cuerpo" --arg de "$remitente" --arg para "$destino" \
        '{sender:{email:$de,name:"Sinergia respaldos"},to:[{email:$para}],subject:$s,textContent:$c}' \
        | curl -s -o /dev/null -w '%{http_code}' -m 30 \
            -X POST https://api.brevo.com/v3/smtp/email \
            -H "api-key: $api" -H 'Content-Type: application/json' --data @- || true)"

  if [[ "$http" == "201" || "$http" == "202" ]]; then
    log "aviso enviado a $destino (HTTP $http)"
  else
    log "AVISO NO ENVIADO: Brevo respondio HTTP $http"
  fi
}

# ---------------------------------------------------------------------------
#  Una sola corrida a la vez. Dos mysqldump simultaneos sobre la misma base no
#  se corrompen entre si, pero duplican la carga a la hora en que tambien corre
#  el respaldo completo.
# ---------------------------------------------------------------------------
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  log "otra corrida en curso, esta se va sin hacer nada"
  exit 0
fi

log "===== inicio (${FECHA}) $([[ $MODO_PRUEBA -eq 1 ]] && echo '-- MODO PRUEBA: no escribe nada')"

# ---- 1. El plan ------------------------------------------------------------
PLAN="$(docker exec "$CONTENEDOR_PANEL" php scripts/plan_respaldo_tenants.php 2>&1)" || {
  fallo "no se pudo obtener el plan de respaldo: $PLAN"
  avisar "Respaldo por cliente FALLIDO" "No se pudo obtener el plan:\n\n$PLAN"
  exit 1
}

if ! jq -e . >/dev/null 2>&1 <<<"$PLAN"; then
  fallo "el plan no es JSON valido: $(head -c 300 <<<"$PLAN")"
  avisar "Respaldo por cliente FALLIDO" "El plan no es JSON valido."
  exit 1
fi

BASE="$(jq -r '.base' <<<"$PLAN")"
N_TENANTS="$(jq -r '.tenants | length' <<<"$PLAN")"
SIN_MAPA="$(jq -r '.sin_mapa | join(", ")' <<<"$PLAN")"
GLOBALES="$(jq -r '.globales | join(", ")' <<<"$PLAN")"

log "base=$BASE clientes=$N_TENANTS tablas_de_la_casa=[${GLOBALES}]"
[[ -n "$SIN_MAPA" ]] && alerta "tablas con datos de un contribuyente que NO se pudieron recortar: $SIN_MAPA"

# ---- 2. La clave de MySQL, del propio contenedor ---------------------------
# Del contenedor y no de un fichero: si se recrea con otra clave, esta la lee
# igual. Un fichero de otro proyecto fue la causa de las 85 noches de fallo.
MYSQL_PASS="$(docker inspect "$CONTENEDOR_MYSQL" \
  --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null \
  | grep -E '^MYSQL_ROOT_PASSWORD=' | head -1 | cut -d= -f2-)" || true

if [[ -z "${MYSQL_PASS:-}" ]]; then
  fallo "no se pudo leer MYSQL_ROOT_PASSWORD de $CONTENEDOR_MYSQL"
  avisar "Respaldo por cliente FALLIDO" "No se pudo leer la clave de MySQL del contenedor."
  exit 1
fi

# La clave viaja por MYSQL_PWD y no por -p: con -p queda a la vista en la lista
# de procesos del host y del contenedor mientras dura el volcado.
mysqldump_tabla() {
  docker exec -e MYSQL_PWD="$MYSQL_PASS" "$CONTENEDOR_MYSQL" \
    mysqldump -uroot --single-transaction --skip-lock-tables --hex-blob \
      --no-tablespaces --compact --default-character-set=utf8mb4 \
      --where="$2" "$BASE" "$1"
}

# ---- 3. Nextcloud ----------------------------------------------------------
NC_URL=""; NC_USER=""; NC_PASS=""; NC_CARPETA="respaldos-sinergia"
if [[ -f "$NEXTCLOUD_ENV" ]]; then
  NC_URL="$(grep -E '^NEXTCLOUD_DAV_URL=' "$NEXTCLOUD_ENV" | head -1 | cut -d= -f2- | tr -d '\r\n')"
  NC_USER="$(grep -E '^NEXTCLOUD_USER=' "$NEXTCLOUD_ENV" | head -1 | cut -d= -f2- | tr -d '\r\n')"
  NC_PASS="$(grep -E '^NEXTCLOUD_PASS=' "$NEXTCLOUD_ENV" | head -1 | cut -d= -f2- | tr -d '\r\n')"
  NC_CARPETA="$(grep -E '^NEXTCLOUD_CARPETA=' "$NEXTCLOUD_ENV" | head -1 | cut -d= -f2- | tr -d '\r\n')"
  NC_CARPETA="${NC_CARPETA:-respaldos-sinergia}"
fi

NEXTCLOUD_LISTO=1
if [[ -z "$NC_URL" || -z "$NC_USER" || -z "$NC_PASS" ]]; then
  NEXTCLOUD_LISTO=0
  alerta "sin credenciales de Nextcloud ($NEXTCLOUD_ENV): la copia remota no se hizo. La copia local si."
fi

# curl con las opciones que importan: falla ruidoso (-f), reintenta lo que es
# reintentable, y limita el tiempo total para no dejar el cron colgado.
nc_curl() { curl -sS -f -u "$NC_USER:$NC_PASS" --retry 2 --retry-delay 5 -m 900 "$@"; }

nc_mkdir() {
  # 405 = ya existe, que no es un error. -f haria fallar igual, asi que este no
  # usa nc_curl.
  local http
  http="$(curl -sS -u "$NC_USER:$NC_PASS" -m 60 -o /dev/null -w '%{http_code}' -X MKCOL "$1" || echo 000)"
  [[ "$http" == "201" || "$http" == "405" ]]
}

# ---- 4. Modo prueba: comprobar accesos y salir -----------------------------
if [[ $MODO_PRUEBA -eq 1 ]]; then
  if docker exec -e MYSQL_PWD="$MYSQL_PASS" "$CONTENEDOR_MYSQL" mysql -uroot -e 'SELECT 1' >/dev/null 2>&1; then
    log "OK: se puede consultar MySQL"
  else
    fallo "no se puede consultar MySQL con la clave leida del contenedor"
  fi

  if [[ $NEXTCLOUD_LISTO -eq 1 ]]; then
    if nc_curl -o /dev/null -X PROPFIND -H 'Depth: 0' "$NC_URL/" 2>/dev/null; then
      log "OK: Nextcloud responde y acepta las credenciales"
    else
      fallo "Nextcloud no responde o rechaza las credenciales ($NC_URL)"
    fi
  fi

  avisar "Prueba del respaldo por cliente" \
    "Prueba de $BASE: $N_TENANTS clientes en el plan. Fallos: ${#FALLOS[@]}. Alertas: ${#ALERTAS[@]}. No se escribio nada."
  log "===== fin de la prueba (fallos=${#FALLOS[@]} alertas=${#ALERTAS[@]})"
  [[ ${#FALLOS[@]} -eq 0 ]] || exit 1
  exit 0
fi

# ---- 5. Un respaldo por cliente -------------------------------------------
OK=0
RESUMEN_JSON='[]'

for i in $(seq 0 $((N_TENANTS - 1))); do
  TENANT="$(jq -c ".tenants[$i]" <<<"$PLAN")"
  ID="$(jq -r '.id' <<<"$TENANT")"
  NOMBRE="$(jq -r '.nombre' <<<"$TENANT")"
  SLUG="$(jq -r '.slug' <<<"$TENANT")"
  N_TABLAS="$(jq -r '.tablas | length' <<<"$TENANT")"

  DIR="$BACKUP_ROOT/$SLUG"
  DESTINO="$DIR/$FECHA.sql.gz"
  PARCIAL="$DIR/.$FECHA.parcial"
  mkdir -p "$DIR"

  # El volcado se arma en un .parcial y solo al final se comprime al nombre
  # definitivo: asi nunca existe un YYYYMMDD.sql.gz a medio escribir que la
  # rotacion de manana pueda contar como copia buena.
  {
    printf -- '-- Respaldo de la cuenta %s (%s) -- %s\n' "$ID" "$NOMBRE" "$(date +'%Y-%m-%d %H:%M:%S')"
    printf -- "-- Base de origen: %s. Solo las filas de esta cuenta.\n" "$BASE"
    printf -- '/*!40101 SET NAMES utf8mb4 */;\n'
    printf -- "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
    printf -- 'SET FOREIGN_KEY_CHECKS=0;\n'
  } > "$PARCIAL"

  ERROR_TABLA=""
  for j in $(seq 0 $((N_TABLAS - 1))); do
    TABLA="$(jq -r ".tablas[$j].tabla" <<<"$TENANT")"
    WHERE="$(jq -r ".tablas[$j].where" <<<"$TENANT")"

    printf -- '\n-- ---- %s\n' "$TABLA" >> "$PARCIAL"

    if ! mysqldump_tabla "$TABLA" "$WHERE" >> "$PARCIAL" 2>>"$LOG_FILE"; then
      ERROR_TABLA="$TABLA"
      break
    fi
  done

  if [[ -n "$ERROR_TABLA" ]]; then
    rm -f "$PARCIAL"
    fallo "cuenta $ID ($NOMBRE): mysqldump fallo en la tabla $ERROR_TABLA"
    continue
  fi

  {
    printf -- 'SET FOREIGN_KEY_CHECKS=1;\n'
    printf -- '-- Respaldo completado %s\n' "$(date +'%Y-%m-%d %H:%M:%S')"
  } >> "$PARCIAL"

  gzip -c "$PARCIAL" > "$DESTINO"
  rm -f "$PARCIAL"

  # VERIFICACION. gzip -t sola no alcanza: da por valido un flujo cortado a la
  # mitad. La linea de cierre es la que prueba que mysqldump llego al final.
  if ! gzip -t "$DESTINO" 2>/dev/null; then
    rm -f "$DESTINO"
    fallo "cuenta $ID ($NOMBRE): el archivo comprimido no pasa gzip -t"
    continue
  fi
  if ! gunzip -c "$DESTINO" | tail -5 | grep -q '^-- Respaldo completado'; then
    rm -f "$DESTINO"
    fallo "cuenta $ID ($NOMBRE): al volcado le falta la linea de cierre (quedo truncado)"
    continue
  fi

  BYTES="$(stat -c%s "$DESTINO")"
  MB="$(awk -v b="$BYTES" 'BEGIN{printf "%.1f", b/1048576}')"

  if [[ "$BYTES" -gt "$TOPE_BYTES" ]]; then
    alerta "cuenta $ID ($NOMBRE): el respaldo pesa ${MB} MB y el tope es $((TOPE_BYTES / 1048576)) MB. Se guardo igual, sin partir."
  fi

  log "cuenta $ID ($NOMBRE) -> $DESTINO  ${MB} MB  ${N_TABLAS} tablas"
  OK=$((OK + 1))

  # ---- Nextcloud ----------------------------------------------------------
  SUBIDO=0
  if [[ $NEXTCLOUD_LISTO -eq 1 ]]; then
    if nc_mkdir "$NC_URL/$NC_CARPETA" && nc_mkdir "$NC_URL/$NC_CARPETA/$SLUG"; then
      if nc_curl -o /dev/null -T "$DESTINO" "$NC_URL/$NC_CARPETA/$SLUG/$FECHA.sql.gz"; then
        SUBIDO=1
        log "  subido a Nextcloud: $NC_CARPETA/$SLUG/$FECHA.sql.gz"
      else
        fallo "cuenta $ID ($NOMBRE): no se pudo subir a Nextcloud"
      fi
    else
      fallo "cuenta $ID ($NOMBRE): no se pudo crear la carpeta en Nextcloud"
    fi
  fi

  RESUMEN_JSON="$(jq -c --argjson r "$RESUMEN_JSON" --arg id "$ID" --arg nombre "$NOMBRE" \
      --arg archivo "$DESTINO" --argjson bytes "$BYTES" --argjson subido "$SUBIDO" \
      -n '$r + [{cuenta:($id|tonumber), nombre:$nombre, archivo:$archivo, bytes:$bytes, nextcloud:($subido==1)}]')"
done

# ---- 6. Rotacion -----------------------------------------------------------
# NO SE BORRA NADA SI ALGO FALLO. Es el freno que convierte "una noche mala" en
# "una copia menos" en vez de "sin copias".
if [[ ${#FALLOS[@]} -gt 0 ]]; then
  log "rotacion OMITIDA: la corrida tuvo ${#FALLOS[@]} fallo(s)"
else
  for i in $(seq 0 $((N_TENANTS - 1))); do
    SLUG="$(jq -r ".tenants[$i].slug" <<<"$PLAN")"
    DIR="$BACKUP_ROOT/$SLUG"
    [[ -d "$DIR" ]] || continue

    # Local: se conservan las COPIAS_LOCALES mas nuevas por nombre, que es la
    # fecha. Ordenar por nombre y no por mtime: el mtime cambia si alguien
    # toca el archivo, la fecha del nombre no.
    mapfile -t SOBRAN < <(ls -1 "$DIR"/*.sql.gz 2>/dev/null | sort -r | tail -n +$((COPIAS_LOCALES + 1)) || true)
    for viejo in "${SOBRAN[@]:-}"; do
      [[ -n "$viejo" ]] || continue
      rm -f "$viejo" && log "  rotado (local): $(basename "$viejo") de $SLUG"
    done

    # Nextcloud: mismas reglas, otra cantidad. Se listan los nombres con
    # PROPFIND y se borran los que sobran.
    if [[ $NEXTCLOUD_LISTO -eq 1 ]]; then
      LISTADO="$(nc_curl -X PROPFIND -H 'Depth: 1' "$NC_URL/$NC_CARPETA/$SLUG/" 2>/dev/null || true)"
      mapfile -t REMOTOS < <(grep -o '[0-9]\{8\}\.sql\.gz' <<<"$LISTADO" | sort -ru | tail -n +$((COPIAS_REMOTAS + 1)) || true)
      for viejo in "${REMOTOS[@]:-}"; do
        [[ -n "$viejo" ]] || continue
        if nc_curl -o /dev/null -X DELETE "$NC_URL/$NC_CARPETA/$SLUG/$viejo"; then
          log "  rotado (nextcloud): $viejo de $SLUG"
        else
          alerta "no se pudo borrar $viejo de $SLUG en Nextcloud"
        fi
      done
    fi
  done
fi

# ---- 7. Estado y cierre ----------------------------------------------------
DURACION=$(( $(date +%s) - INICIO ))

jq -n --arg fecha "$(date +'%Y-%m-%d %H:%M:%S')" --arg base "$BASE" \
      --argjson clientes "$N_TENANTS" --argjson respaldados "$OK" \
      --argjson duracion "$DURACION" \
      --argjson fallos "$(printf '%s\n' "${FALLOS[@]:-}" | jq -R . | jq -s 'map(select(. != ""))')" \
      --argjson alertas "$(printf '%s\n' "${ALERTAS[@]:-}" | jq -R . | jq -s 'map(select(. != ""))')" \
      --argjson detalle "$RESUMEN_JSON" \
      '{fecha:$fecha, base:$base, clientes:$clientes, respaldados:$respaldados, segundos:$duracion, fallos:$fallos, alertas:$alertas, detalle:$detalle}' \
  > "$ESTADO_FILE"

log "RESUMEN respaldados=$OK/$N_TENANTS fallos=${#FALLOS[@]} alertas=${#ALERTAS[@]} nextcloud=$([[ $NEXTCLOUD_LISTO -eq 1 ]] && echo si || echo no) tiempo=${DURACION}s"

if [[ ${#FALLOS[@]} -gt 0 || ${#ALERTAS[@]} -gt 0 ]]; then
  CUERPO="Respaldo por cliente de $BASE -- $(date +'%Y-%m-%d %H:%M')

Respaldados: $OK de $N_TENANTS
Duracion: ${DURACION}s
"
  [[ ${#FALLOS[@]} -gt 0 ]]  && CUERPO="$CUERPO
FALLOS:
$(printf -- '- %s\n' "${FALLOS[@]}")"
  [[ ${#ALERTAS[@]} -gt 0 ]] && CUERPO="$CUERPO
ALERTAS:
$(printf -- '- %s\n' "${ALERTAS[@]}")"

  CUERPO="$CUERPO

Log: $LOG_FILE
Estado: $ESTADO_FILE
En el panel: /admin/tareas/respaldos-tenants"

  avisar "Respaldo por cliente: ${#FALLOS[@]} fallo(s), ${#ALERTAS[@]} alerta(s)" "$CUERPO"
fi

log "===== fin"
[[ ${#FALLOS[@]} -eq 0 ]] || exit 1
exit 0
