#!/bin/bash
#
# deploy.sh -- sinergia_fac_bol (VPS)
#
# Despliegue por git:
#   fetch -> pull -> MIGRACIONES -> SUITE -> build -> up -> verificar.
#
# LOS DOS ENGANCHES QUE PUEDEN ABORTAR van antes del build, para cortar sin
# haber construido ni levantado nada: el arbol queda adelantado, pero los
# contenedores viejos siguen corriendo y sirviendo.
#
# NO toca la base de datos ni los secretos: esos ya viven en el host y entran
# a los contenedores por env_file y por mounts :ro. La comprobacion de
# migraciones es SOLO LECTURA -- estado_migraciones.php no ejecuta ni una
# sentencia que escriba -- y no aplica nada: aplicar sigue siendo humano.
#
# LA SUITE SE AGREGO EL 25-08-2026, cuando dejo de estar en rojo permanente (43
# errores y 16 fallos de arrastre) y por fin sirvio como semaforo. Ese mismo dia
# un cambio se desplego con un fallo que reventaba un cron, porque se habia
# verificado a mano por un camino que no pasaba por la linea rota.
#
# Convenciones tomadas de /data/licitaalerta/deploy.sh, que es el patron que
# ya funciona en este VPS: set -e, guarda temprana de prerequisitos,
# git pull origin master, y resumen de contenedores al final.
#
# Uso:
#   ./deploy.sh              despliega si hay commits nuevos
#   ./deploy.sh --force      reconstruye aunque no haya commits nuevos
#   ./deploy.sh --dry-run    solo muestra que haria, no cambia nada
#
set -Eeuo pipefail

# ── Configuracion ─────────────────────────────────────────────────────────────
APP_DIR="/data/sinergia/facturacion"

# -f explicito en TODOS los comandos compose, sin excepcion. En este directorio
# conviven dos archivos: docker-compose.yml (el del NAS, trackeado en git, con
# rutas /mnt/tank y php -S) y docker-compose.vps.yml (el nuestro). Sin -f,
# compose elegiria el del NAS por ser el nombre por defecto y levantaria un
# stack roto. El .env de este directorio fija COMPOSE_FILE como red adicional,
# pero el flag no depende de que ese archivo exista.
COMPOSE_FILE="docker-compose.vps.yml"
COMPOSE=(docker compose -f "$COMPOSE_FILE")

# Los contenedores del stack se identifican por el label que compose deriva de
# "name: sinergia_fac". Filtrar por nombre seria inseguro: existe un contenedor
# AJENO llamado sinergiaia_landing que un "grep sinergia" capturaria.
PROJECT_LABEL="com.docker.compose.project=sinergia_fac"

# Nombres de SERVICIO del compose (no los container_name, que son sinergia_*).
SERVICIOS=(mysql motor panel)

# Limites del build. El VPS comparte 6 vCPU con 46 contenedores de otros 5
# proyectos: un build sin acotar les roba la maquina.
#   --cpus NO existe en docker build; el equivalente es --cpuset-cpus.
#   DOCKER_BUILDKIT=0 es necesario porque BuildKit ignora --memory (lo dice el
#   propio help de compose: "Not supported by BuildKit"). Usa el legacy
#   builder, que avisa que esta deprecado pero funciona en Docker 29.1.3.
# Prefijo de las bases desechables que se crean para los tests que EJECUTAN
# migraciones. Tiene que coincidir con BackfillAmbiente054Test::PREFIJO -- hay un
# test que lo comprueba -- porque el usuario de pruebas se crea con permisos
# limitados EXACTAMENTE a este patron.
PREFIJO_BASE_PRUEBAS='pruebamig_'

# Los tests que EJECUTAN migraciones contra MySQL. Se corren aparte y con
# --fail-on-skipped, porque en la suite general un skip pasa desapercibido.
TESTS_DE_MIGRACION='BackfillAmbiente054|VeredictoMigraciones'

# Crons del host que administra el repo. Solo uno, a proposito: ver
# infra/cron.d/README.md.
CRONS_ADMINISTRADOS=('sinergia-pagos')
DESTINO_CRON='/etc/cron.d'

BUILD_MEM="2g"
BUILD_CPUSET="0,1"

LOG_DIR="/data/sinergia/deploy-logs"
LOG_FILE="$LOG_DIR/deploy-$(date +%Y%m%d_%H%M%S).log"

SECRETOS=(key.pem fullchain.pem .rcv_internal_key sinergia.env mysql.env)

FORCE=0
DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --force)   FORCE=1 ;;
    --dry-run) DRY_RUN=1 ;;
    *) echo "ERROR: argumento desconocido: $arg"; exit 2 ;;
  esac
done

# ── Log ───────────────────────────────────────────────────────────────────────
mkdir -p "$LOG_DIR"
exec > >(tee -a "$LOG_FILE") 2>&1

paso()  { echo; echo "==> $*"; }
ok()    { echo "    OK: $*"; }
falla() { echo "    ERROR: $*"; exit 1; }

trap 'echo; echo "*** DEPLOY ABORTADO en la linea $LINENO. Log: $LOG_FILE ***"' ERR


echo "======================================================================"
echo " deploy sinergia_fac_bol  --  $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo " host: $(hostname)   usuario: $(whoami)"
[ "$DRY_RUN" -eq 1 ] && echo " MODO DRY-RUN: no se cambia nada"
echo "======================================================================"

# ── 1. Prerequisitos ──────────────────────────────────────────────────────────
paso "Verificando prerequisitos"

[ "$(id -u)" -eq 0 ] || falla "hay que correrlo como root (los secretos son 600 root:root)"
[ -d "$APP_DIR" ]    || falla "no existe $APP_DIR"
cd "$APP_DIR"
[ -f "$COMPOSE_FILE" ] || falla "no existe $APP_DIR/$COMPOSE_FILE"
ok "directorio y compose presentes"

# Los 5 secretos, con permisos. Si falta uno, el stack arranca pero no puede
# firmar: mejor detenerse aqui que descubrirlo en la primera emision.
for s in "${SECRETOS[@]}"; do
  [ -f "$s" ] || falla "falta el secreto $APP_DIR/$s"
  # Dos regimenes distintos, a proposito:
  #   key.pem y fullchain.pem -> 640 root:www-data. El panel los lee desde
  #     panel/public/index.php, y ahi php-fpm y nginx corren como www-data.
  #     Con 600 root:root el panel NO puede leerlos y falla sin que ningun
  #     health check se entere (los contenedores quedan healthy igual).
  #   los otros tres -> 600 root:root. Solo los lee root.
  # Verificado el 2026-07-28. Si esto cambia, comprobar antes quien lee cada
  # archivo, no apretar los permisos a ciegas.
  case "$s" in
    #   .rcv_internal_key -> se sumo al grupo de 640 el 06-ago: el panel la lee
    #     desde la web para GET /api/v1/contribuyente. Antes solo la usaba el
    #     consumidor de RCV, que corre por cron como root y nunca lo noto.
    key.pem|fullchain.pem|.rcv_internal_key) esperado="640 root:www-data" ;;
    *)                     esperado="600 root:root" ;;
  esac
  real=$(stat -c '%a %U:%G' "$s")
  [ "$real" = "$esperado" ] || falla "$s esta en '$real', se esperaba '$esperado'"
done
ok "los ${#SECRETOS[@]} secretos con permisos y dueno esperados"

# LibreDTE no esta en git ni en la imagen: entra por bind mount. Si el
# directorio no esta, el stack levanta igual y la generacion de PDF muere con
# "Class sasco\LibreDTE\PDF not found" recien al pedir un PDF.
[ -d "oracle/LibreDTE-master/lib" ] || falla "falta oracle/LibreDTE-master/lib (LibreDTE, va por mount)"
ok "LibreDTE presente ($(find oracle -type f | wc -l) archivos)"

# Working tree limpio: solo archivos TRACKEADOS. Los untracked son esperables
# (deploy.sh, .env, docker-compose.vps.yml, docker/, y los secretos ignorados).
if [ -n "$(git status --porcelain -uno)" ]; then
  echo "    Cambios locales en archivos trackeados:"
  git status --short -uno | sed 's/^/      /'
  falla "el working tree tiene modificaciones locales; un pull las pisaria"
fi
ok "working tree limpio (sin modificaciones a archivos trackeados)"

# ── 2. Estado ANTES ───────────────────────────────────────────────────────────
paso "Capturando estado previo"

COMMIT_ANTES=$(git rev-parse HEAD)
RAMA=$(git branch --show-current)
echo "    rama: $RAMA   commit: ${COMMIT_ANTES:0:7}"

VECINOS_ANTES=$(mktemp)
docker ps --format '{{.Names}}\t{{.Image}}' \
  | grep -vFf <(docker ps --filter "label=$PROJECT_LABEL" --format '{{.Names}}' || true) \
  | sort > "$VECINOS_ANTES" || true
N_VECINOS_ANTES=$(wc -l < "$VECINOS_ANTES")
ok "$N_VECINOS_ANTES contenedores vecinos (ajenos al stack) corriendo"

# ── 3. Fetch y decision ───────────────────────────────────────────────────────
paso "Consultando el remoto"

# GIT_TERMINAL_PROMPT=0: si por lo que sea el remoto pidiera credenciales, git
# falla en el acto en vez de colgar el deploy esperando en un prompt que nadie
# va a responder (el script corre desatendido). El repo es publico hoy, asi que
# esto es una red de seguridad, no un requisito.
export GIT_TERMINAL_PROMPT=0
git fetch origin "$RAMA"

COMMIT_REMOTO=$(git rev-parse "origin/$RAMA")
echo "    local:  ${COMMIT_ANTES:0:7}"
echo "    remoto: ${COMMIT_REMOTO:0:7}"

if [ "$COMMIT_ANTES" = "$COMMIT_REMOTO" ]; then
  if [ "$FORCE" -eq 0 ]; then
    echo
    echo "    No hay commits nuevos. Nada que desplegar."
    echo "    (usa --force para reconstruir igual)"
    rm -f "$VECINOS_ANTES"
    exit 0
  fi
  ok "sin commits nuevos, pero --force: se reconstruye igual"
else
  echo "    Commits a aplicar:"
  git log --oneline "$COMMIT_ANTES..$COMMIT_REMOTO" | sed 's/^/      /'
fi

# ── Migraciones: la funcion, usada en dry-run y en el deploy real ────────────
#
# EL VERIFICADOR TIENE QUE CORRER CON EL CODIGO NUEVO, Y AHI ESTA TODO EL TRUCO.
#
# La forma obvia -- docker exec sinergia_panel php scripts/estado_migraciones.php
# -- ES INCORRECTA en este punto del deploy. En el VPS /app viene DE LA IMAGEN:
# docker-compose.vps.yml solo monta key.pem, fullchain.pem, .rcv_internal_key,
# ./oracle y el volumen de debug. scripts/ NO esta montado. Asi que despues del
# git pull, el contenedor que corre sigue teniendo el verificador VIEJO, cuyo
# array MIGRACIONES no conoce las migraciones que acaban de llegar: un deploy
# que trae la 032 diria "todas aplicadas" y pasaria. Falso OK, justo en el caso
# que este enganche existe para atrapar.
#
# La solucion es un contenedor DESECHABLE con la imagen vieja -- que ya trae PHP
# y pdo_mysql -- y el codigo NUEVO montado encima. Funciona porque
# estado_migraciones.php no requiere vendor/autoload.php: solo necesita PDO y las
# env vars, asi que alcanza con montar scripts/.
#
# La otra opcion era mover la comprobacion despues del build, donde la imagen
# nueva ya trae el script. Se descarta: la idea es abortar SIN HABER TOCADO NADA,
# y un build de dos imagenes en un VPS con 6 vCPU compartidos no es "nada".
verificar_migraciones() {
  local modo="$1"   # "dry-run" o "real"

  # LA RED NO SE ESCRIBE FIJA: se deriva del contenedor de mysql que ya esta
  # corriendo. En este punto del deploy el stack viejo sigue en pie, asi que el
  # dato existe. Escribir "sinergia_net" a mano seria suponer un nombre que
  # nunca se verifico contra esta maquina.
  local red
  red=$(docker inspect sinergia_mysql \
          --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' 2>/dev/null \
        | grep -v '^$' | head -n1 || true)

  if [ -z "$red" ]; then
    falla "no pude derivar la red desde sinergia_mysql (¿el stack viejo esta caido?). Sin red no se puede verificar migraciones."
  fi
  echo "    red derivada de sinergia_mysql: $red"

  # La imagen vieja aporta PHP + pdo_mysql; el bind mount aporta el codigo nuevo.
  docker image inspect sinergia_panel:latest >/dev/null 2>&1 \
    || falla "no existe la imagen sinergia_panel:latest; no hay donde correr el verificador"

  local salida rc
  set +e
  salida=$(docker run --rm --network "$red" \
             -v "$APP_DIR/scripts:/app/scripts:ro" \
             --env-file sinergia.env \
             -e DB_HOST=sinergia_mysql \
             sinergia_panel:latest \
             php /app/scripts/estado_migraciones.php 2>&1)
  rc=$?
  set -e

  echo "$salida" | sed 's/^/    /'

  case "$rc" in
    0) ok "migraciones al dia (las diferidas a proposito quedaron listadas arriba)" ;;
    2)
      if [ "$modo" = "dry-run" ]; then
        echo "    DRY-RUN: el verificador no pudo conectar a la base (exit 2). En un deploy real esto ABORTARIA."
      else
        falla "el verificador de migraciones no pudo conectar a la base (exit 2)"
      fi
      ;;
    *)
      if [ "$modo" = "dry-run" ]; then
        echo "    DRY-RUN: hay migraciones sin aplicar y sin marcar (exit $rc). En un deploy real esto ABORTARIA."
        echo "    DRY-RUN: no se aborta."
      else
        falla "hay migraciones sin aplicar que no estan marcadas como diferidas (exit $rc). Aplicalas antes de desplegar."
      fi
      ;;
  esac
}

# ── Suite: la funcion, usada en dry-run y en el deploy real ──────────────────
#
# CORRE CONTRA EL CODIGO NUEVO, igual que el verificador de migraciones y por el
# mismo motivo: en el VPS /app viene DE LA IMAGEN, asi que un contenedor del
# stack viejo tiene los tests VIEJOS. La imagen de tests se construye una vez y
# el codigo se le MONTA encima.
#
# POR QUE UNA IMAGEN APARTE (docker/Dockerfile.tests): la imagen del panel
# instala con --no-dev y no trae PHPUnit. La de tests hereda de ella -- misma
# base, mismas extensiones, misma configuracion de OpenSSL -- y solo agrega las
# dependencias de desarrollo. Que herede importa: el 25-08-2026, 34 de los 43
# errores de la suite venian de la configuracion de OpenSSL de la imagen. Una
# suite que corre en un entorno distinto al de produccion no prueba lo que uno
# cree que prueba.
#
# EL CRITERIO DE EXITO ES "CERO ERRORES Y CERO FALLOS", NO "CERO OMITIDOS". Once
# tests comparan contra ficheros reales del SII que el .gitignore excluye por
# tener datos de un contribuyente; en esta maquina se OMITEN, diciendo cual
# falta. PHPUnit sale 0 con omitidos, que es justo lo que se quiere: un deploy
# no puede quedar bloqueado por un fixture que nunca va a estar aqui.
# ── MySQL desechable para los tests que ejecutan migraciones ─────────────────
#
# POR QUE HACE FALTA. tests/BackfillAmbiente054Test.php EJECUTA la migracion 054
# contra MySQL de verdad, porque lo que hay que comprobar -- que aborte, que no
# rellene, que la columna acabe NOT NULL sin default -- son SIGNAL,
# PREPARE/EXECUTE e information_schema, que no existen fuera de MySQL. Un test
# sobre el texto del .sql habria dado por buena una version en la que la guarda
# no podia dispararse nunca.
#
# Sin base, ese test se salta. Y una migracion que mueve la estructura donde se
# cobra dinero no puede validarse con quince tests en gris.
#
# LO QUE SE PREPARA AQUI: un usuario temporal con contrasena aleatoria y permisos
# limitados a `pruebamig\_%`.*, y una base con ese prefijo. El test cuelga de ahi
# las suyas, una por caso.
#
# LA GUARDA DE VERDAD ES EL GRANT, no el nombre. Aunque la comprobacion del lado
# de PHP fallara, este usuario no puede tocar sinergia_fac_bol: MySQL se lo
# impide. Por eso no se usa root ni el usuario de la aplicacion.
#
# LA CONTRASENA NO SE IMPRIME NI SE ESCRIBE EN NINGUN ARCHIVO. Se genera aqui, se
# pasa al contenedor por -e y muere con la corrida. La de root ni siquiera se
# lee: se referencia dentro del propio contenedor de mysql.
MYSQL_PRUEBAS_BASE=""
MYSQL_PRUEBAS_USER=""
MYSQL_PRUEBAS_PASS=""

preparar_mysql_de_pruebas() {
  # FALLA, NO DEGRADA. Antes, cualquier tropiezo aqui dejaba los tests de
  # migracion en gris y el deploy seguia tan tranquilo -- o sea que la
  # comprobacion mas cara del modulo era, en la practica, opcional: bastaba con
  # que MySQL tuviera un mal dia para desplegar sin ella. No poder montar el
  # entorno de validacion no es "una comprobacion menos": es no haber validado.
  if ! docker inspect sinergia_mysql >/dev/null 2>&1; then
    falla "no existe el contenedor sinergia_mysql: sin el no se pueden correr los tests que EJECUTAN migraciones, y esos no son opcionales"
  fi

  local sufijo
  sufijo=$(head -c8 /dev/urandom | od -An -tx1 | tr -d ' \n')

  MYSQL_PRUEBAS_BASE="${PREFIJO_BASE_PRUEBAS}${sufijo}"
  MYSQL_PRUEBAS_USER="pruebas_${sufijo}"
  MYSQL_PRUEBAS_PASS=$(head -c18 /dev/urandom | od -An -tx1 | tr -d ' \n')

  # EL SQL VA POR STDIN Y NO EN -e "...", y no es preferencia: anidar comillas
  # dentro de docker exec sh -c "mysql -e \"...\"" son tres capas de escape, y la
  # del GRANT salio mal la primera vez -- el patron acabo con doble barra y MySQL
  # denegaba el acceso a la base que el propio script acababa de crear. Con
  # heredoc, lo que se escribe es lo que llega.
  #
  # sh -c EN COMILLAS SIMPLES para que $MYSQL_ROOT_PASSWORD lo expanda el shell
  # DE DENTRO del contenedor: la contrasena de root no pasa por el host, no
  # aparece en el log y no queda en el historial.
  #
  # EL GRANT ES LA GUARDA, y el patron se DERIVA del prefijo en vez de escribirse
  # a mano. Escrito a mano salio mal: el prefijo ya termina en '_', asi que
  # anadirle otro '\_' daba `pruebamig_\_%` -- "pruebamig", un caracter
  # cualquiera, un guion bajo literal -- que no casa con pruebamig_a7b5... y
  # MySQL denegaba el acceso a la base que el propio script acababa de crear.
  #
  # Lo que hace falta es escapar el guion bajo que YA ESTA en el prefijo: sin
  # escapar, '_' es el comodin de un caracter en LIKE y el permiso se ampliaria a
  # cualquier base que empiece por "pruebami" + un caracter.
  local patron_grant="${PREFIJO_BASE_PRUEBAS//_/\\_}%"

  if ! docker exec -i sinergia_mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' >/dev/null 2>&1 <<SQL
CREATE DATABASE \`${MYSQL_PRUEBAS_BASE}\`;
CREATE USER '${MYSQL_PRUEBAS_USER}'@'%' IDENTIFIED BY '${MYSQL_PRUEBAS_PASS}';
GRANT ALL PRIVILEGES ON \`${patron_grant}\`.* TO '${MYSQL_PRUEBAS_USER}'@'%';
FLUSH PRIVILEGES;
SQL
  then
    # Se limpia lo que hubiera quedado a medias antes de abortar: si el CREATE
    # DATABASE paso y el GRANT no, la base ya existe.
    limpiar_mysql_de_pruebas
    falla "no se pudo preparar el MySQL desechable (base/usuario/GRANT). Los tests que ejecutan la migracion 054 NO pueden saltarse: revisa el contenedor sinergia_mysql y vuelve a intentar"
  fi

  ok "MySQL de pruebas listo (base ${MYSQL_PRUEBAS_BASE}, usuario acotado a ${PREFIJO_BASE_PRUEBAS}%)"
}

# Se llama desde un trap EXIT: tiene que correr aunque la suite falle, aunque el
# deploy aborte y aunque alguien mate el script. Sin esto, cada corrida fallida
# dejaria una base colgando dentro del MySQL de produccion.
limpiar_mysql_de_pruebas() {
  [ -n "$MYSQL_PRUEBAS_USER" ] || return 0
  docker inspect sinergia_mysql >/dev/null 2>&1 || return 0

  # Se borran TODAS las bases de esta corrida -- la base y las que el test colgo
  # de ella -- buscandolas por prefijo en information_schema, no con una lista
  # que habria que mantener. El DROP USER va con IF EXISTS para que la limpieza
  # se pueda repetir sin ruido.
  docker exec -i sinergia_mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B \
      | mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' >/dev/null 2>&1 <<SQL || true
SELECT CONCAT('DROP DATABASE \`', SCHEMA_NAME, '\`;')
  FROM information_schema.SCHEMATA
 WHERE SCHEMA_NAME LIKE '${MYSQL_PRUEBAS_BASE}%';
SQL

  docker exec -i sinergia_mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' >/dev/null 2>&1 <<SQL || true
DROP USER IF EXISTS '${MYSQL_PRUEBAS_USER}'@'%';
SQL

  MYSQL_PRUEBAS_BASE=""
  MYSQL_PRUEBAS_USER=""
  MYSQL_PRUEBAS_PASS=""
}


trap 'limpiar_mysql_de_pruebas' EXIT

# ── Crons del host, versionados ──────────────────────────────────────────────
#
# POR QUE ESTO ESTA AQUI. /etc/cron.d/sinergia-pagos se creo a mano cuando se
# puso en marcha el cobro en linea. Funcionaba, pero no vivia en ninguna parte:
# si el host se reconstruye o se migra, ese archivo no viaja y el conciliador
# desaparece SIN QUE NADIE SE ENTERE -- no falla nada, simplemente deja de correr.
# Y el conciliador es la unica red que recupera un pago que Flow cobro y cuyo
# aviso se perdio. Un modulo que mueve dinero no puede depender de un archivo que
# solo existe en un servidor.
#
# EL CONTENIDO MANDA DESDE EL REPO. Si alguien edita el archivo en el servidor,
# el siguiente despliegue lo revierte. Es justamente lo que se quiere: que la
# unica forma de cambiar un cron sea cambiarlo aqui.
#
# NO SE REINICIA NADA: cron relee /etc/cron.d por su cuenta.
verificar_crons() {
  local modo="$1"   # "dry-run" o "real"
  local nombre origen destino

  for nombre in "${CRONS_ADMINISTRADOS[@]}"; do
    origen="$APP_DIR/infra/cron.d/$nombre"
    destino="$DESTINO_CRON/$nombre"

    [ -f "$origen" ] || falla "falta $origen: el repo tiene que traer el cron que dice administrar"

    if [ -f "$destino" ] && cmp -s "$origen" "$destino"; then
      # Existe y coincide. Se comprueban igual dueno y permisos: un archivo de
      # /etc/cron.d con el contenido bueno pero mal dueno NO lo ejecuta cron, y
      # el sintoma seria el mismo que si no existiera -- silencio.
      local propietario permisos
      propietario=$(stat -c '%U:%G' "$destino")
      permisos=$(stat -c '%a' "$destino")

      if [ "$propietario" = "root:root" ] && [ "$permisos" = "644" ]; then
        ok "cron $nombre al dia (root:root 0644)"
        continue
      fi

      echo "    $nombre: contenido correcto pero $propietario $permisos (se espera root:root 644)"
    elif [ -f "$destino" ]; then
      echo "    $nombre: el instalado DIFIERE del repo"
      diff -u "$destino" "$origen" 2>/dev/null | sed 's/^/      /' || true
    else
      echo "    $nombre: no esta instalado"
    fi

    if [ "$modo" = "dry-run" ]; then
      echo "    DRY-RUN: aqui se instalaria $destino desde el repo (root:root 0644). No se toca nada."
      continue
    fi

    # install en vez de cp: pone contenido, dueno y permisos en una sola
    # operacion, y escribe a un temporal que renombra -- cron nunca ve un archivo
    # a medio escribir.
    install -o root -g root -m 0644 "$origen" "$destino" \
      || falla "no se pudo instalar $destino"

    # SE VERIFICA DESPUES DE ESCRIBIR, no se da por hecho. install puede volver 0
    # y dejar algo distinto de lo esperado (un umask raro, un /etc montado de
    # otra forma), y aqui el fallo es silencioso por naturaleza.
    cmp -s "$origen" "$destino" || falla "$destino quedo con contenido distinto del repo"
    [ "$(stat -c '%U:%G' "$destino")" = "root:root" ] || falla "$destino no quedo root:root"
    [ "$(stat -c '%a' "$destino")" = "644" ] || falla "$destino no quedo con permisos 0644"

    ok "cron $nombre instalado y verificado (root:root 0644)"
  done
}

# ── Los tests que EJECUTAN migraciones, aparte y obligatorios ────────────────
#
# POR QUE SE CORREN SOLOS Y NO SE CONFIA EN LA SUITE GENERAL. Porque la suite
# general pasa igual con estos quince tests en gris: markTestSkipped no rompe
# nada. Mirar el total de "Skipped" tampoco vale -- hoy son 11 por los fixtures
# de openssl, manana pueden ser otros -- asi que el numero no demuestra nada.
#
# Lo inequivoco son dos banderas de PHPUnit:
#
#   --fail-on-skipped            un test saltado devuelve exit != 0. Si el DSN no
#                                llega al contenedor, o la guarda lo rechaza, o
#                                MySQL no responde, esto se entera.
#   --fail-on-empty-test-suite   un filtro que no casa nada devuelve exit != 0.
#                                Sin esto, renombrar la clase daria "No tests
#                                executed!" con exit 0: verde por no haber hecho
#                                nada, que es la peor forma de verde.
#
# ABORTA TAMBIEN EN DRY-RUN, apartandose del resto del script. verificar_suite
# reporta y sigue en dry-run porque ahi el valor esta en ver el diagnostico
# completo; aqui no hay diagnostico que ver: o se ejecutaron o no, y si no se
# ejecutaron el dry-run estaria diciendo "todo listo para desplegar" sobre una
# validacion que no ocurrio.
verificar_tests_de_migracion() {
  [ -n "$MYSQL_PRUEBAS_BASE" ] \
    || falla "no hay MySQL desechable preparado: los tests que ejecutan migraciones no pueden correr"

  local red_mysql
  red_mysql=$(docker inspect sinergia_mysql \
                --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' 2>/dev/null \
              | grep -v '^$' | head -n1 || true)
  [ -n "$red_mysql" ] || falla "no pude derivar la red de sinergia_mysql para los tests de migracion"

  local salida rc
  set +e
  salida=$(docker run --rm \
             --network "$red_mysql" \
             -e "TEST_MYSQL_DSN=mysql:host=sinergia_mysql;dbname=${MYSQL_PRUEBAS_BASE};charset=utf8mb4" \
             -e "TEST_MYSQL_USER=${MYSQL_PRUEBAS_USER}" \
             -e "TEST_MYSQL_PASS=${MYSQL_PRUEBAS_PASS}" \
             -v "$APP_DIR/src:/app/src:ro" \
             -v "$APP_DIR/tests:/app/tests:ro" \
             -v "$APP_DIR/panel:/app/panel:ro" \
             -v "$APP_DIR/public:/app/public:ro" \
             -v "$APP_DIR/integration:/app/integration:ro" \
             -v "$APP_DIR/scripts:/app/scripts:ro" \
             -v "$APP_DIR/phpunit.xml:/app/phpunit.xml:ro" \
             -v "$APP_DIR/deploy.sh:/app/deploy.sh:ro" \
             -v "$APP_DIR/infra:/app/infra:ro" \
             sinergia_tests:latest \
             vendor/bin/phpunit --no-coverage --testdox \
               --fail-on-skipped --fail-on-empty-test-suite \
               --filter "$TESTS_DE_MIGRACION" 2>&1)
  rc=$?
  set -e

  if [ "$rc" -ne 0 ]; then
    echo "$salida" | sed 's/^/    /'
    falla "los tests que EJECUTAN migraciones no pasaron (exit $rc). No se despliega una migracion de la estructura de pagos sin haberla ejecutado contra MySQL"
  fi

  # Se imprime la lista entera: es la prueba de que corrieron, y va al log del
  # deploy para que quede por escrito cuales fueron.
  echo "$salida" | sed 's/^/    /'
  ok "los tests que ejecutan migraciones corrieron de verdad (ni uno saltado)"
}

verificar_suite() {
  local modo="$1"   # "dry-run" o "real"

  docker image inspect sinergia_panel:latest >/dev/null 2>&1 \
    || falla "no existe la imagen sinergia_panel:latest; la de tests hereda de ella"

  # Construir es barato cuando no cambian composer.json/composer.lock: es su
  # unica capa propia y queda cacheada. Se acota igual que el build real, que
  # este VPS comparte 6 vCPU con los contenedores de otros cinco proyectos.
  if ! DOCKER_BUILDKIT=0 docker build \
        --memory "$BUILD_MEM" --cpuset-cpus "$BUILD_CPUSET" \
        -f docker/Dockerfile.tests -t sinergia_tests:latest . >/dev/null 2>&1; then
    falla "no se pudo construir la imagen de tests (docker/Dockerfile.tests)"
  fi

  # Se montan los directorios UNO A UNO y no el repo entero sobre /app: montar
  # /app taparia el vendor/ de la imagen, que es lo unico que esta imagen
  # aporta.
  #
  # public/ TAMBIEN SE MONTA, y faltaba. La imagen de tests hereda de
  # sinergia_panel:latest, que en este punto del script es todavia la del deploy
  # ANTERIOR -- las imagenes se construyen en el paso 5, despues de esto. Sin
  # este montaje, todo test que lea public/index.php (el motor) estaria leyendo
  # el codigo viejo y aprobando un archivo que no es el que se va a desplegar.
  # Ya habia dos tests asi (DatosDelPanelTest lee TIPOS_PERMITIDOS_PDF de ahi), y
  # no se notaba porque leian constantes que casi nunca cambian: el dia que una
  # cambiara, la suite habria dado verde sobre la version equivocada. La config de OpenSSL se monta tambien, para que la suite corra con
  # la del arbol nuevo y no con la horneada en la imagen vieja -- si no, un
  # deploy que venga a ARREGLAR esa config no podria pasar sus propios tests.
    # Los tests que EJECUTAN migraciones necesitan alcanzar a MySQL. Se les da la
    # red del contenedor que ya corre -- derivada y no escrita a mano, igual que
    # en verificar_migraciones -- y un DSN que apunta SOLO a la base desechable.
    # Si no se pudo preparar, no se pasa nada y esos tests se saltan solos.
    local extra=()
    if [ -n "$MYSQL_PRUEBAS_BASE" ]; then
      local red_mysql
      red_mysql=$(docker inspect sinergia_mysql \
                    --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' 2>/dev/null \
                  | grep -v '^$' | head -n1 || true)
      if [ -n "$red_mysql" ]; then
        extra=(--network "$red_mysql"
               -e "TEST_MYSQL_DSN=mysql:host=sinergia_mysql;dbname=${MYSQL_PRUEBAS_BASE};charset=utf8mb4"
               -e "TEST_MYSQL_USER=${MYSQL_PRUEBAS_USER}"
               -e "TEST_MYSQL_PASS=${MYSQL_PRUEBAS_PASS}")
      fi
    fi

  local salida rc
  set +e
  salida=$(docker run --rm \
               "${extra[@]}" \
             -v "$APP_DIR/src:/app/src:ro" \
             -v "$APP_DIR/tests:/app/tests:ro" \
             -v "$APP_DIR/panel:/app/panel:ro" \
             -v "$APP_DIR/public:/app/public:ro" \
             -v "$APP_DIR/integration:/app/integration:ro" \
             -v "$APP_DIR/scripts:/app/scripts:ro" \
             -v "$APP_DIR/phpunit.xml:/app/phpunit.xml:ro" \
             -v "$APP_DIR/docker/openssl-legacy.cnf:/etc/ssl/openssl-legacy.cnf:ro" \
             -v "$APP_DIR/deploy.sh:/app/deploy.sh:ro" \
             -v "$APP_DIR/infra:/app/infra:ro" \
             sinergia_tests:latest vendor/bin/phpunit 2>&1)
  rc=$?
  set -e

  echo "$salida" | tail -n 6 | sed 's/^/    /'

  if [ "$rc" -eq 0 ]; then
    ok "la suite pasa (los omitidos son fixtures que no viajan en el repo)"
    return 0
  fi

  # Con fallos se imprime TODO, no el resumen: quien lea el log del deploy tiene
  # que poder ver que se rompio sin volver a correr nada.
  echo "$salida" | sed 's/^/    /'
  if [ "$modo" = "dry-run" ]; then
    echo "    DRY-RUN: la suite fallo (exit $rc). En un deploy real esto ABORTARIA."
    return 0
  fi
  falla "la suite fallo (exit $rc): no se despliega codigo que no pasa sus propias pruebas"
}

if [ "$DRY_RUN" -eq 1 ]; then
  # En dry-run se verifica contra el arbol ACTUAL: no hubo pull, asi que las
  # migraciones nuevas del remoto todavia no estan aqui. Igual sirve -- dice si
  # la base esta al dia con lo que ya hay -- y se avisa de la limitacion.
  paso "Verificando migraciones (arbol actual, SIN el pull)"
  verificar_migraciones "dry-run"

  paso "Preparando MySQL desechable para los tests que ejecutan migraciones"
  preparar_mysql_de_pruebas

  paso "Tests que EJECUTAN migraciones (obligatorios, no pueden saltarse)"
  verificar_tests_de_migracion

  paso "Corriendo la suite (arbol actual, SIN el pull)"
  verificar_suite "dry-run"

  paso "Crons del host administrados por el repo"
  verificar_crons "dry-run"

  echo
  echo "==> DRY-RUN: aqui se haria pull, build de motor y panel, y up -d."
  echo "    Nota: la comprobacion de arriba corrio ANTES del pull, asi que no"
  echo "    incluye migraciones que traigan los commits nuevos."
  rm -f "$VECINOS_ANTES"
  exit 0
fi

# ── 4. Pull ───────────────────────────────────────────────────────────────────
paso "git pull origin $RAMA"
git pull --ff-only origin "$RAMA"
COMMIT_NUEVO=$(git rev-parse HEAD)
ok "HEAD ahora en ${COMMIT_NUEVO:0:7}"

# ── 4b. Migraciones ───────────────────────────────────────────────────────────
#
# VA AQUI Y NO EN OTRO SITIO, por las dos puntas:
#   DESPUES del pull  -> para ver las migraciones que traen los commits nuevos.
#   ANTES del build   -> para abortar sin haber construido ni levantado nada. El
#                        arbol queda adelantado, pero los contenedores viejos
#                        siguen corriendo y sirviendo.
paso "Verificando migraciones de la base"
verificar_migraciones "real"

# ── 4c. Suite ─────────────────────────────────────────────────────────────────
#
# MISMAS DOS PUNTAS QUE LAS MIGRACIONES:
#   DESPUES del pull  -> para probar el codigo que se va a desplegar, no el viejo.
#   ANTES del build   -> para abortar sin haber construido ni levantado nada.
#
# Va DESPUES de las migraciones a proposito: si la base no esta al dia, eso se
# arregla antes y no tiene sentido gastar una corrida de tests.
paso "Preparando MySQL desechable para los tests que ejecutan migraciones"
preparar_mysql_de_pruebas

paso "Tests que EJECUTAN migraciones (obligatorios, no pueden saltarse)"
verificar_tests_de_migracion

paso "Corriendo la suite"
verificar_suite "real"

# VA DESPUES DE LA SUITE Y ANTES DEL BUILD, como los otros enganches: si algo va
# a abortar, que aborte sin haber construido ni levantado nada.
paso "Crons del host administrados por el repo"
verificar_crons "real"

# ── 5. Build ──────────────────────────────────────────────────────────────────
paso "Construyendo imagenes (memoria $BUILD_MEM, cpuset $BUILD_CPUSET)"

DOCKER_BUILDKIT=0 docker build \
  --memory="$BUILD_MEM" --cpuset-cpus="$BUILD_CPUSET" \
  -f docker/Dockerfile.motor -t sinergia_motor:latest .
ok "sinergia_motor:latest"

DOCKER_BUILDKIT=0 docker build \
  --memory="$BUILD_MEM" --cpuset-cpus="$BUILD_CPUSET" \
  -f docker/Dockerfile.panel -t sinergia_panel:latest .
ok "sinergia_panel:latest"

# ── 6. Up ─────────────────────────────────────────────────────────────────────
paso "Levantando el stack"
"${COMPOSE[@]}" up -d "${SERVICIOS[@]}"

paso "Esperando a que los servicios queden healthy"
for i in $(seq 1 30); do
  pendientes=""
  for c in sinergia_mysql sinergia_motor sinergia_panel; do
    estado=$(docker inspect "$c" --format '{{.State.Health.Status}}' 2>/dev/null || echo "ausente")
    [ "$estado" = "healthy" ] || pendientes="$pendientes $c($estado)"
  done
  [ -z "$pendientes" ] && { ok "los 3 servicios healthy (${i}0s)"; break; }
  [ "$i" -eq 30 ] && falla "timeout esperando healthy:$pendientes"
  sleep 10
done

# ── 7. Verificaciones ─────────────────────────────────────────────────────────
paso "Verificando el panel"

# El panel publica SOLO en loopback (127.0.0.1:8086). "/" redirige a /login.
codigo=$(curl -s -o /dev/null -m 20 -w '%{http_code}' http://127.0.0.1:8086/login)
[ "$codigo" = "200" ] || falla "/login devolvio HTTP $codigo (se esperaba 200)"
ok "/login responde HTTP 200"

# No basta el codigo: un 200 podria ser una pagina de error del framework.
cuerpo=$(curl -s -m 20 http://127.0.0.1:8086/login)
for marca in '<title>Iniciar sesion</title>' 'name="csrf_token"' 'type="password"'; do
  echo "$cuerpo" | grep -qF "$marca" || falla "/login no contiene: $marca"
done
ok "/login trae titulo, csrf_token y campo password"

# Revision de seguridad del login: el input de password NUNCA debe volver con
# un atributo value relleno. Esto se reviso a mano al construir el login;
# aqui queda permanente, en cada deploy futuro. Un value poblado significaria
# que el servidor devuelve la contrasena al navegador -- tipicamente al
# repoblar el formulario tras un intento fallido -- y de ahi termina en el
# HTML cacheado, en el historial y en cualquier proxy intermedio.
# El HTML se APLANA antes de buscar: el input del login abarca varias lineas
#   <input type="password" name="password" id="login-password"
#          autocomplete="current-password" required>
# y un grep linea a linea no matchea un tag partido -- daria "sin value"
# sin haber mirado nada, que es peor que no comprobar.
cuerpo_plano=$(echo "$cuerpo" | tr '\n' ' ')
PW_INPUTS=$(echo "$cuerpo_plano" | grep -oiE '<input[^>]*>' | grep -iE 'type=["'\'']?password' || true)

# Fail-closed: si no aparece ningun input de password, algo cambio en el login
# y esta comprobacion dejo de aplicar. Se aborta en vez de dar un OK vacio.
[ -n "$PW_INPUTS" ] || falla "no se encontro ningun <input type=password> en /login: la revision de seguridad no pudo ejecutarse"

if echo "$PW_INPUTS" | grep -qiE 'value[[:space:]]*=[[:space:]]*["'\'']?[^"'\''[:space:]>]'; then
  echo "    input(s) de password con value:"
  echo "$PW_INPUTS" | sed 's/^/      /'
  falla "el input de password vuelve con un atributo value relleno"
fi
ok "input de password sin value ($(echo "$PW_INPUTS" | wc -l) input examinado)"

echo "$cuerpo" | grep -qiE 'fatal error|uncaught|stack trace' \
  && falla "/login contiene una traza de error PHP"
ok "/login sin trazas de error PHP"

# Estatico servido por nginx sin pasar por php-fpm.
codigo=$(curl -s -o /dev/null -m 20 -w '%{http_code}' http://127.0.0.1:8086/css/style.css)
[ "$codigo" = "200" ] || falla "/css/style.css devolvio HTTP $codigo"
ok "estaticos servidos por nginx"

paso "Verificando el motor"

# El motor NO publica puerto: solo es alcanzable desde dentro de sinergia_net.
# Por eso la comprobacion va con docker exec desde el panel, nunca por un
# puerto del host.
salud=$(docker exec sinergia_panel curl -s -m 20 http://sinergia_motor/health || true)
echo "$salud" | grep -q '"status":"ok"' || falla "motor /health no respondio ok: $salud"
ok "motor /health responde ok (via red interna)"

# El autoloader debe resolver el namespace de integration/plantiflex/, que
# composer.json declara bajo autoload-dev pese a ser codigo de produccion. Si
# esto falla, el stack luce sano pero no puede descifrar certificados ni emitir.
docker exec sinergia_motor php -r '
  require "/app/vendor/autoload.php";
  exit(class_exists("Plantiflex\\Integration\\Facturacion\\CertificadoCrypto") ? 0 : 1);
' || falla "el autoloader no resuelve CertificadoCrypto (falta dump-autoload --dev en el build)"
ok "autoloader resuelve integration/plantiflex/"

# LibreDTE, que entra por mount y no por la imagen.
docker exec sinergia_motor php -r '
  require "/app/vendor/autoload.php";
  new \Plantiflex\FacturacionCl\Pdf\DtePdfGenerator();
  exit(class_exists("sasco\\LibreDTE\\Sii\\PDF\\Dte") ? 0 : 1);
' >/dev/null 2>&1 || falla "LibreDTE no carga (revisar mount ./oracle:/app/oracle:ro)"
ok "LibreDTE carga por el autoload propio"

paso "Verificando la base de datos"
docker exec sinergia_motor php -r '
  $d = new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_NAME"),
               getenv("DB_USER"), getenv("DB_PASS"));
  $n = (int) $d->query("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema = DATABASE()")->fetchColumn();
  if ($n === 0) { fwrite(STDERR, "la base no tiene tablas\n"); exit(1); }
  echo "    tablas en la base: $n\n";
' || falla "el motor no puede consultar la base"
ok "base accesible desde el motor"

# ── 8. Vecinos DESPUES ────────────────────────────────────────────────────────
paso "Comprobando que los vecinos no se tocaron"

VECINOS_DESPUES=$(mktemp)
docker ps --format '{{.Names}}\t{{.Image}}' \
  | grep -vFf <(docker ps --filter "label=$PROJECT_LABEL" --format '{{.Names}}' || true) \
  | sort > "$VECINOS_DESPUES" || true
N_VECINOS_DESPUES=$(wc -l < "$VECINOS_DESPUES")

if ! diff -q "$VECINOS_ANTES" "$VECINOS_DESPUES" >/dev/null; then
  echo "    *** LOS VECINOS CAMBIARON ***"
  diff "$VECINOS_ANTES" "$VECINOS_DESPUES" | sed 's/^/      /' || true
  rm -f "$VECINOS_ANTES" "$VECINOS_DESPUES"
  falla "el deploy afecto contenedores de otros proyectos"
fi
ok "$N_VECINOS_DESPUES vecinos intactos (antes: $N_VECINOS_ANTES)"
rm -f "$VECINOS_ANTES" "$VECINOS_DESPUES"

# ── 9. Resumen ────────────────────────────────────────────────────────────────
paso "Estado final"
"${COMPOSE[@]}" ps --format 'table {{.Service}}\t{{.Name}}\t{{.Status}}\t{{.Ports}}'

echo
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' \
  sinergia_mysql sinergia_motor sinergia_panel

echo
echo "======================================================================"
echo " DEPLOY OK"
echo "   ${COMMIT_ANTES:0:7} -> ${COMMIT_NUEVO:0:7}"
echo "   panel: https://facturacion.sinergiaia.cl"
echo "   log:   $LOG_FILE"
echo
echo " Si algo salio mal y hay que volver atras:"
echo "   cd $APP_DIR"
echo "   git reset --hard $COMMIT_ANTES"
echo "   ./deploy.sh --force"
echo "======================================================================"
