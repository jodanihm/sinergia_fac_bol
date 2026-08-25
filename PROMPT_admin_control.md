## 0. Rol y objetivo

Vas a construir el **panel de control (superadmin)** de Sinergia Facturacion:
un area `/admin/*` desde la que el equipo interno administra **todas** las
cuentas del SaaS, calcada en concepto y en forma del panel `admin-web` del
proyecto hermano **Brewer Manager**, pero adaptada a este stack y a este
dominio.

Hoy existe un embrion de esto (dos rutas y dos vistas sin estilo propio). Lo
vas a **absorber y ampliar**, no reemplazar a ciegas.

Trabaja en una rama nueva: `git checkout -b admin-control`.

---

## 1. Los dos proyectos

**Brewer Manager** (el referente, no esta en tu clon):
SPA React 18 + Vite 5 + react-router-dom 6 + mermaid, servida por nginx en su
propio contenedor, hablando con un backend NestJS del plano de control.
Multi-tenant **por schema de Postgres** (`t_<slug>`).
Su panel tiene 9 paginas: Panel, Cervecerias (tenants), APIs e integraciones,
Base de datos, Flujos, Roles y permisos, Documentos PDF, Pendientes e ideas,
Changelog. Las cinco ultimas no tocan la base: son arrays en archivos `.ts`
mantenidos a mano.

**Sinergia Facturacion** (tu repo):
Monolito PHP 8.2 sin framework. El panel es un **front controller unico**,
`panel/public/index.php`, de ~16.350 lineas: helpers y handlers arriba, router
al final. Vistas PHP planas en `panel/views/`. MySQL 8. Multi-tenant **por fila**
(`cuenta_id`), todos los tenants comparten tablas.

**Esa diferencia es el eje de todo el trabajo.** En Brewer el aislamiento lo
garantiza Postgres; aqui lo garantiza un `WHERE` que un humano tiene que
acordarse de escribir. Un panel que recorre *todas* las cuentas es exactamente
el lugar donde ese `WHERE` se omite. Ver seccion 7.

---

## 2. Decisiones ya tomadas — no las re-discutas

1. **Plano de control (superadmin).** El panel administra TODAS las cuentas.
   No es un area de autoservicio para que un tenant se administre a si mismo
   (eso ya existe en `/configuracion/*` y `/auditoria` y no se toca).
2. **PHP, el mismo stack del panel.** Rutas en `panel/public/index.php`, vistas
   en `panel/views/`. Sin React, sin Vite, sin build, sin contenedor nuevo.
   Reusa `Auth`, `Csrf`, `Db`, `vista()`, `csrfInput()`, `redirigir()`.
3. **Alcance de paginas** (las cuatro areas pedidas):
   - Panel + Cuentas + Auditoria
   - Base de datos (explorador + diagrama ER)
   - Roles y permisos (matriz visual)
   - Changelog + Pendientes + Flujos + Documentos
4. **Fuera de alcance:** la pagina "APIs e integraciones" de Brewer. No la
   construyas.

---

## 3. Lo que ya existe — leelo antes de escribir una linea

Lee estos archivos completos o en los rangos indicados. **No asumas nada de lo
que sigue: verificalo en el codigo.** Los numeros de linea son de hoy y se
mueven en cuanto edites.

### 3.1 Infraestructura del panel

| Archivo | Que es |
| --- | --- |
| `panel/src/Db.php` | `Db::conexion()`: PDO MySQL con cache estatica por request. Exige `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` (sin default, falla ruidoso); `DB_PORT` default 3306. |
| `panel/src/Auth.php` | Sesion PHP nativa. Guarda **solo** `usuario_id` y `cuenta_id`. `Auth::cuentaId()`, `Auth::usuarioId()`, `Auth::requerirSesion()`. |
| `panel/src/Csrf.php` | Token en sesion, comparado con `hash_equals`. |
| `panel/views/partials/header.php` | Layout del panel del tenant: `$titulo`, `$bodyClase`, clase `con-sidebar`, skip-link, aviso de modo demo, cache-busting del CSS por `filemtime`. |
| `panel/views/partials/_nav.php` | Sidebar del tenant, construido desde `definicionMenu()`. |
| `panel/public/css/style.css` | 2.724 lineas, tema **claro**, tokens en `:root` (`--color-primario: #1a56db`, etc.). |

### 3.2 Helpers del front controller (`panel/public/index.php`)

- Bootstrap (~lineas 95-110): `require` de `panel/src/*.php`, luego
  `vendor/autoload.php`, luego `InformePdf.php` (que extiende TCPDF y por eso
  va despues), y `Auth::iniciar()`.
- `vista(string $nombre, array $datos = []): never` — `extract()` + `require
  panel/views/$nombre.php` + `exit`.
- `csrfInput(): string` — `<input hidden>` con el token.
- `redirigir(string $ruta): never`.
- `flashTomar()` — mensaje flash de un solo uso.
- `negar403()`, `exigirSuperadmin(PDO)`, `exigirOwner(PDO)`,
  `exigirPermisoDeRuta(...)`.
- `registrarAuditoriaAdmin(...)` — `INSERT` en `admin_auditoria`.
- `estadoEmisionProduccion(PDO, int $cuentaId)` — devuelve `['falta' => null]`
  cuando la cuenta tiene las **tres** filas de produccion (emisor + certificado
  + CAF) y por lo tanto puede emitir de verdad.
- `estadoProduccion(PDO, int $cuentaId, string $rutEmisor)`.
- `resumenEtapasBarra(bool, array, ?string)` — funcion **pura** que devuelve las
  6 etapas de certificacion con su clase de color. Ya se creo para el panel de
  superadmin; reusala.
- `sesionEsDemo()` / `cortarPorDemo()` — la cuenta demo es de solo lectura y el
  router **ya** corta todo POST antes de despachar. No tienes que hacer nada
  para respetarlo.

### 3.3 Constantes del gate de permisos

Van **antes** del router, y eso no es cosmetico: hay un commit de reversion en
la historia (`d7158fa`) porque se movieron abajo y rompieron el panel entero.
`php -l` y PHPUnit **no** lo detectaron. Regla: cualquier constante que use el
router va antes del router.

- `CATALOGO_MODULOS` = `ventas, compras, maestros, informes, config,
  certificacion, usuarios, auditoria, chat, dashboard`
- `CATALOGO_ACCIONES` = `ver, gestionar, emitir`
  (criterio: `ver` = GET que muestra; `gestionar` = POST que toca datos
  nuestros; `emitir` = POST que manda al SII y **quema folios**)
- `PERMISOS_RUTA` — mapa `"METODO /ruta" => [modulo, accion]`
- `PERMISOS_RUTA_PATRON` — rutas con parametro, con el **mismo regex** que usa
  el router
- `RUTAS_PUBLICAS`, `PATRONES_PUBLICOS`
- `PREFIJOS_GATE_PROPIO` = `['/admin/', '/configuracion/usuarios',
  '/configuracion/roles']` — espacios cuyo handler tiene su propio gate y que
  el mapa de permisos **no** vuelve a filtrar.

### 3.4 El area `/admin` que ya existe

Rutas registradas en el router:

GET  /admin/tenants                  -> handleAdminTenantsGet()
POST /admin/tenants/suspender        -> cambiarEstadoCuentaAdmin()
POST /admin/tenants/reactivar        -> cambiarEstadoCuentaAdmin()
POST /admin/tenants/revertir-etapa
GET  /admin/auditoria

Vistas: `panel/views/admin-tenants.php` (127 lineas) y
`panel/views/admin-auditoria.php` (35 lineas). Ambas usan el layout del tenant
(`partials/header.php`) y no tienen estilo propio.

`handleAdminTenantsGet()` recorre todas las cuentas y, por cada `rut_emisor` de
certificacion, reusa `setBasicoAprobado()`, `libroAprobado()`,
`calcularEtapasManuales()` y `obtenerCertificacionConfirmadaAt()` **tal cual**
para no reinventar el calculo de en que etapa esta cada empresa. Mantiene ese
principio: **el panel de control no puede tener su propia version de un
calculo que el tenant ya hace.** Si divergen, el superadmin ve una realidad
distinta a la del cliente que lo llama por telefono.

### 3.5 Modelo de datos relevante

cuenta(id, email, nombre, estado ENUM('activa','suspendida'), created_at)
usuario(id, cuenta_id, email, password_hash,
        rol VARCHAR(50) DEFAULT 'owner',   -- 'owner' | 'colaborador' | 'superadmin'
        rol_id BIGINT NULL,                 -- FK a rol
        estado ENUM('activo','inactivo'), demo TINYINT,
        activacion_token, activacion_expira, created_at)
rol(id, cuenta_id, nombre, created_at)      -- UNIQUE (cuenta_id, nombre)
permiso(rol_id, modulo, accion)             -- PK (rol_id, modulo, accion)
admin_auditoria(id, usuario_id, accion, entidad_tipo, entidad_id,
                valor_anterior JSON, valor_nuevo JSON, created_at)
                -- append-only, sin updated_at
api_key(id, cuenta_id, key_hash, prefijo, rut_emisor_scope, ambiente,
        tipo ENUM('externa','servicio'), estado, last_used_at, created_at)
dte_emisor, dte_certificado, dte_caf, dte_folio, dte_folio_log,
dte_emitido, dte_libro, dte_envio_correo, dte_logo, dte_idempotencia,
cliente, producto, proveedor, cotizacion*, orden_compra*, nota_venta*,
lote_carga, chat_consulta*

**Las dos columnas de rol conviven a proposito** y responden preguntas
distintas:

- `usuario.rol` — QUE ES este usuario. `owner` y `superadmin` **bypasean el
  gate entero**. Propiedad estructural.
- `usuario.rol_id` — QUE PUEDE HACER un colaborador. Configurable, es dato.
  Nullable, porque un owner o un superadmin no necesitan rol asignado.

Y administrar roles **no es un permiso del catalogo**, a proposito: un permiso
configurable que permita editar roles es una escalada de privilegios en un
paso (el colaborador se edita su propio rol y se tilda `certificacion:emitir`).
Por eso `/configuracion/roles` usa `exigirOwner()` y no un permiso.

Migraciones: `integration/plantiflex/migrations/`, 42 archivos, la ultima es
`042_rol_permiso.sql`. `php scripts/estado_migraciones.php` informa cuales
estan aplicadas (**solo lectura**, no aplica nada) con tres veredictos:
`APLICADA`, `NO_APLICADA`, `PARCIAL`.

---

## 4. Arquitectura del nuevo panel

### 4.1 Rutas

GET  /admin                     Panel (dashboard)
GET  /admin/tenants             Cuentas          (ya existe: se rehace la vista)
GET  /admin/tenants/{id}        Ficha de cuenta  (nueva, fase 2)
POST /admin/tenants/suspender   (ya existe: NO tocar la logica)
POST /admin/tenants/reactivar   (ya existe: NO tocar la logica)
POST /admin/tenants/revertir-etapa (ya existe: NO tocar la logica)
GET  /admin/auditoria           Auditoria        (ya existe: se rehace la vista)
GET  /admin/base-datos          Explorador de BD
GET  /admin/roles-permisos      Matriz de roles y permisos
GET  /admin/flujos              Flujos
GET  /admin/documentos          Documentos
GET  /admin/pendientes          Pendientes e ideas
GET  /admin/changelog           Changelog

`/admin/tenants` conserva su URL: ya existe, ya esta en `PREFIJOS_GATE_PROPIO`
y ya tiene enlaces. En la interfaz se rotula **"Cuentas"**, que es como se
llama la tabla.

### 4.2 Layout propio

El panel de control **no** usa `partials/header.php` ni el sidebar del tenant.
Crea su propio shell, igual que Brewer tiene el suyo:

panel/views/partials/admin/header.php
panel/views/partials/admin/_nav.php
panel/views/partials/admin/footer.php
panel/public/css/admin.css

Motivos: el sidebar del tenant se construye desde `definicionMenu()` y depende
de `Auth::cuentaId()`, que aqui no significa nada; y `style.css` tiene 2.724
lineas de tema claro que no hay por que tocar. **No edites `style.css`.**
Cache-busting del CSS: copia el patron de `header.php` (`filemtime` como
`?v=`), no una constante a mano.

Sidebar, con los dos grupos de Brewer:

PLATAFORMA        Panel · Cuentas · Auditoria
SISTEMA           Base de datos · Roles y permisos · Flujos ·
                  Documentos · Pendientes e ideas · Changelog

Topbar: email del superadmin + la etiqueta `superadmin` + enlace a `/panel`
("Volver al panel") + "Cerrar sesion".

### 4.3 Estilo visual

Tema **oscuro**, como el admin de Brewer, y deliberadamente distinto del panel
del tenant: quien mira una pantalla tiene que saber en un vistazo si esta
viendo su empresa o todas. Tokens de Brewer, replicalos en `admin.css`:

--bg: #0d1014;  --panel: #151a20;  --panel-2: #1c232b;  --border: #29333d;
--text: #eaeef2; --muted: #8b97a3; --accent: #4aa3df;   --accent-dark: #2f86c4;
--ok: #4caf7d;  --danger: #e0564b; --pk: #d9a441;       --fk: #b57edc;

Clases utilitarias a replicar (son las que usan todas las paginas de Brewer):
`.shell .sidebar .brand .nav-group .main .topbar .content`,
`h2.page-title .panel .field .row .btn .btn.ghost .btn.sm`,
`table .tag .tag.ok .tag.warn .error .msg-ok .muted`,
`.cards .stat .actions .toolbar`,
`.er-grid .er-table .er-col .badge.pk .badge.fk`,
`.chips .chip`, `.flow-diagram .flow-node .flow-arrow`,
`.steps .step .step-num`, `.cl-entry .meta .ver .date .cl-tag`,
`.rp-grid .rp-mod .rp-matrix .rp-cell .rp-roldot .rp-rolecols .rp-rolecard`.

### 4.4 Convenciones de codigo del repo — respetalas

- **Sin tildes ni enies en el codigo ni en la UI del panel.** Todo el panel
  esta en ASCII: "Auditoria", "Modo demostracion", "Sesion". Es la convencion
  vigente; no la cambies en este trabajo.
- Español latinoamericano neutro (tu estandar, nunca voseo).
- Comentarios que explican **por que**, no que. El repo tiene comentarios
  largos donde una decision es contraintuitiva o donde algo ya se rompio una
  vez. Cuando tomes una decision no obvia, escribe por que — ese es el estilo
  de la casa, no ruido.
- `htmlspecialchars()` en absolutamente todo lo que salga a HTML.
- Consultas preparadas siempre. Nunca interpolacion de valores.
- No inventes helpers nuevos si ya existe uno equivalente.

---

## 5. Las paginas, una por una

### 5.1 `/admin` — Panel

Brewer muestra 4 tarjetas + accesos + ultimo cambio. Aqui las cifras que
importan son otras. Tarjetas (`.cards` / `.stat`):

- Cuentas totales
- Cuentas activas / suspendidas
- **Cuentas habilitadas para emitir en produccion** — usa
  `estadoEmisionProduccion()` por cuenta y cuenta las que devuelven
  `['falta' => null]`. Es la metrica de negocio real: cuantos clientes pasaron
  de "contrataron" a "facturan".
- DTE emitidos en los ultimos 30 dias (`dte_emitido`, ambiente produccion)
- Usuarios activos
- Version actual (primera entrada del changelog)

Paneles:

- **Accesos** — botones a Cuentas, Base de datos, Changelog.
- **Ultimo cambio** — primera entrada del changelog, con sus items.
- **Ultimas 10 acciones administrativas** — de `admin_auditoria`, con enlace a
  `/admin/auditoria`.
- **Alertas** — solo se dibuja si hay algo que decir. Candidatos: folios por
  agotarse (`dte_folio`), correos fallidos en cola (`dte_envio_correo`),
  certificados proximos a vencer (`dte_certificado`). Revisa que columnas
  existen de verdad antes de escribir la consulta; si alguna alerta no se puede
  calcular con lo que hay, dejala fuera y anotalo en Pendientes.

### 5.2 `/admin/tenants` — Cuentas

Conserva **toda** la logica actual de `handleAdminTenantsGet()` y de los tres
POST. Lo que cambia es la presentacion: pasa al layout y al estilo del panel de
control, con la barra de 6 etapas de certificacion y los botones de revertir
tal como estan hoy (incluido el `confirm()`, que dice explicitamente que es una
correccion administrativa y no algo rutinario).

Agrega sobre la tabla: buscador por nombre/email/RUT y filtro por estado. Del
lado del servidor, no en JavaScript: la tabla crece con cada cliente.

Cada fila enlaza a la ficha de la cuenta.

**Alta de cuenta:** Brewer crea tenants desde su panel. Aqui **no lo hagas
todavia** — el alta pasa hoy por `/registro`, con activacion por token y correo
(migracion 021). Duplicar ese flujo desde el admin sin revisarlo entero es como
se crean cuentas a medio activar. Registralo en Pendientes.

### 5.3 `/admin/tenants/{id}` — Ficha de cuenta *(fase 2)*

Todo lo que hay que saber de un cliente en una pantalla, **solo lectura**:

- Cabecera: nombre, email, estado, fecha de alta, id.
- Usuarios: email, `usuario.rol`, rol asignado (`rol.nombre`), estado, demo,
  ultimo acceso si existe la columna.
- Roles de la cuenta con su cuenta de permisos y de usuarios.
- Emisores (`dte_emisor`) por ambiente, con la barra de 6 etapas.
- Certificados: RUT sender, vigencia. **Nunca** el contenido cifrado.
- CAF y folios: por tipo de DTE, rango, folio proximo, consumo.
- API keys: **solo prefijo**, ambiente, tipo, estado, ultimo uso.
- Actividad: DTE emitidos por mes (ultimos 12), ultimos 20 documentos.
- Acciones administrativas registradas sobre esta cuenta
  (`admin_auditoria WHERE entidad_tipo='cuenta' AND entidad_id=:id`).

El parametro va como `#^/admin/tenants/(\d+)$#`, con el mismo estilo de regex
que `PERMISOS_RUTA_PATRON`. Valida que la cuenta existe; si no, 404, no 500.

### 5.4 `/admin/auditoria` — Auditoria

La vista actual funciona; llevala al layout nuevo y agregale lo que le falta:

- Filtros por accion, por usuario y por rango de fechas.
- Paginacion (la tabla es append-only y solo crece).
- `valor_anterior` / `valor_nuevo` son JSON: muestralos como diff legible —
  solo las claves que cambiaron — con el JSON crudo detras de un `<details>`.
- Enlace a la ficha de la cuenta afectada.

### 5.5 `/admin/base-datos` — Explorador

La pagina mas vistosa de Brewer, y la que mas hay que adaptar: alli hay un
schema por tenant y un selector; aqui hay **una sola base compartida**.

Fuente de datos, todo contra `information_schema` filtrando por `DATABASE()`
(nunca por un nombre de base escrito a mano — el repo ya sigue ese criterio en
`scripts/estado_migraciones.php`):

- `TABLES` — nombre, motor, collation, `TABLE_ROWS` (aproximado; rotulalo como
  tal, MySQL no garantiza ese numero en InnoDB).
- `COLUMNS` — nombre, `DATA_TYPE`, `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_KEY`,
  `EXTRA`, `COLUMN_COMMENT`, ordenadas por `ORDINAL_POSITION`.
- `KEY_COLUMN_USAGE` con `REFERENCED_TABLE_NAME IS NOT NULL` — las FK.
- `STATISTICS` — indices.

Vista **Detalle**: grid de tarjetas por tabla (`.er-grid` / `.er-table`), una
linea por columna con badges PK/FK y, en las FK, `-> tabla.columna`.

**Columna "Aislamiento" — esto no existe en Brewer y es lo mas valioso de la
pagina aqui.** Clasifica cada tabla en:

- **directo** — tiene `cuenta_id`;
- **indirecto** — llega a `cuenta` por FK (p. ej. `permiso -> rol -> cuenta`),
  indicando el camino;
- **global** — no pertenece a ningun tenant;
- **sin ruta** — no se le encuentra camino a `cuenta`.

En Brewer el aislamiento lo impone Postgres con un schema por cerveceria. Aqui
lo impone un `WHERE cuenta_id = :c` que un humano tiene que acordarse de
escribir, y un olvido no es un bug de pantalla: es un contribuyente viendo los
documentos de otro. Esta columna convierte ese riesgo en algo que se mira.

Calcula el camino recorriendo el grafo de FK, no con una lista escrita a mano
que quedaria desactualizada en la proxima migracion.

**Panel de migraciones:** lee los archivos de
`integration/plantiflex/migrations/` y muestra cuales estan aplicadas. El
metodo — pares (consulta, valor esperado) contra `information_schema`, con los
tres veredictos `APLICADA` / `NO_APLICADA` / `PARCIAL` — **ya esta resuelto en
`scripts/estado_migraciones.php`**: extrae su catalogo a un archivo que puedan
requerir los dos, en vez de duplicarlo. `PARCIAL` es el veredicto que justifica
todo esto: las migraciones que mezclan `CREATE TABLE IF NOT EXISTS` con
`ALTER TABLE` dejan la base a medias si se re-ejecutan.

**Diagrama ER (opcional, al final):** Brewer usa Mermaid. Este panel **no carga
hoy ni un solo archivo JS externo** — no hay `panel/public/js/`, no hay ningun
`<script src=>`, no hay CDN. Si haces el diagrama, **vendoriza** Mermaid en
`panel/public/js/mermaid.min.js` y sirvelo local. **Nunca un CDN:** el panel va
detras de Cloudflare Tunnel y una dependencia externa es una pantalla que se
rompe el dia que el CDN falla. Genera el `erDiagram` en PHP con el mismo
formato que `buildErDiagram()` de Brewer. Si no lo haces, la vista Detalle se
basta sola: dejalo anotado en Pendientes.

**Prohibido en esta pagina:** ejecutar SQL arbitrario. Sin campo de consulta
libre, sin `EXPLAIN`, sin nada que venga del usuario dentro de una sentencia.
Un nombre de tabla que llegue por la URL se valida contra la lista que devolvio
`information_schema` **antes** de usarse en cualquier consulta.

### 5.6 `/admin/roles-permisos` — Matriz

En Brewer es una guia conceptual con roles de ejemplo y no lee datos de ningun
tenant. Aqui puede hacer las dos cosas, y deberia. Cuatro bloques:

**1. El concepto.** La cadena, con el mismo dibujo de Brewer
(`.flow-diagram` / `.flow-node`):

Usuario -> usuario.rol (estructural) -> usuario.rol_id -> permisos (modulo:accion) -> rutas

Y explica los dos ejes que en Brewer no existen: `usuario.rol` frente a
`rol_id`, y por que administrar roles no es un permiso del catalogo (ver 3.5).
Ese texto ya esta escrito en los comentarios de `042_rol_permiso.sql` y del
bloque de roles del front controller: reusalo, no lo reescribas peor.

**2. El catalogo real.** `CATALOGO_MODULOS` x `CATALOGO_ACCIONES` con, por cada
par, **cuantas rutas lo exigen** — contadas sobre `PERMISOS_RUTA` y
`PERMISOS_RUTA_PATRON`, con la lista desplegable. Esto sale del codigo, no de
la base: es la respuesta a "que habilita de verdad `ventas:emitir`".

**3. Los roles reales, de todas las cuentas.** `rol JOIN cuenta`, con nombre de
cuenta, nombre de rol, cantidad de usuarios y la matriz modulos x rol
(`.rp-matrix`), con las celdas de Brewer: `● Todo` / `◐ Parcial` / `·`.
Agrupada por cuenta y plegable: son muchas filas.

**4. Auditoria de cobertura del gate.** Lista las rutas que el router despacha
y que **no** estan declaradas en `PERMISOS_RUTA` ni en `PERMISOS_RUTA_PATRON`
ni en `RUTAS_PUBLICAS` ni bajo `PREFIJOS_GATE_PROPIO`. Antes de escribirla,
**lee `exigirPermisoDeRuta()` y confirma que hace con una ruta no declarada** —
los comentarios dicen que cae al lado seguro y se bloquea; verificalo, no lo
des por cierto. Si es asi, esta lista no son agujeros de seguridad sino rutas
que dejarian de funcionar sin avisar, y ese es justo el hueco que hoy nadie ve.

### 5.7 Changelog, Pendientes, Flujos, Documentos

Las cuatro son **datos mantenidos a mano, sin base de datos**, igual que en
Brewer. Van en archivos PHP que solo devuelven un array:

panel/datos/changelog.php
panel/datos/pendientes.php
panel/datos/flujos.php
panel/datos/documentos.php

Cada uno: `<?php return [ ... ];` — sin logica, sin consultas, sin salida. Se
cargan con `require`. Asi quedan aislados y migrables a otro formato despues,
sin tocar las vistas.

> **Al dia de hoy esto cambio para UNO de los cuatro, y por la razon que este
> mismo parrafo anticipaba.** `pendientes.php` ya no existe: la migracion 044
> se llevo el backlog a la tabla `pendiente`, porque el estado de un pendiente
> cambia varias veces por semana sin que cambie el codigo, y con el archivo cada
> movimiento era editar PHP, commitear y reconstruir dos imagenes de docker. Las
> ideas se separaron a `panel/datos/ideas.php` y ahi el archivo sigue siendo la
> forma correcta: una idea se decide una sola vez y desaparece. Los otros tres
> siguen tal cual describe esta seccion.

**changelog.php** — lo mas nuevo arriba, ejemplo de un item:

'fecha' => '2026-08-21', 'version' => '1.00', 'titulo' => '...', 'tag' => 'arquitectura|backend|frontend|datos|devops', 'items' => ['...', '...']

Semilla: derivala del historial real con
`git log --date=short --pretty='%ad %s'`. No inventes versiones: agrupa los
commits reales en entradas y numeralas desde `1.00`.

**Adopta la convencion de Brewer:** por cada cambio que hagas en el proyecto,
una entrada nueva en el changelog, subiendo la version. Escribe los items para
que los entienda quien no programa — mira el estilo de las entradas de Brewer:
dicen que cambio para el usuario y por que, no que archivo se toco. Deja esta
regla escrita en `CLAUDE.md` al terminar.

**pendientes.php** — `titulo`, `detalle`, `tipo` (idea|pendiente),
`estado` (nuevo|en_pausa|en_curso). Al concretar o descartar un item se
borra de aqui y se registra en el changelog. Semilla: lo que este mismo trabajo
deje fuera (alta de cuenta desde el admin, diagrama ER si no lo haces, pagina
de integraciones, alertas que no se pudieron calcular).

**flujos.php** — `id`, `titulo`, `resumen`, `necesitas[]`, `diagrama[]`,
`pasos[{titulo, detalle, donde}]`. El primer flujo, y el que justifica la
pagina, es **"De cero a emitir en produccion"**, que es EL flujo de este
producto y hoy no esta escrito en ninguna parte:

Empresa -> Certificado digital -> CAF -> Certificacion SII (6 etapas) -> Declaracion de cumplimiento -> Autorizacion del SII -> Empresa produccion -> Certificado produccion -> CAF produccion -> API key -> Emitir

Reconstruyelo leyendo las rutas de certificacion y `estadoEmisionProduccion()`,
no de memoria. Cada paso indica **donde** se hace, con la ruta del panel.

**documentos.php** — el catalogo de documentos imprimibles. Aqui la mayoria son
DTE, no PDF internos: factura (33), factura exenta (34), boleta (39), nota de
credito (61), nota de debito (56), mas orden de compra PDF, cotizacion PDF y
los informes en PDF/Excel. Campos: `nombre`, `grupo`, `desde` (ruta del panel),
`para` (quien lo recibe), `estado` (listo|pendiente), `prioridad`, `nota`.
Comprueba contra `TIPOS_PERMITIDOS_PDF` en `public/index.php` antes de marcar
algo como listo.

---

## 6. Base de datos

**No hace falta migracion nueva.** `admin_auditoria`, `rol` y `permiso` ya
existen y alcanzan.

Si al construir descubres que si hace falta una columna, la migracion va como
`integration/plantiflex/migrations/043_<nombre>.sql`, siguiendo el estilo de
las 42 anteriores:

- 100% aditiva; no borra ni renombra nada.
- `CREATE TABLE IF NOT EXISTS` si, pero `ADD COLUMN IF NOT EXISTS` **no**: esa
  variante es exclusiva de MariaDB y MySQL de Oracle falla con error de
  sintaxis.
- Cabecera de comentario explicando **por que** existe, igual que las otras.
- Agregar su huella al catalogo de `scripts/estado_migraciones.php`.

**Consultalo conmigo antes de escribirla.** Una migracion es lo unico de este
trabajo que no se puede revertir con un `git revert` en produccion.

---

## 7. Seguridad — reglas innegociables

1. **`exigirSuperadmin($pdo)` como primera linea de CADA handler `/admin/*`.**
   `PREFIJOS_GATE_PROPIO` solo evita que el mapa de permisos vuelva a filtrar;
   **no** autoriza nada. El gate es el handler.
2. `exigirSuperadmin()` responde **403, nunca un redirect a `/login`**. Un
   redirect le diria a un usuario legitimo sin privilegios que su sesion caduco,
   que es falso. Respeta ese criterio en todo lo nuevo.
3. **Este panel cruza el limite de tenant a proposito, y es el unico que
   puede.** Cada consulta que omita `cuenta_id` tiene que estar dentro de un
   handler que ya llamo a `exigirSuperadmin()`. No escribas un helper "generico"
   que consulte sin `cuenta_id` y pueda llamarse desde otro lado.
4. **Solo lectura**, salvo las tres acciones que ya existen (suspender,
   reactivar, revertir etapa). No agregues acciones destructivas. Nada de
   borrar cuentas, usuarios ni documentos.
5. **Toda mutacion es POST**, con `csrfInput()` en el formulario. La
   verificacion CSRF es global en el router, en un solo sitio; no la repitas
   por handler ni la esquives.
6. **Toda accion administrativa se registra** con `registrarAuditoriaAdmin()`,
   con snapshot completo antes y despues. `admin_auditoria` es append-only: una
   fila escrita no se edita nunca; si hay que corregir algo, se agrega otra
   fila que lo explique.
7. **Nada de secretos en pantalla, jamas:** `usuario.password_hash`,
   `api_key.key_hash`, el contenido de `dte_certificado`, `CRYPTO_MASTER_KEY`.
   De las API keys solo el prefijo, que existe precisamente para identificarlas
   sin revelarlas.
8. **Sin SQL arbitrario** desde ninguna pantalla (ver 5.5).
9. `htmlspecialchars()` en todo lo que salga a HTML, incluido lo que venga de
   `information_schema` y de los JSON de auditoria.
10. La cuenta demo ya esta cubierta: el router corta todo POST antes de
    despachar. No agregues chequeos duplicados.

---

## 8. Entorno local

Requisitos: PHP 8.2 con `pdo_mysql`, MySQL 8, Composer.

composer install; crear BD sinergia_fac_bol con charset utf8mb4; aplicar
estructura_facturacion_cl.sql y luego las 42 migraciones de
integration/plantiflex/migrations/ en orden; exportar DB_HOST, DB_PORT,
DB_NAME, DB_USER, DB_PASS, CRYPTO_MASTER_KEY, MOTOR_URL; correr
php scripts/estado_migraciones.php para confirmar; levantar con
php -S localhost:8091 -t panel/public panel/router.php

Datos de prueba: `php scripts/sembrar_demo.php`. Superadmin local se marca a
mano con UPDATE usuario SET rol = 'superadmin' WHERE email = 'tu@correo'.
Crea al menos **tres** cuentas de prueba con estados y etapas distintas.

Tests: `vendor/bin/phpunit`.

---

## 9. Plan de entrega

Una fase por commit. Al terminar cada una, deja el panel **funcionando**.

Fase 1: Layout admin.css, partials/admin/*, ruta /admin, dashboard con las
tarjetas. Migrar /admin/tenants y /admin/auditoria al layout nuevo sin tocar
su logica.
Fase 2: Cuentas: buscador, filtros, y la ficha /admin/tenants/{id}.
Fase 3: Auditoria: filtros, paginacion, diff legible de los JSON.
Fase 4: Base de datos: tablas, columnas, FK, columna de aislamiento, panel de
migraciones.
Fase 5: Roles y permisos: los cuatro bloques.
Fase 6: panel/datos/*.php + las cuatro paginas de documentacion.
Fase 7 (opcional): Diagrama ER con Mermaid vendorizado.

---

## 10. Verificacion — antes de decir que algo esta listo

1. php -l panel/public/index.php y php -l de cada vista nueva.
2. Levanta el panel y abre todas las rutas /admin/*, incluidas las que no
   tocaste.
3. Abre tambien el panel del tenant (/panel, /ventas/*,
   /configuracion/usuarios).
4. Entra con un usuario no superadmin y confirma 403.
5. Entra con la cuenta demo y confirma que ningun POST del admin pasa.
6. vendor/bin/phpunit.
7. graphify update .
8. Entrada nueva en panel/datos/changelog.php.

---

## 11. Prohibiciones

- No toques public/index.php (el motor DTE, front controller aparte) ni
  src/ ni integration/. Este trabajo es del panel.
- No edites panel/public/css/style.css.
- No cambies la logica de handleAdminTenantsGet(), cambiarEstadoCuentaAdmin()
  ni del revertir-etapa. Solo su presentacion.
- No muevas PERMISOS_RUTA, PERMISOS_RUTA_PATRON, CATALOGO_MODULOS ni
  ninguna constante que use el router por debajo del router.
- No agregues dependencias de Composer ni de npm. No hay build.
- No cargues nada desde un CDN.
- No corras migraciones ni scripts de escritura contra ninguna base que no sea
  tu MySQL local.
- No hagas git push ni despliegues. El paso a produccion lo decide el usuario.
- Si algo de este prompt contradice lo que encuentres en el codigo, el codigo
  manda: dilo y pregunta antes de seguir.
