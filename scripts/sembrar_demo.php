<?php

declare(strict_types=1);

/**
 * sembrar_demo.php -- CUENTA DE DEMOSTRACION DEL PANEL.
 *
 * Deja lista una cuenta para mostrarle el sistema a un prospecto: menu lateral
 * completo, dashboard con cifras, informes con contenido y las pantallas de
 * configuracion de produccion pobladas. La cuenta es de SOLO LECTURA: su usuario
 * nace con usuario.demo = 1, y el router del panel corta todo POST antes de
 * despachar (ver sesionEsDemo()/cortarPorDemo() en panel/public/index.php).
 *
 * USO
 *   php scripts/sembrar_demo.php            # siembra o resiembra
 *   php scripts/sembrar_demo.php --borrar   # elimina la cuenta demo y se va
 *
 * Dentro del contenedor del panel:
 *   docker exec sinergia_panel php /app/scripts/sembrar_demo.php
 *
 * VARIABLES DE ENTORNO
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS  (las mismas del resto del
 *   proyecto; dentro del contenedor ya vienen del env_file).
 *   DEMO_PASSWORD  opcional: contrasena a fijar. Si no viene, se genera una
 *                  legible y se imprime al final.
 *
 *
 * POR QUE ESTE TENANT VA POR EL "CAMINO DE PRODUCCION"
 * -----------------------------------------------------------------------------
 * Se siembra la fila dte_emisor de PRODUCCION y NO la de certificacion. Eso no
 * es un atajo: es lo que hace que el panel se vea como el de un cliente que ya
 * opera. El dashboard decide que pintar mirando que filas existen (ver
 * handlePanelGet: $caminoProduccion = ! $tieneEmisor && $rutProduccion !== null),
 * y con solo la de produccion muestra el tablero de gestion con KPI en vez del
 * stepper de onboarding. Ademas estadoEmisionProduccion() -- el mismo predicado
 * que usan el guard de las rutas operativas y el menu lateral -- queda en
 * 'falta' => null, que es literalmente la condicion de "todo habilitado" que se
 * pidio. No se toco ni una linea de esa logica: se le dan los datos que espera.
 *
 * Las cuatro pantallas del subgrupo "Certificacion" quedan alcanzables y vacias,
 * como en cualquier tenant que llego ya autorizado por el SII. Es el estado
 * correcto, no un hueco de la demo.
 *
 *
 * EL CERTIFICADO Y LOS CAF SON RELLENO, Y ESO ES A PROPOSITO
 * -----------------------------------------------------------------------------
 * dte_certificado y dte_caf existen aqui SOLO para que las tres condiciones de
 * estadoEmisionProduccion() se cumplan y las pantallas listen algo. Sus blobs no
 * son un certificado ni un CAF de verdad. Se puede, porque ninguna ruta GET del
 * panel los descifra: /certificado-produccion comprueba que la FILA exista y
 * renderiza el formulario, y /caf-produccion lista metadatos (tipo, rango,
 * folios restantes) con un JOIN a dte_folio. Lo unico que descifra material
 * criptografico es el camino de EMISION, que es POST y esta bloqueado.
 *
 * Sembrar un CAF real seria peor que inutil: son folios autorizados por el SII a
 * un contribuyente concreto.
 *
 *
 * LOS DOCUMENTOS LLEVAN XML SINTETICO PERO BIEN FORMADO
 * -----------------------------------------------------------------------------
 * dte_emitido.xml no puede ir vacio: el boton "PDF" del detalle de un documento
 * proxea al motor, y pdfDte() arma el PDF leyendo ESE xml con el renderizador de
 * LibreDTE. Un blob basura daria un 500 en medio de la presentacion. Asi que se
 * genera un EnvioDTE con la estructura real (Caratula + Documento + TED), con
 * datos de la empresa ficticia, y sin firma: el renderizador no valida firmas,
 * solo lee Encabezado/Detalle/TED.
 *
 * El TED lleva un CAF de relleno por la misma razon que la tabla: el timbre se
 * dibuja como codigo de barras PDF417 a partir de ese texto, y nadie lo valida
 * al imprimir. No se copio el TED de ningun documento real -- eso arrastraria la
 * firma y el RUT de un contribuyente de verdad dentro de datos de demo.
 *
 *
 * IDEMPOTENCIA
 * -----------------------------------------------------------------------------
 * Re-ejecutarlo borra y vuelve a sembrar SOLO lo que cuelga de la cuenta demo,
 * identificada por su email (DEMO_EMAIL) y su RUT (DEMO_RUT). No toca ninguna
 * otra cuenta, y aborta si el RUT demo apareciera asociado a otra cuenta_id, que
 * seria senal de que alguien lo uso para algo real.
 */

// SIN require del autoloader de Composer, a diferencia del resto de scripts/:
// este no instancia ni una clase del motor. Solo usa PDO y funciones nativas, y
// el XML lo arma con concatenacion de strings a proposito (ver
// construirEnvioDte). Manteniendolo asi, sembrar la demo no depende de que
// vendor/ este instalado ni de que el motor cargue.

// -----------------------------------------------------------------------------
//  Identidad de la cuenta demo. Cambiarlas aqui cambia todo el script.
//
//  El RUT es ficticio y con digito verificador valido (se verifica al arrancar):
//  tiene que pasar Rut::valido() para que las pantallas que lo formatean no se
//  quejen, y los digitos descendentes lo hacen obviamente inventado a simple
//  vista. Nadie va a confundirlo con el RUT de un cliente real en una captura de
//  pantalla.
// -----------------------------------------------------------------------------
const DEMO_EMAIL   = 'demo@sinergiaia.cl';
const DEMO_RUT     = '76543210-3';
const DEMO_RAZON   = 'Comercial Andes Demo SpA';
const DEMO_GIRO    = 'Venta al por mayor de materiales de construccion';
const DEMO_ACTECO  = 465100;
const DEMO_DIR     = 'Av. Alemania 1450, Oficina 302';
const DEMO_COMUNA  = 'Temuco';
const DEMO_RES_FEC = '2014-08-22';
const DEMO_RES_NUM = 80;

/** Hoy, congelado al arrancar: todas las fechas sembradas se derivan de aqui. */
const DEMO_TZ = 'America/Santiago';

// -----------------------------------------------------------------------------
//  Utilidades
// -----------------------------------------------------------------------------

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit($code);
}

function paso(string $msg): void
{
    echo "  {$msg}\n";
}

function requerirEnv(string $nombre): string
{
    $v = getenv($nombre);
    if ($v === false || $v === '') {
        fail("Falta la variable de entorno {$nombre}.");
    }

    return $v;
}

function conectarDb(): PDO
{
    $host = requerirEnv('DB_HOST');
    $port = getenv('DB_PORT') ?: '3306';
    $name = requerirEnv('DB_NAME');
    $user = requerirEnv('DB_USER');
    $pass = requerirEnv('DB_PASS');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        fail('No se pudo conectar a la base: ' . $e->getMessage(), 2);
    }
}

/**
 * Digito verificador de un RUT chileno (modulo 11), para VERIFICAR los RUT
 * ficticios de este archivo al arrancar. Un DV mal calculado en los datos
 * sembrados no se veria hasta que alguien mirara una pantalla en la
 * presentacion, y ahi ya es tarde.
 */
function digitoVerificador(int $cuerpo): string
{
    $suma  = 0;
    $mult  = 2;
    while ($cuerpo > 0) {
        $suma  += ($cuerpo % 10) * $mult;
        $cuerpo = intdiv($cuerpo, 10);
        $mult   = $mult === 7 ? 2 : $mult + 1;
    }
    $resto = 11 - ($suma % 11);

    return match ($resto) {
        11      => '0',
        10      => 'K',
        default => (string) $resto,
    };
}

function rutValido(string $rut): bool
{
    if (! preg_match('/^(\d+)-([\dK])$/', strtoupper($rut), $m)) {
        return false;
    }

    return digitoVerificador((int) $m[1]) === $m[2];
}

/**
 * Contrasena legible por telefono: sin caracteres ambiguos (0/O, 1/l/I) y con
 * una forma que se dicta sin deletrear. Va a viajar por WhatsApp o correo hasta
 * un prospecto, no a un gestor de contrasenas.
 */
function contrasenaLegible(): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digitos  = '23456789';
    $palabra  = '';
    for ($i = 0; $i < 4; $i++) {
        $palabra .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    $numero = '';
    for ($i = 0; $i < 4; $i++) {
        $numero .= $digitos[random_int(0, strlen($digitos) - 1)];
    }

    return 'Demo-' . $palabra . '-' . $numero;
}

/** Base64 de relleno con la longitud tipica del campo, para que "se vea" bien. */
function rellenoBase64(int $bytes): string
{
    return base64_encode(random_bytes($bytes));
}

// -----------------------------------------------------------------------------
//  Catalogos ficticios
//
//  Nombres INVENTADOS a proposito. Poner empresas reales en datos de demo las
//  expone en cada captura de pantalla y en cada presentacion, sin que se hayan
//  enterado. Los RUT se verifican con digitoVerificador() al arrancar.
// -----------------------------------------------------------------------------

/** @return list<array{rut:string, razon:string, giro:string, dir:string, comuna:string, email:string, tel:string}> */
function catalogoClientes(): array
{
    return [
        ['rut' => '77123456-9', 'razon' => 'Constructora Cordillera Austral SpA',  'giro' => 'Construccion de edificios residenciales', 'dir' => 'Av. Balmaceda 880',        'comuna' => 'Temuco',       'email' => 'pagos@cordilleraaustral.demo',  'tel' => '+56 45 221 4400'],
        ['rut' => '76890123-6', 'razon' => 'Ferreteria El Roble Limitada',          'giro' => 'Venta al por menor de ferreteria',        'dir' => 'Manuel Montt 1215',      'comuna' => 'Padre Las Casas', 'email' => 'compras@elroble.demo',        'tel' => '+56 45 231 7788'],
        ['rut' => '78345678-8', 'razon' => 'Inmobiliaria Vista Lanin SpA',          'giro' => 'Actividades inmobiliarias',              'dir' => 'Av. Pablo Neruda 2040',  'comuna' => 'Temuco',       'email' => 'admin@vistalanin.demo',       'tel' => '+56 45 240 1120'],
        ['rut' => '77456789-5', 'razon' => 'Transportes Rio Cautin Limitada',       'giro' => 'Transporte de carga por carretera',       'dir' => 'Camino Cholchol km 4',   'comuna' => 'Temuco',       'email' => 'facturacion@riocautin.demo',  'tel' => '+56 45 226 3390'],
        ['rut' => '76234567-6', 'razon' => 'Agricola Los Notros SpA',               'giro' => 'Cultivo de cereales y oleaginosas',       'dir' => 'Parcela 12, Ruta S-31',  'comuna' => 'Lautaro',      'email' => 'contabilidad@losnotros.demo', 'tel' => '+56 45 253 6612'],
        ['rut' => '77567890-9', 'razon' => 'Servicios Electricos Araucania SpA',    'giro' => 'Instalaciones electricas',               'dir' => 'Prieto Norte 455',       'comuna' => 'Temuco',       'email' => 'gerencia@electricaraucania.demo', 'tel' => '+56 45 229 8845'],
        ['rut' => '78678901-K', 'razon' => 'Distribuidora Sur Andina Limitada',     'giro' => 'Venta al por mayor no especializada',     'dir' => 'Av. Rudecindo Ortega 3120', 'comuna' => 'Temuco',    'email' => 'ordenes@surandina.demo',      'tel' => '+56 45 234 5511'],
        ['rut' => '76789012-5', 'razon' => 'Maderas Pehuenco SpA',                  'giro' => 'Aserrado y acepilladura de madera',       'dir' => 'Ruta 5 Sur km 678',      'comuna' => 'Freire',       'email' => 'ventas@pehuenco.demo',        'tel' => '+56 45 271 9020'],
        ['rut' => '77901234-4', 'razon' => 'Hotel Puerta del Lago Limitada',        'giro' => 'Actividades de alojamiento',             'dir' => 'Costanera 145',          'comuna' => 'Villarrica',   'email' => 'admin@puertadellago.demo',    'tel' => '+56 45 241 2233'],
        ['rut' => '76012345-5', 'razon' => 'Ingenieria y Montajes Nahuel SpA',      'giro' => 'Obras de ingenieria civil',              'dir' => 'Los Aromos 78',          'comuna' => 'Angol',        'email' => 'proyectos@nahuel.demo',       'tel' => '+56 45 271 4408'],
        ['rut' => '78123456-7', 'razon' => 'Comercial Trapananda Limitada',         'giro' => 'Venta al por mayor de alimentos',         'dir' => 'Av. Caupolican 690',     'comuna' => 'Temuco',       'email' => 'pagos@trapananda.demo',       'tel' => '+56 45 238 1177'],
        ['rut' => '77234567-4', 'razon' => 'Talleres Metalurgicos Bio Sur SpA',     'giro' => 'Fabricacion de productos metalicos',      'dir' => 'Lote 4, Parque Industrial', 'comuna' => 'Lautaro',   'email' => 'compras@biosur.demo',         'tel' => '+56 45 252 3344'],
    ];
}

/** @return list<array{codigo:string, nombre:string, desc:string, precio:int, unidad:string, exento:int}> */
function catalogoProductos(): array
{
    return [
        ['codigo' => 'CEM-25',  'nombre' => 'Cemento especial saco 25 kg',            'desc' => 'Saco de 25 kg, uso estructural',          'precio' => 6490,   'unidad' => 'UN', 'exento' => 0],
        ['codigo' => 'FIE-8',   'nombre' => 'Fierro estriado 8 mm x 6 m',             'desc' => 'Barra de acero A630-420H',                'precio' => 4290,   'unidad' => 'UN', 'exento' => 0],
        ['codigo' => 'PLA-15',  'nombre' => 'Plancha OSB 15 mm 1,22 x 2,44 m',        'desc' => 'Tablero estructural para tabiques',        'precio' => 18990,  'unidad' => 'UN', 'exento' => 0],
        ['codigo' => 'ARE-M3',  'nombre' => 'Arena gruesa lavada',                    'desc' => 'Despacho a obra dentro del radio urbano', 'precio' => 21500,  'unidad' => 'M3', 'exento' => 0],
        ['codigo' => 'GRA-M3',  'nombre' => 'Gravilla 3/4"',                          'desc' => 'Arido para hormigon',                     'precio' => 24800,  'unidad' => 'M3', 'exento' => 0],
        ['codigo' => 'PIN-GL',  'nombre' => 'Pintura latex interior galon',           'desc' => 'Blanco mate, rendimiento 40 m2',          'precio' => 15990,  'unidad' => 'UN', 'exento' => 0],
        ['codigo' => 'AIS-R12', 'nombre' => 'Aislante lana mineral R12 rollo',        'desc' => 'Rollo 1,2 x 12 m',                        'precio' => 42900,  'unidad' => 'UN', 'exento' => 0],
        ['codigo' => 'SRV-ASE', 'nombre' => 'Asesoria tecnica en obra (hora hombre)', 'desc' => 'Visita a terreno con informe',            'precio' => 38000,  'unidad' => 'HH', 'exento' => 0],
        ['codigo' => 'SRV-FLE', 'nombre' => 'Flete a obra dentro de la region',       'desc' => 'Camion 8 toneladas, viaje ida y vuelta',  'precio' => 65000,  'unidad' => 'UN', 'exento' => 0],
        ['codigo' => 'CAP-EXE', 'nombre' => 'Capacitacion en prevencion de riesgos',  'desc' => 'Curso franquiciado SENCE (exento de IVA)', 'precio' => 180000, 'unidad' => 'UN', 'exento' => 1],
    ];
}

// -----------------------------------------------------------------------------
//  Generacion del EnvioDTE sintetico
// -----------------------------------------------------------------------------

/**
 * EnvioDTE de UN documento, con la misma estructura que persiste el motor
 * (Caratula + SetDTE > DTE > Documento, con TED al final del Documento) pero
 * SIN <Signature>: el renderizador de PDF no valida firmas, y una firma falsa
 * seria mentir en un campo que existe justamente para probar autenticidad.
 *
 * Se devuelve ya convertido a ISO-8859-1, que es como el motor lo guarda y como
 * lo declara al servirlo (ver xmlDte()).
 *
 * @param list<array{nombre:string, cantidad:int, precio:int, monto:int}> $lineas
 * @param array{tipo:int, folio:int, fecha:string}|null                   $ref
 */
function construirEnvioDte(
    int $tipoDte,
    int $folio,
    string $fecha,
    array $receptor,
    array $lineas,
    int $neto,
    int $exento,
    int $iva,
    int $total,
    int $formaPago,
    ?string $vencimiento,
    ?array $ref,
): string {
    $esc  = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $sello = $fecha . 'T09:' . str_pad((string) ($folio % 60), 2, '0', STR_PAD_LEFT) . ':00';

    // --- IdDoc: FchVenc solo acompana a FmaPago=2 (credito), igual que el motor.
    $idDoc = "<TipoDTE>{$tipoDte}</TipoDTE><Folio>{$folio}</Folio><FchEmis>{$fecha}</FchEmis>"
        . "<FmaPago>{$formaPago}</FmaPago>";
    if ($formaPago === 2 && $vencimiento !== null) {
        $idDoc .= "<FchVenc>{$vencimiento}</FchVenc>";
    }

    // --- Totales: MntNeto/IVA solo si hay parte afecta; MntExe solo si hay exenta.
    $totales = '';
    if ($neto > 0) {
        $totales .= "<MntNeto>{$neto}</MntNeto>";
    }
    if ($exento > 0) {
        $totales .= "<MntExe>{$exento}</MntExe>";
    }
    if ($iva > 0) {
        $totales .= "<TasaIVA>19</TasaIVA><IVA>{$iva}</IVA>";
    }
    $totales .= "<MntTotal>{$total}</MntTotal>";

    $detalle = '';
    foreach ($lineas as $i => $l) {
        $detalle .= '<Detalle>'
            . '<NroLinDet>' . ($i + 1) . '</NroLinDet>'
            . '<NmbItem>' . $esc($l['nombre']) . '</NmbItem>'
            . '<QtyItem>' . $l['cantidad'] . '</QtyItem>'
            . '<PrcItem>' . $l['precio'] . '</PrcItem>'
            . '<MontoItem>' . $l['monto'] . '</MontoItem>'
            . '</Detalle>';
    }

    // --- Referencia: la llevan las notas de credito y debito (codigo 1 = anula,
    //     3 = corrige montos). Sin ella el PDF de una nota no dice a que documento
    //     apunta, que es lo primero que mira quien la recibe.
    $referencia = '';
    if ($ref !== null) {
        $referencia = '<Referencia>'
            . '<NroLinRef>1</NroLinRef>'
            . '<TpoDocRef>' . $ref['tipo'] . '</TpoDocRef>'
            . '<FolioRef>' . $ref['folio'] . '</FolioRef>'
            . '<FchRef>' . $ref['fecha'] . '</FchRef>'
            . '<CodRef>' . ($tipoDte === 61 ? 3 : 1) . '</CodRef>'
            . '<RazonRef>' . ($tipoDte === 61 ? 'Corrige montos' : 'Cobro de intereses') . '</RazonRef>'
            . '</Referencia>';
    }

    // --- TED de relleno. El PDF417 se dibuja a partir de este texto y nadie lo
    //     valida al imprimir; los campos criptograficos van con base64 aleatorio
    //     del largo tipico para que el timbre tenga densidad realista.
    $primerItem = mb_substr($lineas[0]['nombre'], 0, 40);
    $ted = '<TED version="1.0"><DD>'
        . '<RE>' . DEMO_RUT . '</RE>'
        . "<TD>{$tipoDte}</TD><F>{$folio}</F><FE>{$fecha}</FE>"
        . '<RR>' . $esc($receptor['rut']) . '</RR>'
        . '<RSR>' . $esc(mb_substr($receptor['razon'], 0, 40)) . '</RSR>'
        . "<MNT>{$total}</MNT>"
        . '<IT1>' . $esc($primerItem) . '</IT1>'
        . '<CAF version="1.0"><DA>'
        . '<RE>' . DEMO_RUT . '</RE>'
        . '<RS>' . $esc(mb_strtoupper(DEMO_RAZON)) . '</RS>'
        . "<TD>{$tipoDte}</TD>"
        . '<RNG><D>1</D><H>5000</H></RNG>'
        . '<FA>' . DEMO_RES_FEC . '</FA>'
        . '<RSAPK><M>' . rellenoBase64(64) . '</M><E>Aw==</E></RSAPK>'
        . '<IDK>100</IDK>'
        . '</DA><FRMA algoritmo="SHA1withRSA">' . rellenoBase64(64) . '</FRMA></CAF>'
        . "<TSTED>{$sello}</TSTED>"
        . '</DD><FRMT algoritmo="SHA1withRSA">' . rellenoBase64(64) . '</FRMT></TED>';

    $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>'
        . '<EnvioDTE xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
        . ' version="1.0" xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioDTE_v10.xsd">'
        . '<SetDTE ID="SetDoc"><Caratula version="1.0">'
        . '<RutEmisor>' . DEMO_RUT . '</RutEmisor>'
        . '<RutEnvia>' . DEMO_RUT . '</RutEnvia>'
        . '<RutReceptor>60803000-K</RutReceptor>'
        . '<FchResol>' . DEMO_RES_FEC . '</FchResol>'
        . '<NroResol>' . DEMO_RES_NUM . '</NroResol>'
        . "<TmstFirmaEnv>{$sello}</TmstFirmaEnv>"
        . "<SubTotDTE><TpoDTE>{$tipoDte}</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>"
        . '</Caratula>'
        . '<DTE version="1.0">'
        . "<Documento ID=\"F{$folio}T{$tipoDte}\">"
        . '<Encabezado>'
        . "<IdDoc>{$idDoc}</IdDoc>"
        . '<Emisor>'
        . '<RUTEmisor>' . DEMO_RUT . '</RUTEmisor>'
        . '<RznSoc>' . $esc(DEMO_RAZON) . '</RznSoc>'
        . '<GiroEmis>' . $esc(DEMO_GIRO) . '</GiroEmis>'
        . '<Acteco>' . DEMO_ACTECO . '</Acteco>'
        . '<DirOrigen>' . $esc(DEMO_DIR) . '</DirOrigen>'
        . '<CmnaOrigen>' . $esc(DEMO_COMUNA) . '</CmnaOrigen>'
        . '</Emisor>'
        . '<Receptor>'
        . '<RUTRecep>' . $esc($receptor['rut']) . '</RUTRecep>'
        . '<RznSocRecep>' . $esc($receptor['razon']) . '</RznSocRecep>'
        . '<GiroRecep>' . $esc(mb_substr($receptor['giro'], 0, 40)) . '</GiroRecep>'
        . '<DirRecep>' . $esc($receptor['dir']) . '</DirRecep>'
        . '<CmnaRecep>' . $esc($receptor['comuna']) . '</CmnaRecep>'
        . '</Receptor>'
        . "<Totales>{$totales}</Totales>"
        . '</Encabezado>'
        . $detalle
        . $referencia
        . $ted
        . "<TmstFirma>{$sello}</TmstFirma>"
        . '</Documento></DTE></SetDTE></EnvioDTE>';

    // El motor persiste en ISO-8859-1 (migracion 008) y lo declara al servirlo.
    // Sembrar UTF-8 haria que los acentos salieran rotos justo en el PDF.
    return (string) mb_convert_encoding($xml, 'ISO-8859-1', 'UTF-8');
}

// -----------------------------------------------------------------------------
//  Borrado de la cuenta demo (idempotencia)
// -----------------------------------------------------------------------------

/**
 * Borra TODO lo que cuelga de la cuenta demo, en orden de dependencia.
 *
 * Filtra por cuenta_id (o por el RUT demo en las tablas del motor, que no tienen
 * cuenta_id) y NUNCA por otra cosa: este script no puede tocar datos de un
 * tenant real ni por error de tipeo en una fecha.
 */
function borrarDemo(PDO $pdo, ?int $cuentaId): void
{
    if ($cuentaId !== null) {
        // dte_envio_correo cuelga de dte_emitido con ON DELETE CASCADE, pero se
        // borra explicito: las filas se ubican por cuenta_id y no dependen de
        // que el borrado en cascada exista.
        $pdo->prepare('DELETE FROM dte_envio_correo WHERE cuenta_id = :c')->execute([':c' => $cuentaId]);
        $pdo->prepare('DELETE FROM nota_venta      WHERE cuenta_id = :c')->execute([':c' => $cuentaId]);
        $pdo->prepare('DELETE FROM lote_carga      WHERE cuenta_id = :c')->execute([':c' => $cuentaId]);
        $pdo->prepare('DELETE FROM cliente         WHERE cuenta_id = :c')->execute([':c' => $cuentaId]);
        $pdo->prepare('DELETE FROM producto        WHERE cuenta_id = :c')->execute([':c' => $cuentaId]);
        $pdo->prepare('DELETE FROM api_key         WHERE cuenta_id = :c')->execute([':c' => $cuentaId]);

        // admin_auditoria NO tiene cuenta_id: se cuelga del usuario que ejecuto
        // la accion, con FK a usuario. Hay que vaciarla por usuario_id -- y antes
        // de borrar el usuario -- o el DELETE de mas abajo choca contra la clave
        // foranea. Una sesion demo no genera auditoria (todo POST esta cortado),
        // pero si alguien encendio la bandera sobre una cuenta que ya opero, sus
        // filas existen y hay que limpiarlas igual.
        $pdo->prepare(
            'DELETE FROM admin_auditoria WHERE usuario_id IN (SELECT id FROM usuario WHERE cuenta_id = :c)'
        )->execute([':c' => $cuentaId]);
    }

    // Tablas del motor: se identifican por rut_emisor, no por cuenta.
    // dte_folio cae por CASCADE al borrar dte_caf, pero se borra antes de forma
    // explicita para no depender de eso.
    $pdo->prepare('DELETE f FROM dte_folio f INNER JOIN dte_caf c ON c.id = f.caf_id WHERE c.rut_emisor = :r')
        ->execute([':r' => DEMO_RUT]);
    $pdo->prepare('DELETE FROM dte_caf         WHERE rut_emisor = :r')->execute([':r' => DEMO_RUT]);
    $pdo->prepare('DELETE FROM dte_emitido     WHERE rut_emisor = :r')->execute([':r' => DEMO_RUT]);
    $pdo->prepare('DELETE FROM dte_certificado WHERE rut_emisor = :r')->execute([':r' => DEMO_RUT]);
    $pdo->prepare('DELETE FROM dte_emisor      WHERE rut_emisor = :r')->execute([':r' => DEMO_RUT]);

    if ($cuentaId !== null) {
        $pdo->prepare('DELETE FROM usuario WHERE cuenta_id = :c')->execute([':c' => $cuentaId]);
        $pdo->prepare('DELETE FROM cuenta  WHERE id = :c')->execute([':c' => $cuentaId]);
    }
}

// =============================================================================
//  Programa
// =============================================================================

date_default_timezone_set(DEMO_TZ);
mb_internal_encoding('UTF-8');

$soloBorrar = in_array('--borrar', $argv, true);

// Verificacion de los RUT ficticios ANTES de tocar la base: un DV mal calculado
// en este archivo se veria recien en la presentacion.
if (! rutValido(DEMO_RUT)) {
    fail('DEMO_RUT (' . DEMO_RUT . ') tiene digito verificador invalido.');
}
foreach (catalogoClientes() as $c) {
    if (! rutValido($c['rut'])) {
        fail("El RUT ficticio {$c['rut']} ({$c['razon']}) tiene digito verificador invalido.");
    }
}

$pdo = conectarDb();

// La columna la crea la migracion 029. Sin ella la cuenta quedaria sembrada pero
// ESCRIBIBLE, que es exactamente lo que no puede pasar.
$col = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND COLUMN_NAME = 'demo'"
)->fetchColumn();
if ((int) $col !== 1) {
    fail('Falta usuario.demo. Aplica primero integration/plantiflex/migrations/029_usuario_demo.sql.');
}

// Guarda contra pisar algo real: si el RUT demo ya esta asociado a una cuenta
// que no es la demo, alguien lo uso de verdad y este script no sigue.
$stmt = $pdo->prepare('SELECT id FROM cuenta WHERE email = :e LIMIT 1');
$stmt->execute([':e' => DEMO_EMAIL]);
$cuentaExistente = $stmt->fetchColumn();
$cuentaExistente = $cuentaExistente === false ? null : (int) $cuentaExistente;

$stmt = $pdo->prepare('SELECT DISTINCT cuenta_id FROM dte_emisor WHERE rut_emisor = :r AND cuenta_id IS NOT NULL');
$stmt->execute([':r' => DEMO_RUT]);
foreach ($stmt->fetchAll() as $fila) {
    if ((int) $fila['cuenta_id'] !== $cuentaExistente) {
        fail(
            'El RUT ' . DEMO_RUT . " esta asociado a la cuenta {$fila['cuenta_id']}, que no es la cuenta demo. "
            . 'Cambia DEMO_RUT en este script antes de continuar.'
        );
    }
}

if ($soloBorrar) {
    echo "Borrando la cuenta demo...\n";
    $pdo->beginTransaction();
    borrarDemo($pdo, $cuentaExistente);
    $pdo->commit();
    echo "Listo: la cuenta demo ya no existe.\n";
    exit(0);
}

$password = getenv('DEMO_PASSWORD') ?: contrasenaLegible();

echo "Sembrando la cuenta de demostracion...\n";

$pdo->beginTransaction();

try {
    borrarDemo($pdo, $cuentaExistente);
    paso('Datos previos de la demo eliminados.');

    // --- cuenta + usuario ----------------------------------------------------
    $pdo->prepare("INSERT INTO cuenta (email, nombre, estado) VALUES (:e, :n, 'activa')")
        ->execute([':e' => DEMO_EMAIL, ':n' => DEMO_RAZON]);
    $cuentaId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO usuario (cuenta_id, email, password_hash, rol, demo, estado) '
        . "VALUES (:c, :e, :h, 'owner', 1, 'activo')"
    )->execute([
        ':c' => $cuentaId,
        ':e' => DEMO_EMAIL,
        ':h' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $usuarioId = (int) $pdo->lastInsertId();
    paso("Cuenta {$cuentaId} y usuario {$usuarioId} creados (demo = 1).");

    // --- emisor de PRODUCCION (y solo de produccion) -------------------------
    $pdo->prepare(
        'INSERT INTO dte_emisor '
        . '(rut_emisor, cuenta_id, ambiente, razon_social, giro, acteco, dir_origen, cmna_origen, '
        . ' resolucion_fecha, resolucion_numero) '
        . "VALUES (:r, :c, 'produccion', :rs, :g, :a, :d, :cm, :rf, :rn)"
    )->execute([
        ':r'  => DEMO_RUT,
        ':c'  => $cuentaId,
        ':rs' => DEMO_RAZON,
        ':g'  => DEMO_GIRO,
        ':a'  => DEMO_ACTECO,
        ':d'  => DEMO_DIR,
        ':cm' => DEMO_COMUNA,
        ':rf' => DEMO_RES_FEC,
        ':rn' => DEMO_RES_NUM,
    ]);
    paso('Empresa de produccion cargada.');

    // --- certificado de relleno ---------------------------------------------
    $pdo->prepare(
        'INSERT INTO dte_certificado (rut_emisor, ambiente, cert_data_cifrado, pkey_data_cifrado, dek_envuelta, rut_sender) '
        . "VALUES (:r, 'produccion', :c, :p, :d, :s)"
    )->execute([
        ':r' => DEMO_RUT,
        ':c' => rellenoBase64(1024),
        ':p' => rellenoBase64(1024),
        ':d' => rellenoBase64(48),
        ':s' => DEMO_RUT,
    ]);
    paso('Certificado digital de produccion registrado (relleno, no descifrable).');

    // --- CAF + contadores de folio ------------------------------------------
    //
    // proximo_folio arranca en folio_desde y se AJUSTA AL FINAL, cuando ya se
    // sabe cual fue el ultimo folio realmente emitido de cada tipo (ver el
    // UPDATE al cierre de la siembra). Fijarlo a ojo aqui produce una demo que
    // se contradice sola: la pantalla de folios calcula los restantes como
    // folio_hasta - proximo_folio + 1, asi que un contador inventado en 341 con
    // 99 facturas emitidas afirma que se quemaron 240 folios que no aparecen en
    // ninguna parte del panel de emision ni de los informes. Es exactamente el
    // tipo de descuadre que un contador detecta en la primera pregunta.
    $rangosCaf = [
        // tipo, desde, hasta
        [33, 1, 5000],
        [34, 1, 1000],
        [39, 1, 20000],
        [56, 1, 500],
        [61, 1, 1000],
    ];
    $insCaf = $pdo->prepare(
        'INSERT INTO dte_caf (rut_emisor, tipo_dte, ambiente, folio_desde, folio_hasta, caf_xml_cifrado, dek_envuelta, estado) '
        . "VALUES (:r, :t, 'produccion', :d, :h, :x, :k, 'activo')"
    );
    $insFolio = $pdo->prepare(
        'INSERT INTO dte_folio (caf_id, rut_emisor, tipo_dte, ambiente, proximo_folio, proximo_folio_inicial, folio_hasta) '
        . "VALUES (:id, :r, :t, 'produccion', :p, :pi, :h)"
    );
    foreach ($rangosCaf as [$tipo, $desde, $hasta]) {
        $insCaf->execute([
            ':r' => DEMO_RUT, ':t' => $tipo, ':d' => $desde, ':h' => $hasta,
            ':x' => rellenoBase64(768), ':k' => rellenoBase64(48),
        ]);
        $insFolio->execute([
            ':id' => (int) $pdo->lastInsertId(), ':r' => DEMO_RUT, ':t' => $tipo,
            ':p'  => $desde, ':pi' => $desde, ':h' => $hasta,
        ]);
    }
    paso('CAF de produccion cargados para los tipos 33, 34, 39, 56 y 61.');

    // --- maestros ------------------------------------------------------------
    $insCliente = $pdo->prepare(
        'INSERT INTO cliente (cuenta_id, rut_cliente, razon_social, giro, direccion, comuna, email, telefono, activo) '
        . 'VALUES (:c, :r, :rs, :g, :d, :cm, :e, :t, :a)'
    );
    $clientes = catalogoClientes();
    foreach ($clientes as $i => $cli) {
        $insCliente->execute([
            ':c'  => $cuentaId, ':r' => $cli['rut'], ':rs' => $cli['razon'], ':g' => $cli['giro'],
            ':d'  => $cli['dir'], ':cm' => $cli['comuna'], ':e' => $cli['email'], ':t' => $cli['tel'],
            // Uno inactivo a proposito: la pantalla de clientes tiene filtro por
            // estado y con todo activo no se ve que exista la baja logica.
            ':a'  => $i === count($clientes) - 1 ? 0 : 1,
        ]);
    }

    $insProducto = $pdo->prepare(
        'INSERT INTO producto (cuenta_id, codigo, nombre, descripcion, precio_unitario, unidad, exento, activo) '
        . 'VALUES (:c, :co, :n, :d, :p, :u, :e, 1)'
    );
    foreach (catalogoProductos() as $pr) {
        $insProducto->execute([
            ':c' => $cuentaId, ':co' => $pr['codigo'], ':n' => $pr['nombre'], ':d' => $pr['desc'],
            ':p' => $pr['precio'], ':u' => $pr['unidad'], ':e' => $pr['exento'],
        ]);
    }
    paso(count($clientes) . ' clientes y ' . count(catalogoProductos()) . ' productos cargados.');

    // --- documentos emitidos -------------------------------------------------
    //
    // Seis meses de historia para que el dashboard tenga con que comparar: sus
    // KPI muestran la variacion contra el periodo anterior, y sin meses previos
    // los deltas saldrian todos en cero.
    $productos = catalogoProductos();
    $insDte = $pdo->prepare(
        'INSERT INTO dte_emitido '
        . '(rut_emisor, ambiente, tipo_dte, folio, track_id, estado, glosa_sii, xml, fecha_emision, '
        . ' neto, exento, iva, impuesto_adicional, total, forma_pago, fecha_vencimiento, receptor_rut, '
        . ' folio_ref, tipo_dte_ref) '
        . "VALUES (:r, 'produccion', :t, :f, :tr, :e, :g, :x, :fe, :n, :ex, :iva, 0, :tot, :fp, :fv, :rr, :fr, :tr2)"
    );

    // Contadores de folio por tipo. Arrancan en el folio_desde de su CAF y son
    // la fuente del proximo_folio que se fija al cerrar la siembra.
    $folios     = [33 => 1, 34 => 1, 39 => 1, 56 => 1, 61 => 1];
    $emitidos   = [];   // para referenciar desde las notas y encolar correos
    $hoy        = new DateTimeImmutable('today');
    $desde      = $hoy->modify('first day of this month')->modify('-5 months');

    for ($mes = 0; $mes < 6; $mes++) {
        $inicioMes = $desde->modify("+{$mes} months");
        $finMes    = $inicioMes->modify('last day of this month');
        // El mes en curso llega solo hasta hoy: documentos con fecha futura
        // saldrian en los informes de un periodo que todavia no ocurrio.
        $ultimoDia = min((int) $finMes->format('j'), $inicioMes->format('Y-m') === $hoy->format('Y-m')
            ? (int) $hoy->format('j')
            : (int) $finMes->format('j'));

        // PRORRATEO DEL MES EN CURSO. El mes corriente esta a medio transcurrir,
        // asi que su volumen se escala por la fraccion de dias ya vividos. Sin
        // esto, el mes en curso salia con MAS documentos que el mes completo
        // anterior metidos en los primeros dias -- el dashboard mostraba un
        // crecimiento imposible y el grafico de ventas por dia, una pared. Es
        // justo el numero que un cliente mira primero.
        $prorrateo = $inicioMes->format('Y-m') === $hoy->format('Y-m')
            ? $ultimoDia / (int) $finMes->format('t')
            : 1.0;

        // Volumen creciente mes a mes: una serie plana no deja ver la tendencia
        // que el grafico del dashboard existe para mostrar.
        $facturas = max(1, (int) round((12 + $mes * 3) * $prorrateo));
        for ($i = 0; $i < $facturas; $i++) {
            $dia    = random_int(1, max(1, $ultimoDia));
            $fecha  = $inicioMes->setDate((int) $inicioMes->format('Y'), (int) $inicioMes->format('n'), $dia);
            $cliente = $clientes[random_int(0, count($clientes) - 1)];

            // Una de cada seis es exenta (tipo 34) y usa el producto exento.
            $esExenta = $i % 6 === 5;
            $tipoDte  = $esExenta ? 34 : 33;

            $lineas = [];
            $bruto  = 0;
            $nLineas = random_int(1, 3);
            for ($l = 0; $l < $nLineas; $l++) {
                $prod = $esExenta
                    ? $productos[count($productos) - 1]
                    : $productos[random_int(0, count($productos) - 2)];
                $cant  = random_int(1, 12);
                $monto = $cant * $prod['precio'];
                $lineas[] = ['nombre' => $prod['nombre'], 'cantidad' => $cant, 'precio' => $prod['precio'], 'monto' => $monto];
                $bruto += $monto;
            }

            $neto   = $esExenta ? 0 : $bruto;
            $exento = $esExenta ? $bruto : 0;
            $iva    = (int) round($neto * 0.19);
            $total  = $neto + $exento + $iva;

            // Un tercio a credito, a 30 dias: sin esto la columna de vencimiento
            // del panel de emision sale siempre vacia.
            $credito     = $i % 3 === 0;
            $formaPago   = $credito ? 2 : 1;
            $vencimiento = $credito ? $fecha->modify('+30 days')->format('Y-m-d') : null;

            // Estados realistas. El grueso ya tiene veredicto; los del mes en
            // curso pueden estar todavia en 'enviado' (el SII tarda), y se dejan
            // dos rechazos para que la tarjeta de rechazados del dashboard y el
            // informe por estado tengan algo que mostrar.
            $esMesActual = $mes === 5;
            $estado      = 'EPR';
            $glosa       = 'Envio procesado';
            if ($esMesActual && $i >= $facturas - 3) {
                $estado = 'enviado';
                $glosa  = null;
            } elseif ($mes === 3 && $i === 4) {
                $estado = 'RCT';
                $glosa  = 'Rechazado por Error en Caratula (CRT-3-19) Fecha/Numero Resolucion Invalido';
            } elseif ($mes === 1 && $i === 7) {
                $estado = 'RCT';
                $glosa  = 'Rechazado por Error en Caratula (CRT-3-19) Fecha/Numero Resolucion Invalido';
            }

            $folio = $folios[$tipoDte]++;
            $receptor = ['rut' => $cliente['rut'], 'razon' => $cliente['razon'], 'giro' => $cliente['giro'],
                         'dir' => $cliente['dir'], 'comuna' => $cliente['comuna']];

            $insDte->execute([
                ':r'   => DEMO_RUT, ':t' => $tipoDte, ':f' => $folio,
                ':tr'  => (string) random_int(1_000_000_000, 1_999_999_999),
                ':e'   => $estado, ':g' => $glosa,
                ':x'   => construirEnvioDte($tipoDte, $folio, $fecha->format('Y-m-d'), $receptor, $lineas,
                                            $neto, $exento, $iva, $total, $formaPago, $vencimiento, null),
                ':fe'  => $fecha->format('Y-m-d'),
                ':n'   => $neto, ':ex' => $exento, ':iva' => $iva, ':tot' => $total,
                ':fp'  => $formaPago, ':fv' => $vencimiento,
                ':rr'  => $cliente['rut'], ':fr' => null, ':tr2' => null,
            ]);

            $emitidos[] = [
                'id' => (int) $pdo->lastInsertId(), 'tipo' => $tipoDte, 'folio' => $folio,
                'fecha' => $fecha, 'cliente' => $cliente, 'total' => $total, 'neto' => $neto,
                'exento' => $exento, 'iva' => $iva, 'estado' => $estado,
            ];
        }

        // --- boletas del mes (39): muchas, chicas, sin cliente identificado.
        $boletas = max(1, (int) round((25 + $mes * 5) * $prorrateo));
        for ($i = 0; $i < $boletas; $i++) {
            $dia   = random_int(1, max(1, $ultimoDia));
            $fecha = $inicioMes->setDate((int) $inicioMes->format('Y'), (int) $inicioMes->format('n'), $dia);
            $prod  = $productos[random_int(0, count($productos) - 2)];
            $cant  = random_int(1, 4);

            // En boleta el precio unitario ya viene con IVA incluido y MntTotal
            // es ese bruto; el neto y el IVA se desagregan hacia atras, igual que
            // hace el motor al emitirlas.
            $totalBoleta = $cant * $prod['precio'];
            $netoBoleta  = (int) round($totalBoleta / 1.19);
            $ivaBoleta   = $totalBoleta - $netoBoleta;

            $folio = $folios[39]++;
            $receptor = ['rut' => '66666666-6', 'razon' => 'Consumidor final', 'giro' => 'Particular',
                         'dir' => 'Sin direccion', 'comuna' => DEMO_COMUNA];
            $lineas = [['nombre' => $prod['nombre'], 'cantidad' => $cant, 'precio' => $prod['precio'], 'monto' => $totalBoleta]];

            $insDte->execute([
                ':r'  => DEMO_RUT, ':t' => 39, ':f' => $folio,
                ':tr' => (string) random_int(1_000_000_000, 1_999_999_999),
                ':e'  => 'EPR', ':g' => 'Envio procesado',
                ':x'  => construirEnvioDte(39, $folio, $fecha->format('Y-m-d'), $receptor, $lineas,
                                           $netoBoleta, 0, $ivaBoleta, $totalBoleta, 1, null, null),
                ':fe' => $fecha->format('Y-m-d'),
                ':n'  => $netoBoleta, ':ex' => 0, ':iva' => $ivaBoleta, ':tot' => $totalBoleta,
                ':fp' => 1, ':fv' => null, ':rr' => '66666666-6', ':fr' => null, ':tr2' => null,
            ]);
        }
    }

    // --- notas de credito y debito, referidas a facturas que existen ---------
    //
    // Van al final, sobre $emitidos ya poblado: una nota que apunte a un folio
    // inexistente se ve mal justo en la pantalla donde se explica el modulo.
    $facturasAfectas = array_values(array_filter(
        $emitidos,
        static fn (array $d): bool => $d['tipo'] === 33 && $d['estado'] === 'EPR'
    ));
    $notas = 0;
    foreach ([61, 61, 61, 61, 56, 56] as $k => $tipoNota) {
        if (! isset($facturasAfectas[$k * 7])) {
            continue;
        }
        $origen  = $facturasAfectas[$k * 7];
        $cliente = $origen['cliente'];
        $fecha   = $origen['fecha']->modify('+' . random_int(5, 20) . ' days');
        if ($fecha > $hoy) {
            $fecha = $hoy;
        }

        // La NC devuelve una fraccion de la factura; la ND cobra intereses.
        $bruto = $tipoNota === 61
            ? (int) round($origen['neto'] * 0.25)
            : (int) round($origen['neto'] * 0.05);
        $bruto = max($bruto, 10000);
        $iva   = (int) round($bruto * 0.19);
        $total = $bruto + $iva;

        $folio  = $folios[$tipoNota]++;
        $glosa  = $tipoNota === 61 ? 'Devolucion parcial de mercaderia' : 'Intereses por pago fuera de plazo';
        $lineas = [['nombre' => $glosa, 'cantidad' => 1, 'precio' => $bruto, 'monto' => $bruto]];
        $receptor = ['rut' => $cliente['rut'], 'razon' => $cliente['razon'], 'giro' => $cliente['giro'],
                     'dir' => $cliente['dir'], 'comuna' => $cliente['comuna']];

        $insDte->execute([
            ':r'  => DEMO_RUT, ':t' => $tipoNota, ':f' => $folio,
            ':tr' => (string) random_int(1_000_000_000, 1_999_999_999),
            ':e'  => 'EPR', ':g' => 'Envio procesado',
            ':x'  => construirEnvioDte(
                $tipoNota, $folio, $fecha->format('Y-m-d'), $receptor, $lineas,
                $bruto, 0, $iva, $total, 1, null,
                ['tipo' => 33, 'folio' => $origen['folio'], 'fecha' => $origen['fecha']->format('Y-m-d')]
            ),
            ':fe' => $fecha->format('Y-m-d'),
            ':n'  => $bruto, ':ex' => 0, ':iva' => $iva, ':tot' => $total,
            ':fp' => 1, ':fv' => null, ':rr' => $cliente['rut'],
            ':fr' => $origen['folio'], ':tr2' => 33,
        ]);
        $notas++;
    }

    $totalDocs = (int) $pdo->query(
        "SELECT COUNT(*) FROM dte_emitido WHERE rut_emisor = '" . DEMO_RUT . "' AND ambiente = 'produccion'"
    )->fetchColumn();
    paso("{$totalDocs} documentos emitidos sembrados ({$notas} notas de credito/debito).");

    // --- cuadrar los contadores de folio con lo realmente emitido ------------
    //
    // Recien AQUI se conoce el ultimo folio de cada tipo, porque las notas de
    // credito y debito se emiten despues del bucle de meses. $folios ya trae el
    // SIGUIENTE folio libre (se incrementa con cada documento), asi que se
    // escribe tal cual: la pantalla de "Folios y CAF" muestra entonces
    // exactamente los folios que el panel de emision es capaz de listar, y los
    // restantes cuadran con el rango del CAF. Sin este paso los dos numeros
    // salen de fuentes distintas y no hay razon para que coincidan.
    $ajusteFolio = $pdo->prepare(
        'UPDATE dte_folio SET proximo_folio = :p '
        . "WHERE rut_emisor = :r AND ambiente = 'produccion' AND tipo_dte = :t"
    );
    foreach ($folios as $tipo => $siguiente) {
        $ajusteFolio->execute([':p' => $siguiente, ':r' => DEMO_RUT, ':t' => $tipo]);
    }
    paso('Contadores de folio cuadrados con los documentos emitidos.');

    // --- cola de correos -----------------------------------------------------
    //
    // Con los tres estados que la pantalla sabe pintar. Solo facturas: las
    // boletas no se despachan por correo.
    $insCorreo = $pdo->prepare(
        'INSERT INTO dte_envio_correo (dte_emitido_id, cuenta_id, destinatario, estado, intentos, ultimo_error, enviado_at, created_at) '
        . 'VALUES (:d, :c, :dest, :e, :i, :err, :env, :cr)'
    );
    $paraCorreo = array_slice(array_reverse($emitidos), 0, 24);
    $correos = 0;
    foreach ($paraCorreo as $i => $doc) {
        [$estado, $intentos, $error, $enviado] = match (true) {
            $i === 3            => ['error', 3, 'SMTP 550: buzon del destinatario lleno', null],
            $i === 9            => ['sin_destinatario', 0, null, null],
            $i < 2              => ['pendiente', 0, null, null],
            default             => ['enviado', 1, null, $doc['fecha']->format('Y-m-d') . ' 09:20:00'],
        };
        $insCorreo->execute([
            ':d'    => $doc['id'],
            ':c'    => $cuentaId,
            ':dest' => $estado === 'sin_destinatario' ? null : $doc['cliente']['email'],
            ':e'    => $estado,
            ':i'    => $intentos,
            ':err'  => $error,
            ':env'  => $enviado,
            ':cr'   => $doc['fecha']->format('Y-m-d') . ' 09:15:00',
        ]);
        $correos++;
    }
    paso("{$correos} correos en la cola (enviados, pendientes, con error y sin destinatario).");

    // --- un lote de carga masiva ya facturado -------------------------------
    $pdo->prepare(
        'INSERT INTO lote_carga (cuenta_id, usuario_id, nombre_archivo, total_filas, filas_validas, filas_error, tipo_dte, created_at) '
        . 'VALUES (:c, :u, :n, :t, :v, :e, 33, :cr)'
    )->execute([
        ':c' => $cuentaId, ':u' => $usuarioId, ':n' => 'ventas_' . $hoy->format('Y_m') . '.xlsx',
        ':t' => 8, ':v' => 7, ':e' => 1, ':cr' => $hoy->modify('-6 days')->format('Y-m-d') . ' 11:05:00',
    ]);
    $loteId = (int) $pdo->lastInsertId();

    $insNota = $pdo->prepare(
        'INSERT INTO nota_venta '
        . '(cuenta_id, lote_carga_id, identificador_externo, receptor_rut, receptor_razon_social, receptor_giro, '
        . ' receptor_direccion, receptor_comuna, receptor_email, fecha_nota, detalle, monto_estimado, tipo_dte, '
        . ' forma_pago, estado, error_mensaje, fila_original) '
        . 'VALUES (:c, :l, :ie, :rr, :rs, :g, :d, :cm, :e, :f, :det, :m, 33, 1, :est, :err, :fo)'
    );
    foreach (array_slice($clientes, 0, 8) as $i => $cli) {
        $esError = $i === 7;
        $prod    = $productos[$i % (count($productos) - 1)];
        $cant    = random_int(2, 9);
        $monto   = (int) round($cant * $prod['precio'] * 1.19);

        $insNota->execute([
            ':c'   => $cuentaId,
            ':l'   => $loteId,
            ':ie'  => $esError ? null : 'OC-' . $hoy->format('Ym') . '-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            ':rr'  => $esError ? null : $cli['rut'],
            ':rs'  => $esError ? null : $cli['razon'],
            ':g'   => $esError ? null : $cli['giro'],
            ':d'   => $esError ? null : $cli['dir'],
            ':cm'  => $esError ? null : $cli['comuna'],
            ':e'   => $esError ? null : $cli['email'],
            ':f'   => $esError ? null : $hoy->modify('-7 days')->format('Y-m-d'),
            ':det' => $esError ? null : json_encode([[
                'nombre'   => $prod['nombre'],
                'cantidad' => $cant,
                'precio'   => $prod['precio'],
            ]], JSON_UNESCAPED_UNICODE),
            ':m'   => $esError ? 0 : $monto,
            ':est' => $esError ? 'error' : 'facturada',
            ':err' => $esError ? 'RUT del receptor invalido: digito verificador no corresponde.' : null,
            ':fo'  => $esError
                ? json_encode(['77123456-0', 'Cliente con RUT mal tipeado', '', '', '', '', '', '', '', '', '', '', '', ''], JSON_UNESCAPED_UNICODE)
                : null,
        ]);
    }
    paso('Un lote de carga masiva con 7 filas facturadas y 1 con error.');

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail('Fallo la siembra, no se escribio nada: ' . $e->getMessage(), 3);
}

echo "\n";
echo "===========================================================\n";
echo "  CUENTA DE DEMOSTRACION LISTA\n";
echo "===========================================================\n";
echo "  Usuario:    " . DEMO_EMAIL . "\n";
echo "  Contrasena: {$password}\n";
echo "\n";
echo "  Empresa:    " . DEMO_RAZON . " (" . DEMO_RUT . ")\n";
echo "  Modo:       SOLO LECTURA (usuario.demo = 1)\n";
echo "\n";
echo "  El menu completo queda habilitado. Cualquier accion que\n";
echo "  escriba -- emitir, cargar CAF o certificado, generar API\n";
echo "  keys, enviar correos, editar maestros -- se corta en el\n";
echo "  router con una pantalla de 'Modo demostracion'.\n";
echo "\n";
echo "  Para eliminarla:  php scripts/sembrar_demo.php --borrar\n";
echo "===========================================================\n";
