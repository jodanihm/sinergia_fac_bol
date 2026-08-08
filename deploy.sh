#!/bin/bash
#
# deploy.sh -- sinergia_fac_bol (VPS)
#
# Despliegue por git: fetch -> pull -> build -> up -> verificar.
#
# NO esta trackeado en git (es infraestructura de esta maquina, no del repo).
# NO toca la base de datos ni los secretos: esos ya viven en el host y entran
# a los contenedores por env_file y por mounts :ro.
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

if [ "$DRY_RUN" -eq 1 ]; then
  echo
  echo "==> DRY-RUN: aqui se haria pull, build de motor y panel, y up -d."
  rm -f "$VECINOS_ANTES"
  exit 0
fi

# ── 4. Pull ───────────────────────────────────────────────────────────────────
paso "git pull origin $RAMA"
git pull --ff-only origin "$RAMA"
COMMIT_NUEVO=$(git rev-parse HEAD)
ok "HEAD ahora en ${COMMIT_NUEVO:0:7}"

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
