<?php

declare(strict_types=1);

/**
 * Front controller del panel de autoservicio (onboarding SaaS).
 *
 * App PHP plana, SEPARADA de public/ (API DTE) y del motor (src/, integration/
 * en la raiz del repo): comparte solo la base de datos, via las mismas env
 * vars DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS que usa public/index.php (ver
 * panel/src/Db.php).
 *
 * Etapa actual (1+2+3+4+5): registro, login, datos de empresa, carga de
 * certificado digital, carga de CAF, progreso de certificacion (consulta en
 * vivo al SII), panel de progreso. api_key se muestra como seccion aparte.
 */

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Libro;
use Plantiflex\FacturacionCl\Dto\LineaLibro;
use Plantiflex\FacturacionCl\Correo\EncoladorCorreo;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Enums\TipoEnvioLibro;
use Plantiflex\FacturacionCl\Enums\TipoLibro;
use Plantiflex\FacturacionCl\Enums\TipoOperacionLibro;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Plantiflex\FacturacionCl\Pdf\MuestrasImpresasZipBuilder;
use Plantiflex\FacturacionCl\Providers\BoletaFacturador;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use Plantiflex\FacturacionCl\Sii\BoletaSetPruebasBuilder;
use Plantiflex\FacturacionCl\Sii\CertificacionEstadoResolver;
use Plantiflex\FacturacionCl\Sii\EnvioDteParser;
use Plantiflex\FacturacionCl\Sii\EstadoContable;
use Plantiflex\FacturacionCl\Sii\EnvioRecibosBuilder;
use Plantiflex\FacturacionCl\Sii\LibroComprasPayloadBuilder;
use Plantiflex\FacturacionCl\Sii\LibroService;
use Plantiflex\FacturacionCl\Sii\LibroVentasPayloadBuilder;
use Plantiflex\FacturacionCl\Sii\LoteDteEmisor;
use Plantiflex\FacturacionCl\Sii\RespuestaDteBuilder;
use Plantiflex\FacturacionCl\Sii\RvdBuilder;
use Plantiflex\FacturacionCl\Sii\RvdResumenCalculator;
use Plantiflex\FacturacionCl\Sii\SetBasicoPayloadBuilder;
use Plantiflex\FacturacionCl\Sii\SetPruebasParser;
use Plantiflex\FacturacionCl\Sii\SimulacionSetBuilder;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\RegistroVeredictoSii;
use Plantiflex\FacturacionCl\Sii\SiiConsultor;
use Plantiflex\FacturacionCl\Sii\SiiUploader;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\FacturacionCl\Sii\DatosContribuyenteSiiParser;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\CertificadoRutSenderExtractor;
use Plantiflex\Integration\Facturacion\CertificadoCryptoException;
use Plantiflex\Integration\Facturacion\MySqlBoletaRvdRepository;
use Plantiflex\Integration\Facturacion\MySqlDteEmitidoRepository;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;
use Plantiflex\Integration\Facturacion\MySqlIntercambioRespuestaRepository;
use Plantiflex\Integration\Facturacion\MySqlLibroRepository;
use Plantiflex\Integration\Facturacion\MySqlSetBasicoSokRepository;
use Plantiflex\Integration\Facturacion\MySqlSetPruebasArchivoRepository;
use Plantiflex\Integration\Facturacion\MySqlClienteRepository;
use Plantiflex\Integration\Facturacion\ClienteDuplicadoException;
use Plantiflex\Integration\Facturacion\MySqlProductoRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Plantiflex\Integration\Facturacion\ProductoDuplicadoException;

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Rut.php';
require __DIR__ . '/../src/Csrf.php';
require __DIR__ . '/../src/FechaExcel.php';
// Reutiliza el motor TAL CUAL (no se modifica): via el autoloader de Composer
// del propio proyecto, que ya resuelve tanto Plantiflex\FacturacionCl\ (src/)
// como Plantiflex\Integration\Facturacion\ (integration/plantiflex/) -- misma
// libreria que usa public/index.php, sin reimplementar nada de cifrado, firma
// ni consulta al SII.
require __DIR__ . '/../../vendor/autoload.php';
// InformePdf extiende TCPDF, asi que va DESPUES del autoloader de Composer: al
// declarar la clase, PHP necesita que la clase padre ya sea resoluble. Con los
// require de arriba (antes del autoload) fallaria con "Class TCPDF not found".
require __DIR__ . '/../src/InformePdf.php';

Auth::iniciar();

// ===========================================================================
//  Helpers
// ===========================================================================
function vista(string $nombre, array $datos = []): never
{
    extract($datos);
    require __DIR__ . '/../views/' . $nombre . '.php';
    exit;
}

/**
 * <input> oculto con el token CSRF de la sesion, listo para insertar dentro
 * de cualquier <form method="post">. Centraliza el escape/generado para no
 * repetirlo en cada uno de los formularios de panel/views/ -- la
 * verificacion correspondiente vive en el router (ver mas abajo), UNA sola
 * vez para todos los POST, no handler por handler.
 */
function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(Csrf::generarToken()) . '">';
}

function redirigir(string $ruta): never
{
    header('Location: ' . $ruta);
    exit;
}

/**
 * Redirect PRG (Post-Redirect-Get) con 303 See Other explicito: el codigo
 * correcto para que el navegador convierta el siguiente request a GET SIEMPRE
 * (a diferencia de 302, que en teoria HTTP es ambiguo). Usar al final de un
 * handler POST que ya termino de procesar, para que un refresh/atras del
 * navegador no reenvie el POST original.
 */
function redirigirPrg(string $ruta): never
{
    header('Location: ' . $ruta, true, 303);
    exit;
}

/**
 * Guarda un mensaje flash en sesion, para mostrarlo UNA vez tras un redirect
 * (patron PRG). $datos es opcional (retrocompatible): permite adjuntar
 * estructura extra (ej. errores de construccion, resultado de una emision)
 * que un flash de solo texto no alcanza a expresar.
 */
function flashSet(string $tipo, string $mensaje, array $datos = []): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje] + $datos;
}

/**
 * Recupera y BORRA el flash de sesion: se muestra una sola vez, nunca
 * sobrevive a un segundo GET (ej. un refresh de la pagina que ya lo mostro).
 *
 * @return array{tipo:string, mensaje:string}|null
 */
function flashTomar(): ?array
{
    if (! isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/** Valida una fecha YYYY-MM-DD real (calendario), mismo patron que validaFecha() en public/index.php. */
function fechaValida(string $f): bool
{
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
        return false;
    }
    [$y, $m, $d] = explode('-', $f);
    return checkdate((int) $m, (int) $d, (int) $y);
}

/**
 * Llave maestra (KEK) para envelope encryption de certificados. Mismo patron
 * que public/index.php: getenv('CRYPTO_MASTER_KEY') en hex -> 32 bytes crudos.
 * Nunca se cachea en sesion ni se loguea.
 */
function kekMaestra(): string
{
    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        error_log('panel: CRYPTO_MASTER_KEY ausente o mal configurada (esperados 32 bytes en hex)');
        http_response_code(500);
        echo 'Error de configuracion del servidor. Contacta al administrador.';
        exit;
    }
    return $bin;
}

/**
 * Los tipos DTE que el sistema maneja, para las pantallas que necesitan
 * RECORRER la lista y no solo consultarla: el checklist de la pantalla de CAF
 * (/caf y /caf-produccion) y el dashboard.
 *
 * Es un envoltorio de TipoDte::catalogo(), que a su vez sale de
 * TipoDte::MANEJADOS. NO es TipoDte::cases(): el enum modela el catalogo del
 * SII e incluye guia de despacho (52) y boleta exenta (41), que este sistema no
 * emite -- ofrecerlos en el selector de CAF seria peor que el problema que se
 * vino a arreglar. Ver el comentario de MANEJADOS.
 *
 * Un CAF de un tipo fuera de esta lista igual se lista (con nombre generico),
 * solo no aparece en el checklist.
 *
 * @return array<int,string>
 */
function catalogoTiposDte(): array
{
    return TipoDte::catalogo();
}

// ---------------------------------------------------------------------------
//  Constantes del dashboard de gestion (sus funciones viven al final del
//  archivo, junto a handlePanelGet()).
//
//  Van declaradas AQUI, y no junto a esas funciones, porque las declaraciones
//  de funcion se elevan pero las de constante NO: se ejecutan cuando el flujo
//  llega a ellas. El router if-chain despacha y termina mucho antes del final
//  del archivo, asi que una constante declarada alla nunca llegaria a existir.
// ---------------------------------------------------------------------------

/** Tipo de DTE que RESTA al neto del periodo: nota de credito. */
const DASH_TIPO_NOTA_CREDITO = 61;

/**
 * Segundos que sobrevive un CAF subido pero aun no confirmado.
 *
 * La carga de CAF es de DOS PASOS (subir -> revisar -> confirmar) y entre uno y
 * otro el archivo YA CIFRADO espera en $_SESSION['caf_pendiente']. Pasado este
 * plazo se descarta y hay que volver a subirlo: un CAF a medio cargar no debe
 * quedar indefinidamente en la sesion de alguien que abandono el flujo.
 */
const CAF_PENDIENTE_TTL = 900;

/** Bajo este porcentaje de folios disponibles el indicador pasa a rojo. */
const DASH_FOLIOS_UMBRAL_ROJO = 10;

/** Bajo este porcentaje (y sobre el rojo) el indicador pasa a ambar. */
const DASH_FOLIOS_UMBRAL_AMBAR = 25;

/** Nombres de mes sin tildes, para las etiquetas de periodo. */
const DASH_MESES = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
    5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
    9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
];

/**
 * CATALOGO CERRADO de informes. La clave es la que viaja en la URL
 * (/informes/{clave}), asi que esta constante es tambien la lista blanca: una
 * clave que no este aqui es 404, no hay ruta dinamica que construir.
 *
 * 'periodo' => false significa que el informe NO admite rango de fechas. Hoy
 * solo folios: es una foto del stock actual de folios disponibles, no un
 * agregado de un periodo, asi que un desde/hasta ahi no significaria nada y se
 * ignora explicitamente (ver handleInformeGet()).
 */
const INFORMES = [
    'facturacion' => [
        'label'       => 'Facturacion por tipo de documento',
        'descripcion' => 'Documentos, neto, IVA y total agrupados por tipo de DTE.',
        'periodo'     => true,
    ],
    'ventas-dia' => [
        'label'       => 'Ventas por dia',
        'descripcion' => 'Serie diaria con ventas, notas de credito y neto. Incluye los dias sin emision.',
        'periodo'     => true,
    ],
    'clientes' => [
        'label'       => 'Clientes por facturacion',
        'descripcion' => 'Ranking completo de receptores por neto facturado en el periodo.',
        'periodo'     => true,
    ],
    'estados' => [
        'label'       => 'Documentos por estado del SII',
        'descripcion' => 'Cuantos documentos hay en cada estado, tal cual lo devolvio el SII.',
        'periodo'     => true,
    ],
    'detalle' => [
        'label'       => 'Detalle documento a documento',
        'descripcion' => 'Una fila por documento emitido, con receptor, montos y estado.',
        'periodo'     => true,
    ],
    'folios' => [
        'label'       => 'Estado de folios',
        'descripcion' => 'Folios disponibles, usados y CAF cargados por tipo de documento.',
        'periodo'     => false,
    ],
];

/**
 * "Nombre (N)", o "Documento tipo N (N)" si el enum no conoce el tipo.
 *
 * SU FORMA EXTERNA NO CAMBIA: sus 14 consumidores siguen recibiendo lo mismo.
 * Lo que cambio es de donde sale el nombre -- ahora de TipoDte::nombreDe(), que
 * es la unica fuente --, y por eso todos ellos heredan los tipos nuevos sin
 * tocar una linea.
 */
function nombreTipoDte(int $tipo): string
{
    return TipoDte::nombreDe($tipo) . " ({$tipo})";
}

/** Primer valor de texto de un tag XML plano (sin namespace), igual que scripts/cargar_caf.php. */
function cafTexto(DOMDocument $dom, string $tag): string
{
    $n = $dom->getElementsByTagName($tag)->item(0);
    return $n === null ? '' : trim((string) $n->textContent);
}

/**
 * Lista los CAF cargados para un rut_emisor (ambiente certificacion), con
 * folios restantes calculados. Se reutiliza tanto para GET /caf como para
 * redibujar la lista cuando POST /caf falla con un error.
 *
 * @return list<array<string,mixed>>
 */
function listarCafs(PDO $pdo, string $rutEmisor, string $ambiente = 'certificacion'): array
{
    $stmt = $pdo->prepare(
        // proximo_folio_inicial: con que folio arranco el contador. En un CAF
        // normal vale folio_desde; si es mayor, el CAF vino MIGRADO de otro
        // proveedor y la vista lo marca para que el salto no parezca un error.
        'SELECT c.tipo_dte, c.folio_desde, c.folio_hasta, c.estado, '
        . '       f.proximo_folio, f.proximo_folio_inicial '
        . 'FROM dte_caf c '
        . 'INNER JOIN dte_folio f ON f.caf_id = c.id '
        . 'WHERE c.rut_emisor = :rut AND c.ambiente = :amb '
        . 'ORDER BY c.tipo_dte ASC, c.folio_desde ASC'
    );
    $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente]);
    $cafs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cafs as &$c) {
        $c['folios_restantes'] = (int) $c['folio_hasta'] - (int) $c['proximo_folio'] + 1;
    }
    unset($c);

    return $cafs;
}

// ===========================================================================
//  Menu lateral (shell del panel) -- ver partials/_nav.php
// ===========================================================================

/**
 * Resuelve el estado visual de un item del menu a partir de los dos ejes.
 * Precedencia: si el modulo NO esta construido gana "proximamente" (no tiene
 * sentido pedir requisitos para algo que aun no existe).
 *
 * $puedeEmitir SALE DEL MISMO CRITERIO QUE EL SERVIDOR. Antes salia de una
 * funcion tenantEnProduccion() que media otra cosa -- si el tenant habia
 * confirmado su certificacion -- y el menu terminaba mintiendo en las dos
 * direcciones: prometia Ventas a quien el guard rebotaba (certificado pero sin
 * filas de produccion) y lo negaba a quien el guard dejaba pasar (preautorizado
 * por el SII, que nunca paso por el circuito de certificacion). Aquella funcion
 * se elimino: su nombre decia "produccion" y consultaba certificacion, y un
 * predicado huerfano con nombre engañoso es como se reintroduce este bug.
 * Hoy el unico origen de la verdad es estadoEmisionProduccion().
 *
 * @param array<string,mixed> $item
 *
 * @return 'habilitado'|'sin_produccion'|'proximamente'
 */
function navEstadoItem(array $item, bool $puedeEmitir): string
{
    if (empty($item['construido'])) {
        return 'proximamente';
    }
    if (! empty($item['requiereProduccion']) && ! $puedeEmitir) {
        return 'sin_produccion';
    }
    return 'habilitado';
}

/**
 * Definicion declarativa del menu lateral. Eje "construido" por item: se cambia
 * a true a medida que cada modulo se implementa (hoy: Dashboard, Maestros y las
 * opciones de Configuracion ya existentes). Los items operativos llevan
 * requiereProduccion=true (Ventas); Maestros/Configuracion/Dashboard no.
 *
 * Estructura: cada seccion es un item suelto (sin 'items') o un grupo (con
 * 'items'); un item de grupo puede a su vez tener 'items' (subgrupo, ej.
 * Ventas > Emision). 'clave' identifica el item para marcarlo activo.
 *
 * @return list<array<string,mixed>>
 */
function definicionMenu(): array
{
    return [
        ['clave' => 'dashboard', 'label' => 'Dashboard', 'destino' => '/panel', 'icono' => 'dashboard', 'construido' => true, 'requiereProduccion' => false],
        [
            'label' => 'Ventas',
            'items' => [
                [
                    'label' => 'Emision',
                    'items' => [
                        ['clave' => 'ventas.factura', 'label' => 'Factura electronica', 'destino' => '/ventas/factura', 'icono' => 'factura', 'construido' => true, 'requiereProduccion' => true, 'sub' => true],
                        // Mismo icono 'factura' que la afecta, y no es un icono
                        // prestado: una exenta ES una factura, solo que sin IVA.
                        // Lo que las distingue es la etiqueta, no el dibujo.
                        ['clave' => 'ventas.factura-exenta', 'label' => 'Factura exenta', 'destino' => '/ventas/factura-exenta', 'icono' => 'factura', 'construido' => true, 'requiereProduccion' => true, 'sub' => true],
                        ['clave' => 'ventas.nc', 'label' => 'Nota de credito', 'destino' => '/ventas/nota-credito', 'icono' => 'nota-credito', 'construido' => true, 'requiereProduccion' => true, 'sub' => true],
                        ['clave' => 'ventas.nd', 'label' => 'Nota de debito', 'destino' => '/ventas/nota-debito', 'icono' => 'nota-debito', 'construido' => true, 'requiereProduccion' => true, 'sub' => true],
                    ],
                ],
                ['clave' => 'ventas.carga-masiva', 'label' => 'Carga masiva de notas de venta', 'destino' => '/ventas/carga-masiva', 'icono' => 'carga-masiva', 'construido' => true, 'requiereProduccion' => true],
                ['clave' => 'ventas.facturacion-masiva', 'label' => 'Facturacion masiva', 'destino' => '/ventas/facturacion-masiva', 'icono' => 'facturacion-masiva', 'construido' => true, 'requiereProduccion' => true],
                ['clave' => 'ventas.panel-emision', 'label' => 'Panel de emision', 'destino' => '/ventas/panel-emision', 'icono' => 'panel-emision', 'construido' => true, 'requiereProduccion' => true],
                // requiereProduccion=true por dos razones independientes: sin
                // poder emitir la cola esta vacia por definicion, y su handler
                // usa exigirProduccionCompleto() igual que sus vecinos.
                ['clave' => 'ventas.correos', 'label' => 'Envio de correos', 'destino' => '/ventas/correos', 'icono' => 'envio-correo', 'construido' => true, 'requiereProduccion' => true],
            ],
        ],
        [
            'label' => 'Maestros',
            'items' => [
                ['clave' => 'maestros.clientes', 'label' => 'Clientes', 'destino' => '/maestros/clientes', 'icono' => 'clientes', 'construido' => true, 'requiereProduccion' => false],
                ['clave' => 'maestros.productos', 'label' => 'Productos y servicios', 'destino' => '/maestros/productos', 'icono' => 'productos', 'construido' => true, 'requiereProduccion' => false],
            ],
        ],
        // Los seis informes leen dte_emitido de PRODUCCION, asi que todos van
        // con requiereProduccion => true, igual que el Panel de emision. El
        // guard real es exigirProduccionCompleto() en cada handler; esto solo
        // evita mostrar enlaces que llevarian a un bloqueo.
        [
            'label' => 'Informes',
            'items' => [
                ['clave' => 'informes.facturacion', 'label' => 'Facturacion por tipo', 'destino' => '/informes/facturacion', 'icono' => 'informe-tipos', 'construido' => true, 'requiereProduccion' => true],
                ['clave' => 'informes.ventas-dia', 'label' => 'Ventas por dia', 'destino' => '/informes/ventas-dia', 'icono' => 'informe-dia', 'construido' => true, 'requiereProduccion' => true],
                ['clave' => 'informes.clientes', 'label' => 'Clientes por facturacion', 'destino' => '/informes/clientes', 'icono' => 'informe-clientes', 'construido' => true, 'requiereProduccion' => true],
                ['clave' => 'informes.estados', 'label' => 'Documentos por estado', 'destino' => '/informes/estados', 'icono' => 'informe-estados', 'construido' => true, 'requiereProduccion' => true],
                ['clave' => 'informes.detalle', 'label' => 'Detalle documento a documento', 'destino' => '/informes/detalle', 'icono' => 'informe-detalle', 'construido' => true, 'requiereProduccion' => true],
                ['clave' => 'informes.folios', 'label' => 'Estado de folios', 'destino' => '/informes/folios', 'icono' => 'informe-folios', 'construido' => true, 'requiereProduccion' => true],
            ],
        ],
        // CERTIFICACION Y PRODUCCION SON DOS SUBGRUPOS HERMANOS, de peso visual
        // identico. Antes los cuatro items de certificacion colgaban sueltos de
        // "Configuracion" -- sin decir en ninguna parte que eran de
        // certificacion -- y los de produccion iban en un subgrupo subordinado,
        // mas pequeno y mas tenue. Los cuatro pares comparten label exacto
        // (Empresa, Certificado digital, Folios y CAF, API keys), asi que la
        // unica senal de en que ambiente estabas era la sangria: 9px de
        // diferencia. Un CAF cargado por error en produccion quema folios
        // reales ante el SII.
        //
        // Ahora el ambiente lo da el ENCABEZADO del subgrupo, no el item, y por
        // eso los labels no repiten la palabra: dentro de "Produccion", el item
        // "Empresa" solo puede ser la empresa de produccion.
        //
        // 'variante' la lee partials/_nav.php para agregar la clase modificadora
        // del subgrupo; un subgrupo sin ella se pinta como siempre.
        [
            'label' => 'Configuracion empresa',
            'items' => [
                [
                    'label'    => 'Certificacion',
                    'variante' => 'certificacion',
                    'items'    => [
                        ['clave' => 'config.empresa', 'label' => 'Empresa', 'destino' => '/empresa', 'icono' => 'empresa', 'construido' => true, 'requiereProduccion' => false],
                        ['clave' => 'config.certificado', 'label' => 'Certificado digital', 'destino' => '/certificado', 'icono' => 'certificado', 'construido' => true, 'requiereProduccion' => false],
                        ['clave' => 'config.caf', 'label' => 'Folios y CAF', 'destino' => '/caf', 'icono' => 'caf', 'construido' => true, 'requiereProduccion' => false],
                        ['clave' => 'config.apikeys', 'label' => 'API keys', 'destino' => '/apikeys', 'icono' => 'apikeys', 'construido' => true, 'requiereProduccion' => false],
                        // Va al final del grupo y no al principio: es el tramite
                        // ante el SII, que se hace DESPUES de tener empresa,
                        // certificado y CAF cargados.
                        ['clave' => 'config.certificacion', 'label' => 'Certificacion SII', 'destino' => '/certificacion-elegir', 'icono' => 'certificacion-sii', 'construido' => true, 'requiereProduccion' => false],
                    ],
                ],
                [
                    // requiereProduccion=false a proposito, en los cuatro: estas
                    // son las rutas que LLEVAN a completar produccion, no
                    // funciones que dependan de estar ya en produccion.
                    // Marcarlas 'sin_produccion' las volveria inalcanzables
                    // justo cuando hay que usarlas. El aviso de que aqui los
                    // folios son reales es visual (ver la variante en el CSS),
                    // no un bloqueo.
                    'label'    => 'Produccion',
                    'variante' => 'produccion',
                    'items'    => [
                        ['clave' => 'config.empresa-prod', 'label' => 'Empresa', 'destino' => '/empresa-produccion', 'icono' => 'empresa', 'construido' => true, 'requiereProduccion' => false],
                        ['clave' => 'config.certificado-prod', 'label' => 'Certificado digital', 'destino' => '/certificado-produccion', 'icono' => 'certificado', 'construido' => true, 'requiereProduccion' => false],
                        ['clave' => 'config.caf-prod', 'label' => 'Folios y CAF', 'destino' => '/caf-produccion', 'icono' => 'caf', 'construido' => true, 'requiereProduccion' => false],
                        ['clave' => 'config.apikeys-prod', 'label' => 'API keys', 'destino' => '/apikeys-produccion', 'icono' => 'apikeys', 'construido' => true, 'requiereProduccion' => false],
                    ],
                ],
            ],
        ],
        // Fuera de "Configuracion empresa" a proposito: no tiene ambiente. Antes
        // quedaba al final de esa seccion, justo despues del bloque de
        // produccion, y se leia como si perteneciera a el. Aqui hace pareja con
        // Auditoria, que es lo que es: administracion transversal del tenant.
        ['clave' => 'config.usuarios', 'label' => 'Usuarios y permisos', 'destino' => '/configuracion/usuarios', 'icono' => 'usuarios', 'construido' => true, 'requiereProduccion' => false],
        ['clave' => 'auditoria', 'label' => 'Auditoria', 'destino' => '/auditoria', 'icono' => 'auditoria', 'construido' => true, 'requiereProduccion' => false],
    ];
}

// ===========================================================================
//  Maestros > Clientes (CRUD). Aislamiento por cuenta_id via
//  MySqlClienteRepository (ver integration/plantiflex/).
// ===========================================================================

/** Arma el repo de clientes sobre la conexion del panel. */
function clienteRepo(): MySqlClienteRepository
{
    return new MySqlClienteRepository(Db::conexion());
}

/**
 * Valida y normaliza los datos de un cliente desde $_POST (compartido por alta
 * y edicion). rut_cliente y razon_social obligatorios; el resto opcional.
 * rut_cliente queda NORMALIZADO (Rut::normalizar) listo para guardar.
 *
 * @param array<string,mixed> $post
 *
 * @return array{0:array<string,mixed>, 1:array<string,string>} [datos, errores]
 */
function validarCliente(array $post): array
{
    $rut    = Rut::normalizar((string) ($post['rut_cliente'] ?? ''));
    $razon  = trim((string) ($post['razon_social'] ?? ''));
    $giro   = trim((string) ($post['giro'] ?? ''));
    $dir    = trim((string) ($post['direccion'] ?? ''));
    $comuna = trim((string) ($post['comuna'] ?? ''));
    $email  = trim((string) ($post['email'] ?? ''));
    $tel    = trim((string) ($post['telefono'] ?? ''));

    $errores = [];
    if (! Rut::valido($rut)) {
        $errores['rut_cliente'] = 'RUT invalido (formato NNNNNNNN-DV, digito verificador incorrecto).';
    }
    if ($razon === '') {
        $errores['razon_social'] = 'La razon social es obligatoria.';
    }
    if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores['email'] = 'El email no tiene un formato valido.';
    }

    $datos = [
        'rut_cliente'  => $rut,
        'razon_social' => $razon,
        'giro'         => $giro,
        'direccion'    => $dir,
        'comuna'       => $comuna,
        'email'        => $email,
        'telefono'     => $tel,
    ];

    return [$datos, $errores];
}

/**
 * Los campos que un cliente necesita para poder ser FACTURADO, y que sin embargo
 * no son obligatorios para GUARDARLO.
 *
 * LA DISTINCION NO ES UN DESCUIDO, ES DELIBERADA Y HAY QUE MANTENERLA. El
 * esquema los declara nullables ("Nullable aqui; el motor lo exige al emitir"),
 * y ya existen clientes en produccion con estos campos en NULL. Exigirlos al
 * guardar romperia la EDICION de todos ellos: nadie podria corregirle el
 * telefono a un cliente antiguo sin antes averiguarle el giro.
 *
 * Pero sin ellos el documento NO SE PUEDE EMITIR. La matriz de obligatoriedad
 * del Formato DTE v2.5 les da codigo 1 -- "dato obligatorio, debe estar siempre"
 * -- en factura (33) y en factura exenta (34): GiroRecep campo 57, DirRecep
 * campo 60, CmnaRecep campo 61.
 *
 * De ahi la conducta: se guarda igual, y se avisa.
 *
 * @var list<string>
 */
const CLIENTE_CAMPOS_PARA_FACTURAR = ['giro', 'direccion', 'comuna'];

/**
 * Cuales de los campos necesarios para facturar le faltan a un cliente.
 *
 * Sirve para las tres pantallas -- el aviso del ABM, la marca del listado y su
 * filtro -- para que las tres digan lo mismo. Un cliente "incompleto" tiene que
 * ser el mismo conjunto en todas.
 *
 * @param array<string,mixed> $cliente fila del repositorio o datos de validarCliente()
 * @return list<string> nombres de los campos vacios, en el orden de la constante
 */
function clienteCamposFaltantes(array $cliente): array
{
    $faltan = [];
    foreach (CLIENTE_CAMPOS_PARA_FACTURAR as $campo) {
        if (trim((string) ($cliente[$campo] ?? '')) === '') {
            $faltan[] = $campo;
        }
    }

    return $faltan;
}

/**
 * Mensaje de exito del ABM, con el aviso pegado cuando el cliente quedo sin los
 * datos que hacen falta para facturarlo. NO es un error: el cliente SE GUARDO.
 */
function mensajeClienteGuardado(string $base, array $datos): string
{
    $faltan = clienteCamposFaltantes($datos);
    if ($faltan === []) {
        return $base;
    }

    return sprintf(
        '%s Ojo: le falta %s, y sin %s no vas a poder emitirle documentos. Puedes completarlo cuando quieras.',
        $base,
        implode(', ', $faltan),
        count($faltan) === 1 ? 'ese dato' : 'esos datos',
    );
}

/**
 * Resuelve un cliente por su RUT dentro de la cuenta, distinguiendo 3 estados de
 * forma inequivoca. Base reutilizable para M3 (emision unitaria): "el usuario
 * escribe un RUT en el formulario de factura; si el cliente existe se
 * autocompleta, si no existe se ofrece crearlo sin abandonar la factura".
 *
 * Recibe el RUT CRUDO (como lo escribe el usuario): normaliza y valida aqui, para
 * que quien llame no repita esa logica. Un cliente INACTIVO se devuelve como
 * 'encontrado' con cliente['activo'] === false (NO como 'no_encontrado'): asi el
 * caller lo ve y decide, y no cae en un ClienteDuplicadoException si intentara
 * "crearlo" (el UNIQUE(cuenta_id, rut_cliente) cubre activos e inactivos).
 *
 * @return array{
 *   estado: 'rut_invalido'|'no_encontrado'|'encontrado',
 *   rut: string,
 *   cliente: array<string,mixed>|null
 * }
 */
function resolverClientePorRut(int $cuentaId, string $rutCrudo): array
{
    $rut = Rut::normalizar($rutCrudo);
    if (! Rut::valido($rut)) {
        return ['estado' => 'rut_invalido', 'rut' => '', 'cliente' => null];
    }

    $cliente = clienteRepo()->buscarPorRut($cuentaId, $rut);
    if ($cliente === null) {
        return ['estado' => 'no_encontrado', 'rut' => $rut, 'cliente' => null];
    }

    return ['estado' => 'encontrado', 'rut' => $rut, 'cliente' => $cliente];
}

/** 404 real (no redirect) cuando un cliente no existe o es de otra cuenta. */
function responder404Cliente(): never
{
    http_response_code(404);
    $titulo = 'Cliente no encontrado';
    require __DIR__ . '/../views/partials/header.php';
    echo '<h1>Cliente no encontrado</h1>';
    echo '<p>El cliente no existe o no pertenece a tu empresa. '
        . '<a href="/maestros/clientes">Volver al listado</a>.</p>';
    require __DIR__ . '/../views/partials/footer.php';
    exit;
}

function handleClientesListar(): void
{
    $cuentaId         = Auth::cuentaId();
    $repo             = clienteRepo();
    $q                = trim((string) ($_GET['q'] ?? ''));
    $busqueda         = $q !== '' ? $q : null;
    $incluirInactivos = ($_GET['inactivos'] ?? '') === '1';
    $soloActivos      = ! $incluirInactivos;
    $soloIncompletos  = ($_GET['incompletos'] ?? '') === '1';

    $porPagina = 25;
    $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
    $total     = $repo->contar($cuentaId, $busqueda, $soloActivos, $soloIncompletos);
    $offset    = ($pagina - 1) * $porPagina;
    $clientes  = $repo->listar($cuentaId, $busqueda, $soloActivos, $porPagina, $offset, $soloIncompletos);

    vista('clientes-listado', [
        'clientes'         => $clientes,
        'q'                => $q,
        'incluirInactivos' => $incluirInactivos,
        'soloIncompletos'  => $soloIncompletos,
        // El conteo va SIN los filtros de busqueda a proposito: el aviso tiene
        // que decir cuantos clientes de la cuenta estan incompletos, no cuantos
        // de los que se estan mirando ahora. Si dependiera de la busqueda,
        // filtrar por un nombre haria "desaparecer" el problema.
        'totalIncompletos' => $repo->contarIncompletos($cuentaId),
        'pagina'           => $pagina,
        'totalPaginas'     => max(1, (int) ceil($total / $porPagina)),
        'total'            => $total,
        'flash'            => flashTomar(),
        'navActivo'        => 'maestros.clientes',
    ]);
}

function handleClienteNuevoGet(): void
{
    vista('cliente-form', [
        'modo'      => 'nuevo',
        'accion'    => '/maestros/clientes/nuevo',
        'cliente'   => [],
        'errores'   => [],
        'navActivo' => 'maestros.clientes',
    ]);
}

function handleClienteNuevoPost(): void
{
    $cuentaId          = Auth::cuentaId();
    $repo              = clienteRepo();
    [$datos, $errores] = validarCliente($_POST);

    if ($errores === [] && $repo->buscarPorRut($cuentaId, $datos['rut_cliente']) !== null) {
        $errores['rut_cliente'] = 'Ya existe un cliente con ese RUT en tu empresa.';
    }

    if ($errores === []) {
        try {
            $repo->crear($cuentaId, $datos);
            // Se guarda igual y se avisa: bloquear romperia la edicion de los
            // clientes que ya existen con estos campos en NULL. Ver
            // CLIENTE_CAMPOS_PARA_FACTURAR.
            flashSet('exito', mensajeClienteGuardado('Cliente creado.', $datos));
            redirigirPrg('/maestros/clientes');
        } catch (ClienteDuplicadoException $e) {
            // Borde de carrera: otro request creo el mismo RUT entre el chequeo
            // previo y este insert. Mismo mensaje amigable, no error generico.
            $errores['rut_cliente'] = 'Ya existe un cliente con ese RUT en tu empresa.';
        }
    }

    vista('cliente-form', [
        'modo'      => 'nuevo',
        'accion'    => '/maestros/clientes/nuevo',
        'cliente'   => $datos,
        'errores'   => $errores,
        'navActivo' => 'maestros.clientes',
    ]);
}

function handleClienteEditarGet(int $id): void
{
    $cliente = clienteRepo()->buscarPorId(Auth::cuentaId(), $id);
    if ($cliente === null) {
        responder404Cliente();
    }
    vista('cliente-form', [
        'modo'      => 'editar',
        'accion'    => "/maestros/clientes/{$id}/editar",
        'cliente'   => $cliente,
        'errores'   => [],
        'navActivo' => 'maestros.clientes',
    ]);
}

function handleClienteEditarPost(int $id): void
{
    $cuentaId = Auth::cuentaId();
    $repo     = clienteRepo();

    if ($repo->buscarPorId($cuentaId, $id) === null) {
        responder404Cliente();
    }

    [$datos, $errores] = validarCliente($_POST);
    if ($errores === []) {
        $otro = $repo->buscarPorRut($cuentaId, $datos['rut_cliente']);
        if ($otro !== null && (int) $otro['id'] !== $id) {
            $errores['rut_cliente'] = 'Ya existe otro cliente con ese RUT en tu empresa.';
        }
    }

    if ($errores === []) {
        try {
            $repo->actualizar($cuentaId, $id, $datos);
            flashSet('exito', mensajeClienteGuardado('Cliente actualizado.', $datos));
            redirigirPrg('/maestros/clientes');
        } catch (ClienteDuplicadoException $e) {
            $errores['rut_cliente'] = 'Ya existe otro cliente con ese RUT en tu empresa.';
        }
    }

    $datos['id'] = $id;
    vista('cliente-form', [
        'modo'      => 'editar',
        'accion'    => "/maestros/clientes/{$id}/editar",
        'cliente'   => $datos,
        'errores'   => $errores,
        'navActivo' => 'maestros.clientes',
    ]);
}

function handleClienteActivarPost(int $id): void
{
    if (! clienteRepo()->activar(Auth::cuentaId(), $id)) {
        responder404Cliente();
    }
    flashSet('exito', 'Cliente activado.');
    redirigirPrg('/maestros/clientes');
}

function handleClienteDesactivarPost(int $id): void
{
    if (! clienteRepo()->desactivar(Auth::cuentaId(), $id)) {
        responder404Cliente();
    }
    flashSet('exito', 'Cliente desactivado.');
    redirigirPrg('/maestros/clientes');
}

// ===========================================================================
//  Maestros > Productos (CRUD). Aislamiento por cuenta_id via
//  MySqlProductoRepository. Espejo de Clientes con las diferencias propias de
//  producto: codigo opcional (unico solo si no es NULL), precio decimal,
//  exento (bool).
// ===========================================================================

/** Arma el repo de productos sobre la conexion del panel. */
function productoRepo(): MySqlProductoRepository
{
    return new MySqlProductoRepository(Db::conexion());
}

/**
 * Valida y normaliza los datos de un producto desde $_POST (compartido por alta
 * y edicion). Solo nombre es obligatorio. precio_unitario acepta entero o
 * decimal ("1990" o "1990.50"); texto no numerico es error de validacion (no un
 * 500). exento es checkbox (ausente = 0).
 *
 * @param array<string,mixed> $post
 *
 * @return array{0:array<string,mixed>, 1:array<string,string>} [datos, errores]
 */
function validarProducto(array $post): array
{
    $codigo    = trim((string) ($post['codigo'] ?? ''));
    $nombre    = trim((string) ($post['nombre'] ?? ''));
    $desc      = trim((string) ($post['descripcion'] ?? ''));
    $precioRaw = trim((string) ($post['precio_unitario'] ?? ''));
    $unidad    = trim((string) ($post['unidad'] ?? ''));
    $exento    = ! empty($post['exento']);

    $errores = [];
    if ($nombre === '') {
        $errores['nombre'] = 'El nombre es obligatorio.';
    }
    if ($precioRaw !== '' && ! is_numeric($precioRaw)) {
        $errores['precio_unitario'] = 'El precio debe ser un numero (ej. 1990 o 1990.50).';
    }

    $datos = [
        'codigo'          => $codigo,
        'nombre'          => $nombre,
        'descripcion'     => $desc,
        'precio_unitario' => $precioRaw, // el repo castea a float; vacio -> NULL
        'unidad'          => $unidad,
        'exento'          => $exento,
    ];

    return [$datos, $errores];
}

/** 404 real (no redirect) cuando un producto no existe o es de otra cuenta. */
function responder404Producto(): never
{
    http_response_code(404);
    $titulo = 'Producto no encontrado';
    require __DIR__ . '/../views/partials/header.php';
    echo '<h1>Producto no encontrado</h1>';
    echo '<p>El producto no existe o no pertenece a tu empresa. '
        . '<a href="/maestros/productos">Volver al listado</a>.</p>';
    require __DIR__ . '/../views/partials/footer.php';
    exit;
}

function handleProductosListar(): void
{
    $cuentaId         = Auth::cuentaId();
    $repo             = productoRepo();
    $q                = trim((string) ($_GET['q'] ?? ''));
    $busqueda         = $q !== '' ? $q : null;
    $incluirInactivos = ($_GET['inactivos'] ?? '') === '1';
    $soloActivos      = ! $incluirInactivos;

    $porPagina = 25;
    $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
    $total     = $repo->contar($cuentaId, $busqueda, $soloActivos);
    $offset    = ($pagina - 1) * $porPagina;
    $productos = $repo->listar($cuentaId, $busqueda, $soloActivos, $porPagina, $offset);

    vista('productos-listado', [
        'productos'        => $productos,
        'q'                => $q,
        'incluirInactivos' => $incluirInactivos,
        'pagina'           => $pagina,
        'totalPaginas'     => max(1, (int) ceil($total / $porPagina)),
        'total'            => $total,
        'flash'            => flashTomar(),
        'navActivo'        => 'maestros.productos',
    ]);
}

function handleProductoNuevoGet(): void
{
    vista('producto-form', [
        'modo'      => 'nuevo',
        'accion'    => '/maestros/productos/nuevo',
        'producto'  => [],
        'errores'   => [],
        'navActivo' => 'maestros.productos',
    ]);
}

function handleProductoNuevoPost(): void
{
    $cuentaId          = Auth::cuentaId();
    $repo              = productoRepo();
    [$datos, $errores] = validarProducto($_POST);

    // Duplicado SOLO si el usuario ingreso un codigo (los productos sin codigo
    // conviven: UNIQUE(cuenta_id, codigo) permite multiples NULL).
    if ($errores === [] && $datos['codigo'] !== ''
        && $repo->buscarPorCodigo($cuentaId, $datos['codigo']) !== null
    ) {
        $errores['codigo'] = 'Ya existe un producto con ese codigo en tu empresa.';
    }

    if ($errores === []) {
        try {
            $repo->crear($cuentaId, $datos);
            flashSet('exito', 'Producto creado.');
            redirigirPrg('/maestros/productos');
        } catch (ProductoDuplicadoException $e) {
            $errores['codigo'] = 'Ya existe un producto con ese codigo en tu empresa.';
        }
    }

    vista('producto-form', [
        'modo'      => 'nuevo',
        'accion'    => '/maestros/productos/nuevo',
        'producto'  => $datos,
        'errores'   => $errores,
        'navActivo' => 'maestros.productos',
    ]);
}

function handleProductoEditarGet(int $id): void
{
    $producto = productoRepo()->buscarPorId(Auth::cuentaId(), $id);
    if ($producto === null) {
        responder404Producto();
    }
    vista('producto-form', [
        'modo'      => 'editar',
        'accion'    => "/maestros/productos/{$id}/editar",
        'producto'  => $producto,
        'errores'   => [],
        'navActivo' => 'maestros.productos',
    ]);
}

function handleProductoEditarPost(int $id): void
{
    $cuentaId = Auth::cuentaId();
    $repo     = productoRepo();

    if ($repo->buscarPorId($cuentaId, $id) === null) {
        responder404Producto();
    }

    [$datos, $errores] = validarProducto($_POST);
    if ($errores === [] && $datos['codigo'] !== '') {
        $otro = $repo->buscarPorCodigo($cuentaId, $datos['codigo']);
        if ($otro !== null && (int) $otro['id'] !== $id) {
            $errores['codigo'] = 'Ya existe otro producto con ese codigo en tu empresa.';
        }
    }

    if ($errores === []) {
        try {
            $repo->actualizar($cuentaId, $id, $datos);
            flashSet('exito', 'Producto actualizado.');
            redirigirPrg('/maestros/productos');
        } catch (ProductoDuplicadoException $e) {
            $errores['codigo'] = 'Ya existe otro producto con ese codigo en tu empresa.';
        }
    }

    $datos['id'] = $id;
    vista('producto-form', [
        'modo'      => 'editar',
        'accion'    => "/maestros/productos/{$id}/editar",
        'producto'  => $datos,
        'errores'   => $errores,
        'navActivo' => 'maestros.productos',
    ]);
}

function handleProductoActivarPost(int $id): void
{
    if (! productoRepo()->activar(Auth::cuentaId(), $id)) {
        responder404Producto();
    }
    flashSet('exito', 'Producto activado.');
    redirigirPrg('/maestros/productos');
}

function handleProductoDesactivarPost(int $id): void
{
    if (! productoRepo()->desactivar(Auth::cuentaId(), $id)) {
        responder404Producto();
    }
    flashSet('exito', 'Producto desactivado.');
    redirigirPrg('/maestros/productos');
}

// ===========================================================================
//  Ventas > Emision unitaria (M3): factura 33, NC 61, ND 56.
//
//  El panel NO instancia el motor: llama a su API por HTTP con la key de
//  SERVICIO (X-Api-Key), asi rut_emisor/ambiente salen siempre de la fila
//  api_key (garantia del refactor multi-tenant), nunca del payload. La URL
//  del motor viene de MOTOR_URL. El Idempotency-Key se genera UNA vez en el
//  GET (campo hidden) y viaja igual en cualquier reintento: el motor deduplica.
// ===========================================================================

/** Metadatos de cada tipo emitible: [titulo, ruta, clave de nav]. */
function metaTipoEmision(int $tipoDte): array
{
    return match ($tipoDte) {
        33 => ['Factura electronica', '/ventas/factura', 'ventas.factura'],
        34 => ['Factura exenta', '/ventas/factura-exenta', 'ventas.factura-exenta'],
        61 => ['Nota de credito', '/ventas/nota-credito', 'ventas.nc'],
        56 => ['Nota de debito', '/ventas/nota-debito', 'ventas.nd'],
        default => ['Documento', '/ventas/factura', 'ventas.factura'],
    };
}

/** Renderiza el formulario de emision (compartido por los 3 tipos). */
function renderEmisionForm(int $tipoDte, string $idemKey, array $form, ?string $errorCampo, ?string $errorMsg, ?string $flashError): never
{
    [$titulo, $accion, $nav] = metaTipoEmision($tipoDte);
    $productos = productoRepo()->listar(Auth::cuentaId(), null, true, 1000, 0);
    vista('emision-form', [
        'tipoDte'    => $tipoDte,
        'tituloDoc'  => $titulo,
        'accion'     => $accion,
        'idemKey'    => $idemKey,
        'form'       => $form,
        'errorCampo' => $errorCampo,
        'errorMsg'   => $errorMsg,
        'flashError' => $flashError,
        'productos'  => $productos,
        'navActivo'  => $nav,
    ]);
}

/** Arma el JSON del DocumentoTributario que espera POST /api/v1/dte desde $_POST. */
function armarDocumentoEmision(int $tipoDte, array $post): array
{
    $r        = is_array($post['receptor'] ?? null) ? $post['receptor'] : [];
    $receptor = [
        'rut'         => trim((string) ($r['rut'] ?? '')),
        'razonSocial' => trim((string) ($r['razonSocial'] ?? '')),
        'giro'        => trim((string) ($r['giro'] ?? '')),
        'direccion'   => trim((string) ($r['direccion'] ?? '')),
        'comuna'      => trim((string) ($r['comuna'] ?? '')),
    ];
    $email = trim((string) ($r['email'] ?? ''));
    if ($email !== '') {
        $receptor['email'] = $email;
    }

    $detalles = [];
    foreach (is_array($post['detalles'] ?? null) ? $post['detalles'] : [] as $d) {
        if (! is_array($d)) {
            continue;
        }
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $cantR  = trim((string) ($d['cantidad'] ?? ''));
        $precR  = trim((string) ($d['precioUnitario'] ?? ''));
        if ($nombre === '' && $cantR === '' && $precR === '') {
            continue; // linea totalmente vacia: se ignora
        }
        $linea = [
            'nombre'         => $nombre,
            'cantidad'       => is_numeric($cantR) ? (float) $cantR : $cantR,
            'precioUnitario' => is_numeric($precR) ? (float) $precR : $precR,
            // FACTURA EXENTA (34): todas las lineas son exentas por definicion,
            // no por lo que venga en el POST. La casilla de la vista va marcada y
            // DESHABILITADA, y una casilla deshabilitada no viaja en el POST, asi
            // que sin este forzado el documento saldria con todo afecto.
            // El motor lo valida ademas por su cuenta (validarDocumentoDte).
            'exento'         => $tipoDte === 34 ? true : ! empty($d['exento']),
        ];
        $unidad = trim((string) ($d['unidad'] ?? ''));
        if ($unidad !== '') {
            $linea['unidad'] = $unidad;
        }
        $desc = trim((string) ($d['descripcion'] ?? ''));
        if ($desc !== '') {
            $linea['descripcion'] = $desc;
        }
        $detalles[] = $linea;
    }

    $doc = [
        'tipoDte'         => $tipoDte,
        'receptor'        => $receptor,
        'detalles'        => $detalles,
        'montosSonBrutos' => ! empty($post['montosSonBrutos']),
    ];

    // FORMA DE PAGO Y VENCIMIENTO (migracion 026). Solo para FACTURA y FACTURA
    // EXENTA: son los dos tipos para los que el Formato DTE exige informar
    // FmaPago (pag. 4, cambio del 31/05/2017). NC y ND no lo llevan, y el
    // formulario tampoco se los ofrece.
    //
    // NO HAY DEFAULT NI AQUI NI EN LA VISTA. Omitir el campo no es no-elegir: el
    // SII lo interpreta como 2 (credito), asi que un default silencioso seria
    // decidir por el usuario. Si no viene, no se manda -- y el motor lo rechaza
    // solo cuando falta la fecha con credito, no por faltar la forma de pago,
    // porque la carga masiva todavia no la manda (entrega 2).
    if (in_array($tipoDte, [33, 34], true)) {
        $fp = trim((string) ($post['formaPago'] ?? ''));
        if ($fp !== '' && ctype_digit($fp)) {
            $doc['formaPago'] = (int) $fp;
        }
        $fv = trim((string) ($post['fechaVencimiento'] ?? ''));
        // Se manda SOLO con credito. Con contado o sin costo, una fecha que el
        // usuario haya tecleado antes de cambiar de opcion se descarta aqui, para
        // no mandarle al motor una combinacion que el mismo rechaza.
        if ($fv !== '' && ($doc['formaPago'] ?? null) === 2) {
            $doc['fechaVencimiento'] = $fv;
        }
    }

    $dg = trim((string) ($post['descuentoGlobalPct'] ?? ''));
    if ($dg !== '' && is_numeric($dg)) {
        $doc['descuentoGlobalPct'] = (float) $dg;
    }
    $obs = trim((string) ($post['observaciones'] ?? ''));
    if ($obs !== '') {
        $doc['observaciones'] = $obs;
    }

    // Referencias: solo para NC (61) / ND (56), entrada manual (M3).
    if (in_array($tipoDte, [61, 56], true)) {
        $refs = [];
        foreach (is_array($post['referencias'] ?? null) ? $post['referencias'] : [] as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $td = trim((string) ($ref['tipoDocumento'] ?? ''));
            if ($td === '') {
                continue;
            }
            $item = ['tipoDocumento' => is_numeric($td) ? (int) $td : $td];
            $fol  = trim((string) ($ref['folio'] ?? ''));
            if ($fol !== '') {
                $item['folio'] = is_numeric($fol) ? (int) $fol : $fol;
            }
            $fe = trim((string) ($ref['fecha'] ?? ''));
            if ($fe !== '') {
                $item['fecha'] = $fe;
            }
            $cod = trim((string) ($ref['codigo'] ?? ''));
            if ($cod !== '') {
                $item['codigo'] = is_numeric($cod) ? (int) $cod : $cod;
            }
            $raz = trim((string) ($ref['razon'] ?? ''));
            if ($raz !== '') {
                $item['razon'] = $raz;
            }
            $refs[] = $item;
        }
        if ($refs !== []) {
            $doc['referencias'] = $refs;
        }
    }

    return $doc;
}

/**
 * Cliente Guzzle configurado contra el motor (MOTOR_URL). Centraliza la unica
 * construccion del Client que usan emitirEnMotor() y las funciones de
 * consulta de M5 (listar/pdf/xml/estado-sii) -- no duplicar base_uri/timeout/
 * http_errors en cada una.
 */
function clienteMotor(): Client
{
    $base = getenv('MOTOR_URL');
    if ($base === false || trim($base) === '') {
        throw new RuntimeException('MOTOR_URL no configurada en el entorno del panel.');
    }
    return new Client([
        'base_uri'    => rtrim($base, '/') . '/',
        'timeout'     => 60,
        'http_errors' => false,
    ]);
}

/**
 * POST al motor. Devuelve ['status'=>int, 'body'=>array]. NO lanza en 4xx/5xx
 * (http_errors=false); un fallo de conexion (motor caido/timeout) SI propaga
 * como GuzzleException para que el handler lo distinga de una respuesta HTTP.
 */
function emitirEnMotor(string $keyServicio, array $documento, string $idemKey): array
{
    $resp = clienteMotor()->post('api/v1/dte', [
        'headers' => [
            'X-Api-Key'       => $keyServicio,
            'Idempotency-Key' => $idemKey,
            'Content-Type'    => 'application/json',
        ],
        'json' => $documento,
    ]);
    $body = json_decode((string) $resp->getBody(), true);

    return ['status' => $resp->getStatusCode(), 'body' => is_array($body) ? $body : []];
}

/**
 * GET /api/v1/dte del motor (listado de documentos, M5). $filtros: pares
 * clave=>valor que se mandan como query string (desde/hasta/tipoDte/folio/
 * limit/offset, los que vengan definidos). Devuelve ['status'=>int,
 * 'body'=>array]; igual que emitirEnMotor(), NO lanza en 4xx/5xx.
 */
function listarDocumentosEnMotor(string $keyServicio, array $filtros): array
{
    $resp = clienteMotor()->get('api/v1/dte', [
        'headers' => ['X-Api-Key' => $keyServicio],
        'query'   => $filtros,
    ]);
    $body = json_decode((string) $resp->getBody(), true);

    return ['status' => $resp->getStatusCode(), 'body' => is_array($body) ? $body : []];
}

/**
 * GET binario del motor (pdf/xml de un documento, M5). Devuelve
 * ['status'=>int, 'contentType'=>?string, 'body'=>string]; en 4xx/5xx el
 * body es el JSON de error del motor (string sin decodificar, el llamador
 * decide si lo muestra).
 */
function obtenerBinarioEnMotor(string $keyServicio, string $ruta): array
{
    $resp = clienteMotor()->get($ruta, [
        'headers' => ['X-Api-Key' => $keyServicio],
    ]);

    return [
        'status'      => $resp->getStatusCode(),
        'contentType' => $resp->getHeaderLine('Content-Type') ?: null,
        'body'        => (string) $resp->getBody(),
    ];
}

/**
 * GET /api/v1/dte/{tipoDte}/{folio}/estado-sii del motor: consulta el SII
 * (QueryEstUp.jws via track_id) y persiste el estado nuevo en dte_emitido
 * (ver consultarEstadoSiiDte() en el motor). Devuelve ['status'=>int,
 * 'body'=>array].
 */
function consultarEstadoSiiEnMotor(string $keyServicio, int $tipoDte, int $folio): array
{
    $resp = clienteMotor()->get("api/v1/dte/{$tipoDte}/{$folio}/estado-sii", [
        'headers' => ['X-Api-Key' => $keyServicio],
    ]);
    $body = json_decode((string) $resp->getBody(), true);

    return ['status' => $resp->getStatusCode(), 'body' => is_array($body) ? $body : []];
}

/** Best-effort: crea el cliente en el maestro tras emitir (no invalida la emision). */
function guardarClienteDesdeReceptor(int $cuentaId, array $receptor): void
{
    try {
        $rut = Rut::normalizar((string) ($receptor['rut'] ?? ''));
        if (! Rut::valido($rut)) {
            return;
        }
        $repo = clienteRepo();
        if ($repo->buscarPorRut($cuentaId, $rut) !== null) {
            return; // ya existe (activo o inactivo): no se toca
        }
        $repo->crear($cuentaId, [
            'rut_cliente'  => $rut,
            'razon_social' => (string) ($receptor['razonSocial'] ?? ''),
            'giro'         => (string) ($receptor['giro'] ?? ''),
            'direccion'    => (string) ($receptor['direccion'] ?? ''),
            'comuna'       => (string) ($receptor['comuna'] ?? ''),
            'email'        => (string) ($receptor['email'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('panel emision: no se pudo guardar el cliente tras emitir - ' . $e->getMessage());
    }
}

function handleEmisionGet(int $tipoDte): void
{
    exigirProduccionCompleto(Db::conexion(), Auth::cuentaId()); // guard (redirige si falta emisor/cert/CAF prod)
    renderEmisionForm($tipoDte, bin2hex(random_bytes(16)), formConReferenciaPrellenada($tipoDte), null, null, null);
}

/**
 * Prellena referencias[0] desde query params (ref_tipo/ref_folio/ref_fecha/
 * ref_codigo/ref_razon) cuando se llega desde el boton "Anular"/"Corregir" del
 * detalle de un documento (M5). Solo aplica a NC/ND (61/56); factura no lleva
 * referencia. $form queda editable igual que en un re-render de POST/422 --
 * armarDocumentoEmision() y la vista no cambian, ya leen $form['referencias'][0].
 *
 * @return array<string,mixed>
 */
function formConReferenciaPrellenada(int $tipoDte): array
{
    if (! in_array($tipoDte, [61, 56], true)) {
        return [];
    }
    $folioRef = trim((string) ($_GET['ref_folio'] ?? ''));
    if ($folioRef === '') {
        return [];
    }
    return [
        'referencias' => [
            0 => [
                'tipoDocumento' => trim((string) ($_GET['ref_tipo'] ?? '')),
                'folio'         => $folioRef,
                'fecha'         => trim((string) ($_GET['ref_fecha'] ?? '')),
                'codigo'        => trim((string) ($_GET['ref_codigo'] ?? '')),
                'razon'         => trim((string) ($_GET['ref_razon'] ?? '')),
            ],
        ],
    ];
}

function handleEmisionPost(int $tipoDte): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirProduccionCompleto($pdo, $cuentaId); // guard

    // Idempotency-Key: viene del hidden del GET; se preserva en reintentos. Si
    // faltara (manipulacion), se usa uno nuevo para este submit.
    $idemKey   = trim((string) ($_POST['idem_key'] ?? '')) !== '' ? trim((string) $_POST['idem_key']) : bin2hex(random_bytes(16));
    $documento = armarDocumentoEmision($tipoDte, $_POST);

    try {
        $keyServicio = obtenerKeyServicio($pdo, $cuentaId, $rutEmisor);
        $res         = emitirEnMotor($keyServicio, $documento, $idemKey);
    } catch (Throwable $e) {
        error_log('panel emision: fallo de conexion con el motor - ' . $e->getMessage());
        renderEmisionForm(
            $tipoDte,
            $idemKey,
            $_POST,
            null,
            null,
            'No se pudo contactar el motor de emision. NO se emitio ningun documento; revisa la conexion y reintenta.'
        );
    }

    $status = $res['status'];
    $body   = $res['body'];

    if ($status === 201) {
        if (! empty($_POST['guardar_cliente'])) {
            guardarClienteDesdeReceptor($cuentaId, $documento['receptor']);
        }

        // RELLENO DEL CORREO EN EL MAESTRO. NO depende del checkbox
        // "guardar cliente" a proposito, y no es lo mismo que la linea de
        // arriba: guardarClienteDesdeReceptor() CREA un cliente completo y solo
        // si no existe todavia. Esto rellena un hueco en uno que YA existe.
        //
        // Sin esto seguia vivo por el camino unitario el mismo trinquete que la
        // Entrega 1 cerro para la carga masiva: un cliente creado sin correo no
        // podia recibirlo nunca mas, porque crear() lo escribe una sola vez y el
        // ABM exige que alguien lo teclee a mano. Si el usuario escribio un
        // correo al emitir, se aprovecha aunque no haya pedido guardar nada.
        //
        // La guarda de no-sobrescritura es la MISMA de la Entrega 1 y vive en el
        // WHERE de rellenarEmailSiVacio(), no en un if de PHP.
        rellenarCorreoMaestroDesdeReceptor($cuentaId, $documento['receptor']);

        // ENCOLADO DEL CORREO (migracion 024). Va despues de todo lo demas del
        // camino feliz y antes del redirect.
        //
        // POR QUE ESTE try/catch, Y POR QUE ENVUELVE EL BLOQUE ENTERO. Llegado
        // aqui el documento YA se emitio: el folio se quemo y el SII lo tiene.
        // EncoladorCorreo::encolarUno() trae su propia guarda, pero la RESOLUCION del
        // destinatario queda fuera de ella -- correoReceptorDeMaestro() consulta
        // la base con ERRMODE_EXCEPTION y puede lanzar. Sin este catch la
        // excepcion escaparia, y el panel no tiene handler global: el usuario
        // veria un "Fatal error" en vez de su confirmacion.
        //
        // Y EL PROBLEMA NO ES QUE SEA FEO. Un usuario que ve un error fatal
        // despues de emitir vuelve a intentarlo, y los folios NO se liberan: se
        // queman. El reintento emitiria una SEGUNDA factura ante el SII por la
        // misma venta. Existen el idemKey y dte_idempotencia, que PODRIAN
        // deduplicar ese reintento, pero eso no esta verificado y este codigo no
        // se apoya en ello: la unica garantia solida es que el usuario nunca vea
        // el error y por lo tanto no reintente.
        //
        // MISMO CRITERIO DE BLOQUE QUE EN facturarSubLote(): se envuelve el
        // bloque, no cada llamada, para que cualquier linea que se agregue aqui
        // en el futuro nazca cubierta.
        //
        // guardarClienteDesdeReceptor() y rellenarCorreoMaestroDesdeReceptor()
        // NO se meten dentro: cada una ya trae su propio try/catch(Throwable).
        // Son funciones autonomas y su seguridad no debe depender de quien las
        // llame; ademas guardarClienteDesdeReceptor() ya era asi antes de este
        // modulo, y romper esa simetria entre dos hermanas que hacen lo mismo
        // solo confundiria. Este catch cubre lo que NO vive dentro de una
        // funcion que se proteja sola: la resolucion del destinatario.
        try {
            // CASCADA DEL CAMINO UNITARIO: formulario > maestro. Lo tecleado se
            // escribio recien y para ESTE documento, asi que gana sobre el
            // maestro. Es la misma jerarquia por deliberacion que explica
            // EncoladorCorreo::encolarUno().
            $destinatario = trim((string) ($documento['receptor']['email'] ?? ''));
            if ($destinatario === '') {
                $destinatario = correoReceptorDeMaestro($cuentaId, (string) ($documento['receptor']['rut'] ?? ''));
            }
            EncoladorCorreo::encolarUno(
                $pdo,
                $cuentaId,
                $rutEmisor,
                // 'produccion' EXPLICITO, no por default. El panel emite solo en
                // produccion (todas estas rutas pasan por exigirProduccionCompleto
                // y usan la key de servicio, filtrada por ambiente='produccion'),
                // pero ahora el encolador lo comparte con el motor, que emite en
                // el ambiente de su tenant. Un default aqui volveria a esconder el
                // supuesto que se vino a eliminar.
                'produccion',
                (int) ($body['tipoDte'] ?? $tipoDte),
                (int) ($body['folio'] ?? 0),
                $destinatario !== '' ? $destinatario : null
            );
        } catch (Throwable $e) {
            error_log(sprintf(
                'encolar correo: fallo el encolado del documento tipo %d folio %s -- EL DOCUMENTO YA SE EMITIO '
                . 'y la emision no se toca; el usuario recibe su confirmacion normal - %s',
                (int) ($body['tipoDte'] ?? $tipoDte),
                (string) ($body['folio'] ?? '?'),
                $e->getMessage()
            ));
        }

        flashSet('exito', 'Documento emitido.', ['resultado' => [
            'tipoDte' => $body['tipoDte'] ?? $tipoDte,
            'folio'   => $body['folio'] ?? null,
            'estado'  => $body['estado'] ?? null,
            'trackId' => $body['trackId'] ?? null,
            'fchEmis' => $body['fchEmis'] ?? null,
            'neto'    => $body['neto'] ?? null,
            'iva'     => $body['iva'] ?? null,
            'total'   => $body['total'] ?? null,
        ]]);
        redirigirPrg('/ventas/resultado');
    }

    if ($status === 422) {
        // El motor devuelve {error, campo} (un campo a la vez, fail-fast).
        renderEmisionForm(
            $tipoDte,
            $idemKey,
            $_POST,
            (string) ($body['campo'] ?? ''),
            (string) ($body['error'] ?? 'Hay un dato invalido en el documento.'),
            null
        );
    }

    if ($status === 502) {
        error_log('panel emision: 502 del motor - ' . json_encode($body, JSON_UNESCAPED_UNICODE));
        renderEmisionForm(
            $tipoDte,
            $idemKey,
            $_POST,
            null,
            null,
            'No se pudo emitir: el SII rechazo el documento o no respondio. Revisa los datos e intenta nuevamente en unos minutos.'
        );
    }

    // 500 u otra respuesta inesperada.
    error_log('panel emision: respuesta inesperada del motor (' . $status . ') - ' . json_encode($body, JSON_UNESCAPED_UNICODE));
    renderEmisionForm(
        $tipoDte,
        $idemKey,
        $_POST,
        null,
        null,
        'Error del motor de emision. NO se emitio; intenta nuevamente.'
    );
}

function handleEmisionResultadoGet(): void
{
    $flash = flashTomar();
    if (empty($flash['resultado'])) {
        redirigir('/ventas/factura');
    }
    vista('resultado-emision', [
        'resultado' => $flash['resultado'],
        'navActivo'  => 'ventas.factura',
    ]);
}

// ===========================================================================
//  Ventas > Panel de emision (M5): listado de dte_emitido de produccion,
//  detalle, descarga de PDF/XML y consulta de estado SII. El panel NUNCA lee
//  dte_emitido de produccion directo de la BD (Decision #1 del proyecto): todo
//  pasa por HTTP al motor con la key de servicio, igual que la emision de M3.
// ===========================================================================

/**
 * Arma el array de filtros ($_GET -> query string del motor) para el listado
 * y para el link "siguiente/anterior" de paginacion. Solo incluye claves con
 * valor (el motor trata su ausencia como "sin filtro").
 *
 * @return array<string,string>
 */
function filtrosDocumentosDesdeGet(): array
{
    $filtros = [];
    foreach (['desde', 'hasta', 'tipoDte', 'folio', 'receptorRut', 'estado'] as $campo) {
        $v = trim((string) ($_GET[$campo] ?? ''));
        if ($v !== '') {
            $filtros[$campo] = $v;
        }
    }
    // desde/hasta deben ir juntos (mismo criterio que el motor): si falta uno,
    // se descarta el otro para no mandar un rango incompleto.
    if (isset($filtros['desde']) !== isset($filtros['hasta'])) {
        unset($filtros['desde'], $filtros['hasta']);
    }
    return $filtros;
}

/**
 * Resuelve razon_social del receptor de cada item en UNA sola query (evita
 * N+1). Normaliza receptor_rut en LECTURA (Rut::normalizar): armarDocumentoEmision()
 * de M3 solo hace trim() antes de mandar el RUT al motor, asi que
 * dte_emitido.receptor_rut puede no venir en el mismo formato canonico que
 * cliente.rut_cliente (ej. con puntos). No se toca M3 para esto.
 *
 * @param list<array<string,mixed>> $items
 *
 * @return list<array<string,mixed>> mismos items + 'receptorRazonSocial'
 */
function resolverRazonSocialReceptores(int $cuentaId, array $items): array
{
    $ruts = [];
    foreach ($items as $it) {
        $ruts[] = Rut::normalizar((string) ($it['receptorRut'] ?? ''));
    }
    $porRut = clienteRepo()->buscarPorRuts($cuentaId, $ruts);

    foreach ($items as &$it) {
        $rutNorm = Rut::normalizar((string) ($it['receptorRut'] ?? ''));
        $it['receptorRazonSocial'] = $porRut[$rutNorm]['razon_social'] ?? null;
    }
    unset($it);

    return $items;
}

function handleDocumentosListadoGet(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirProduccionCompleto($pdo, $cuentaId);

    $porPagina = 25;
    $filtros   = filtrosDocumentosDesdeGet();
    $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));

    $filtrosMotor           = $filtros;
    $filtrosMotor['limit']  = $porPagina;
    $filtrosMotor['offset'] = ($pagina - 1) * $porPagina;

    $keyServicio = obtenerKeyServicio($pdo, $cuentaId, $rutEmisor);

    try {
        $res = listarDocumentosEnMotor($keyServicio, $filtrosMotor);
    } catch (Throwable $e) {
        error_log('panel documentos: fallo de conexion al listar - ' . $e->getMessage());
        vista('documentos-listado', [
            'items'        => [],
            'total'        => 0,
            'pagina'       => 1,
            'totalPaginas' => 1,
            'filtros'      => $filtros,
            'errorMotor'   => 'No se pudo contactar el motor de emision.',
            'navActivo'    => 'ventas.panel-emision',
        ]);
    }

    if ($res['status'] !== 200) {
        error_log('panel documentos: respuesta ' . $res['status'] . ' del motor al listar - ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        vista('documentos-listado', [
            'items'        => [],
            'total'        => 0,
            'pagina'       => 1,
            'totalPaginas' => 1,
            'filtros'      => $filtros,
            'errorMotor'   => 'El motor de emision devolvio un error al listar los documentos.',
            'navActivo'    => 'ventas.panel-emision',
        ]);
    }

    $items = is_array($res['body']['items'] ?? null) ? $res['body']['items'] : [];
    $items = resolverRazonSocialReceptores($cuentaId, $items);
    $total = (int) ($res['body']['total'] ?? 0);

    vista('documentos-listado', [
        'items'        => $items,
        'total'        => $total,
        'pagina'       => $pagina,
        'totalPaginas' => max(1, (int) ceil($total / $porPagina)),
        'filtros'      => $filtros,
        'errorMotor'   => null,
        'navActivo'    => 'ventas.panel-emision',
    ]);
}

function handleDocumentoDetalleGet(int $tipoDte, int $folio): void
{
    // El 34 va aqui porque sin el, el detalle de una factura exenta redirige al
    // listado y el documento queda invisible: no es un mapa de nombres, es una
    // guarda de acceso. Los mapas de nombres (que solo cambian la etiqueta y ya
    // tienen fallback "Tipo N") son otra entrega.
    if (! in_array($tipoDte, [33, 34, 61, 56, 39], true) || $folio <= 0) {
        redirigir('/ventas/panel-emision');
    }

    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirProduccionCompleto($pdo, $cuentaId);

    $keyServicio = obtenerKeyServicio($pdo, $cuentaId, $rutEmisor);

    $documento   = null;
    $errorMotor  = null;
    try {
        $res = listarDocumentosEnMotor($keyServicio, ['tipoDte' => $tipoDte, 'folio' => $folio]);
        if ($res['status'] === 200 && ! empty($res['body']['items'])) {
            [$documento] = resolverRazonSocialReceptores($cuentaId, $res['body']['items']);
        } elseif ($res['status'] !== 200) {
            error_log('panel documentos: respuesta ' . $res['status'] . ' del motor al pedir detalle - ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
            $errorMotor = 'El motor de emision devolvio un error al buscar el documento.';
        }
    } catch (Throwable $e) {
        error_log('panel documentos: fallo de conexion al pedir detalle - ' . $e->getMessage());
        $errorMotor = 'No se pudo contactar el motor de emision.';
    }

    vista('documento-detalle', [
        'tipoDte'    => $tipoDte,
        'folio'      => $folio,
        'documento'  => $documento,
        'errorMotor' => $errorMotor,
        'flash'      => flashTomar(),
        'navActivo'  => 'ventas.panel-emision',
    ]);
}

/**
 * Descarga binaria (PDF o XML) proxeada desde el motor. $sufijo: 'pdf' | 'xml'.
 * Content-Type se reenvia tal cual del motor (incluye el charset ISO-8859-1
 * del XML); Content-Disposition se arma igual aqui porque el nombre de
 * archivo depende solo de tipoDte/folio/sufijo, ya conocidos.
 */
function proxyDocumentoBinario(int $tipoDte, int $folio, string $sufijo): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirProduccionCompleto($pdo, $cuentaId);
    $keyServicio = obtenerKeyServicio($pdo, $cuentaId, $rutEmisor);

    try {
        $res = obtenerBinarioEnMotor($keyServicio, "api/v1/dte/{$tipoDte}/{$folio}/{$sufijo}");
    } catch (Throwable $e) {
        error_log("panel documentos: fallo de conexion al pedir {$sufijo} - " . $e->getMessage());
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo contactar el motor de emision.';
        exit;
    }

    if ($res['status'] !== 200) {
        error_log("panel documentos: respuesta {$res['status']} del motor al pedir {$sufijo}");
        http_response_code($res['status'] === 404 ? 404 : 502);
        header('Content-Type: text/plain; charset=utf-8');
        echo $res['status'] === 404 ? 'Documento no encontrado.' : 'No se pudo obtener el documento.';
        exit;
    }

    if ($res['contentType'] !== null) {
        header('Content-Type: ' . $res['contentType']);
    }
    $disposicion = $sufijo === 'pdf' ? 'inline' : 'attachment';
    header(sprintf('Content-Disposition: %s; filename="dte_%d_%d.%s"', $disposicion, $tipoDte, $folio, $sufijo));
    header('Content-Length: ' . strlen($res['body']));
    echo $res['body'];
    exit;
}

function handleDocumentoPdfGet(int $tipoDte, int $folio): void
{
    proxyDocumentoBinario($tipoDte, $folio, 'pdf');
}

function handleDocumentoXmlGet(int $tipoDte, int $folio): void
{
    proxyDocumentoBinario($tipoDte, $folio, 'xml');
}

// ===========================================================================
//  COLA DE ENVIO DE CORREOS AL RECEPTOR (tabla dte_envio_correo, migracion 024)
//
//  Ver, y poder desatascar. Nada mas. NO hay boton de "enviar ahora": el
//  enviador corre en el contenedor del MOTOR y el panel es otro contenedor, asi
//  que no puede ejecutarlo. Con el cron cada 5 minutos, reintentar ya significa
//  que sale en los proximos minutos.
//
//  A DIFERENCIA DE /ventas/panel-emision, ESTA LISTA CONSULTA LA BASE DIRECTO.
//  Aquella le pide los documentos al motor por HTTP porque dte_emitido es suya;
//  la cola, en cambio, vive en la base del panel, asi que pagina con
//  LIMIT/OFFSET en SQL y no delega en nadie.
// ===========================================================================

/** Los cuatro estados de dte_envio_correo. Fuente unica para el filtro y la vista. */
const CORREO_ESTADOS = ['pendiente', 'enviado', 'error', 'sin_destinatario'];

/**
 * Tope de CORREOS DISTINTOS que resuelve de una pasada la rebusca masiva de
 * destinatarios.
 *
 * EL TOPE VA SOBRE SENTENCIAS, NO SOBRE FILAS LEIDAS, y no es un detalle de
 * gusto. Medido sobre 10.000 filas de un mismo tenant en la base desechable:
 *
 *     un UPDATE por fila, sin transaccion ... 267.055 ms  (10.000 sentencias)
 *     agrupado por correo, sin transaccion ..   8.929 ms  (   200 sentencias)
 *     un UPDATE por fila, en transaccion ....   4.824 ms  (10.000 sentencias)
 *     agrupado por correo, en transaccion ...   1.056 ms  (   200 sentencias)
 *
 * El costo lo manda el numero de sentencias, no el de filas tocadas: la ida y
 * vuelta a la base es de 0,18 ms, asi que los ~30 ms por sentencia del caso sin
 * transaccion son el fsync de cada commit (innodb_flush_log_at_trx_commit=1).
 * De ahi las dos decisiones: se agrupan los ids por correo resuelto, y se
 * commitea una sola vez.
 *
 * El peor caso del agrupado es que TODOS los receptores sean distintos, y ahi
 * vuelve a haber una sentencia por fila: medido, 0,45 ms cada una, o sea ~1,1 s
 * con este tope.
 *
 * Y HAY UNA SEGUNDA RAZON PARA TOPEAR SENTENCIAS Y NO FILAS: si se topearan las
 * filas leidas, las primeras N sin correo en el maestro se volverian a leer en
 * cada click y las de mas atras no se alcanzarian NUNCA. Una fila que no
 * resuelve no gasta sentencia, asi que el presupuesto se gasta solo en filas que
 * si se van a mover, y cada click avanza.
 */
const CORREO_REBUSCA_MAX_CORREOS = 2000;

/**
 * Listado de la cola de correos de la cuenta en sesion.
 *
 * AISLAMIENTO POR TENANT: todas las consultas llevan q.cuenta_id = :c, con el
 * cuenta_id de Auth::cuentaId() -- de la SESION, nunca de la peticion. Es el
 * mismo patron del resto del panel.
 *
 * El JOIN a dte_emitido va por id NUMERICO (q.dte_emitido_id = e.id). Es lo que
 * permite traer tipo, folio y rut del receptor sin cruzar las dos familias de
 * collation del esquema: dte_emitido es utf8mb4_0900_ai_ci y dte_envio_correo
 * es utf8mb4_unicode_ci, y unirlas por una columna de TEXTO daria "Illegal mix
 * of collations". Por eso esta lista NO resuelve la razon social del receptor:
 * eso vive en el maestro de clientes, de la otra familia, y exigiria el rodeo de
 * resolverRazonSocialReceptores(). El destinatario -- que es el dato que importa
 * para diagnosticar un correo -- ya esta en la propia cola.
 */
function handleCorreosListadoGet(): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    exigirProduccionCompleto($pdo, $cuentaId); // mismo guard que sus vecinos de Ventas

    $porPagina = 25;
    $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));

    // Filtro por estado, validado contra la lista cerrada: cualquier otra cosa
    // se ignora y se muestra todo. No se interpola nunca en el SQL.
    $estado = trim((string) ($_GET['estado'] ?? ''));
    if (! in_array($estado, CORREO_ESTADOS, true)) {
        $estado = '';
    }

    // CONTEOS: siempre sobre TODA la cuenta, sin aplicar el filtro. Es lo que
    // permite contestar "fallo algo?" de un vistazo aunque estes filtrando.
    $conteos = array_fill_keys(CORREO_ESTADOS, 0);
    $stmt    = $pdo->prepare(
        'SELECT estado, COUNT(*) AS n FROM dte_envio_correo WHERE cuenta_id = :c GROUP BY estado'
    );
    $stmt->execute([':c' => $cuentaId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $conteos[(string) $f['estado']] = (int) $f['n'];
    }
    $totalCuenta = array_sum($conteos);

    // El JOIN se repite en el conteo y en el listado para que no puedan
    // divergir. No puede descartar filas: fk_envio_documento es ON DELETE
    // CASCADE, asi que toda fila de la cola tiene su dte_emitido.
    $where  = 'WHERE q.cuenta_id = :c' . ($estado !== '' ? ' AND q.estado = :estado' : '');
    $params = [':c' => $cuentaId] + ($estado !== '' ? [':estado' => $estado] : []);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM dte_envio_correo q JOIN dte_emitido e ON e.id = q.dte_emitido_id ' . $where
    );
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT q.id, q.destinatario, q.estado, q.intentos, q.ultimo_error, q.enviado_at, q.created_at, '
        . '       e.tipo_dte, e.folio, e.receptor_rut '
        . 'FROM dte_envio_correo q JOIN dte_emitido e ON e.id = q.dte_emitido_id '
        . $where . ' ORDER BY q.created_at DESC, q.id DESC LIMIT :lim OFFSET :off'
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    // LIMIT/OFFSET van con PARAM_INT explicito: como string, MySQL los rechaza.
    $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
    $stmt->execute();

    vista('correos-listado', [
        'items'        => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'conteos'      => $conteos,
        'totalCuenta'  => $totalCuenta,
        'total'        => $total,
        'pagina'       => $pagina,
        'totalPaginas' => max(1, (int) ceil($total / $porPagina)),
        'estado'       => $estado,
        'flash'        => flashTomar(),
        'navActivo'    => 'ventas.correos',
    ]);
}

/**
 * Devuelve una fila a la cola para que el runner la vuelva a tomar.
 *
 * POR QUE TAMBIEN RESETEA intentos, y no solo el estado: el runner toma las
 * filas en 'pendiente' y las 'error' que sigan bajo el tope de 3 intentos. Una
 * fila que agoto sus intentos y solo cambiara de estado volveria a quedar
 * trabada en cuanto fallara una vez mas. Reintentar es empezar de cero.
 *
 * ultimo_error NO se borra a proposito: es el ultimo diagnostico conocido y
 * sigue siendo util mientras la fila espera. El proximo intento lo reescribe si
 * vuelve a fallar, o lo deja en NULL si sale bien (ver
 * PreparadorEnvio::registrarResultado).
 *
 * AISLAMIENTO POR TENANT: el cuenta_id va en el WHERE junto al id, no en un if
 * previo. Un id de otra cuenta afecta CERO filas y no hay forma de que un tenant
 * toque la cola de otro cambiando un numero en el formulario.
 */
function handleCorreoReintentarPost(int $envioId): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    exigirProduccionCompleto($pdo, $cuentaId);

    $stmt = $pdo->prepare(
        "UPDATE dte_envio_correo SET estado = 'pendiente', intentos = 0 "
        . 'WHERE id = :id AND cuenta_id = :c'
    );
    $stmt->execute([':id' => $envioId, ':c' => $cuentaId]);

    if ($stmt->rowCount() === 1) {
        flashSet('exito', "Envio {$envioId} devuelto a la cola. Sale en los proximos minutos.");
    } else {
        // Mismo mensaje para "no existe" y "es de otra cuenta": no se le confirma
        // a nadie que un id ajeno exista.
        flashSet('error', "No se pudo reintentar el envio {$envioId}.");
    }

    redirigirPrg('/ventas/correos');
}

/**
 * Devuelve a la cola TODAS las filas en error de la cuenta.
 *
 * Es UNA sola sentencia con valores constantes, asi que no hay nada que agrupar
 * ni trocear: el caso caro es el de la rebusca de destinatarios, donde cada fila
 * lleva un valor distinto (ver CORREO_REBUSCA_MAX_CORREOS).
 *
 * Apunta a la CUENTA ENTERA, no a la pagina visible ni al filtro activo. Por eso
 * el boton lleva el numero en la etiqueta: es la unica forma de que diga de
 * verdad cuanto va a mover.
 *
 * AISLAMIENTO POR TENANT: cuenta_id va en el WHERE y sale de Auth::cuentaId(),
 * o sea de la SESION, nunca de la peticion. En una sentencia que toca muchas
 * filas de una vez esto no es una formalidad: no hay ningun dato del formulario
 * que pueda ensanchar el alcance del UPDATE.
 */
function handleCorreosReintentarFallidosPost(): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    exigirProduccionCompleto($pdo, $cuentaId);

    $stmt = $pdo->prepare(
        "UPDATE dte_envio_correo SET estado = 'pendiente', intentos = 0 "
        . "WHERE cuenta_id = :c AND estado = 'error'"
    );
    $stmt->execute([':c' => $cuentaId]);
    $n = $stmt->rowCount();

    if ($n === 0) {
        flashSet('error', 'No habia envios con error para reintentar.');
    } else {
        flashSet('exito', sprintf(
            '%d envio%s %s a la cola. El envio se hace de a poco: hay un tope diario, '
            . 'asi que pueden repartirse en varios dias.',
            $n,
            $n === 1 ? '' : 's',
            $n === 1 ? 'devuelto' : 'devueltos'
        ));
    }

    redirigirPrg('/ventas/correos');
}

/**
 * Busca en el maestro de clientes el correo del receptor de cada fila, y deja
 * en 'pendiente' las que encuentran uno.
 *
 * MISMO PRECEDENTE QUE resolverRazonSocialReceptores(): los RUT se normalizan EN
 * LECTURA y el cruce con el maestro se hace EN PHP, sobre un mapa. NUNCA un JOIN
 * entre dte_emitido y cliente: viven en las dos familias de collation del
 * esquema (utf8mb4_0900_ai_ci la del motor, utf8mb4_unicode_ci la del panel) y
 * unirlas por una columna de texto revienta con "Illegal mix of collations".
 *
 * Normalizar es obligatorio y no cosmetico: armarDocumentoEmision() de M3 solo
 * hace trim() antes de mandar el RUT al motor, asi que dte_emitido.receptor_rut
 * puede traer puntos mientras cliente.rut_cliente siempre esta canonico.
 *
 * NO TOCA intentos, a diferencia del reintento: 'sin_destinatario' solo lo
 * escribe EncoladorCorreo::encolarUno() al encolar, y el runner nunca selecciona ese
 * estado, asi que esas filas tienen intentos = 0 desde siempre. No hay nada que
 * resetear.
 *
 * La usan los DOS botones -- el de una fila y el masivo -- para que no puedan
 * divergir: lo unico que cambia entre ellos es cuantas filas se le pasan.
 *
 * @param list<array<string,mixed>> $filas cada una con 'id' (de la cola) y 'receptor_rut'
 *
 * @return array{resueltas:int, sinCorreo:int, pospuestas:int, correos:array<int,string>}
 */
function reresolverDestinatarios(PDO $pdo, int $cuentaId, array $filas): array
{
    $ruts = [];
    foreach ($filas as $f) {
        $ruts[] = Rut::normalizar((string) $f['receptor_rut']);
    }
    $porRut = clienteRepo()->buscarPorRuts($cuentaId, $ruts);

    // SE AGRUPAN LOS IDS POR CORREO RESUELTO. Muchas filas comparten receptor,
    // asi que esto convierte N sentencias en una por correo distinto: 10.000
    // filas de 200 receptores son 200 UPDATE, no 10.000.
    $porCorreo = [];
    $correos   = [];
    foreach ($filas as $f) {
        $email = trim((string) ($porRut[Rut::normalizar((string) $f['receptor_rut'])]['email'] ?? ''));
        // Misma validacion de formato que EncoladorCorreo::encolarUno(): un correo mal
        // escrito en el maestro se trata como si no estuviera. Dejarlo pasar solo
        // cambiaria 'sin_destinatario' por un 'error' seguro en el proximo envio.
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $porCorreo[$email][]     = (int) $f['id'];
        $correos[(int) $f['id']] = $email;
    }

    // El tope se aplica sobre los correos distintos, que es lo que cuesta. Lo
    // que queda afuera se informa; no se recorta en silencio.
    $pospuestas = 0;
    if (count($porCorreo) > CORREO_REBUSCA_MAX_CORREOS) {
        foreach (array_slice($porCorreo, CORREO_REBUSCA_MAX_CORREOS, null, true) as $ids) {
            $pospuestas += count($ids);
        }
        $porCorreo = array_slice($porCorreo, 0, CORREO_REBUSCA_MAX_CORREOS, true);
    }

    // UNA transaccion para todo: es lo que baja el costo 8x, porque el fsync del
    // commit se paga una vez y no una por sentencia.
    $resueltas = 0;
    $pdo->beginTransaction();
    try {
        foreach ($porCorreo as $email => $ids) {
            // Se trocea la lista de ids: son numericos y de la propia cola, asi
            // que el IN no toca el problema de collations, pero una lista sin
            // limite armaria una sentencia de tamaño arbitrario.
            foreach (array_chunk($ids, 1000) as $trozo) {
                $marcadores = implode(',', array_fill(0, count($trozo), '?'));
                $stmt = $pdo->prepare(
                    "UPDATE dte_envio_correo SET destinatario = ?, estado = 'pendiente' "
                    . "WHERE cuenta_id = ? AND estado = 'sin_destinatario' AND id IN ({$marcadores})"
                );
                $stmt->execute([$email, $cuentaId, ...$trozo]);
                $resueltas += $stmt->rowCount();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();

        throw $e;
    }

    return [
        'resueltas'  => $resueltas,
        'sinCorreo'  => count($filas) - count($correos),
        'pospuestas' => $pospuestas,
        'correos'    => $correos,
    ];
}

/**
 * Busca el correo del receptor de UNA fila sin destinatario.
 *
 * El estado va en el WHERE junto al id y al cuenta_id: si la fila no existe, es
 * de otra cuenta, o ya dejo de estar en 'sin_destinatario', la consulta no
 * devuelve nada y el mensaje es el mismo para los tres casos.
 */
function handleCorreoBuscarDestinatarioPost(int $envioId): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    exigirProduccionCompleto($pdo, $cuentaId);

    $stmt = $pdo->prepare(
        'SELECT q.id, e.receptor_rut FROM dte_envio_correo q '
        . 'JOIN dte_emitido e ON e.id = q.dte_emitido_id '
        . "WHERE q.id = :id AND q.cuenta_id = :c AND q.estado = 'sin_destinatario' LIMIT 1"
    );
    $stmt->execute([':id' => $envioId, ':c' => $cuentaId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($fila === false) {
        flashSet('error', "No se pudo buscar el correo del envio {$envioId}.");
        redirigirPrg('/ventas/correos');
    }

    $r     = reresolverDestinatarios($pdo, $cuentaId, [$fila]);
    $email = $r['correos'][$envioId] ?? null;

    if ($email !== null && $r['resueltas'] === 1) {
        flashSet('exito', sprintf(
            'Envio %d: se encontro %s en el maestro de clientes. Queda en cola y sale en los proximos minutos.',
            $envioId,
            $email
        ));
    } elseif ($email !== null) {
        // Se encontro el correo pero el UPDATE no toco nada: la fila dejo de
        // estar en 'sin_destinatario' entre la lectura y la escritura.
        flashSet('error', "Envio {$envioId}: cambio de estado mientras se buscaba el correo. Vuelve a mirarlo.");
    } else {
        flashSet('error', sprintf(
            'Envio %d: el receptor %s no tiene correo en el maestro de clientes. Sigue sin destinatario.',
            $envioId,
            (string) $fila['receptor_rut']
        ));
    }

    redirigirPrg('/ventas/correos');
}

/**
 * Busca el correo de TODAS las filas sin destinatario de la cuenta.
 *
 * Se leen todas, sin LIMIT: leerlas es barato (medido, 33 ms para 10.000 filas,
 * mas 9 ms de la unica consulta al maestro) y el tope de trabajo se aplica
 * despues, sobre los correos distintos. Ver CORREO_REBUSCA_MAX_CORREOS.
 */
function handleCorreosBuscarDestinatariosPost(): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    exigirProduccionCompleto($pdo, $cuentaId);

    $stmt = $pdo->prepare(
        'SELECT q.id, e.receptor_rut FROM dte_envio_correo q '
        . 'JOIN dte_emitido e ON e.id = q.dte_emitido_id '
        . "WHERE q.cuenta_id = :c AND q.estado = 'sin_destinatario' ORDER BY q.id"
    );
    $stmt->execute([':c' => $cuentaId]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($filas === []) {
        flashSet('error', 'No hay envios sin destinatario que revisar.');
        redirigirPrg('/ventas/correos');
    }

    $r = reresolverDestinatarios($pdo, $cuentaId, $filas);

    $mensaje = sprintf(
        'Se revisaron %d envios sin destinatario: %d quedaron en cola con el correo del maestro '
        . 'y %d siguen sin correo.',
        count($filas),
        $r['resueltas'],
        $r['sinCorreo']
    );
    if ($r['pospuestas'] > 0) {
        $mensaje .= sprintf(
            ' Quedan %d sin revisar por el tope de esta pasada; vuelve a pulsar el boton para seguir.',
            $r['pospuestas']
        );
    }
    if ($r['resueltas'] > 0) {
        $mensaje .= ' El envio se hace de a poco: hay un tope diario, asi que pueden repartirse en varios dias.';
    }

    flashSet($r['resueltas'] > 0 ? 'exito' : 'error', $mensaje);
    redirigirPrg('/ventas/correos');
}

function handleDocumentoEstadoSiiPost(int $tipoDte, int $folio): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirProduccionCompleto($pdo, $cuentaId);
    $keyServicio = obtenerKeyServicio($pdo, $cuentaId, $rutEmisor);
    $destino     = "/ventas/panel-emision/{$tipoDte}/{$folio}";

    try {
        $res = consultarEstadoSiiEnMotor($keyServicio, $tipoDte, $folio);
    } catch (Throwable $e) {
        error_log('panel documentos: fallo de conexion al consultar estado SII - ' . $e->getMessage());
        flashSet('error', 'No se pudo contactar el motor de emision.');
        redirigirPrg($destino);
    }

    if ($res['status'] === 200) {
        // EL VEREDICTO MANDA EL COLOR DEL FLASH. Antes esto era siempre 'exito',
        // asi que el panel decia en verde "Estado actualizado: RCT." despues de
        // un RECHAZO. Es la misma enfermedad que costo las 68 facturas exentas,
        // en version manual: la respuesta llegaba y se mostraba como si fuera
        // una buena noticia. La clasificacion es la misma que usa el runner
        // (RegistroVeredictoSii), para que el boton y el cron nunca discrepen.
        //
        // Y se informa CUANTOS documentos quedaron actualizados: el veredicto es
        // del sobre entero, y ver "20 documentos" es lo que hace visible que la
        // consulta no resolvio solo el que se estaba mirando.
        $estadoSii = (string) ($res['body']['estado'] ?? '-');
        $docs      = (int) ($res['body']['documentos'] ?? 0);
        $detalle   = trim((string) ($res['body']['glosa'] ?? ''));

        if ($estadoSii === 'sin_trackid') {
            // CASO APARTE, y no por prolijidad: 'sin_trackid' no es un veredicto
            // del SII sino la respuesta del motor cuando el documento no tiene
            // track que consultar. No se actualizo ninguna fila, asi que decir
            // "se actualizaron 0 documentos" seria confundir dos cosas
            // distintas -- "el SII no dijo nada bueno" y "no habia a quien
            // preguntarle". Va en rojo igual: un documento emitido sin track es
            // un problema, no una consulta normal.
            flashSet('error', 'Este documento no tiene Track ID: no hay envio que consultar en el SII.');
        } else {
            $mensaje = sprintf(
                'Estado actualizado: %s%s. %s',
                $estadoSii,
                $detalle !== '' ? ' (' . $detalle . ')' : '',
                $docs === 1
                    ? 'Se actualizo 1 documento de este envio.'
                    : sprintf('Se actualizaron %d documentos de este envio.', $docs)
            );

            flashSet(RegistroVeredictoSii::esRechazo($estadoSii) ? 'error' : 'exito', $mensaje);
        }
    } else {
        error_log('panel documentos: estado-sii respondio ' . $res['status'] . ' - ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        flashSet('error', (string) ($res['body']['error'] ?? 'No se pudo consultar el estado en el SII.'));
    }

    redirigirPrg($destino);
}

// ===========================================================================
//  Ventas > Carga masiva de notas de venta (M4).
//
//  Contexto (ver PASO 0/1 de M4): un cliente con sistema de reservas cobra
//  via Mercado Pago, emite boleta al momento y a fin de mes hay que anularla
//  (NC) y reemplazarla por factura -- unos 200-300 documentos/mes. El motor
//  (POST /api/v1/dte/lote) es todo-o-nada por sobre: si el envio falla, falla
//  entero. La idempotencia de NEGOCIO vive aqui, en nota_venta
//  (UNIQUE(cuenta_id, identificador_externo) contra recarga del Excel + columna
//  estado contra reintento de facturacion). Sin infraestructura de colas:
//  sincrono con set_time_limit() + polling sobre el mismo estado persistido.
//
//  ACTUALIZADO: el motor SI tiene idempotencia en el lote desde que
//  Idempotency-Key se volvio obligatoria ahi. Cubre un hueco que nota_venta no
//  podia cubrir sola: el corte de red DESPUES de que el SII acepto el envio,
//  donde el panel marca las notas en error sin saber que los documentos ya se
//  emitieron. Ver la derivacion de la clave en facturarSubLote().
// ===========================================================================

const NOTA_VENTA_ENCABEZADOS = [
    'identificador_externo', 'rut_receptor', 'razon_social_receptor', 'giro_receptor',
    'direccion_receptor', 'comuna_receptor', 'email_receptor', 'fecha_nota',
    'producto_servicio', 'cantidad', 'precio_unitario', 'exento',
    // Las dos columnas de pago van JUNTO A 'exento' y no al final del archivo:
    // son condiciones del documento, igual que la exencion, y quien llena el
    // Excel las lee seguidas en vez de tener que saltar por encima de las
    // columnas de anulacion de boleta, que casi siempre van vacias.
    'forma_pago', 'fecha_vencimiento',
    'folio_boleta_a_anular', 'fecha_boleta_a_anular',
];

/**
 * Los 14 encabezados ANTERIORES a la carga de forma de pago. Se conservan SOLO
 * para reconocer un archivo hecho con la plantilla vieja y decirlo con esas
 * palabras, en vez de soltar el mensaje generico de "los encabezados no
 * coinciden", que no le dice a nadie que lo que tiene que hacer es bajar la
 * plantilla otra vez.
 *
 * NO habilita un formato dual: el archivo viejo se RECHAZA igual. Aceptarlo
 * dejaria esas notas emitiendo sin FmaPago, o sea declarando credito en
 * silencio, que es exactamente lo que esta carga vino a evitar.
 */
const NOTA_VENTA_ENCABEZADOS_V1 = [
    'identificador_externo', 'rut_receptor', 'razon_social_receptor', 'giro_receptor',
    'direccion_receptor', 'comuna_receptor', 'email_receptor', 'fecha_nota',
    'producto_servicio', 'cantidad', 'precio_unitario', 'exento',
    'folio_boleta_a_anular', 'fecha_boleta_a_anular',
];

/**
 * Valores aceptados en la columna forma_pago, en el mismo estilo legible del
 * SI/NO de 'exento': lo que se escribe en la celda es una palabra, no el numero
 * crudo del SII. La traduccion a 1/2/3 (IdDoc/FmaPago) ocurre aqui y no en la
 * cabeza de quien llena el Excel.
 *
 * CREDITO va con y sin tilde porque quien escribe en español pone la tilde, y
 * rechazar "CRÉDITO" seria un rechazo por ortografia. La comparacion usa
 * mb_strtoupper (no strtoupper, que no es multibyte y dejaria la e con tilde
 * intacta al pasar a mayusculas).
 */
const NOTA_VENTA_FORMAS_PAGO = [
    'CONTADO'   => 1,
    'CREDITO'   => 2,
    'CRÉDITO'   => 2,
    'SIN COSTO' => 3,
];

/** Limite de sanidad: sin fuente oficial de un tope menor (ver PASO 1 de M4,
 *  el XSD del SII permite hasta 2000 <DTE> por sobre); esto es solo para que
 *  un archivo enorme no reviente PHP por memoria, acotado ademas por un
 *  IReadFilter al leer (ver leerFilasExcelCargaMasiva()). */
const NOTA_VENTA_MAX_FILAS = 5000;

/** Documentos por sub-lote de facturacion masiva. Sin limite oficial menor
 *  encontrado (ver PASO 1 de M4); valor conservador por gestion de riesgo
 *  (el lote del motor es todo-o-nada), ajustable. */
const FACTURACION_MASIVA_SUBLOTE = 20;

/** Minutos sin resolverse para considerar una nota 'en_proceso' abandonada
 *  (pestana cerrada a mitad de un sub-lote, ver PASO 3 de M4) y devolverla a
 *  'pendiente' automaticamente. Mayor que cualquier timeout HTTP razonable
 *  de un sub-lote real (motor+SII), para no recuperar una nota que en
 *  realidad sigue siendo procesada por una request lenta legitima. */
const FACTURACION_MASIVA_RECUPERAR_MINUTOS = 5;

/** Filas de la plantilla que vienen con formato de fecha preaplicado. Cubre de
 *  sobra una carga mensual tipica (200-300 filas); mas abajo la celda queda sin
 *  formato y el valor cae al parseo de texto, que igual la acepta. */
const NOTA_VENTA_PLANTILLA_FILAS_FORMATEADAS = 1000;

/** Genera el .xlsx vacio con los encabezados de la plantilla, directo a la
 *  salida HTTP (nunca toca disco propio, igual criterio que las descargas
 *  de PDF/XML de M5).
 *
 *  Las dos columnas de fecha (H fecha_nota, N fecha_boleta_a_anular) salen con
 *  formato de FECHA 'yyyy-mm-dd', no con formato de texto. La diferencia
 *  importa:
 *
 *    - Con formato de fecha, lo que el usuario escribe lo convierte Excel a su
 *      numero de serie interno y el lector lo recupera exacto, sin depender del
 *      idioma del Excel. Ademas se ve al tiro si Excel NO lo reconocio como
 *      fecha: queda alineado a la izquierda como texto.
 *    - Con formato de TEXTO el valor queda literal, pero entonces toda fecha
 *      depende del parseo de texto (que para DD/MM vs MM/DD es ambiguo), Excel
 *      marca las celdas con el triangulo verde de "numero guardado como texto",
 *      y pegar una fecha real dentro deja un numero de serie crudo (46228) que
 *      el validador rechazaria.
 *
 *  Por eso: formato de fecha, que empuja el dato al camino exacto.
 */
function handlePlantillaExcelGet(): void
{
    $libro = new Spreadsheet();
    $hoja  = $libro->getActiveSheet();
    $hoja->setTitle('Notas de venta');
    $hoja->fromArray(NOTA_VENTA_ENCABEZADOS, null, 'A1');

    // TRES columnas de fecha desde que existe el vencimiento. Las letras se
    // corrieron al insertar forma_pago/fecha_vencimiento antes de las de boleta:
    //   H fecha_nota  ·  N fecha_vencimiento  ·  P fecha_boleta_a_anular
    $ultima = NOTA_VENTA_PLANTILLA_FILAS_FORMATEADAS + 1;
    foreach (['H', 'N', 'P'] as $col) {
        $hoja->getStyle("{$col}2:{$col}{$ultima}")
            ->getNumberFormat()
            ->setFormatCode('yyyy-mm-dd');
    }

    // Los formatos aceptados quedan a la vista al pasar por el encabezado. Va
    // como comentario y no como celda extra ni hoja aparte: cualquier contenido
    // adicional en la fila 1 rompe la comparacion exacta de encabezados que
    // hace leerFilasExcelCargaMasiva().
    $ayuda = 'Formatos aceptados: ' . FechaExcel::FORMATOS
        . '. Lo mas seguro es escribir la fecha y dejar que Excel la reconozca'
        . ' (queda alineada a la derecha).';
    $hoja->getComment('H1')->getText()->createText($ayuda);
    $hoja->getComment('P1')->getText()->createText($ayuda . ' Dejar vacia si la nota no anula una boleta.');

    // Las dos columnas nuevas explican SUS valores y su regla en el propio
    // encabezado, que es donde mira quien esta llenando el archivo.
    $hoja->getComment('M1')->getText()->createText(
        'Obligatorio en todas las filas. Valores: CONTADO, CREDITO o SIN COSTO.'
        . ' No se puede dejar vacia: si el documento no informa forma de pago,'
        . ' el SII lo toma como CREDITO.'
    );
    $hoja->getComment('N1')->getText()->createText(
        'Obligatoria SOLO si forma_pago es CREDITO, y en ese caso no se puede omitir.'
        . ' Dejar vacia con CONTADO o SIN COSTO. ' . $ayuda
    );

    $hoja->getStyle('A1:P1')->getFont()->setBold(true);
    $hoja->freezePane('A2');
    foreach (range('A', 'P') as $col) {
        $hoja->getColumnDimension($col)->setAutoSize(true);
    }

    $libro->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="plantilla_notas_venta.xlsx"');
    (new XlsxWriter($libro))->save('php://output');
    exit;
}

/**
 * Lee un .xlsx de carga masiva desde su tmp_name (NUNCA se copia a disco
 * propio, se lee directo del archivo temporal que ya crea PHP para el
 * upload -- mismo criterio que file_get_contents($archivo['tmp_name']) en
 * CAF/certificado). El IReadFilter acota la memoria usada por PhpSpreadsheet
 * a NOTA_VENTA_MAX_FILAS+2 filas SIN IMPORTAR cuantas traiga el archivo real
 * -- la validacion de "demasiadas filas" ocurre ANTES de intentar leer todo
 * el contenido de cada fila.
 *
 * @return list<array<string,string>> filas no vacias, como array asociativo
 *                                     clave=>valor segun NOTA_VENTA_ENCABEZADOS
 *
 * @throws RuntimeException si el archivo no se puede leer, los encabezados no
 *                          coinciden con la plantilla, o excede el limite de filas
 */
function leerFilasExcelCargaMasiva(string $rutaArchivo): array
{
    $limite = NOTA_VENTA_MAX_FILAS + 2; // +1 encabezado, +1 para poder detectar el excedente

    $filtro = new class ($limite) implements IReadFilter {
        public function __construct(private readonly int $limite)
        {
        }

        public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
        {
            return $row <= $this->limite;
        }
    };

    try {
        $reader = new XlsxReader();
        $reader->setReadFilter($filtro);
        $libro = $reader->load($rutaArchivo);
    } catch (Throwable $e) {
        throw new RuntimeException('El archivo no es un .xlsx valido.', 0, $e);
    }

    // Lectura celda por celda en vez de toArray(..., formatData: true).
    //
    // POR QUE: con formatData el valor de una celda de FECHA sale ya
    // convertido a texto usando el formato de visualizacion del archivo, que
    // depende del locale de quien lo creo. Una celda que en pantalla decia
    // 2026-07-25 llegaba aca como "7/25/2025" y la validacion la rechazaba.
    //
    // Una celda de fecha de Excel guarda un NUMERO DE SERIE, no un texto: leer
    // ese numero y convertirlo con Date::excelToDateTimeObject() da la fecha
    // exacta, sin depender del formato ni del idioma. El resto de las celdas
    // se sigue leyendo con getFormattedValue(), que es exactamente lo que
    // hacia toArray() antes: este cambio NO altera como se leen los montos,
    // las cantidades ni los textos.
    $hoja = $libro->getActiveSheet();

    // El rectangulo se acota a los datos REALES, igual que hacia toArray():
    // getRowIterator() por si solo recorre todo el rango pedido aunque las
    // filas no existan, y el iterador de celdas sin columna final se detiene
    // en la ultima columna DE CADA FILA (dejaria filas de largo distinto y
    // array_combine fallaria). El min() con $limite conserva la deteccion de
    // "archivo demasiado grande": el IReadFilter ya no cargo mas alla de ahi.
    $ultimaFila    = min($hoja->getHighestDataRow(), $limite);
    $ultimaColumna = $hoja->getHighestDataColumn();

    $filasCrudas = [];
    foreach ($hoja->getRowIterator(1, $ultimaFila) as $fila) {
        $celdas = $fila->getCellIterator('A', $ultimaColumna);
        $celdas->setIterateOnlyExistingCells(false);
        $valores = [];
        foreach ($celdas as $celda) {
            $valores[] = FechaExcel::esCeldaDeFecha($celda)
                ? FechaExcel::aIso($celda)
                : $celda->getFormattedValue();
        }
        $filasCrudas[] = $valores;
    }
    if ($filasCrudas === []) {
        throw new RuntimeException('El archivo esta vacio.');
    }

    $encabezados = array_map(static fn ($v): string => trim((string) $v), array_shift($filasCrudas));
    if ($encabezados !== NOTA_VENTA_ENCABEZADOS) {
        // La plantilla ANTERIOR se reconoce y se nombra. Un archivo hecho con
        // ella no tiene nada malformado: simplemente le faltan las dos columnas
        // de pago, y el usuario necesita saber ESO y no "los encabezados no
        // coinciden", que no dice que hacer.
        if ($encabezados === NOTA_VENTA_ENCABEZADOS_V1) {
            throw new RuntimeException(
                'Este archivo usa la plantilla anterior, sin las columnas "forma_pago" y "fecha_vencimiento". '
                . 'Descarga la plantilla nueva y vuelve a cargar los datos: la forma de pago ahora es obligatoria '
                . 'en cada fila, porque un documento que no la informa el SII lo toma como credito.'
            );
        }
        throw new RuntimeException(
            'Los encabezados del archivo no coinciden con la plantilla. Descarga la plantilla y no cambies el orden ni los nombres de columna.'
        );
    }

    if (count($filasCrudas) > NOTA_VENTA_MAX_FILAS) {
        throw new RuntimeException(
            'El archivo tiene mas de ' . NOTA_VENTA_MAX_FILAS . ' filas de datos. Divide la carga en archivos mas chicos.'
        );
    }

    $filas = [];
    foreach ($filasCrudas as $filaCruda) {
        $vacia = true;
        foreach ($filaCruda as $v) {
            if (trim((string) $v) !== '') {
                $vacia = false;
                break;
            }
        }
        if ($vacia) {
            continue; // fila 100% vacia (comun al final de un Excel exportado): se ignora, no es un error
        }
        $filas[] = array_combine(NOTA_VENTA_ENCABEZADOS, array_map(static fn ($v): string => trim((string) $v), $filaCruda));
    }

    return $filas;
}

/**
 * Valida y normaliza UNA fila cruda del Excel. NO escribe en la BD (eso lo
 * hace el llamador en la pasada de guardado): solo valida y, si la fila es
 * valida, resuelve el cliente por RUT (resolverClientePorRut(), reusado tal
 * cual de M2/M3 -- ya cubre "inactivo -> reactivar en vez de duplicar").
 *
 * $externosVistos es un Set (clave=>true) que el llamador mantiene ENTRE
 * llamadas para detectar identificador_externo repetido DENTRO del mismo
 * archivo. El Set de RUTs-nuevos-repetidos NO hace falta aqui: no es un
 * error de validacion (un mismo cliente puede tener 2 reservas en el mismo
 * Excel), el dedupe de creacion se resuelve en la pasada de guardado.
 *
 * @param array<string,string> $fila
 * @param array<string,bool> $externosVistos
 *
 * @return array{status:string, errores:list<string>, fila_original:array<string,string>, datos:?array<string,mixed>}
 */
function validarFilaCargaMasiva(array $fila, PDO $pdo, int $cuentaId, array &$externosVistos): array
{
    $errores = [];

    $externo = $fila['identificador_externo'];
    if ($externo === '') {
        $errores[] = 'identificador_externo es obligatorio';
    } elseif (isset($externosVistos[$externo])) {
        $errores[] = 'identificador_externo duplicado en este archivo';
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM nota_venta WHERE cuenta_id = :c AND identificador_externo = :e LIMIT 1');
        $stmt->execute([':c' => $cuentaId, ':e' => $externo]);
        if ($stmt->fetchColumn() !== false) {
            $errores[] = 'identificador_externo ya existe (esta nota ya se cargo en otro lote)';
        }
    }

    $resolucionCliente = resolverClientePorRut($cuentaId, $fila['rut_receptor']);
    if ($resolucionCliente['estado'] === 'rut_invalido') {
        $errores[] = 'rut_receptor invalido';
    }

    $fechaNota = FechaExcel::normalizar($fila['fecha_nota']);
    if ($fechaNota === null) {
        $errores[] = $fila['fecha_nota'] === ''
            ? 'fecha_nota es obligatoria (' . FechaExcel::FORMATOS . ')'
            : 'fecha_nota no es una fecha valida. Formatos aceptados: ' . FechaExcel::FORMATOS;
    }

    if ($fila['producto_servicio'] === '') {
        $errores[] = 'producto_servicio es obligatorio';
    }

    $cantidadRaw = $fila['cantidad'];
    if ($cantidadRaw === '' || ! is_numeric($cantidadRaw) || (float) $cantidadRaw <= 0) {
        $errores[] = 'cantidad debe ser un numero mayor que 0';
    }

    $precioRaw = $fila['precio_unitario'];
    if ($precioRaw === '' || ! is_numeric($precioRaw) || (float) $precioRaw < 0) {
        $errores[] = 'precio_unitario debe ser un numero mayor o igual a 0';
    }

    $exentoRaw = strtoupper($fila['exento']);
    if ($exentoRaw !== '' && ! in_array($exentoRaw, ['SI', 'NO'], true)) {
        $errores[] = 'exento debe ser SI, NO o quedar vacio';
    }
    $exento = $exentoRaw === 'SI';

    // FORMA DE PAGO Y VENCIMIENTO: AQUI SOLO SE PARSEAN, NO SE RECHAZAN.
    //
    // Y es a proposito. Todo lo demas de esta funcion marca la FILA como
    // erronea y deja que el archivo se cargue con esa fila apartada. Estas dos
    // columnas, en cambio, rechazan el ARCHIVO COMPLETO -- mismo criterio que
    // las dos validaciones de facturas exentas --, y esa decision necesita ver
    // todas las filas juntas, asi que vive en handleCargaMasivaPost(). Si aqui
    // se agregara a $errores, la fila quedaria apartada y el archivo entraria
    // igual, que es justo lo contrario de lo pedido.
    //
    // Se conserva el valor CRUDO ademas del parseado para que el mensaje de
    // rechazo pueda decir que escribio el usuario.
    $formaPagoRaw = trim($fila['forma_pago']);
    $formaPago    = NOTA_VENTA_FORMAS_PAGO[mb_strtoupper($formaPagoRaw, 'UTF-8')] ?? null;

    $vencimientoRaw = trim($fila['fecha_vencimiento']);
    $vencimiento    = $vencimientoRaw !== '' ? FechaExcel::normalizar($vencimientoRaw) : null;

    $folioBoletaRaw = $fila['folio_boleta_a_anular'];
    $fechaBoletaRaw = $fila['fecha_boleta_a_anular'];
    $folioBoleta    = null;
    $fechaBoleta    = null;
    if ($folioBoletaRaw !== '') {
        if (! is_numeric($folioBoletaRaw) || (int) $folioBoletaRaw <= 0) {
            $errores[] = 'folio_boleta_a_anular debe ser un numero entero mayor que 0';
        } else {
            $folioBoleta = (int) $folioBoletaRaw;
        }
        $fechaBoletaNormalizada = FechaExcel::normalizar($fechaBoletaRaw);
        if ($fechaBoletaNormalizada === null) {
            $errores[] = $fechaBoletaRaw === ''
                ? 'fecha_boleta_a_anular es obligatoria cuando viene folio_boleta_a_anular (' . FechaExcel::FORMATOS . ')'
                : 'fecha_boleta_a_anular no es una fecha valida. Formatos aceptados: ' . FechaExcel::FORMATOS;
        } else {
            $fechaBoleta = $fechaBoletaNormalizada;
        }
    }

    // Datos del receptor: si el cliente existe en el maestro, se usan SUS
    // datos (la fila los ignora); si es nuevo, la fila EXIGE razon social,
    // giro, direccion y comuna (el motor los exige al emitir, ver
    // validarDocumentoDte() en public/index.php).
    $receptorRazonSocial = $fila['razon_social_receptor'];
    $receptorGiro        = $fila['giro_receptor'];
    $receptorDireccion   = $fila['direccion_receptor'];
    $receptorComuna      = $fila['comuna_receptor'];
    $receptorEmail       = $fila['email_receptor'];

    // EL CORREO ES ACCESORIO Y NUNCA FRENA UNA FACTURA. Se valida el formato
    // igual que en el ABM de clientes (handleClientePost()), pero un correo mal
    // escrito NO agrega un error: se descarta y la fila sigue su curso. Emitir
    // es una obligacion legal con folio comprometido; el correo es un dato de
    // entrega. Es lo contrario del fail-fast que se aplica a folios y montos, y
    // es deliberado.
    if ($receptorEmail !== '' && ! filter_var($receptorEmail, FILTER_VALIDATE_EMAIL)) {
        $receptorEmail = '';
    }

    if ($resolucionCliente['estado'] === 'encontrado') {
        $cliente             = $resolucionCliente['cliente'];
        $receptorRazonSocial = $cliente['razon_social'];
        $receptorGiro        = (string) ($cliente['giro'] ?? '');
        $receptorDireccion   = (string) ($cliente['direccion'] ?? '');
        $receptorComuna      = (string) ($cliente['comuna'] ?? '');

        // EL MAESTRO GANA SOLO CUANDO TIENE VALOR -- y esto vale UNICAMENTE
        // para el correo. Los otros cuatro campos de arriba conservan el
        // maestro-manda incondicional, que ahi es la conducta correcta: son
        // datos de identidad tributaria y el maestro es su fuente.
        //
        // El correo no. Antes esta linea era incondicional y pisaba con ''
        // cualquier correo que trajera el Excel cuando el maestro no tenia
        // ninguno. Como cliente.email solo se escribia al CREAR el cliente, un
        // cliente nacido sin correo no podia recibirlo nunca mas por ningun
        // camino: medido en produccion, 4 de 4 notas con receptor cayeron ahi.
        $emailMaestro = trim((string) ($cliente['email'] ?? ''));
        if ($emailMaestro !== '') {
            $receptorEmail = $emailMaestro;
        }

        // EL MAESTRO PUEDE ESTAR INCOMPLETO, Y ANTES ESO PASABA EN SILENCIO.
        //
        // cliente.giro, .direccion y .comuna son NULLABLES (ver el comentario
        // del propio esquema: "Nullable aqui; el motor lo exige al emitir"), y
        // validarCliente() del ABM no los exige. O sea que un cliente que ya
        // esta en el maestro puede no tenerlos. Hasta aqui esta rama copiaba el
        // vacio y la fila seguia su curso como si estuviera bien.
        //
        // LO QUE COSTABA: la nota quedaba 'pendiente' y el fallo aparecia recien
        // al emitir. Y el lote del motor es TODO-O-NADA por sobre -- valida
        // todo antes de asignar folios y devuelve 422 al primer documento malo
        // --, asi que facturarSubLote() marca en error EL SUB-LOTE COMPLETO:
        // hasta 20 notas caidas por un solo cliente al que le falta el giro. El
        // mensaje ademas decia "documentos[7].receptor.giro" y no de quien era.
        //
        // POR QUE ES ERROR Y NO ADVERTENCIA: sin esos tres campos el documento
        // NO SE PUEDE EMITIR. La matriz de obligatoriedad del Formato DTE v2.5
        // les da codigo 1 -- "dato obligatorio, debe estar siempre" -- tanto en
        // factura (33) como en factura exenta (34): GiroRecep campo 57,
        // DirRecep campo 60, CmnaRecep campo 61. No es un criterio nuestro.
        //
        // Se nombran el RUT y los campos que faltan porque el usuario tiene que
        // poder ir a arreglarlo: el numero de fila solo no le dice a que cliente
        // del maestro entrar.
        $faltantes = [];
        foreach (['giro' => $receptorGiro, 'direccion' => $receptorDireccion, 'comuna' => $receptorComuna] as $campo => $valor) {
            if (trim($valor) === '') {
                $faltantes[] = $campo;
            }
        }
        if ($faltantes !== []) {
            $errores[] = sprintf(
                'el cliente %s esta en tu maestro pero le falta %s; completalo en Maestros > Clientes antes de cargar (sin %s el SII no acepta la factura)',
                $resolucionCliente['rut'],
                implode(', ', $faltantes),
                count($faltantes) === 1 ? 'ese dato' : 'esos datos',
            );
        }
    } elseif ($resolucionCliente['estado'] === 'no_encontrado') {
        if ($receptorRazonSocial === '') {
            $errores[] = 'razon_social_receptor es obligatorio (cliente nuevo, no esta en tu maestro)';
        }
        if ($receptorGiro === '') {
            $errores[] = 'giro_receptor es obligatorio (cliente nuevo)';
        }
        if ($receptorDireccion === '') {
            $errores[] = 'direccion_receptor es obligatorio (cliente nuevo)';
        }
        if ($receptorComuna === '') {
            $errores[] = 'comuna_receptor es obligatorio (cliente nuevo)';
        }
    }

    if ($errores !== []) {
        return ['status' => 'error', 'errores' => $errores, 'fila_original' => $fila, 'datos' => null];
    }

    // Solo se marca "visto" si la fila quedo OK: un identificador invalido no
    // debe "reservar" el slot y ocultar el error real de una fila repetida
    // valida mas adelante.
    if ($externo !== '') {
        $externosVistos[$externo] = true;
    }

    $montoNeto = (float) $cantidadRaw * (float) $precioRaw;

    return [
        'status'        => 'ok',
        'errores'       => [],
        'fila_original' => $fila,
        'datos'         => [
            'identificador_externo' => $externo,
            'receptor_razon_social' => $receptorRazonSocial,
            'receptor_giro'         => $receptorGiro !== '' ? $receptorGiro : null,
            'receptor_direccion'    => $receptorDireccion !== '' ? $receptorDireccion : null,
            'receptor_comuna'       => $receptorComuna !== '' ? $receptorComuna : null,
            'receptor_email'        => $receptorEmail !== '' ? $receptorEmail : null,
            'fecha_nota'            => $fechaNota,
            'detalle'               => [[
                'nombre'         => $fila['producto_servicio'],
                'cantidad'       => (float) $cantidadRaw,
                'precioUnitario' => (float) $precioRaw,
                'exento'         => $exento,
            ]],
            'forma_pago'            => $formaPago,
            'forma_pago_raw'        => $formaPagoRaw,
            'fecha_vencimiento'     => $vencimiento,
            'fecha_vencimiento_raw' => $vencimientoRaw,
            'monto_estimado'        => (int) round($exento ? $montoNeto : $montoNeto * 1.19),
            'boleta_ref_tipo'       => $folioBoleta !== null ? 39 : null,
            'boleta_ref_folio'      => $folioBoleta,
            'boleta_ref_fecha'      => $fechaBoleta,
            'cliente_resolucion'    => $resolucionCliente,
        ],
    ];
}

function crearLoteCarga(PDO $pdo, int $cuentaId, int $usuarioId, string $nombreArchivo, int $totalFilas, int $filasValidas, int $filasError, int $tipoDte = 33): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO lote_carga (cuenta_id, usuario_id, nombre_archivo, total_filas, filas_validas, filas_error, tipo_dte) '
        . 'VALUES (:cuenta_id, :usuario_id, :nombre, :total, :validas, :errores, :tipo)'
    );
    $stmt->execute([
        ':cuenta_id' => $cuentaId,
        ':usuario_id' => $usuarioId,
        ':nombre'    => $nombreArchivo,
        ':total'     => $totalFilas,
        ':validas'   => $filasValidas,
        ':errores'   => $filasError,
        ':tipo'      => $tipoDte,
    ]);

    return (int) $pdo->lastInsertId();
}

/** @return list<array<string,mixed>> */
function listarLotesCarga(PDO $pdo, int $cuentaId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, nombre_archivo, total_filas, filas_validas, filas_error, created_at '
        . 'FROM lote_carga WHERE cuenta_id = :c ORDER BY created_at DESC, id DESC LIMIT 50'
    );
    $stmt->execute([':c' => $cuentaId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed>|null */
function obtenerLoteCarga(PDO $pdo, int $cuentaId, int $loteId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, nombre_archivo, total_filas, filas_validas, filas_error, created_at '
        . 'FROM lote_carga WHERE id = :id AND cuenta_id = :c LIMIT 1'
    );
    $stmt->execute([':id' => $loteId, ':c' => $cuentaId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    return $fila === false ? null : $fila;
}

/** @return list<array<string,mixed>> */
function listarNotasVentaDeLote(PDO $pdo, int $cuentaId, int $loteId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, identificador_externo, receptor_rut, receptor_razon_social, fecha_nota, monto_estimado, '
        . 'boleta_ref_folio, estado, error_mensaje, fila_original, resultado_documentos '
        . 'FROM nota_venta WHERE cuenta_id = :c AND lote_carga_id = :lote ORDER BY id ASC'
    );
    $stmt->execute([':c' => $cuentaId, ':lote' => $loteId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function crearNotaVentaValida(PDO $pdo, int $cuentaId, int $loteId, array $d): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO nota_venta '
        . '(cuenta_id, lote_carga_id, identificador_externo, receptor_rut, receptor_razon_social, '
        . ' receptor_giro, receptor_direccion, receptor_comuna, receptor_email, fecha_nota, detalle, '
        . " monto_estimado, tipo_dte, forma_pago, fecha_vencimiento, "
        . " boleta_ref_tipo, boleta_ref_folio, boleta_ref_fecha, estado) VALUES "
        . '(:cuenta_id, :lote_id, :externo, :rut, :razon, :giro, :dir, :comuna, :email, :fecha, '
        . " :detalle, :monto, :tipo, :fpago, :fvenc, :bref_tipo, :bref_folio, :bref_fecha, 'pendiente')"
    );
    $stmt->execute([
        ':cuenta_id'  => $cuentaId,
        ':lote_id'    => $loteId,
        ':externo'    => $d['identificador_externo'],
        ':rut'        => $d['receptor_rut'],
        ':razon'      => $d['receptor_razon_social'],
        ':giro'       => $d['receptor_giro'],
        ':dir'        => $d['receptor_direccion'],
        ':comuna'     => $d['receptor_comuna'],
        ':email'      => $d['receptor_email'],
        ':fecha'      => $d['fecha_nota'],
        ':detalle'    => json_encode($d['detalle'], JSON_UNESCAPED_UNICODE),
        ':monto'      => $d['monto_estimado'],
        // Denormalizado a proposito (migracion 025): el sub-lote se arma con un
        // conjunto libre de ids y puede mezclar archivos, asi que cada nota tiene
        // que bastarse a si misma para saber que emitir.
        ':tipo'       => (int) ($d['tipo_dte'] ?? 33),
        // NULL cuando no viene, y NO 2: aunque el SII lea el silencio como
        // credito, guardar un 2 que nadie eligio borraria la diferencia entre
        // "el usuario eligio credito" y "no se pregunto". Con esta entrega ya no
        // deberia llegar null por la carga masiva, pero el default se conserva
        // para no romper a ningun llamador que todavia no lo pase.
        ':fpago'      => $d['forma_pago'] ?? null,
        ':fvenc'      => $d['fecha_vencimiento'] ?? null,
        ':bref_tipo'  => $d['boleta_ref_tipo'],
        ':bref_folio' => $d['boleta_ref_folio'],
        ':bref_fecha' => $d['boleta_ref_fecha'],
    ]);
}

function crearNotaVentaError(PDO $pdo, int $cuentaId, int $loteId, array $filaOriginal, array $errores): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO nota_venta (cuenta_id, lote_carga_id, estado, error_mensaje, fila_original) '
        . "VALUES (:cuenta_id, :lote_id, 'error', :error, :original)"
    );
    $stmt->execute([
        ':cuenta_id' => $cuentaId,
        ':lote_id'   => $loteId,
        ':error'     => mb_substr(implode('; ', $errores), 0, 500),
        ':original'  => json_encode($filaOriginal, JSON_UNESCAPED_UNICODE),
    ]);
}

function handleCargaMasivaGet(): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    exigirProduccionCompleto($pdo, $cuentaId); // guard (redirige si falta emisor/cert/CAF prod)

    vista('carga-masiva-form', [
        'error'     => null,
        'lotes'     => listarLotesCarga($pdo, $cuentaId),
        'navActivo' => 'ventas.carga-masiva',
    ]);
}

function handleCargaMasivaPost(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $usuarioId = Auth::usuarioId();
    exigirProduccionCompleto($pdo, $cuentaId);

    $errorForm = static function (string $mensaje) use ($pdo, $cuentaId): never {
        vista('carga-masiva-form', [
            'error'     => $mensaje,
            'lotes'     => listarLotesCarga($pdo, $cuentaId),
            'navActivo' => 'ventas.carga-masiva',
        ]);
    };

    $archivo = $_FILES['archivo'] ?? null;
    if (
        ! is_array($archivo)
        || ! isset($archivo['error'], $archivo['tmp_name'], $archivo['name'])
        || $archivo['error'] !== UPLOAD_ERR_OK
        || ! is_uploaded_file($archivo['tmp_name'])
    ) {
        $errorForm('Debes seleccionar un archivo .xlsx valido.');
    }

    try {
        $filas = leerFilasExcelCargaMasiva($archivo['tmp_name']);
    } catch (Throwable $e) {
        $errorForm($e->getMessage());
    }

    if ($filas === []) {
        $errorForm('El archivo no tiene filas de datos (fuera del encabezado).');
    }

    $externosVistos = [];
    $items = [];
    foreach ($filas as $fila) {
        $items[] = validarFilaCargaMasiva($fila, $pdo, $cuentaId, $externosVistos);
    }

    // EL TIPO DEL ARCHIVO, del check de la pantalla. Marcado = todo el archivo
    // sale como factura exenta (34); sin marcar = como siempre (33).
    //
    // NO ES UNA COLUMNA DEL EXCEL a proposito: la plantilla YA tiene una columna
    // 'exento' POR LINEA, y una columna de tipo POR FILA seria un segundo
    // concepto solapado con el primero, capaz de contradecirlo (tipo 34 con
    // exento=NO en la misma fila). Un check por archivo no puede contradecir
    // nada: o el archivo entero es exento, o no lo es.
    $esExento = ! empty($_POST['tipo_exento']);
    $tipoDte  = $esExento ? 34 : 33;

    // --- LAS DOS VALIDACIONES DE ARCHIVO COMPLETO ------------------------------
    //
    // Rechazan ANTES de abrir la transaccion, o sea antes de crear el lote y
    // antes de crear ninguna nota. No queda nada a medias: no se llega a escribir
    // una sola fila.
    //
    // Se evaluan sobre las filas VALIDAS unicamente. Las filas con error ya
    // fueron rechazadas por validarFilaCargaMasiva() y ni siquiera tienen
    // 'datos': mirarlas aqui reventaria, y ademas no van a emitir nada.
    if ($esExento) {
        // 1. TODAS las lineas de TODAS las filas tienen que ser exentas.
        //
        //    MISMA REGLA QUE PROTEGE AL 34 UNITARIO en el motor
        //    (validarDocumentoDte, public/index.php): un 34 con una sola linea
        //    afecta hace que el builder emita MntNeto, TasaIVA e IVA dentro de un
        //    documento que no puede tenerlos -- resolverTotales() decide POR
        //    DATOS, no por tipo. El SII lo rechaza Y EL FOLIO QUEDA QUEMADO
        //    IGUAL. Aqui se atrapa en la carga para que ni siquiera llegue a
        //    existir la nota; el motor lo vuelve a validar por su cuenta, porque
        //    al cliente no se le cree nunca.
        $filasConAfecta = [];
        foreach ($items as $i => $item) {
            if ($item['status'] !== 'ok') {
                continue;
            }
            foreach ($item['datos']['detalle'] as $linea) {
                if (empty($linea['exento'])) {
                    $filasConAfecta[] = $i + 2; // +1 por el encabezado, +1 base 1
                    break;
                }
            }
        }
        if ($filasConAfecta !== []) {
            $errorForm(sprintf(
                'Marcaste el archivo como de facturas exentas, pero %s tiene lineas afectas (columna "exento" '
                . 'distinta de SI): fila%s %s. Una factura exenta no puede llevar ninguna linea afecta. '
                . 'No se cargo ninguna nota.',
                count($filasConAfecta) === 1 ? 'una fila' : count($filasConAfecta) . ' filas',
                count($filasConAfecta) === 1 ? '' : 's',
                implode(', ', array_slice($filasConAfecta, 0, 20)) . (count($filasConAfecta) > 20 ? ', ...' : '')
            ));
        }

        // 2. NINGUNA fila puede traer boleta_ref_folio.
        //
        //    POR QUE, Y ES TEMPORAL: una fila con boleta_ref_folio genera DOS
        //    documentos, la factura mas una nota de credito tipo 61 que anula la
        //    boleta. Esa NC hoy sale mal formada cuando el documento de al lado
        //    es un 34: SiiDirectoFacturador::anular() y el camino de totales
        //    explicitos fijan SIEMPRE 'MntNeto' e 'IVA', y para un 34 ambos valen
        //    cero; como resolverTotales() corta en seco al recibir totales
        //    explicitos (src/Sii/DteXmlBuilder.php), la proteccion por datos que
        //    funciona al emitir NO aplica ahi.
        //
        //    ESTA RESTRICCION SE LEVANTA cuando se arreglen esos totales. Es lo
        //    unico que hay que tocar aqui: borrar este bloque.
        $filasConBoleta = [];
        foreach ($items as $i => $item) {
            if ($item['status'] === 'ok' && ! empty($item['datos']['boleta_ref_folio'])) {
                $filasConBoleta[] = $i + 2;
            }
        }
        if ($filasConBoleta !== []) {
            $errorForm(sprintf(
                'Marcaste el archivo como de facturas exentas, pero %s trae folio de boleta a anular: fila%s %s. '
                . 'Todavia no se puede anular una boleta con una factura exenta. Sube esas filas en un archivo '
                . 'aparte, sin marcar. No se cargo ninguna nota.',
                count($filasConBoleta) === 1 ? 'una fila' : count($filasConBoleta) . ' filas',
                count($filasConBoleta) === 1 ? '' : 's',
                implode(', ', array_slice($filasConBoleta, 0, 20)) . (count($filasConBoleta) > 20 ? ', ...' : '')
            ));
        }
    }

    // --- LAS CUATRO VALIDACIONES DE PAGO, TAMBIEN DE ARCHIVO COMPLETO ---------
    //
    // Mismo criterio y mismo momento que las dos de exentas: rechazan ANTES de
    // abrir la transaccion, asi que no llega a existir ni el lote ni una sola
    // nota. Y sobre las filas VALIDAS unicamente: las que ya fallaron por otra
    // cosa no tienen 'datos'.
    //
    // POR QUE NO HAY VALOR POR DEFECTO. Formato DTE v2.5, pag. 14, campo 13: si
    // el documento no informa forma de pago, "se entendera que tiene valor 2
    // (Credito)". O sea que una celda vacia no es "sin dato": es credito elegido
    // en silencio por el SII. Poner un default aqui seria decidir por el usuario
    // exactamente lo que esta entrega vino a dejar de hacer.
    // VACIA y NO RECONOCIDA se cuentan aparte a proposito: "TRANSFERENCIA" no es
    // una celda que falta, es una que dice algo que no existe, y decirle al
    // usuario que "falta" cuando el escribio una palabra lo manda a buscar el
    // error donde no esta.
    $sinFormaPago = $formaPagoDesconocida = [];
    $conCreditoSinFecha = $conFechaSinCredito = $conFechaInvalida = [];
    foreach ($items as $i => $item) {
        if ($item['status'] !== 'ok') {
            continue;
        }
        $filaExcel = $i + 2; // +1 por el encabezado, +1 porque el usuario cuenta desde 1
        $d         = $item['datos'];

        if ($d['forma_pago'] === null) {
            if ($d['forma_pago_raw'] === '') {
                $sinFormaPago[] = (string) $filaExcel;
            } else {
                $formaPagoDesconocida[] = $filaExcel . " (\"{$d['forma_pago_raw']}\")";
            }
            continue; // sin forma de pago valida, las otras tres reglas no se pueden evaluar
        }
        if ($d['fecha_vencimiento_raw'] !== '' && $d['fecha_vencimiento'] === null) {
            $conFechaInvalida[] = $filaExcel . " (\"{$d['fecha_vencimiento_raw']}\")";
            continue;
        }
        if ($d['forma_pago'] === 2 && $d['fecha_vencimiento'] === null) {
            $conCreditoSinFecha[] = $filaExcel;
        }
        if ($d['forma_pago'] !== 2 && $d['fecha_vencimiento'] !== null) {
            $conFechaSinCredito[] = $filaExcel;
        }
    }

    $listar = static function (array $filas): string {
        return implode(', ', array_slice($filas, 0, 20)) . (count($filas) > 20 ? ', ...' : '');
    };
    if ($sinFormaPago !== []) {
        $errorForm(sprintf(
            'Falta la forma de pago en %d fila(s): %s. Es obligatoria en todas, y los valores aceptados son '
            . 'CONTADO, CREDITO o SIN COSTO. No se puede dejar vacia: un documento que no informa forma de pago '
            . 'el SII lo toma como credito. No se cargo ninguna nota.',
            count($sinFormaPago),
            $listar($sinFormaPago)
        ));
    }
    if ($formaPagoDesconocida !== []) {
        $errorForm(sprintf(
            'La forma de pago no se reconoce en %d fila(s): %s. Los unicos valores aceptados son CONTADO, '
            . 'CREDITO o SIN COSTO. No se cargo ninguna nota.',
            count($formaPagoDesconocida),
            $listar($formaPagoDesconocida)
        ));
    }
    if ($conFechaInvalida !== []) {
        $errorForm(sprintf(
            'La fecha de vencimiento no es una fecha valida en %d fila(s): %s. Formatos aceptados: %s. '
            . 'No se cargo ninguna nota.',
            count($conFechaInvalida),
            $listar($conFechaInvalida),
            FechaExcel::FORMATOS
        ));
    }
    if ($conCreditoSinFecha !== []) {
        $errorForm(sprintf(
            'Falta la fecha de vencimiento en %d fila(s) con forma de pago CREDITO: %s. Con credito es '
            . 'obligatoria: una factura a credito sin vencimiento no sirve para cobrar. No se cargo ninguna nota.',
            count($conCreditoSinFecha),
            $listar($conCreditoSinFecha)
        ));
    }
    if ($conFechaSinCredito !== []) {
        $errorForm(sprintf(
            'Hay fecha de vencimiento en %d fila(s) cuya forma de pago no es CREDITO: %s. El vencimiento solo '
            . 'aplica a credito; con CONTADO o SIN COSTO la columna va vacia. No se cargo ninguna nota.',
            count($conFechaSinCredito),
            $listar($conFechaSinCredito)
        ));
    }

    $totalValidas = count(array_filter($items, static fn (array $it): bool => $it['status'] === 'ok'));
    $totalErrores = count($items) - $totalValidas;

    $pdo->beginTransaction();
    try {
        $loteId = crearLoteCarga($pdo, $cuentaId, $usuarioId, $archivo['name'], count($items), $totalValidas, $totalErrores, $tipoDte);

        // RUT nuevo -> id de cliente ya creado EN ESTA MISMA carga (evita
        // crear el mismo cliente 2 veces si aparece en varias filas).
        $clienteIdPorRutNuevo = [];

        foreach ($items as $item) {
            if ($item['status'] === 'error') {
                crearNotaVentaError($pdo, $cuentaId, $loteId, $item['fila_original'], $item['errores']);
                continue;
            }

            $d   = $item['datos'];
            $res = $d['cliente_resolucion'];

            if ($res['estado'] === 'no_encontrado') {
                $rutNorm = $res['rut'];
                if (! isset($clienteIdPorRutNuevo[$rutNorm])) {
                    try {
                        $clienteIdPorRutNuevo[$rutNorm] = clienteRepo()->crear($cuentaId, [
                            'rut_cliente'  => $rutNorm,
                            'razon_social' => $d['receptor_razon_social'],
                            'giro'         => $d['receptor_giro'],
                            'direccion'    => $d['receptor_direccion'],
                            'comuna'       => $d['receptor_comuna'],
                            'email'        => $d['receptor_email'],
                        ]);
                    } catch (ClienteDuplicadoException) {
                        // Carrera improbable (otra request creo el mismo RUT
                        // entre la validacion y el guardado): se reusa el que
                        // ya quedo creado, no se aborta la carga por esto.
                        $existente = clienteRepo()->buscarPorRut($cuentaId, $rutNorm);
                        $clienteIdPorRutNuevo[$rutNorm] = $existente['id'] ?? 0;
                    }
                }
            } elseif ($res['estado'] === 'encontrado') {
                if ($res['cliente']['activo'] === false) {
                    clienteRepo()->activar($cuentaId, (int) $res['cliente']['id']);
                }

                // RELLENO DEL CORREO QUE FALTABA. Un cliente que nacio sin
                // correo no tenia forma de conseguirlo: crear() lo escribe una
                // sola vez y el ABM exige que alguien lo teclee a mano. Si el
                // Excel trae uno y el maestro esta vacio, se aprovecha aqui.
                //
                // Va dentro de la transaccion del lote, igual que el alta de
                // clientes nuevos de arriba: si la carga se cae, no queda un
                // maestro tocado por un lote que nunca existio.
                //
                // Este if es solo un filtro barato para no ir a la base al
                // pedo. La garantia de NO SOBRESCRITURA no esta aca sino en el
                // WHERE de rellenarEmailSiVacio(): $res['cliente'] es una foto
                // tomada en la pasada de validacion, y entre esa foto y este
                // UPDATE cabe otra request. Solo el motor puede resolverlo sin
                // ventana.
                if (trim((string) ($res['cliente']['email'] ?? '')) === '' && $d['receptor_email'] !== null) {
                    clienteRepo()->rellenarEmailSiVacio(
                        $cuentaId,
                        (int) $res['cliente']['id'],
                        (string) $d['receptor_email']
                    );
                }
            }

            crearNotaVentaValida($pdo, $cuentaId, $loteId, [
                'identificador_externo' => $d['identificador_externo'],
                'receptor_rut'          => $res['rut'],
                'receptor_razon_social' => $d['receptor_razon_social'],
                'receptor_giro'         => $d['receptor_giro'],
                'receptor_direccion'    => $d['receptor_direccion'],
                'receptor_comuna'       => $d['receptor_comuna'],
                'receptor_email'        => $d['receptor_email'],
                'fecha_nota'            => $d['fecha_nota'],
                'detalle'               => $d['detalle'],
                'monto_estimado'        => $d['monto_estimado'],
                'tipo_dte'              => $tipoDte,
                'forma_pago'            => $d['forma_pago'],
                'fecha_vencimiento'     => $d['fecha_vencimiento'],
                'boleta_ref_tipo'       => $d['boleta_ref_tipo'],
                'boleta_ref_folio'      => $d['boleta_ref_folio'],
                'boleta_ref_fecha'      => $d['boleta_ref_fecha'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('panel carga masiva: fallo al guardar el lote - ' . $e->getMessage());
        $errorForm('No se pudo guardar la carga. Intenta nuevamente.');
    }

    redirigirPrg("/ventas/carga-masiva/{$loteId}");
}

function handleCargaMasivaDetalleGet(int $loteId): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    exigirProduccionCompleto($pdo, $cuentaId);

    $lote = obtenerLoteCarga($pdo, $cuentaId, $loteId);
    if ($lote === null) {
        redirigir('/ventas/carga-masiva');
    }

    vista('carga-masiva-detalle', [
        'lote'      => $lote,
        'notas'     => listarNotasVentaDeLote($pdo, $cuentaId, $loteId),
        'navActivo' => 'ventas.carga-masiva',
    ]);
}

/** @return list<array<string,mixed>> */
function listarNotasVentaPendientes(PDO $pdo, int $cuentaId, ?string $rutFiltro): array
{
    $where  = "cuenta_id = :c AND estado = 'pendiente'";
    $params = [':c' => $cuentaId];
    if ($rutFiltro !== null && $rutFiltro !== '') {
        $where           .= ' AND receptor_rut LIKE :rut';
        $params[':rut']   = '%' . $rutFiltro . '%';
    }
    // tipo_dte SE TRAE PORQUE LA LISTA TIENE QUE MOSTRARLO. Esta consulta no
    // filtra por lote_carga_id: lista pendientes de TODA la cuenta, de cualquier
    // archivo. O sea que aqui conviven notas afectas y exentas, y el usuario
    // puede marcarlas juntas -- mezclar esta permitido por construccion. Sin la
    // columna a la vista, nadie puede saber que esta a punto de emitir.
    // forma_pago y fecha_vencimiento se traen por el MISMO motivo que tipo_dte:
    // esta lista mezcla notas de cualquier archivo, y quien selecciona un
    // conjunto tiene que poder ver que va a emitir. Ademas aqui conviven notas
    // NUEVAS (con forma de pago elegida) y VIEJAS (forma_pago NULL, cargadas
    // antes de que la columna se pidiera), y esas dos no son lo mismo.
    $stmt = $pdo->prepare(
        'SELECT id, identificador_externo, receptor_rut, receptor_razon_social, fecha_nota, monto_estimado, '
        . 'tipo_dte, forma_pago, fecha_vencimiento, '
        . "boleta_ref_folio FROM nota_venta WHERE {$where} ORDER BY fecha_nota ASC, id ASC LIMIT 500"
    );
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Notas 'pendiente' de un conjunto de ids, ordenadas por id (orden estable
 * entre llamadas sucesivas del mismo conjunto). $limite acota cuantas trae
 * (para pedir "el siguiente sub-lote"); null trae todas (para el chequeo de
 * folios sobre lo que falta procesar).
 *
 * @return list<array<string,mixed>>
 */
function obtenerNotasVentaPendientesPorIds(PDO $pdo, int $cuentaId, array $ids, ?int $limite = null): array
{
    if ($ids === []) {
        return [];
    }
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT * FROM nota_venta WHERE cuenta_id = ? AND estado = 'pendiente' AND id IN ({$marcadores}) ORDER BY id ASC";
    if ($limite !== null) {
        $sql .= ' LIMIT ?';
    }
    $stmt = $pdo->prepare($sql);
    $pos = 1;
    $stmt->bindValue($pos++, $cuentaId, PDO::PARAM_INT);
    foreach ($ids as $id) {
        $stmt->bindValue($pos++, $id, PDO::PARAM_INT);
    }
    if ($limite !== null) {
        $stmt->bindValue($pos, $limite, PDO::PARAM_INT);
    }
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Devuelve a 'pendiente' las notas 'en_proceso' de la cuenta que llevan mas
 * de FACTURACION_MASIVA_RECUPERAR_MINUTOS sin resolverse (ver updated_at,
 * migracion 018/020, ON UPDATE CURRENT_TIMESTAMP nativo) -- pestana cerrada a
 * mitad de un sub-lote, PASO 3 de M4. Se llama al abrir el selector y antes
 * de procesar un sub-lote nuevo, asi el usuario nunca ve una nota "trabada"
 * para siempre.
 */
function recuperarNotasVentaEnProcesoViejas(PDO $pdo, int $cuentaId): void
{
    $pdo->prepare(
        "UPDATE nota_venta SET estado = 'pendiente' "
        . "WHERE cuenta_id = :c AND estado = 'en_proceso' "
        . 'AND updated_at < (NOW() - INTERVAL ' . FACTURACION_MASIVA_RECUPERAR_MINUTOS . ' MINUTE)'
    )->execute([':c' => $cuentaId]);
}

/** @return list<array<string,mixed>> */
function obtenerNotasVentaPorIdsCualquierEstado(PDO $pdo, int $cuentaId, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, identificador_externo, receptor_rut, receptor_razon_social, estado, error_mensaje, resultado_documentos '
        . "FROM nota_venta WHERE cuenta_id = ? AND id IN ({$marcadores})"
    );
    $stmt->execute([$cuentaId, ...$ids]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{total:int,pendiente:int,en_proceso:int,facturada:int,error:int} */
function contarNotasVentaPorEstado(PDO $pdo, int $cuentaId, array $ids): array
{
    $conteo = ['pendiente' => 0, 'en_proceso' => 0, 'facturada' => 0, 'error' => 0];
    if ($ids === []) {
        return $conteo + ['total' => 0];
    }
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT estado, COUNT(*) AS n FROM nota_venta WHERE cuenta_id = ? AND id IN ({$marcadores}) GROUP BY estado"
    );
    $stmt->execute([$cuentaId, ...$ids]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $conteo[(string) $r['estado']] = (int) $r['n'];
    }
    $conteo['total'] = array_sum($conteo);

    return $conteo;
}

/**
 * Reclama 'en_proceso' SOLO las notas que en ESE INSTANTE siguen 'pendiente'
 * -- UPDATE...WHERE atomico con la condicion de estado incluida (id = ? AND
 * estado = 'pendiente'), UNA fila a la vez para saber exactamente cuales se
 * reclamaron de verdad (MySQL no devuelve que filas afecto un UPDATE con
 * IN(...), solo el conteo). Devuelve la lista de ids REALMENTE reclamados.
 *
 * Esto es lo que cierra la carrera contra recuperarNotasVentaEnProcesoViejas():
 * si dos procesos compiten por la misma nota (una recuperacion+reproceso
 * concurrente con el proceso original que la tenia en_proceso), el UPDATE de
 * uno de los dos afecta 0 filas (la condicion estado='pendiente' ya no se
 * cumple, porque el otro ya la reclamo primero) y ESE proceso la descarta acá
 * mismo, sin llamar nunca al motor por ella. Commit inmediato y corto, sin
 * transaccion envolvente: si la pestana se cierra antes de que el sub-lote
 * termine, la nota queda 'en_proceso' visible (y recuperable por
 * recuperarNotasVentaEnProcesoViejas()) en vez de perderse.
 *
 * @param list<int> $ids
 *
 * @return list<int> ids efectivamente reclamados (subconjunto de $ids)
 */
function marcarNotasVentaEnProceso(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $stmt = $pdo->prepare("UPDATE nota_venta SET estado = 'en_proceso' WHERE id = ? AND estado = 'pendiente'");
    $reclamados = [];
    foreach ($ids as $id) {
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 1) {
            $reclamados[] = $id;
        }
    }

    return $reclamados;
}

/**
 * UPDATE...WHERE atomico: solo marca 'error' las notas que SIGUEN
 * 'en_proceso' (condicion de estado en el WHERE). Si el conteo de filas
 * afectadas no coincide con lo esperado, alguna nota ya cambio de estado por
 * otra via (recuperada y reprocesada por otro proceso mientras esta llamada
 * estaba en curso) -- se deja constancia en el log en vez de sobreescribir en
 * silencio un resultado que ya quedo resuelto de otra forma.
 */
function marcarNotasVentaError(PDO $pdo, array $ids, string $mensaje): void
{
    if ($ids === []) {
        return;
    }
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $mensajeCorto = mb_substr($mensaje, 0, 500);
    $stmt = $pdo->prepare(
        "UPDATE nota_venta SET estado = 'error', error_mensaje = ? WHERE id IN ({$marcadores}) AND estado = 'en_proceso'"
    );
    $stmt->execute([$mensajeCorto, ...$ids]);
    if ($stmt->rowCount() !== count($ids)) {
        error_log(sprintf(
            'facturacion masiva: marcarNotasVentaError afecto %d de %d notas esperadas (ids: %s) -- alguna ya no estaba en_proceso (carrera con recuperacion/otro proceso)',
            $stmt->rowCount(),
            count($ids),
            implode(',', $ids)
        ));
    }
}

/**
 * UPDATE...WHERE atomico: solo marca 'facturada' si la nota SIGUE
 * 'en_proceso'. Si rowCount() da 0, la nota ya no estaba en_proceso (otro
 * proceso la reclamo primero via una carrera con la recuperacion) -- en vez
 * de perder en silencio el resultado_documentos de una emision REAL que si
 * ocurrio en el motor, se deja logueado completo para poder reconciliar a
 * mano (buscar el folio en dte_emitido del motor).
 */
function marcarNotaVentaFacturada(PDO $pdo, int $id, array $documentos): void
{
    $documentosJson = json_encode($documentos, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        "UPDATE nota_venta SET estado = 'facturada', resultado_documentos = :res WHERE id = :id AND estado = 'en_proceso'"
    );
    $stmt->execute([':res' => $documentosJson, ':id' => $id]);
    if ($stmt->rowCount() !== 1) {
        error_log(sprintf(
            'facturacion masiva: la nota %d ya no estaba en_proceso al intentar marcarla facturada -- '
            . 'el motor SI emitio estos documentos, revisar manualmente: %s',
            $id,
            $documentosJson
        ));
    }
}

// ===========================================================================
//  ENCOLADO DE ENVIO DE DTE POR CORREO AL RECEPTOR (tabla dte_envio_correo,
//  migracion 024). Esta entrega SOLO encola: no envia nada.
//
//  LA REGLA QUE MANDA SOBRE TODAS: ENCOLAR NUNCA PUEDE ROMPER UNA EMISION.
//  Cuando se llama a esto, el folio YA se quemo y el SII YA tiene el documento:
//  no hay marcha atras posible. Por eso todo va envuelto en try/catch(Throwable)
//  y ni una excepcion escapa hacia el flujo de facturacion. Es lo contrario del
//  fail-fast que el proyecto aplica -- bien -- a los folios y a los montos, y es
//  deliberado: un correo es un dato de entrega, una factura es una obligacion
//  legal ya contraida.
//
//  LA JERARQUIA DEL DESTINATARIO: LA PRECEDENCIA SIGUE CUAN DELIBERADO ES EL
//  ORIGEN DEL DATO.
//
//      unitario:  formulario  >  maestro     (tecleado ahora para ESTE documento)
//      masivo:    maestro     >  nota        (el maestro es el dato vivo)
//
//  Parece invertido entre los dos caminos y no lo es. Lo tecleado en el
//  formulario se escribio recien y para este documento: es lo mas deliberado que
//  hay, y por eso gana. Una nota de venta, en cambio, es una FOTO tomada al
//  cargar el lote, y puede tener semanas: entre la carga y la facturacion
//  alguien pudo corregir la direccion en el maestro. El maestro es el dato vivo
//  y curado a mano por el tenant, asi que ahi gana el maestro.
//
//  Se descarto unificar como "el documento gana siempre": en el masivo eso
//  dejaria ganar a una fila de Excel venida de otro sistema, que puede traer
//  direcciones viejas para cientos de filas de una sola vez.
//
//  El respaldo de cada camino cubre el hueco del otro: si el maestro esta vacio
//  se usa la nota, y si el formulario viene vacio se usa el maestro. Nunca se
//  descarta un dato por preferir uno que no existe.
//
//  EL DESTINATARIO SE RESUELVE AL ENCOLAR Y SE GUARDA COMO FOTO. Nunca despues:
//  resolverlo al enviar obligaria a cruzar dte_envio_correo (utf8mb4_unicode_ci)
//  con dte_emitido (utf8mb4_0900_ai_ci) por columnas de texto, que son las dos
//  familias de collation del esquema.
// ===========================================================================

/**
 * Correo del receptor segun el maestro de clientes de la cuenta, o null.
 *
 * Devuelve null tanto si el cliente no existe como si existe sin correo: para
 * la cascada los dos casos son "el maestro no aporta nada".
 */
/**
 * Si el receptor trae correo y su ficha en el maestro NO lo tiene, se lo carga.
 *
 * ALCANCE ACOTADO A PROPOSITO: rellena un hueco, no crea ni edita nada mas. Es
 * el complemento de guardarClienteDesdeReceptor(), que hace otra cosa (crear el
 * cliente entero, y solo si no existe, y solo si el usuario marco el checkbox).
 *
 * NUNCA SOBRESCRIBE un correo que ya este cargado: esa garantia la da el WHERE
 * de rellenarEmailSiVacio() y no este codigo. Ver la Entrega 1.
 *
 * No lanza: un fallo aqui no puede tocar una emision ya hecha.
 */
function rellenarCorreoMaestroDesdeReceptor(int $cuentaId, array $receptor): void
{
    try {
        $email = trim((string) ($receptor['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $rut = Rut::normalizar((string) ($receptor['rut'] ?? ''));
        if (! Rut::valido($rut)) {
            return;
        }
        $repo    = clienteRepo();
        $cliente = $repo->buscarPorRut($cuentaId, $rut);
        if ($cliente === null) {
            return; // no existe: crearlo es asunto de guardarClienteDesdeReceptor()
        }
        $repo->rellenarEmailSiVacio($cuentaId, (int) $cliente['id'], $email);
    } catch (Throwable $e) {
        error_log('panel emision: no se pudo rellenar el correo del maestro - ' . $e->getMessage());
    }
}

function correoReceptorDeMaestro(int $cuentaId, string $rutReceptor): ?string
{
    $rut = Rut::normalizar($rutReceptor);
    if (! Rut::valido($rut)) {
        return null;
    }
    $cliente = clienteRepo()->buscarPorRut($cuentaId, $rut);
    $email   = trim((string) ($cliente['email'] ?? ''));

    return $email !== '' ? $email : null;
}


/** Suma folios_restantes de los CAF activos de un tipo, en produccion. Reusa
 *  listarCafs() (ya existente, M1) en vez de inventar una consulta nueva. */
function sumarFoliosDisponibles(PDO $pdo, string $rutEmisor, int $tipoDte): int
{
    $total = 0;
    foreach (listarCafs($pdo, $rutEmisor, 'produccion') as $c) {
        if ((int) $c['tipo_dte'] === $tipoDte && $c['estado'] === 'activo') {
            $total += max(0, (int) $c['folios_restantes']);
        }
    }

    return $total;
}

/** POST /api/v1/dte/lote del motor (facturacion masiva, M4). Mismo patron que
 *  emitirEnMotor()/listarDocumentosEnMotor(): reusa clienteMotor(), NO lanza
 *  en 4xx/5xx (http_errors=false, vive en clienteMotor()). */
function emitirLoteEnMotor(string $keyServicio, array $documentos, string $claveIdempotencia): array
{
    // Idempotency-Key es OBLIGATORIA en el lote del motor (a diferencia del
    // unitario, donde sigue siendo opcional): sin ella responde 422. La clave la
    // deriva el llamador del contenido del sub-lote, no se genera aqui, porque
    // tiene que ser la MISMA en un reintento.
    $resp = clienteMotor()->post('api/v1/dte/lote', [
        'headers' => [
            'X-Api-Key'       => $keyServicio,
            'Content-Type'    => 'application/json',
            'Idempotency-Key' => $claveIdempotencia,
        ],
        'json'    => ['documentos' => $documentos],
    ]);
    $body = json_decode((string) $resp->getBody(), true);

    return ['status' => $resp->getStatusCode(), 'body' => is_array($body) ? $body : []];
}

/**
 * Los tipos de DTE que produce esta nota, EN EL MISMO ORDEN en que
 * armarDocumentosSubLote() los arma: la factura primero, y la NC despues si
 * reemplaza una boleta.
 *
 * DEVUELVE LA LISTA Y NO UN NUMERO, y ese fue el punto del cambio: antes habia
 * dos reglas paralelas leyendo lo mismo por su cuenta -- esta funcion contaba
 * documentos para repartir la respuesta del motor, y la guarda de folios
 * repetia el mismo criterio con su propio count() + array_filter(). Dos reglas
 * que pueden divergir. Ahora las dos leen de aqui.
 *
 * El tipo sale de la NOTA, no de su lote: el sub-lote puede mezclar notas de
 * archivos distintos (ver listarNotasVentaPendientes).
 *
 * @return list<int>
 */
function tiposDocumentosPorNota(array $nota): array
{
    $tipos = [(int) ($nota['tipo_dte'] ?? 33)];
    if (! empty($nota['boleta_ref_folio'])) {
        $tipos[] = 61; // NC que anula la boleta reemplazada
    }

    return $tipos;
}

/** Cuantos documentos produce esta nota. Se mantiene como envoltorio de
 *  tiposDocumentosPorNota() para que el conteo NUNCA pueda discrepar de la lista
 *  de tipos: hay una sola fuente y esto solo la mide. */
function cantidadDocumentosPorNota(array $nota): int
{
    return count(tiposDocumentosPorNota($nota));
}

/**
 * Traduce el error de un sub-lote rechazado, cambiando el INDICE del documento
 * por el RUT y la razon social de la nota que ocupa esa posicion.
 *
 * POR QUE HACE FALTA. El motor valida todo el lote antes de asignar folios y
 * responde 422 con el campo exacto, pero numerado por su propia posicion:
 * "documentos[7].receptor.giro es obligatorio". Ese 7 es el indice DENTRO DEL
 * SOBRE, no el numero de fila del Excel ni el id de la nota, asi que al usuario
 * no le sirve para nada: le dice que algo esta mal en un sub-lote de hasta 20
 * notas, sin decirle cual.
 *
 * COMO SE RESUELVE EL INDICE. armarDocumentosSubLote() recorre $notas EN ORDEN y
 * emite tiposDocumentosPorNota() documentos por cada una -- normalmente uno, dos
 * cuando la nota ademas anula una boleta con NC. Aqui se reconstruye ese mismo
 * recorrido para saber a que nota pertenece el indice N. Se usa la MISMA funcion
 * que arma el sub-lote (cantidadDocumentosPorNota) y no un conteo propio: si
 * manana una nota emitiera tres documentos, las dos cuentas cambian juntas.
 *
 * SI NO SE PUEDE TRADUCIR, SE DEVUELVE EL MENSAJE TAL CUAL. Un error del motor
 * que no venga indexado -- o un indice fuera de rango, que solo pasaria si las
 * dos funciones se desincronizaran -- no debe perderse ni convertirse en una
 * excepcion: vale mas el mensaje crudo que ningun mensaje.
 *
 * @param list<array<string,mixed>> $notas el MISMO arreglo, en el MISMO orden,
 *        que se le paso a armarDocumentosSubLote()
 */
function mensajeRechazoSubLote(string $error, array $notas): string
{
    if (! preg_match('/documentos\[(\d+)\]/', $error, $m)) {
        return $error;
    }

    $indice = (int) $m[1];
    $cursor = 0;
    foreach ($notas as $nota) {
        $cursor += cantidadDocumentosPorNota($nota);
        if ($indice < $cursor) {
            $rut   = trim((string) ($nota['receptor_rut'] ?? ''));
            $razon = trim((string) ($nota['receptor_razon_social'] ?? ''));
            $quien = $rut !== '' && $razon !== '' ? "{$rut} ({$razon})" : ($rut !== '' ? $rut : $razon);
            if ($quien === '') {
                return $error;
            }

            // Se conserva el mensaje del motor completo y se le antepone de
            // quien es: el detalle del campo que falta sigue siendo suyo y es
            // lo que dice QUE arreglar.
            return sprintf('Cliente %s: %s', $quien, $error);
        }
    }

    return $error;
}

/** Arma los documentos del sub-lote para POST /api/v1/dte/lote, en el mismo
 *  orden de $notas (factura primero, NC despues si aplica). La NC anula la
 *  boleta original: mismo detalle/montos que la factura de reemplazo. */
function armarDocumentosSubLote(array $notas): array
{
    $documentos = [];
    foreach ($notas as $nota) {
        $detalle  = json_decode((string) $nota['detalle'], true);
        $receptor = [
            'rut'         => $nota['receptor_rut'],
            'razonSocial' => $nota['receptor_razon_social'],
            'giro'        => (string) ($nota['receptor_giro'] ?? ''),
            'direccion'   => (string) ($nota['receptor_direccion'] ?? ''),
            'comuna'      => (string) ($nota['receptor_comuna'] ?? ''),
        ];
        if (! empty($nota['receptor_email'])) {
            $receptor['email'] = $nota['receptor_email'];
        }

        // EL TIPO SALE DE LA NOTA (migracion 025), no de un 33 escrito a mano.
        // tiposDocumentosPorNota() es la unica fuente y su PRIMER elemento es
        // siempre el de la factura, por contrato con el orden de este bucle.
        $tipos = tiposDocumentosPorNota($nota);

        $documento = [
            'tipoDte'         => $tipos[0],
            'receptor'        => $receptor,
            'detalles'        => $detalle,
            'montosSonBrutos' => false,
        ];

        // FORMA DE PAGO Y VENCIMIENTO, si la nota los trae. Las notas cargadas
        // ANTES de esta entrega tienen forma_pago en NULL y siguen emitiendo sin
        // el campo, exactamente como hasta ahora: por eso las claves se agregan
        // solo cuando hay valor, en vez de mandarlas en null. El motor las acepta
        // opcionales justamente para no dejar esas notas intrafacturables.
        if ($nota['forma_pago'] !== null) {
            $documento['formaPago'] = (int) $nota['forma_pago'];
            if ($nota['fecha_vencimiento'] !== null) {
                $documento['fechaVencimiento'] = (string) $nota['fecha_vencimiento'];
            }
        }

        $documentos[] = $documento;

        if (! empty($nota['boleta_ref_folio'])) {
            $documentos[] = [
                'tipoDte'         => 61,
                'receptor'        => $receptor,
                'detalles'        => $detalle,
                'montosSonBrutos' => false,
                'referencias'     => [[
                    'tipoDocumento' => (int) ($nota['boleta_ref_tipo'] ?? 39),
                    'folio'         => (int) $nota['boleta_ref_folio'],
                    'fecha'         => $nota['boleta_ref_fecha'],
                    'codigo'        => 1,
                    'razon'         => 'Anula boleta N ' . $nota['boleta_ref_folio'],
                ]],
            ];
        }
    }

    return $documentos;
}

/**
 * Factura UN sub-lote: reclama en_proceso (commit inmediato, atomico -- ver
 * marcarNotasVentaEnProceso()), llama al motor, y segun respuesta marca
 * facturada (con resultado_documentos) o error (con el mensaje del motor) --
 * TODAS las notas reclamadas juntas, porque POST /api/v1/dte/lote es
 * todo-o-nada (confirmado en PASO 0 de M4). Un sub-lote fallido NO aborta
 * los siguientes: el llamador sigue el loop.
 *
 * Solo se llama al motor por las notas EFECTIVAMENTE reclamadas: si otro
 * proceso ya se quedo con alguna (carrera con recuperarNotasVentaEnProcesoViejas()
 * + un reproceso concurrente), esta llamada la descarta ANTES de armar el
 * payload -- nunca hay 2 llamadas al motor por la misma nota.
 */
/**
 * $cuentaId y $rutEmisor los recibe -- en vez de rederivarlos -- porque el
 * llamador ya los resolvio con exigirProduccionCompleto(). Los necesita el
 * encolado del correo del final: cuenta_id se guarda en la fila de la cola y
 * rut_emisor es parte del UNIQUE con el que se busca el documento emitido.
 */
function facturarSubLote(PDO $pdo, string $keyServicio, array $notas, int $cuentaId, string $rutEmisor): void
{
    $ids           = array_map(static fn (array $n): int => (int) $n['id'], $notas);
    $idsReclamados = marcarNotasVentaEnProceso($pdo, $ids);
    if ($idsReclamados === []) {
        return; // ninguna nota de este sub-lote seguia pendiente al reclamarla: ya las tomo otro proceso
    }
    if (count($idsReclamados) !== count($ids)) {
        error_log(sprintf(
            'facturacion masiva: sub-lote reclamo %d de %d notas pedidas (ids pedidos: %s, reclamados: %s) -- el resto ya no estaba pendiente',
            count($idsReclamados),
            count($ids),
            implode(',', $ids),
            implode(',', $idsReclamados)
        ));
    }
    $notas = array_values(array_filter(
        $notas,
        static fn (array $n): bool => in_array((int) $n['id'], $idsReclamados, true)
    ));

    // CLAVE DE IDEMPOTENCIA DEL SUB-LOTE, ahora obligatoria en el motor.
    //
    // SE DERIVA DE LOS IDS DE NOTA RECLAMADOS, ordenados: son exactamente los
    // documentos que este envio va a emitir. Es la unica derivacion que cumple
    // las dos condiciones que sirven:
    //
    //   - ESTABLE entre reintentos: el mismo sub-lote de las mismas notas
    //     produce la misma clave. Una clave aleatoria por peticion no serviria
    //     de nada -- el reintento no encontraria el claim anterior y volveria a
    //     emitir todo.
    //   - DISTINTA entre sub-lotes: dos sub-lotes de la misma corrida tienen
    //     conjuntos de ids disjuntos (marcarNotasVentaEnProceso() los reclama en
    //     exclusiva), asi que no colisionan.
    //
    // Se ordenan antes de unir porque marcarNotasVentaEnProceso() no promete un
    // orden, y dos reintentos con el mismo conjunto en distinto orden tienen que
    // dar la MISMA clave.
    //
    // No hace falta meter cuenta_id ni rut_emisor: la PK de dte_idempotencia es
    // (rut_emisor, ambiente, clave) y el motor toma el rut del tenant
    // autenticado, asi que dos cuentas no pueden pisarse aunque los ids
    // coincidan.
    $idsOrdenados = $idsReclamados;
    sort($idsOrdenados, SORT_NUMERIC);
    $claveIdem = 'sublote-' . hash('sha256', implode(',', $idsOrdenados));

    try {
        $res = emitirLoteEnMotor($keyServicio, armarDocumentosSubLote($notas), $claveIdem);
    } catch (Throwable $e) {
        error_log('facturacion masiva: fallo de conexion con el motor - ' . $e->getMessage());
        marcarNotasVentaError($pdo, $idsReclamados, 'No se pudo contactar el motor de emision.');

        return;
    }

    if ($res['status'] !== 201) {
        error_log('facturacion masiva: sub-lote fallo (' . $res['status'] . ') - ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        marcarNotasVentaError(
            $pdo,
            $idsReclamados,
            mensajeRechazoSubLote((string) ($res['body']['error'] ?? 'El motor rechazo el sub-lote.'), $notas),
        );

        return;
    }

    $documentosResultado = is_array($res['body']['documentos'] ?? null) ? $res['body']['documentos'] : [];
    $trackId             = $res['body']['trackId'] ?? null;
    $cursor              = 0;
    foreach ($notas as $nota) {
        $n        = cantidadDocumentosPorNota($nota);
        $docsNota = array_slice($documentosResultado, $cursor, $n);
        foreach ($docsNota as &$dn) {
            $dn['trackId'] = $trackId;
        }
        unset($dn);
        marcarNotaVentaFacturada($pdo, (int) $nota['id'], $docsNota);

        // ENCOLADO DEL CORREO (migracion 024). Va DESPUES de marcar la nota
        // facturada, que es donde termina el ciclo de vida del documento: si
        // esto fallara, la nota ya quedo facturada y la emision no se entera.
        //
        // EL try/catch ENVUELVE TODO EL ENCOLADO, no solo el INSERT.
        // EncoladorCorreo::encolarUno() ya trae su propia guarda, pero la RESOLUCION del
        // destinatario queda fuera de ella: correoReceptorDeMaestro() consulta
        // la base con ERRMODE_EXCEPTION y puede lanzar PDOException. Sin este
        // catch, esa excepcion escaparia hacia el flujo de facturacion, que no
        // tiene handler global, y el dano no seria solo "no se encolo": el
        // foreach de notas se cortaria ahi, y las notas SIGUIENTES del sub-lote
        // se quedarian en 'en_proceso' con sus documentos YA emitidos por el
        // SII -- el estado que este mismo archivo advierte como "revisar
        // manualmente" en marcarNotaVentaFacturada().
        //
        // VA DENTRO DEL foreach Y NO ALREDEDOR: si envolviera el bucle entero,
        // un fallo en la nota N impediria marcar facturadas a las N+1. Por nota,
        // cada una se marca y se encola con independencia de las demas.
        //
        // CASCADA DEL CAMINO MASIVO: maestro > nota. Se consulta el maestro
        // PRIMERO, y la nota queda de respaldo.
        //
        // POR QUE EL MAESTRO NUNCA ES PEOR. Despues de la Entrega 1,
        // nota_venta.receptor_email es SIEMPRE una foto del maestro: si el
        // maestro tenia valor, la carga copio ESE valor a la nota; si estaba
        // vacio, la nota tomo el del Excel y la Entrega 1 relleno el maestro con
        // ese mismo valor. No existe ningun caso en que la nota traiga algo mas
        // deliberado que el maestro de hoy.
        //
        // Y A VECES ES MEJOR: entre la carga del lote y la facturacion puede
        // pasar tiempo. Si alguien corrige la direccion en el maestro en ese
        // intervalo, leer el maestro hace que la correccion surta efecto en vez
        // de perderse contra una foto vieja.
        //
        // EL RESPALDO NO SOBRA: cubre el maestro vaciado despues de la carga, y
        // las notas cargadas ANTES de que existiera la regla de la Entrega 1.
        try {
            $destinatario = correoReceptorDeMaestro($cuentaId, (string) ($nota['receptor_rut'] ?? ''));
            if ($destinatario === null) {
                $destinatario = trim((string) ($nota['receptor_email'] ?? ''));
            }

            // UNA NOTA PUEDE GENERAR VARIOS DOCUMENTOS (factura mas nota de
            // credito cuando anula una boleta, ver cantidadDocumentosPorNota):
            // se encola CADA UNO, con su propio tipo y folio.
            foreach ($docsNota as $doc) {
                EncoladorCorreo::encolarUno(
                    $pdo,
                    $cuentaId,
                    $rutEmisor,
                    'produccion', // explicito, ver el comentario del camino unitario
                    (int) ($doc['tipoDte'] ?? 0),
                    (int) ($doc['folio'] ?? 0),
                    $destinatario !== '' ? $destinatario : null
                );
            }
        } catch (Throwable $e) {
            error_log(sprintf(
                'encolar correo: fallo el encolado de la nota %d (ya facturada, la emision no se toca) - %s',
                (int) $nota['id'],
                $e->getMessage()
            ));
        }

        $cursor += $n;
    }
}

function handleFacturacionMasivaGet(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirProduccionCompleto($pdo, $cuentaId);

    // Recupera notas 'en_proceso' abandonadas (pestana cerrada a mitad de un
    // sub-lote) ANTES de listar pendientes: asi el usuario las vuelve a ver
    // disponibles para seleccionar sin tener que hacer nada especial.
    recuperarNotasVentaEnProcesoViejas($pdo, $cuentaId);

    $rutFiltro = trim((string) ($_GET['rut'] ?? ''));

    $idsResultado = trim((string) ($_GET['ids'] ?? ''));
    $resultado    = [];
    if ($idsResultado !== '') {
        $ids       = array_values(array_filter(array_map('intval', explode(',', $idsResultado)), static fn ($v): bool => $v > 0));
        $resultado = obtenerNotasVentaPorIdsCualquierEstado($pdo, $cuentaId, $ids);
    }

    vista('facturacion-masiva-form', [
        'pendientes'         => listarNotasVentaPendientes($pdo, $cuentaId, $rutFiltro !== '' ? $rutFiltro : null),
        'rutFiltro'          => $rutFiltro,
        'foliosFactura'      => sumarFoliosDisponibles($pdo, $rutEmisor, 33),
        // Tercera cubeta: la guarda de folios ya la cuenta, asi que la pantalla
        // tiene que mostrarla o el usuario no sabe contra que se estrello el 409.
        'foliosExenta'       => sumarFoliosDisponibles($pdo, $rutEmisor, 34),
        'foliosNc'           => sumarFoliosDisponibles($pdo, $rutEmisor, 61),
        'resultado'          => $resultado,
        'flash'              => flashTomar(),
        'subLoteTamano'      => FACTURACION_MASIVA_SUBLOTE,
        'navActivo'          => 'ventas.facturacion-masiva',
    ]);
}

/**
 * Responde JSON y termina. Este endpoint SOLO se llama via fetch() desde
 * facturacion-masiva-form.php (nunca un form POST clasico): un redirect PRG
 * clasico se seguiria en silencio dentro del propio fetch (comportamiento
 * default de la Fetch API), y el flash de error quedaria consumido por esa
 * navegacion invisible ANTES de que el usuario llegue a ver la pagina real
 * -- por eso este handler responde JSON directo en vez de flash+redirect.
 */
function responderJsonFacturacionMasiva(int $http, array $body): never
{
    http_response_code($http);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Procesa UN sub-lote por request (PASO 3 de M4: el servidor del panel
 * es php -S, de un solo hilo -- confirmado empiricamente que un POST largo
 * bloquea cualquier otra request concurrente, incluido un polling de
 * progreso separado. En vez de tocar la infraestructura, se trocea la
 * orquestacion: el JS del navegador llama a este endpoint UNA vez por
 * sub-lote, nunca dos a la vez, y cada respuesta ES el progreso -- no hace
 * falta un endpoint de polling aparte ni set_time_limit() extendido, porque
 * ninguna request individual corre mas que lo que tarda un sub-lote de 20).
 *
 * Recibe el conjunto COMPLETO de ids seleccionados por el usuario en
 * notas[] (no solo el sub-lote): este handler decide solo cuales de esas
 * siguen 'pendiente' y toma hasta FACTURACION_MASIVA_SUBLOTE para procesar
 * ahora. Responde con el resultado de ESTA pasada mas el conteo acumulado
 * de TODO el conjunto, para que el JS sepa si terminar o pedir la siguiente.
 */
function handleFacturacionMasivaConfirmarSubLotePost(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirProduccionCompleto($pdo, $cuentaId);

    $ids = is_array($_POST['notas'] ?? null) ? $_POST['notas'] : [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v): bool => $v > 0)));
    if ($ids === []) {
        responderJsonFacturacionMasiva(422, ['status' => 'error', 'mensaje' => 'No seleccionaste ninguna nota.']);
    }

    // Recupera 'en_proceso' abandonadas de la cuenta ANTES de decidir que
    // sigue pendiente: si este conjunto incluye notas trabadas de un intento
    // anterior (pestana cerrada), vuelven a estar disponibles para procesar
    // aqui mismo, sin perderlas ni duplicarlas.
    recuperarNotasVentaEnProcesoViejas($pdo, $cuentaId);

    $pendientesDelConjunto = obtenerNotasVentaPendientesPorIds($pdo, $cuentaId, $ids);
    if ($pendientesDelConjunto === []) {
        // Nada pendiente: ya se proceso todo (o el conjunto no tenia notas
        // de esta cuenta). El conteo acumulado le confirma al JS que termino.
        responderJsonFacturacionMasiva(200, [
            'status'    => 'ok',
            'conteo'    => contarNotasVentaPorEstado($pdo, $cuentaId, $ids),
            'terminado' => true,
        ]);
    }

    // Resumen de folios ANTES DE TOCAR NADA, sobre TODO lo que sigue
    // pendiente del conjunto (no solo este sub-lote): si no alcanza para
    // terminar, se aborta aqui sin marcar ninguna nota en_proceso ni llamar
    // al motor (mismo criterio fail-fast que la emision unitaria de M3). Se
    // revalida en CADA pasada (no solo la primera) porque la disponibilidad
    // de folios puede cambiar entre sub-lotes.
    //
    // TRES CUBETAS, y se cuentan LEYENDO tiposDocumentosPorNota() en vez de
    // repetir su criterio aqui: antes este bloque deducia por su cuenta cuantas
    // facturas y cuantas NC hacian falta, con un count() y un array_filter()
    // propios, mientras el reparto de la respuesta del motor usaba la otra
    // funcion. Dos reglas paralelas sobre el mismo hecho. Ahora hay una.
    $necesita = [33 => 0, 34 => 0, 61 => 0];
    foreach ($pendientesDelConjunto as $nota) {
        foreach (tiposDocumentosPorNota($nota) as $t) {
            $necesita[$t] = ($necesita[$t] ?? 0) + 1;
        }
    }
    $disponible = [
        33 => sumarFoliosDisponibles($pdo, $rutEmisor, 33),
        34 => sumarFoliosDisponibles($pdo, $rutEmisor, 34),
        61 => sumarFoliosDisponibles($pdo, $rutEmisor, 61),
    ];
    $falta = false;
    foreach ($necesita as $t => $n) {
        if ($n > ($disponible[$t] ?? 0)) {
            $falta = true;
        }
    }
    if ($falta) {
        // Se informan las TRES cubetas siempre, incluso las que alcanzaban, que
        // es como venia comportandose este mensaje con las dos originales.
        responderJsonFacturacionMasiva(409, ['status' => 'error', 'mensaje' => sprintf(
            'Folios insuficientes: faltan %d factura(s) (disponibles %d), %d factura(s) exenta(s) '
            . '(disponibles %d) y %d nota(s) de credito (disponibles %d). No se toco ninguna nota.',
            $necesita[33],
            $disponible[33],
            $necesita[34],
            $disponible[34],
            $necesita[61],
            $disponible[61]
        )]);
    }

    $subLote = obtenerNotasVentaPendientesPorIds($pdo, $cuentaId, $ids, FACTURACION_MASIVA_SUBLOTE);

    $keyServicio = obtenerKeyServicio($pdo, $cuentaId, $rutEmisor);
    facturarSubLote($pdo, $keyServicio, $subLote, $cuentaId, $rutEmisor);

    $conteo = contarNotasVentaPorEstado($pdo, $cuentaId, $ids);
    responderJsonFacturacionMasiva(200, [
        'status'    => 'ok',
        'subLote'   => ['procesadas' => count($subLote)],
        'conteo'    => $conteo,
        'terminado' => ($conteo['pendiente'] + $conteo['en_proceso']) === 0,
    ]);
}

// ===========================================================================
//  Configuracion > Usuarios (M6, pieza 1): invitar un segundo usuario a la
//  cuenta via link de activacion de un solo uso. Sin SMTP en el proyecto
//  (confirmado en M5): el owner copia el link generado y lo comparte por
//  cualquier canal fuera de la app; quien lo abre define SU PROPIA
//  contrasena (el owner nunca la conoce ni la elige). rol='colaborador' es
//  puramente visual (sin logica que lo lea para permitir/bloquear nada):
//  el sistema de permisos real queda fuera de alcance de M6 (PASO 0: hoy
//  solo puede existir un usuario por cuenta, construir permisos ahora seria
//  especulativo).
// ===========================================================================

/** @return list<array<string,mixed>> */
function listarUsuariosDeCuenta(PDO $pdo, int $cuentaId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, email, rol, estado, activacion_token, activacion_expira, created_at '
        . 'FROM usuario WHERE cuenta_id = :c ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([':c' => $cuentaId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function contarUsuariosActivos(PDO $pdo, int $cuentaId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuario WHERE cuenta_id = :c AND estado = 'activo'");
    $stmt->execute([':c' => $cuentaId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Crea una invitacion nueva o regenera el token de una pendiente (cubre
 * "el link vencio, reenvialo" y un doble-submit accidental del formulario).
 * Cubre los 3 casos de duplicado de email (usuario.email es UNIQUE GLOBAL,
 * no por cuenta -- ver PASO 0 de M6): email de OTRA cuenta como owner
 * original, email ya activo en ESTA cuenta, email de OTRA cuenta como
 * colaborador -- los 3 dan un error claro, nunca un 500 por violar el
 * UNIQUE a ciegas.
 *
 * @return array{status:string, mensaje?:string, token?:string, usuarioId?:int, regenerado?:bool}
 */
function crearOResendearInvitacion(PDO $pdo, int $cuentaId, string $email): array
{
    // Ojo: cuenta.email es el email del OWNER original (lo fija /registro).
    // Si el email pertenece a esta MISMA cuenta (invitar el propio email del
    // owner), no es un error de "otra cuenta" -- cae al chequeo de usuario de
    // abajo, que lo va a encontrar como activo y dar el mensaje correcto.
    $stmt = $pdo->prepare('SELECT id FROM cuenta WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $cuentaConEseEmail = $stmt->fetchColumn();
    if ($cuentaConEseEmail !== false && (int) $cuentaConEseEmail !== $cuentaId) {
        return ['status' => 'error', 'mensaje' => 'Ese email ya esta en uso en otra cuenta.'];
    }

    $stmt = $pdo->prepare('SELECT id, cuenta_id, estado FROM usuario WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $existente = $stmt->fetch(PDO::FETCH_ASSOC);

    $expira = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');

    if ($existente !== false) {
        if ((int) $existente['cuenta_id'] !== $cuentaId) {
            return ['status' => 'error', 'mensaje' => 'Ese email ya esta en uso en otra cuenta.'];
        }
        if ($existente['estado'] === 'activo') {
            return ['status' => 'error', 'mensaje' => 'Esta persona ya tiene acceso a tu cuenta.'];
        }
        // inactivo, misma cuenta: invitacion pendiente (o desactivada antes de
        // completar la activacion) -- regenerar token y reenviar.
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE usuario SET activacion_token = :token, activacion_expira = :expira WHERE id = :id')
            ->execute([':token' => $token, ':expira' => $expira, ':id' => $existente['id']]);

        return ['status' => 'ok', 'token' => $token, 'usuarioId' => (int) $existente['id'], 'regenerado' => true];
    }

    $token = bin2hex(random_bytes(32));
    // Hash de bytes aleatorios: hace login imposible hasta que se active
    // (usuario.password_hash es NOT NULL, no se puede dejar vacio).
    $passwordInutilizable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $pdo->prepare(
        'INSERT INTO usuario (cuenta_id, email, password_hash, rol, estado, activacion_token, activacion_expira, created_at) '
        . "VALUES (:cuenta_id, :email, :hash, 'colaborador', 'inactivo', :token, :expira, NOW())"
    )->execute([
        ':cuenta_id' => $cuentaId,
        ':email'     => $email,
        ':hash'      => $passwordInutilizable,
        ':token'     => $token,
        ':expira'    => $expira,
    ]);

    return ['status' => 'ok', 'token' => $token, 'usuarioId' => (int) $pdo->lastInsertId(), 'regenerado' => false];
}

/**
 * Resuelve un token de activacion: 'invalido' (no existe, o el usuario ya
 * no esta 'inactivo'), 'vencido' (existe pero paso activacion_expira), o
 * 'valido' (listo para definir contrasena).
 *
 * @return array{estado:string, usuario?:array<string,mixed>}
 */
function resolverUsuarioPorTokenActivacion(PDO $pdo, string $token): array
{
    if ($token === '') {
        return ['estado' => 'invalido'];
    }
    $stmt = $pdo->prepare(
        'SELECT id, cuenta_id, email, estado, activacion_expira FROM usuario WHERE activacion_token = :token LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fila === false || $fila['estado'] !== 'inactivo') {
        return ['estado' => 'invalido'];
    }
    if ($fila['activacion_expira'] === null || (string) $fila['activacion_expira'] < date('Y-m-d H:i:s')) {
        return ['estado' => 'vencido', 'usuario' => $fila];
    }

    return ['estado' => 'valido', 'usuario' => $fila];
}

function handleUsuariosListadoGet(): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();

    vista('usuarios-listado', [
        'usuarios'  => listarUsuariosDeCuenta($pdo, $cuentaId),
        'flash'     => flashTomar(),
        'navActivo' => 'config.usuarios',
    ]);
}

function handleUsuarioInvitarPost(): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    $email    = trim((string) ($_POST['email'] ?? ''));

    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flashSet('error', 'Email invalido.');
        redirigirPrg('/configuracion/usuarios');
    }

    $resultado = crearOResendearInvitacion($pdo, $cuentaId, $email);
    if ($resultado['status'] === 'error') {
        flashSet('error', $resultado['mensaje']);
        redirigirPrg('/configuracion/usuarios');
    }

    $esquema = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host    = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $link    = sprintf('%s://%s/activar/%s', $esquema, $host, $resultado['token']);

    registrarAuditoria(
        $pdo,
        Auth::usuarioId(),
        'usuario.invitar',
        'usuario',
        $resultado['usuarioId'],
        null,
        ['email' => $email, 'regenerado' => $resultado['regenerado']],
    );

    flashSet(
        'exito',
        'Invitacion lista. Copia este link y compartelo por fuera de la app (valido 48 horas, un solo uso):',
        ['link' => $link],
    );
    redirigirPrg('/configuracion/usuarios');
}

function handleUsuarioActivarPost(int $id): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();

    $stmt = $pdo->prepare('SELECT estado, activacion_token FROM usuario WHERE id = :id AND cuenta_id = :c LIMIT 1');
    $stmt->execute([':id' => $id, ':c' => $cuentaId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fila === false) {
        flashSet('error', 'Usuario no encontrado.');
        redirigirPrg('/configuracion/usuarios');
    }
    if ($fila['activacion_token'] !== null) {
        flashSet('error', 'Este usuario nunca completo su activacion. Vuelve a invitarlo desde el formulario de arriba.');
        redirigirPrg('/configuracion/usuarios');
    }

    $pdo->prepare("UPDATE usuario SET estado = 'activo' WHERE id = :id AND cuenta_id = :c")
        ->execute([':id' => $id, ':c' => $cuentaId]);

    registrarAuditoria($pdo, Auth::usuarioId(), 'usuario.activar', 'usuario', $id, ['estado' => $fila['estado']], ['estado' => 'activo']);

    flashSet('exito', 'Usuario activado.');
    redirigirPrg('/configuracion/usuarios');
}

function handleUsuarioDesactivarPost(int $id): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();

    if ($id === Auth::usuarioId()) {
        flashSet('error', 'No puedes desactivar tu propia cuenta.');
        redirigirPrg('/configuracion/usuarios');
    }

    $stmt = $pdo->prepare('SELECT estado FROM usuario WHERE id = :id AND cuenta_id = :c LIMIT 1');
    $stmt->execute([':id' => $id, ':c' => $cuentaId]);
    $estadoActual = $stmt->fetchColumn();
    if ($estadoActual === false) {
        flashSet('error', 'Usuario no encontrado.');
        redirigirPrg('/configuracion/usuarios');
    }
    if ($estadoActual !== 'activo') {
        flashSet('error', 'Ese usuario ya esta inactivo.');
        redirigirPrg('/configuracion/usuarios');
    }

    // Guarda: nunca dejar la cuenta en 0 usuarios activos (auto-bloqueo total,
    // sin via de recuperacion salvo un fix manual por SQL).
    if (contarUsuariosActivos($pdo, $cuentaId) <= 1) {
        flashSet('error', 'No puedes desactivar al ultimo usuario activo de tu cuenta.');
        redirigirPrg('/configuracion/usuarios');
    }

    $pdo->prepare("UPDATE usuario SET estado = 'inactivo' WHERE id = :id AND cuenta_id = :c")
        ->execute([':id' => $id, ':c' => $cuentaId]);

    registrarAuditoria($pdo, Auth::usuarioId(), 'usuario.desactivar', 'usuario', $id, ['estado' => 'activo'], ['estado' => 'inactivo']);

    flashSet('exito', 'Usuario desactivado.');
    redirigirPrg('/configuracion/usuarios');
}

/** GET /activar/{token}: publica, sin sesion (mismo nivel que /login, /registro). */
function handleActivarCuentaGet(string $token): void
{
    if (Auth::autenticado()) {
        redirigir('/panel');
    }

    $pdo = Db::conexion();
    vista('activar-cuenta', [
        'token'      => $token,
        'resolucion' => resolverUsuarioPorTokenActivacion($pdo, $token),
        'errores'    => [],
    ]);
}

/**
 * POST /activar/{token}: publica. El UPDATE final exige
 * activacion_token = :token en el WHERE (atomico): si el mismo token se
 * manda 2 veces casi al mismo tiempo (doble-submit), solo el primero en
 * llegar afecta la fila -- el segundo la encuentra con activacion_token ya
 * en NULL y falla limpio, sin reactivar ni pisar nada.
 */
function handleActivarCuentaPost(string $token): void
{
    if (Auth::autenticado()) {
        redirigir('/panel');
    }

    $pdo        = Db::conexion();
    $resolucion = resolverUsuarioPorTokenActivacion($pdo, $token);
    if ($resolucion['estado'] !== 'valido') {
        vista('activar-cuenta', ['token' => $token, 'resolucion' => $resolucion, 'errores' => []]);
    }

    $pass     = (string) ($_POST['password'] ?? '');
    $confirma = (string) ($_POST['password_confirmacion'] ?? '');
    $errores  = [];
    if (strlen($pass) < 8) {
        $errores[] = 'La contrasena debe tener al menos 8 caracteres.';
    }
    if ($pass !== $confirma) {
        $errores[] = 'Las contrasenas no coinciden.';
    }
    if ($errores !== []) {
        vista('activar-cuenta', ['token' => $token, 'resolucion' => $resolucion, 'errores' => $errores]);
    }

    $usuario = $resolucion['usuario'];
    $hash    = password_hash($pass, PASSWORD_DEFAULT);
    $stmt    = $pdo->prepare(
        "UPDATE usuario SET password_hash = :hash, estado = 'activo', activacion_token = NULL, activacion_expira = NULL "
        . 'WHERE id = :id AND activacion_token = :token'
    );
    $stmt->execute([':hash' => $hash, ':id' => $usuario['id'], ':token' => $token]);

    if ($stmt->rowCount() !== 1) {
        // Carrera de doble-submit: otro request ya consumio este token.
        vista('activar-cuenta', ['token' => $token, 'resolucion' => ['estado' => 'invalido'], 'errores' => []]);
    }

    Auth::login((int) $usuario['id'], (int) $usuario['cuenta_id']);
    Csrf::regenerarToken();
    redirigir('/panel');
}

// ===========================================================================
//  Auditoria de tenant (M6, pieza 2): filtra admin_auditoria por los eventos
//  que pertenecen a ESTA cuenta especifica. La tabla no tiene columna
//  cuenta_id propia (ver PASO 0 de M6): se resuelve indirectamente segun
//  entidad_tipo, un LEFT JOIN distinto por tipo. Extensible: un entidad_tipo
//  nuevo a futuro solo agrega un LEFT JOIN mas + una condicion mas en el OR.
//
//  Por que nunca se ve una cuenta ajena: :cuentaId es SIEMPRE
//  Auth::cuentaId() de la sesion actual. Para entidad_tipo='cuenta' (las
//  unicas 2 acciones exclusivas de superadmin que existen hoy: suspender/
//  reactivar), la condicion exige que la cuenta AFECTADA (entidad_id) sea
//  la propia -- si superadmin actua sobre OTRO tenant, esa fila no matchea
//  para nadie mas que ese tenant. Si superadmin actua sobre ESTA cuenta, SI
//  se ve (transparencia sobre acciones que afectan la cuenta propia).
// ===========================================================================

/**
 * @return array{0:string, 1:array<string,mixed>}
 */
function filtroAuditoriaTenant(int $cuentaId, ?string $desde, ?string $hasta, ?string $accionFiltro): array
{
    $where = "(\n"
        . "    (a.entidad_tipo = 'api_key' AND ak.cuenta_id = :cuenta_ak)\n"
        . "    OR (a.entidad_tipo = 'cuenta' AND a.entidad_id = :cuenta_directa)\n"
        . "    OR (a.entidad_tipo = 'dte_emisor' AND de.cuenta_id = :cuenta_de)\n"
        . "    OR (a.entidad_tipo = 'usuario' AND u2.cuenta_id = :cuenta_u2)\n"
        . ')';
    $params = [
        ':cuenta_ak'      => $cuentaId,
        ':cuenta_directa' => $cuentaId,
        ':cuenta_de'      => $cuentaId,
        ':cuenta_u2'      => $cuentaId,
    ];
    if ($desde !== null && $hasta !== null) {
        $where .= ' AND a.created_at BETWEEN :desde AND :hasta';
        $params[':desde'] = $desde . ' 00:00:00';
        $params[':hasta'] = $hasta . ' 23:59:59';
    }
    if ($accionFiltro !== null && $accionFiltro !== '') {
        $where .= ' AND a.accion LIKE :accion';
        $params[':accion'] = '%' . $accionFiltro . '%';
    }

    return [$where, $params];
}

/** @return list<array<string,mixed>> */
function listarAuditoriaTenant(PDO $pdo, int $cuentaId, ?string $desde, ?string $hasta, ?string $accionFiltro, int $limit, int $offset): array
{
    [$where, $params] = filtroAuditoriaTenant($cuentaId, $desde, $hasta, $accionFiltro);
    $stmt = $pdo->prepare(
        'SELECT a.id, a.usuario_id, u.email AS usuario_email, a.accion, a.entidad_tipo, a.entidad_id, '
        . '       a.valor_anterior, a.valor_nuevo, a.created_at '
        . 'FROM admin_auditoria a '
        . 'LEFT JOIN usuario u ON u.id = a.usuario_id '
        . "LEFT JOIN api_key ak ON a.entidad_tipo = 'api_key' AND ak.id = a.entidad_id "
        . "LEFT JOIN dte_emisor de ON a.entidad_tipo = 'dte_emisor' AND de.id = a.entidad_id "
        . "LEFT JOIN usuario u2 ON a.entidad_tipo = 'usuario' AND u2.id = a.entidad_id "
        . 'WHERE ' . $where
        . ' ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function contarAuditoriaTenant(PDO $pdo, int $cuentaId, ?string $desde, ?string $hasta, ?string $accionFiltro): int
{
    [$where, $params] = filtroAuditoriaTenant($cuentaId, $desde, $hasta, $accionFiltro);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM admin_auditoria a '
        . "LEFT JOIN api_key ak ON a.entidad_tipo = 'api_key' AND ak.id = a.entidad_id "
        . "LEFT JOIN dte_emisor de ON a.entidad_tipo = 'dte_emisor' AND de.id = a.entidad_id "
        . "LEFT JOIN usuario u2 ON a.entidad_tipo = 'usuario' AND u2.id = a.entidad_id "
        . 'WHERE ' . $where
    );
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function handleAuditoriaTenantGet(): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();

    $desdeRaw = trim((string) ($_GET['desde'] ?? ''));
    $hastaRaw = trim((string) ($_GET['hasta'] ?? ''));
    $desde    = ($desdeRaw !== '' && fechaValida($desdeRaw)) ? $desdeRaw : null;
    $hasta    = ($hastaRaw !== '' && fechaValida($hastaRaw)) ? $hastaRaw : null;
    if ($desde === null || $hasta === null) {
        $desde = null;
        $hasta = null;
    }
    $accionFiltro = trim((string) ($_GET['accion'] ?? ''));

    $porPagina = 25;
    $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
    $total     = contarAuditoriaTenant($pdo, $cuentaId, $desde, $hasta, $accionFiltro !== '' ? $accionFiltro : null);
    $offset    = ($pagina - 1) * $porPagina;
    $filas     = listarAuditoriaTenant($pdo, $cuentaId, $desde, $hasta, $accionFiltro !== '' ? $accionFiltro : null, $porPagina, $offset);

    vista('auditoria-tenant', [
        'filas'        => $filas,
        'desde'        => $desde ?? '',
        'hasta'        => $hasta ?? '',
        'accionFiltro' => $accionFiltro,
        'pagina'       => $pagina,
        'totalPaginas' => max(1, (int) ceil($total / $porPagina)),
        'total'        => $total,
        'navActivo'    => 'auditoria',
    ]);
}

/** Endpoint JSON: resuelve el cliente por RUT para el autocompletado del form. */
function handleClientePorRutGet(): void
{
    $r = resolverClientePorRut(Auth::cuentaId(), (string) ($_GET['rut'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Exige el onboarding base completo (empresa + certificado + >=1 CAF) para
 * llegar a /apikeys: redirige a la etapa pendiente si falta algo. Devuelve el
 * rut_emisor de la cuenta cuando todo esta completo.
 */
function exigirOnboardingCompleto(PDO $pdo, int $cuentaId): string
{
    $stmt = $pdo->prepare(
        "SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmt->execute([':cuenta_id' => $cuentaId]);
    $rutEmisor = $stmt->fetchColumn();
    if ($rutEmisor === false) {
        redirigir('/empresa');
    }

    $stmtCert = $pdo->prepare(
        "SELECT 1 FROM dte_certificado WHERE rut_emisor = :rut AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmtCert->execute([':rut' => $rutEmisor]);
    if ($stmtCert->fetchColumn() === false) {
        redirigir('/certificado');
    }

    $stmtCaf = $pdo->prepare(
        "SELECT 1 FROM dte_caf WHERE rut_emisor = :rut AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmtCaf->execute([':rut' => $rutEmisor]);
    if ($stmtCaf->fetchColumn() === false) {
        redirigir('/caf');
    }

    return (string) $rutEmisor;
}

/**
 * Lista las api_key de una cuenta (todos los estados), mas recientes primero.
 * NUNCA incluye key_hash: solo metadata segura de mostrar en la vista.
 *
 * @return list<array<string,mixed>>
 */
function listarApiKeys(PDO $pdo, int $cuentaId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, prefijo, ambiente, estado, last_used_at, created_at '
        . "FROM api_key WHERE cuenta_id = :cuenta_id AND tipo = 'externa' ORDER BY created_at DESC"
    );
    $stmt->execute([':cuenta_id' => $cuentaId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Duplicado de listarApiKeys() filtrado a ambiente='produccion' -- funcion
 * NUEVA e independiente (listarApiKeys() no se toca), mismo criterio de
 * duplicacion literal ya aplicado para certificado/CAF de produccion.
 *
 * @return list<array<string,mixed>>
 */
function listarApiKeysProduccion(PDO $pdo, int $cuentaId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, prefijo, ambiente, estado, last_used_at, created_at "
        . "FROM api_key WHERE cuenta_id = :cuenta_id AND ambiente = 'produccion' AND tipo = 'externa' ORDER BY created_at DESC"
    );
    $stmt->execute([':cuenta_id' => $cuentaId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===========================================================================
//  API key de SERVICIO (interna del panel) -- ver migracion 017.
//
//  A diferencia de las externas (secreto irrecuperable, solo key_hash), la key
//  de servicio guarda su secreto CIFRADO con el mismo envelope encryption que
//  los certificados (DEK aleatoria -> secreto_cifrado; DEK envuelta con la KEK
//  -> dek_envuelta). El panel la desencripta al emitir para el header X-Api-Key.
//  Invisible al usuario (listarApiKeys* filtran tipo='externa').
// ===========================================================================

/**
 * Desenvuelve el secreto de una key de servicio: KEK -> DEK (dek_envuelta),
 * DEK -> secreto (secreto_cifrado). Mismo patron que
 * MySqlEmisorRepository::obtenerCertificado.
 *
 * @throws CertificadoCryptoException si el material esta corrupto o la KEK no calza.
 */
function descifrarSecretoServicio(string $dekEnvuelta, string $secretoCifrado): string
{
    $dek = (new CertificadoCrypto(kekMaestra()))->descifrar($dekEnvuelta);
    return (new CertificadoCrypto($dek))->descifrar($secretoCifrado);
}

/**
 * Genera una key de servicio nueva para (cuenta, rut_emisor) en ambiente
 * produccion, la cifra y la persiste, garantizando la invariante "una sola
 * activa por cuenta" a nivel de aplicacion (MySQL no tiene indices unicos
 * filtrados): serializa con un lock por cuenta (SELECT ... FOR UPDATE sobre
 * cuenta) y, si otra transaccion ya creo una activa, la reutiliza; si no,
 * inserta la nueva y revoca cualquier otra activa por defensa.
 *
 * @return array{id:int, prefijo:string, secreto:string}
 */
function generarKeyServicio(PDO $pdo, int $cuentaId, string $rutEmisor): array
{
    $pdo->beginTransaction();
    try {
        // Mutex por cuenta: serializa generaciones concurrentes de la misma cuenta.
        $pdo->prepare('SELECT id FROM cuenta WHERE id = :c FOR UPDATE')
            ->execute([':c' => $cuentaId]);

        // Re-chequeo dentro del lock: si otra transaccion ya dejo una activa que
        // desenvuelve bien, se reutiliza (verificar-antes-de-insertar).
        $ex = $pdo->prepare(
            "SELECT id, prefijo, secreto_cifrado, dek_envuelta FROM api_key "
            . "WHERE cuenta_id = :c AND ambiente = 'produccion' AND tipo = 'servicio' "
            . "  AND estado = 'activa' ORDER BY id LIMIT 1"
        );
        $ex->execute([':c' => $cuentaId]);
        $fila = $ex->fetch(PDO::FETCH_ASSOC);
        if ($fila !== false) {
            $secreto = descifrarSecretoServicio((string) $fila['dek_envuelta'], (string) $fila['secreto_cifrado']);
            $pdo->commit();
            return ['id' => (int) $fila['id'], 'prefijo' => (string) $fila['prefijo'], 'secreto' => $secreto];
        }

        // Secreto (base64url de 32 bytes, sin punto) + prefijo unico marcado 'svc_'.
        $secreto = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $prefijo = null;
        for ($i = 0; $i < 5; $i++) {
            $cand = 'svc_' . bin2hex(random_bytes(4));
            $chk  = $pdo->prepare('SELECT 1 FROM api_key WHERE prefijo = :p LIMIT 1');
            $chk->execute([':p' => $cand]);
            if ($chk->fetchColumn() === false) {
                $prefijo = $cand;
                break;
            }
        }
        if ($prefijo === null) {
            throw new RuntimeException('No se pudo generar un prefijo unico para la key de servicio.');
        }

        $keyHash        = hash('sha256', $secreto);
        $dek            = random_bytes(32);
        $secretoCifrado = (new CertificadoCrypto($dek))->cifrar($secreto);
        $dekEnvuelta    = (new CertificadoCrypto(kekMaestra()))->cifrar($dek);

        $pdo->prepare(
            'INSERT INTO api_key '
            . '(cuenta_id, key_hash, prefijo, rut_emisor_scope, ambiente, estado, tipo, secreto_cifrado, dek_envuelta) '
            . "VALUES (:c, :hash, :prefijo, :rut, 'produccion', 'activa', 'servicio', :sc, :de)"
        )->execute([
            ':c'       => $cuentaId,
            ':hash'    => $keyHash,
            ':prefijo' => $prefijo,
            ':rut'     => $rutEmisor,
            ':sc'      => $secretoCifrado,
            ':de'      => $dekEnvuelta,
        ]);
        $nuevaId = (int) $pdo->lastInsertId();

        // Invariante (defensa): revoca cualquier OTRA activa de servicio de la cuenta.
        $pdo->prepare(
            "UPDATE api_key SET estado = 'revocada' "
            . "WHERE cuenta_id = :c AND ambiente = 'produccion' AND tipo = 'servicio' "
            . "  AND estado = 'activa' AND id != :id"
        )->execute([':c' => $cuentaId, ':id' => $nuevaId]);

        $pdo->commit();
        return ['id' => $nuevaId, 'prefijo' => $prefijo, 'secreto' => $secreto];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Devuelve el X-Api-Key ("prefijo.secreto") de la key de SERVICIO de la cuenta
 * (ambiente produccion), generandola de forma perezosa si no existe. Si la key
 * activa esta corrupta (no desenvuelve), la revoca, genera una nueva y registra
 * el evento en admin_auditoria (auto-recuperacion, no silenciosa).
 */
function obtenerKeyServicio(PDO $pdo, int $cuentaId, string $rutEmisor): string
{
    $stmt = $pdo->prepare(
        "SELECT id, prefijo, secreto_cifrado, dek_envuelta FROM api_key "
        . "WHERE cuenta_id = :c AND ambiente = 'produccion' AND tipo = 'servicio' "
        . "  AND estado = 'activa' ORDER BY id LIMIT 1"
    );
    $stmt->execute([':c' => $cuentaId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($fila !== false) {
        try {
            $secreto = descifrarSecretoServicio((string) $fila['dek_envuelta'], (string) $fila['secreto_cifrado']);
            return (string) $fila['prefijo'] . '.' . $secreto;
        } catch (CertificadoCryptoException $e) {
            // Corrupta: revocar, regenerar y AUDITAR la auto-recuperacion.
            $viejaId      = (int) $fila['id'];
            $viejoPrefijo = (string) $fila['prefijo'];
            $pdo->prepare("UPDATE api_key SET estado = 'revocada' WHERE id = :id")
                ->execute([':id' => $viejaId]);

            $nueva = generarKeyServicio($pdo, $cuentaId, $rutEmisor);
            registrarAuditoria(
                $pdo,
                Auth::usuarioId(),
                'apikey_servicio.auto_recuperacion',
                'api_key',
                $nueva['id'],
                ['motivo' => 'descifrado_fallido', 'revocada_id' => $viejaId, 'revocado_prefijo' => $viejoPrefijo],
                ['nueva_id' => $nueva['id'], 'nuevo_prefijo' => $nueva['prefijo']],
            );
            return $nueva['prefijo'] . '.' . $nueva['secreto'];
        }
    }

    $nueva = generarKeyServicio($pdo, $cuentaId, $rutEmisor);
    return $nueva['prefijo'] . '.' . $nueva['secreto'];
}

/**
 * Estado de los 4 pasos de PRODUCCION de un tenant, en solo lectura.
 *
 * Es la version OBSERVABLE de exigirProduccionCompleto(): mismas condiciones,
 * pero devolviendo el estado en vez de redirigir. El guard sigue siendo la
 * unica autoridad para dejar pasar o no; esta funcion existe para PINTAR el
 * avance (estacion 7 del dashboard) sin duplicar el criterio.
 *
 * OJO AL EDITAR: las 3 condiciones de "puede emitir" viven ahora en
 * estadoEmisionProduccion(), que es lo que consultan el guard y el menu
 * lateral. Esta funcion las REPITE a proposito, porque necesita reportar los 4
 * pasos por separado (incluida la api_key, que no bloquea emitir) y recibe el
 * rut por parametro en vez de derivarlo. Si cambia una condicion hay que
 * cambiarla en los DOS sitios: no hay nada que lo detecte automaticamente.
 *
 * TERCER CONSUMIDOR (nuevo): estacionesProduccion() arma con esto el stepper de
 * solo-produccion. Usa el detalle paso a paso que esta funcion reporta, pero
 * NO decide con el si el tenant puede emitir: eso se lo pregunta a
 * estadoEmisionProduccion() y lo recibe ya resuelto por parametro. Es decir, la
 * repeticion de condiciones sigue viviendo en DOS sitios, no en tres -- y asi
 * tiene que quedar.
 *
 * Las consultas de certificado y CAF son las mismas que ya usaba
 * handleAdminTenantsGet() para su resumen de superadmin; se centralizan aqui
 * para que tenant y superadmin no puedan divergir.
 *
 * La api_key se filtra por tipo='externa' y estado='activa'. Las de tipo
 * 'servicio' (migracion 017) las genera el panel solo, cifradas e invisibles al
 * usuario, cuando emite: contarlas haria que el paso apareciera "configurado"
 * despues de la primera emision sin que el tenant hubiera generado nunca una
 * credencial. handleAdminTenantsGet() aplica el mismo criterio en su propia
 * consulta por cuenta.
 *
 * @return array{empresa:bool, certificado:bool, caf:bool, apiKey:bool}
 */
function estadoProduccion(PDO $pdo, int $cuentaId, string $rutEmisor): array
{
    // Mismas condiciones que exigirProduccionCompleto(): no basta la fila de
    // produccion, tiene que traer la Resolucion real informada.
    $stmtEmpresa = $pdo->prepare(
        'SELECT 1 FROM dte_emisor '
        . "WHERE cuenta_id = :cuenta_id AND ambiente = 'produccion' "
        . 'AND resolucion_fecha IS NOT NULL AND resolucion_numero > 0 LIMIT 1'
    );
    $stmtEmpresa->execute([':cuenta_id' => $cuentaId]);

    $stmtCert = $pdo->prepare(
        "SELECT 1 FROM dte_certificado WHERE rut_emisor = :rut AND ambiente = 'produccion' LIMIT 1"
    );
    $stmtCert->execute([':rut' => $rutEmisor]);

    $stmtCaf = $pdo->prepare(
        "SELECT 1 FROM dte_caf WHERE rut_emisor = :rut AND ambiente = 'produccion' LIMIT 1"
    );
    $stmtCaf->execute([':rut' => $rutEmisor]);

    $stmtApiKey = $pdo->prepare(
        'SELECT 1 FROM api_key '
        . "WHERE cuenta_id = :cuenta_id AND ambiente = 'produccion' "
        . "AND tipo = 'externa' AND estado = 'activa' LIMIT 1"
    );
    $stmtApiKey->execute([':cuenta_id' => $cuentaId]);

    return [
        'empresa'     => $stmtEmpresa->fetchColumn() !== false,
        'certificado' => $stmtCert->fetchColumn() !== false,
        'caf'         => $stmtCaf->fetchColumn() !== false,
        'apiKey'      => $stmtApiKey->fetchColumn() !== false,
    ];
}

/**
 * Los 4 sub-pasos de la estacion 7, listos para pintar.
 *
 * 'obligatorio' distingue los 3 que exigirProduccionCompleto() realmente
 * verifica (sin ellos no se puede emitir) del 4to, que es para consumidores
 * externos del motor y NO bloquea la emision desde el panel: para eso el panel
 * usa su key de servicio, que se genera sola.
 *
 * El texto dice "Configurado" a proposito, nunca "autorizado": la app sabe que
 * el dato esta cargado, no sabe si el SII autorizo al contribuyente. Mismo
 * criterio que la estacion 6.
 *
 * @param array{empresa:bool, certificado:bool, caf:bool, apiKey:bool} $estado
 *
 * @return list<array<string,mixed>>
 */
function subpasosProduccion(array $estado): array
{
    return [
        [
            'titulo'      => 'Datos de empresa (Resolucion SII)',
            'destino'     => '/empresa-produccion',
            'obligatorio' => true,
            'completado'  => $estado['empresa'],
        ],
        [
            'titulo'      => 'Certificado digital de produccion',
            'destino'     => '/certificado-produccion',
            'obligatorio' => true,
            'completado'  => $estado['certificado'],
        ],
        [
            'titulo'      => 'CAF de produccion (folios reales)',
            'destino'     => '/caf-produccion',
            'obligatorio' => true,
            'completado'  => $estado['caf'],
        ],
        [
            'titulo'      => 'API key de produccion (opcional)',
            'destino'     => '/apikeys-produccion',
            'obligatorio' => false,
            'completado'  => $estado['apiKey'],
        ],
    ];
}

/**
 * Stepper de SOLO PRODUCCION, para el tenant que llego ya autorizado por el SII
 * y nunca paso por el circuito de certificacion.
 *
 * Mismo shape que el array de 7 estaciones de handlePanelGet(), asi que lo pinta
 * partials/_estaciones.php TAL CUAL, sin tocarlo: 'titulo', 'estado' y 'enlace'
 * opcional. Ese partial ya era agnostico del ambiente; lo unico que le faltaba
 * era que alguien le pasara la otra definicion.
 *
 * SON 6 FILAS, no 7. Las estaciones 5 y 6 del circuito de certificacion (sets de
 * prueba y certificacion aprobada) NO tienen equivalente aqui: son el tramite
 * ante el SII, que este tenant ya trae hecho de antes de llegar. Y la 7 ("En
 * produccion") deja de ser una estacion futura para convertirse en la ultima
 * fila, "Listo para emitir": aqui produccion no es el destino lejano, es todo
 * el camino.
 *
 * ENCADENADO IGUAL QUE EL SERVIDOR: cada fila queda 'inactiva' mientras la
 * anterior no este, en el mismo orden en que exigirProduccionCompleto() encadena
 * sus redirects. No se ofrece un enlace que el guard vaya a rebotar.
 *
 * DE DONDE SALE CADA COSA:
 *   - el detalle paso a paso, de estadoProduccion() (por eso lo recibe armado);
 *   - "puede emitir", de estadoEmisionProduccion(), YA RESUELTO por el
 *     llamador. Esta funcion no vuelve a comprobar las 3 condiciones ni podria:
 *     no tiene con que. Ver la nota de TERCER CONSUMIDOR en estadoProduccion().
 *
 * La fila de API keys va aunque no bloquee emitir -- el titulo dice "(opcional)"
 * y queda 'inactiva' hasta que se pueda emitir, que es cuando su ruta deja de
 * rebotar. _estaciones.php no tiene un estado "opcional" y no se le agrega uno
 * por esto.
 *
 * @param array{empresa:bool, certificado:bool, caf:bool, apiKey:bool} $estado
 *
 * @return list<array<string,mixed>>
 */
function estacionesProduccion(array $estado, bool $puedeEmitir): array
{
    return [
        ['titulo' => 'Registrado',                        'estado' => 'completado'],
        ['titulo' => 'Datos de empresa (Resolucion SII)', 'estado' => $estado['empresa'] ? 'completado' : 'pendiente', 'enlace' => '/empresa-produccion'],
        ['titulo' => 'Certificado digital de produccion', 'estado' => $estado['certificado'] ? 'completado' : ($estado['empresa'] ? 'pendiente' : 'inactiva'), 'enlace' => '/certificado-produccion'],
        ['titulo' => 'CAF de produccion (folios reales)', 'estado' => $estado['caf'] ? 'completado' : ($estado['certificado'] ? 'pendiente' : 'inactiva'), 'enlace' => '/caf-produccion'],
        ['titulo' => 'API key de produccion (opcional)',  'estado' => $estado['apiKey'] ? 'completado' : ($puedeEmitir ? 'pendiente' : 'inactiva'), 'enlace' => '/apikeys-produccion'],
        ['titulo' => 'Listo para emitir',                 'estado' => $puedeEmitir ? 'completado' : 'inactiva'],
    ];
}

/**
 * LAS 3 CONDICIONES PARA EMITIR EN PRODUCCION, EN UN SOLO SITIO.
 *
 * Para ambiente='produccion': fila dte_emisor con resolucion_fecha/
 * resolucion_numero informados (NOT NULL en el schema, y resolucion_numero > 0
 * -- ver validacion de handleEmpresaProduccionPost(), que exige entero
 * positivo), fila dte_certificado, y al menos una fila dte_caf.
 *
 * NO redirige ni corta la ejecucion: devuelve QUE falta. Es lo que permite que
 * el guard del servidor (exigirProduccionCompleto) y el menu lateral usen el
 * mismo criterio sin duplicarlo. Antes no existia este predicado y el menu
 * resolvia por su cuenta con una condicion distinta; ahi nacio la divergencia.
 *
 * 'falta' nombra el PRIMER eslabon que falla, en el mismo orden en que
 * exigirProduccionCompleto() encadena sus redirects. Cuando es null estan las
 * tres y 'rut' trae el rut_emisor.
 *
 * El rut se deriva de la fila de PRODUCCION, no de la de certificacion: es el
 * emisor con el que se va a emitir de verdad.
 *
 * @return array{rut: ?string, falta: ?string}  falta: 'empresa'|'certificado'|'caf'|null
 */
function estadoEmisionProduccion(PDO $pdo, int $cuentaId): array
{
    $stmt = $pdo->prepare(
        'SELECT rut_emisor FROM dte_emisor '
        . "WHERE cuenta_id = :cuenta_id AND ambiente = 'produccion' "
        . 'AND resolucion_fecha IS NOT NULL AND resolucion_numero > 0 LIMIT 1'
    );
    $stmt->execute([':cuenta_id' => $cuentaId]);
    $rutEmisor = $stmt->fetchColumn();
    if ($rutEmisor === false) {
        return ['rut' => null, 'falta' => 'empresa'];
    }

    $stmtCert = $pdo->prepare(
        "SELECT 1 FROM dte_certificado WHERE rut_emisor = :rut AND ambiente = 'produccion' LIMIT 1"
    );
    $stmtCert->execute([':rut' => $rutEmisor]);
    if ($stmtCert->fetchColumn() === false) {
        return ['rut' => null, 'falta' => 'certificado'];
    }

    $stmtCaf = $pdo->prepare(
        "SELECT 1 FROM dte_caf WHERE rut_emisor = :rut AND ambiente = 'produccion' LIMIT 1"
    );
    $stmtCaf->execute([':rut' => $rutEmisor]);
    if ($stmtCaf->fetchColumn() === false) {
        return ['rut' => null, 'falta' => 'caf'];
    }

    return ['rut' => (string) $rutEmisor, 'falta' => null];
}

/**
 * rut_emisor de la fila de PRODUCCION de una cuenta, o null si no hay fila.
 *
 * POR QUE EXISTE, teniendo estadoEmisionProduccion() al lado: aquella devuelve
 * el rut SOLO cuando las 3 condiciones estan completas -- en sus tres salidas
 * tempranas retorna 'rut' => null a proposito, porque su contrato es "puede
 * emitir", no "que rut tiene". Un tenant que cargo la empresa de produccion y
 * todavia no el certificado necesita que alguien le diga su rut para poder
 * PINTAR ese avance, y no habia de donde sacarlo.
 *
 * NO duplica ninguna de las 3 condiciones: solo lee la fila. Si hace falta
 * saber si esa fila sirve para emitir, eso lo sigue contestando
 * estadoEmisionProduccion() y nadie mas.
 */
function rutEmisorProduccion(PDO $pdo, int $cuentaId): ?string
{
    $stmt = $pdo->prepare(
        "SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'produccion' LIMIT 1"
    );
    $stmt->execute([':cuenta_id' => $cuentaId]);
    $rut = $stmt->fetchColumn();

    return $rut === false ? null : (string) $rut;
}

/**
 * Guard de las rutas operativas. Duplicado de exigirOnboardingCompleto() para
 * el ambiente de PRODUCCION -- funcion NUEVA e independiente
 * (exigirOnboardingCompleto() no se toca). Encadena igual que aquel: redirige a
 * la estacion de PRODUCCION que falte (nunca a las de certificacion) y devuelve
 * el rut_emisor cuando todo esta completo.
 *
 * CONTRATO SIN CAMBIOS: misma firma, mismo valor de retorno y el mismo redirect
 * en el mismo caso que antes. Lo unico que cambio es de donde salen las tres
 * comprobaciones -- ahora de estadoEmisionProduccion(), para que el menu pueda
 * preguntar lo mismo sin duplicar la logica. Los 25 llamadores no se tocan.
 */
function exigirProduccionCompleto(PDO $pdo, int $cuentaId): string
{
    $estado = estadoEmisionProduccion($pdo, $cuentaId);

    $rutaQueFalta = [
        'empresa'     => '/empresa-produccion',
        'certificado' => '/certificado-produccion',
        'caf'         => '/caf-produccion',
    ];
    if ($estado['falta'] !== null) {
        redirigir($rutaQueFalta[$estado['falta']]);
    }

    return (string) $estado['rut'];
}

/**
 * Lista los dte_emitido del SET BASICO (factura 33 / NC 61 / ND 56) del
 * rut_emisor, ambiente certificacion. Boleta (39) NO entra aqui: se certifica
 * en un proceso aparte del SII (ver agruparEmitidosPorEnvio()/setBasicoAprobado()).
 *
 * @return list<array<string,mixed>>
 */
function listarEmitidosFactura(PDO $pdo, string $rutEmisor): array
{
    $stmt = $pdo->prepare(
        'SELECT id, tipo_dte, folio, track_id, estado, fecha_emision '
        . 'FROM dte_emitido '
        . "WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND tipo_dte IN (33, 61, 56) "
        . 'ORDER BY tipo_dte ASC, folio ASC'
    );
    $stmt->execute([':rut' => $rutEmisor]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Agrupa los dte_emitido del set basico por ENVIO (track_id).
 *
 * El estado que devuelve el SII (EPR/RCT/RFR/-11/...) es del ENVIO completo,
 * no de cada documento: todos los documentos de un mismo EnvioDTE comparten
 * track_id y por lo tanto el MISMO estado (ver
 * SiiConsultor::consultarEnvio(), SOAP QueryEstUp.jws). Antes esta vista
 * listaba documento por documento (8 documentos x hasta 8 intentos de
 * certificacion = 64 filas y 64 botones "Actualizar estado" en la
 * certificacion real de EASY AGENDA SPA, 78157243-8), lo cual era inusable y
 * ademas podia mostrar estados distintos para filas de un mismo envio.
 *
 * Documentos sin track_id se devuelven aparte (sinTrackId): no hay nada que
 * consultar ni agrupar para ellos.
 *
 * @param list<array<string,mixed>> $emitidos
 * @return array{
 *     envios: list<array{trackId:string, estado:string, fechaEmision:string, resumen:string, tipos:list<int>, documentos:list<array<string,mixed>>}>,
 *     sinTrackId: list<array<string,mixed>>,
 * }
 */
function agruparEmitidosPorEnvio(array $emitidos): array
{
    $porTrack   = [];
    $sinTrackId = [];

    foreach ($emitidos as $e) {
        $trackId = $e['track_id'];
        if ($trackId === null || $trackId === '') {
            $sinTrackId[] = $e;
            continue;
        }
        $porTrack[(string) $trackId][] = $e;
    }

    $envios = [];
    foreach ($porTrack as $trackId => $docs) {
        // El estado mostrado es el de la fila mas reciente (mayor id): si hay
        // inconsistencia historica entre filas de un mismo envio (el bug que
        // esta agrupacion corrige de raiz hacia adelante), se muestra el
        // ultimo valor persistido, no uno arbitrario.
        usort($docs, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $masReciente = $docs[array_key_last($docs)];

        $conteos = [];
        foreach ($docs as $d) {
            $tipo = (int) $d['tipo_dte'];
            $conteos[$tipo] = ($conteos[$tipo] ?? 0) + 1;
        }
        $resumen = [];
        foreach ($conteos as $tipo => $n) {
            $resumen[] = sprintf('%dx %s', $n, TipoDte::nombreDe((int) $tipo));
        }

        $envios[] = [
            'trackId'      => $trackId,
            'estado'       => $masReciente['estado'],
            'fechaEmision' => $docs[0]['fecha_emision'],
            'resumen'      => implode(', ', $resumen),
            'tipos'        => array_keys($conteos),
            'documentos'   => $docs,
            'ultimoId'     => (int) $masReciente['id'],
        ];
    }

    // Envio mas reciente arriba. Se ordena por el mayor id de fila (orden de
    // insercion real), no por track_id ni fecha: cada reintento de
    // certificacion crea filas NUEVAS con folios nuevos, asi que el id mas
    // alto es siempre el envio mas reciente, sin asumir nada sobre el formato
    // del track_id que asigna el SII.
    usort($envios, static fn (array $a, array $b): int => $b['ultimoId'] <=> $a['ultimoId']);

    return ['envios' => $envios, 'sinTrackId' => $sinTrackId];
}

/**
 * Determina si el SET BASICO esta APROBADO: el SII exige que los 3 tipos (33
 * factura / 61 NC / 56 ND) vayan en UN SOLO EnvioDTE aceptado (EPR), Y que el
 * tenant haya confirmado a mano que ESE envio paso la revision de CONTENIDO
 * del SII (SOK) -- EPR por si solo NO alcanza (bug real corregido: ver
 * CertificacionEstadoResolver::setBasicoAprobado(), donde vive ahora la
 * logica -- movida a src/Sii/ para poder testearla sin requerir este front
 * controller completo).
 *
 * @param list<array{trackId:string, estado:string, tipos:list<int>}> $envios Salida de agruparEmitidosPorEnvio()['envios']
 * @param array<string,string> $sokPorTrackId Salida de MySqlSetBasicoSokRepository::confirmadosPorTrackId(): track_id => confirmado_sok_at
 * @return array{aprobado:bool, trackId:?string}
 */
function setBasicoAprobado(array $envios, array $sokPorTrackId): array
{
    return CertificacionEstadoResolver::setBasicoAprobado($envios, $sokPorTrackId);
}

/**
 * Identifica el(los) candidato(s) a envio de SIMULACION. A diferencia del Set
 * Basico (setBasicoAprobado()), el SII no da ningun campo/estado propio que
 * distinga "esto es la simulacion" -- se infiere con este criterio, elegido
 * tras evaluar alternativas:
 *   - Excluye el propio envio del Set Basico ya identificado.
 *   - Exige estado EPR (envio aceptado).
 *   - Exige MAS de 8 documentos: el Set Basico son EXACTAMENTE 8 (4 factura +
 *     3 NC + 1 ND); la Simulacion la exige el SII en el rango 20-100
 *     (evidencia real: EASY AGENDA 30 docs -- respuesta_simulacion_v2.json --
 *     y Plantiflex 30 docs, docs/CERTIFICACION_MUESTRAS_IMPRESAS.md).
 * Alternativa evaluada y descartada: "lo que sobra tras excluir el Set
 * Basico" da el MISMO resultado pero es menos explicita sobre POR QUE se
 * descarta un envio que no sea el de simulacion (ej. un intento de Set
 * Basico rechazado con 2-3 documentos sueltos); el corte por cantidad (>8)
 * dice la razon directamente y evita ese falso positivo.
 * Si hay MAS DE UN candidato (ej. la simulacion se reenvio una vez), NO se
 * adivina cual es la vigente: se devuelven TODOS para que la vista le pida
 * al tenant que elija (un <select>, no una deteccion silenciosa incorrecta).
 *
 * @param list<array{trackId:string, estado:string, fechaEmision:string, resumen:string, documentos:list<array<string,mixed>>}> $envios Salida de agruparEmitidosPorEnvio()['envios']
 * @return list<array{trackId:string, fechaEmision:string, resumen:string, cantidad:int}>
 */
function simulacionCandidatos(array $envios, ?string $trackIdSetBasico): array
{
    $candidatos = [];
    foreach ($envios as $envio) {
        if ($trackIdSetBasico !== null && $envio['trackId'] === $trackIdSetBasico) {
            continue;
        }
        if ($envio['estado'] !== 'EPR' || count($envio['documentos']) <= 8) {
            continue;
        }
        $candidatos[] = [
            'trackId'      => $envio['trackId'],
            'fechaEmision' => $envio['fechaEmision'],
            'resumen'      => $envio['resumen'],
            'cantidad'     => count($envio['documentos']),
        ];
    }

    return $candidatos;
}

/**
 * Resuelve el envio de SIMULACION: si hay exactamente un candidato
 * (simulacionCandidatos()), se toma como aprobado sin preguntar; si hay 0,
 * aun no existe; si hay MAS de uno, es ambiguo y el llamador debe pedirle al
 * tenant que elija (ver 'candidatos').
 *
 * @param list<array{trackId:string, estado:string, fechaEmision:string, resumen:string, documentos:list<array<string,mixed>>}> $envios
 * @return array{aprobado:bool, trackId:?string, ambiguo:bool, candidatos:list<array<string,mixed>>}
 */
function simulacionAprobada(array $envios, ?string $trackIdSetBasico): array
{
    $candidatos = simulacionCandidatos($envios, $trackIdSetBasico);

    if (count($candidatos) === 1) {
        return ['aprobado' => true, 'trackId' => $candidatos[0]['trackId'], 'ambiguo' => false, 'candidatos' => []];
    }
    if ($candidatos === []) {
        return ['aprobado' => false, 'trackId' => null, 'ambiguo' => false, 'candidatos' => []];
    }

    return ['aprobado' => false, 'trackId' => null, 'ambiguo' => true, 'candidatos' => $candidatos];
}

/**
 * Codigos de estado de un ENVIO DE LIBRO que el SII considera aceptado --
 * suficiente para avanzar a "Declarar Avance" en el portal de certificacion:
 *   LOK = aceptado sin reparos
 *   LTC = aceptado con reparos
 * Fuente: docs/REFERENCIA_CERTIFICACION_SII_DTE.md lineas 61-64 ("enviar ->
 * LOK/LTC -> Declarar Avance"). LNC ("Tipo de Envio de Libro No Corresponde",
 * ej. reenviar un TOTAL del mismo periodo -- observado real en el tenant
 * 78157243-8, trackId 0253052338) y LRC son estados de RECHAZO: NO cuentan
 * como aceptado. NO se adivino: confirmado contra el doc antes de codear.
 */
const ESTADOS_LIBRO_ACEPTADO = ['LOK', 'LTC'];

/**
 * Lista los libros IECV enviados (dte_libro) del rut_emisor, ambiente
 * certificacion, filtrados por tipo de operacion (VENTA/COMPRA). Envio mas
 * reciente primero (mayor id = insercion mas reciente, mismo criterio de
 * orden que agruparEmitidosPorEnvio() usa para el set basico).
 *
 * @return list<array<string,mixed>>
 */
function listarLibros(PDO $pdo, string $rutEmisor, string $tipoOperacion): array
{
    $stmt = $pdo->prepare(
        'SELECT id, track_id, estado, periodo_tributario, tipo_libro, tipo_envio, folio_notificacion, created_at '
        . 'FROM dte_libro '
        . "WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND tipo_operacion = :tipo "
        . 'ORDER BY id DESC'
    );
    $stmt->execute([':rut' => $rutEmisor, ':tipo' => $tipoOperacion]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * True si existe al menos un libro (de un tipo de operacion) en estado
 * aceptado (ver ESTADOS_LIBRO_ACEPTADO). Devuelve el trackId de ese libro para
 * mostrarlo en la vista, igual que setBasicoAprobado().
 *
 * @param list<array<string,mixed>> $libros Salida de listarLibros()
 * @return array{aprobado:bool, trackId:?string}
 */
function libroAprobado(array $libros): array
{
    foreach ($libros as $libro) {
        if (in_array($libro['estado'], ESTADOS_LIBRO_ACEPTADO, true)) {
            return ['aprobado' => true, 'trackId' => $libro['track_id']];
        }
    }

    return ['aprobado' => false, 'trackId' => null];
}

/**
 * True si los 3 componentes que exige el SII para certificar factura estan
 * APROBADOS (ver docs/REFERENCIA_CERTIFICACION_SII_DTE.md seccion 4):
 *   1. Set Basico      -> un envio en EPR con los 3 tipos (setBasicoAprobado()).
 *   2. Libro de Ventas -> un envio en LOK/LTC (libroAprobado()).
 *   3. Libro de Compras-> un envio en LOK/LTC (libroAprobado()).
 * UNICA funcion de este criterio: la usan la estacion 5 (handlePanelGet()) y
 * la guardia de la estacion 6 (handleCertificacionAprobadaGet() y
 * handleCertificacionAprobadaConfirmarPost()), para que las tres queden
 * garantizadas consistentes entre si -- antes esta funcion (entonces
 * setBasicoCompleto()) solo miraba el set basico, lo que era un falso
 * positivo: un tenant podia llegar a la estacion 6 sin haber enviado libros.
 */
function certificacionCompleta(PDO $pdo, string $rutEmisor): bool
{
    $sokPorTrackId = (new MySqlSetBasicoSokRepository($pdo))->confirmadosPorTrackId($rutEmisor, Ambiente::Certificacion);
    $setBasico = setBasicoAprobado(agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor))['envios'], $sokPorTrackId);
    $ventas    = libroAprobado(listarLibros($pdo, $rutEmisor, 'VENTA'));
    $compras   = libroAprobado(listarLibros($pdo, $rutEmisor, 'COMPRA'));

    return $setBasico['aprobado'] && $ventas['aprobado'] && $compras['aprobado'];
}

/**
 * Fecha de confirmacion de certificacion del emisor de esta cuenta (ambiente
 * certificacion), o null si no esta confirmada (o no hay fila de emisor).
 * Scope estricto por cuenta_id: nunca se consulta un emisor ajeno.
 */
function obtenerCertificacionConfirmadaAt(PDO $pdo, int $cuentaId): ?string
{
    $stmt = $pdo->prepare(
        "SELECT certificacion_confirmada_at FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmt->execute([':cuenta_id' => $cuentaId]);
    $valor = $stmt->fetchColumn();

    return ($valor === false || $valor === null) ? null : (string) $valor;
}

/**
 * Fila cruda de las 3 columnas *_at (+ simulacion_track_id) de las etapas
 * manuales 2-4 (Simulacion, Intercambio, Muestras Impresas), ver migracion
 * 010_emisor_etapas_manuales.sql. Scope estricto por cuenta_id, mismo
 * criterio que obtenerCertificacionConfirmadaAt(). Si no hay fila de emisor
 * (no deberia pasar tras exigirOnboardingCompleto(), pero se cubre igual),
 * devuelve las 4 columnas en null.
 *
 * @return array{simulacion_confirmada_at:?string, simulacion_track_id:?string,
 *         intercambio_confirmado_at:?string, muestras_impresas_confirmadas_at:?string}
 */
function obtenerEtapasManualesRaw(PDO $pdo, int $cuentaId): array
{
    $stmt = $pdo->prepare(
        'SELECT simulacion_confirmada_at, simulacion_track_id, intercambio_confirmado_at, '
        . "muestras_impresas_confirmadas_at FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmt->execute([':cuenta_id' => $cuentaId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    return $fila !== false ? $fila : [
        'simulacion_confirmada_at'         => null,
        'simulacion_track_id'              => null,
        'intercambio_confirmado_at'        => null,
        'muestras_impresas_confirmadas_at' => null,
    ];
}

/**
 * Encadena la habilitacion de las etapas manuales 2-4 igual que Set Basico ->
 * Libro de Ventas/Compras (ver $todosAprobados en handleCertificacionGet()):
 * Simulacion exige la etapa 1 (Set de Prueba) completa; Intercambio exige
 * Simulacion confirmada; Muestras Impresas exige Intercambio confirmado.
 *
 * @param array{simulacion_confirmada_at:?string, simulacion_track_id:?string,
 *        intercambio_confirmado_at:?string, muestras_impresas_confirmadas_at:?string} $raw
 * @return array{
 *     simulacion:array{confirmada:bool,fecha:?string,trackId:?string,habilitada:bool},
 *     intercambio:array{confirmada:bool,fecha:?string,habilitada:bool},
 *     muestrasImpresas:array{confirmada:bool,fecha:?string,habilitada:bool}
 * }
 */
function calcularEtapasManuales(array $raw, bool $todosAprobados): array
{
    $simulacionConfirmada  = $raw['simulacion_confirmada_at'] !== null;
    $intercambioConfirmado = $raw['intercambio_confirmado_at'] !== null;
    $muestrasConfirmadas   = $raw['muestras_impresas_confirmadas_at'] !== null;

    return [
        'simulacion' => [
            'confirmada' => $simulacionConfirmada,
            'fecha'      => $raw['simulacion_confirmada_at'],
            'trackId'    => $raw['simulacion_track_id'],
            'habilitada' => $todosAprobados,
        ],
        'intercambio' => [
            'confirmada' => $intercambioConfirmado,
            'fecha'      => $raw['intercambio_confirmado_at'],
            'habilitada' => $simulacionConfirmada,
        ],
        'muestrasImpresas' => [
            'confirmada' => $muestrasConfirmadas,
            'fecha'      => $raw['muestras_impresas_confirmadas_at'],
            'habilitada' => $intercambioConfirmado,
        ],
    ];
}

// ===========================================================================
//  Sesion de DEMOSTRACION (solo lectura) -- ver migracion 029_usuario_demo.sql
// ===========================================================================

/**
 * Si el usuario de la sesion actual tiene usuario.demo = 1.
 *
 * SE PREGUNTA A LA BASE EN CADA POST, no a $_SESSION. Cachear la bandera en la
 * sesion ahorraria un SELECT por clave primaria -- nada -- a cambio de que
 * apagar el demo (UPDATE usuario SET demo = 0) no tuviera efecto hasta que la
 * persona cerrara sesion. Para una cuenta que existe justamente para entregarse
 * a terceros, la revocacion tiene que ser inmediata.
 *
 * Sin sesion devuelve false a proposito: POST /login y POST /registro corren
 * antes de que exista usuario_id, y son los dos POST que un demo SI necesita
 * poder hacer (sin el primero no podria ni entrar).
 */
function sesionEsDemo(): bool
{
    if (! Auth::autenticado()) {
        return false;
    }

    $stmt = Db::conexion()->prepare('SELECT demo FROM usuario WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => Auth::usuarioId()]);

    return (int) $stmt->fetchColumn() === 1;
}

/**
 * Corta el request con la pantalla de "modo demostracion" y termina.
 *
 * NO es un 403 pelado: esto lo va a ver un prospecto haciendo clic en botones
 * durante una presentacion, no un atacante. La pagina dice que el sistema hace
 * la accion de verdad y que en esta cuenta esta desactivada, y ofrece volver.
 *
 * 403 igual en el codigo de estado: la accion efectivamente se rechazo, y un
 * 200 aqui haria que cualquier chequeo automatico creyera que se ejecuto.
 */
function cortarPorDemo(): never
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        . '<title>Modo demostracion</title>'
        . '<link rel="stylesheet" href="/css/style.css"></head><body>'
        . '<h1>Modo demostracion</h1>'
        . '<p>Esta es una cuenta de <strong>solo lectura</strong>, pensada para recorrer el '
        . 'sistema completo sin alterar datos. La accion que acabas de intentar existe y '
        . 'funciona en una cuenta normal &mdash; emitir documentos ante el SII, cargar '
        . 'certificados y CAF, enviar correos, editar maestros &mdash;, pero aqui esta '
        . 'desactivada a proposito.</p>'
        . '<p>Todas las pantallas siguen disponibles para navegar.</p>'
        . '<p><a href="javascript:history.back()">Volver atras</a> &middot; '
        . '<a href="/panel">Ir al dashboard</a></p>'
        . '</body></html>';
    exit;
}

// ===========================================================================
//  Router: metodo y ruta
// ===========================================================================
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$ruta   = rtrim($ruta, '/');
if ($ruta === '') {
    $ruta = '/';
}

// ===========================================================================
//  CSRF: chequeo CENTRAL, antes de despachar a CUALQUIER handler POST.
//
//  Se verifica aqui -- una sola vez para las ~31 rutas POST de este router --
//  en vez de repetir Csrf::validar() en cada handler. Cubre TODAS las rutas
//  POST sin excepcion, incluidas /login, /registro y las de /admin/tenants/*
//  (ninguna pasa por Auth::requerirSesion() en el router, pero todas viven
//  sobre la MISMA sesion PHP que arranca Auth::iniciar() para cualquier
//  visitante -- el token existe aunque todavia no haya usuario autenticado).
//  /login en particular SI necesita CSRF (previene "login CSRF": forzar a la
//  victima a autenticarse en una cuenta del atacante sin que se de cuenta).
//
//  Este panel es la UNICA superficie que usa cookies de sesion: la API real
//  del motor (POST /api/v1/dte, /api/v1/boleta, /api/v1/libro, etc.) vive en
//  un front controller COMPLETAMENTE APARTE (public/index.php en la raiz del
//  repo, no panel/public/index.php) y se autentica por X-Api-Key, nunca por
//  cookie -- por eso no aplica CSRF ahi y no hay nada que excluir aqui.
// ===========================================================================
if ($metodo === 'POST' && ! Csrf::validar((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Solicitud invalida</title></head><body>'
        . '<h1>403 - Solicitud invalida</h1>'
        . '<p>El token de seguridad de este formulario vencio o no es valido (la pagina pudo quedar '
        . 'abierta demasiado tiempo, o el formulario no vino de este sitio). '
        . 'Vuelve atras, recarga la pagina y vuelve a intentarlo.</p>'
        . '</body></html>';
    exit;
}

// ===========================================================================
//  DEMO: bloqueo CENTRAL de escritura, antes de despachar a CUALQUIER handler.
//
//  Va aqui, junto al CSRF y por el MISMO argumento, que ya se demostro correcto
//  para las ~58 rutas POST de este router: una regla que vale para todas se
//  aplica una vez, no se reparte en 58 handlers de los que alguien se va a
//  olvidar. La propiedad que importa no es que hoy queden cubiertas todas las
//  rutas -- eso lo daria tambien un guard por handler -- sino que la ruta POST
//  que se escriba MANANA nazca cubierta sin que su autor sepa que esto existe.
//
//  POR QUE EL METODO ES EL CRITERIO COMPLETO. En este router la separacion es
//  limpia y verificable: TODA mutacion de estado del tenant es un POST (emitir,
//  cargar certificado/CAF, generar y revocar api keys, confirmar etapas de
//  certificacion, reintentar correos, alta/baja de clientes, productos y
//  usuarios, y las acciones de superadmin sobre tenants). Los GET renderizan,
//  listan o proxean binarios ya generados. Bloquear POST bloquea, por lo tanto,
//  el conjunto entero de acciones con efecto.
//
//  LA UNICA ESCRITURA QUE SOBREVIVE ES DELIBERADA: obtenerKeyServicio() crea la
//  api_key de tipo 'servicio' con la que el propio panel le habla al motor si no
//  existe, y eso pasa en GET. Es interna (nunca se muestra ni se entrega), no
//  toca datos del tenant y no contacta al SII; ademas, para la cuenta demo se
//  siembra por adelantado, asi que en la practica no se dispara. Bloquearla
//  romperia el panel de emision y los informes, que es justamente lo que hay que
//  mostrar.
//
//  NO cubre la API del motor (X-Api-Key, front controller aparte, ver la nota
//  del bloque CSRF de arriba): esa superficie no ve esta bandera. La contencion
//  ahi es no darle al demo ninguna api_key 'externa' activa.
// ===========================================================================
if ($metodo === 'POST' && sesionEsDemo()) {
    cortarPorDemo();
}

if ($metodo === 'GET' && $ruta === '/') {
    redirigir(Auth::autenticado() ? '/panel' : '/login');
}

if ($metodo === 'GET' && $ruta === '/registro') {
    if (Auth::autenticado()) {
        redirigir('/panel');
    }
    vista('registro', ['errores' => [], 'email' => '']);
}

if ($metodo === 'POST' && $ruta === '/registro') {
    handleRegistroPost();
}

if ($metodo === 'GET' && $ruta === '/login') {
    if (Auth::autenticado()) {
        redirigir('/panel');
    }
    vista('login', ['error' => null, 'email' => '']);
}

if ($metodo === 'POST' && $ruta === '/login') {
    handleLoginPost();
}

if ($metodo === 'GET' && $ruta === '/logout') {
    Auth::logout();
    redirigir('/login');
}

// --- Activacion de invitacion (M6, publica, sin sesion) ---
if ($metodo === 'GET' && preg_match('#^/activar/([0-9a-f]{64})$#', $ruta, $mActivar)) {
    handleActivarCuentaGet($mActivar[1]);
}

if ($metodo === 'POST' && preg_match('#^/activar/([0-9a-f]{64})$#', $ruta, $mActivar)) {
    handleActivarCuentaPost($mActivar[1]);
}

// --- Maestros > Clientes ---
if ($metodo === 'GET' && $ruta === '/maestros/clientes') {
    Auth::requerirSesion();
    handleClientesListar();
}

if ($metodo === 'GET' && $ruta === '/maestros/clientes/nuevo') {
    Auth::requerirSesion();
    handleClienteNuevoGet();
}

if ($metodo === 'POST' && $ruta === '/maestros/clientes/nuevo') {
    Auth::requerirSesion();
    handleClienteNuevoPost();
}

if ($metodo === 'GET' && preg_match('#^/maestros/clientes/(\d+)/editar$#', $ruta, $mCli)) {
    Auth::requerirSesion();
    handleClienteEditarGet((int) $mCli[1]);
}

if ($metodo === 'POST' && preg_match('#^/maestros/clientes/(\d+)/editar$#', $ruta, $mCli)) {
    Auth::requerirSesion();
    handleClienteEditarPost((int) $mCli[1]);
}

if ($metodo === 'POST' && preg_match('#^/maestros/clientes/(\d+)/activar$#', $ruta, $mCli)) {
    Auth::requerirSesion();
    handleClienteActivarPost((int) $mCli[1]);
}

if ($metodo === 'POST' && preg_match('#^/maestros/clientes/(\d+)/desactivar$#', $ruta, $mCli)) {
    Auth::requerirSesion();
    handleClienteDesactivarPost((int) $mCli[1]);
}

// --- Maestros > Productos ---
if ($metodo === 'GET' && $ruta === '/maestros/productos') {
    Auth::requerirSesion();
    handleProductosListar();
}

if ($metodo === 'GET' && $ruta === '/maestros/productos/nuevo') {
    Auth::requerirSesion();
    handleProductoNuevoGet();
}

if ($metodo === 'POST' && $ruta === '/maestros/productos/nuevo') {
    Auth::requerirSesion();
    handleProductoNuevoPost();
}

if ($metodo === 'GET' && preg_match('#^/maestros/productos/(\d+)/editar$#', $ruta, $mProd)) {
    Auth::requerirSesion();
    handleProductoEditarGet((int) $mProd[1]);
}

if ($metodo === 'POST' && preg_match('#^/maestros/productos/(\d+)/editar$#', $ruta, $mProd)) {
    Auth::requerirSesion();
    handleProductoEditarPost((int) $mProd[1]);
}

if ($metodo === 'POST' && preg_match('#^/maestros/productos/(\d+)/activar$#', $ruta, $mProd)) {
    Auth::requerirSesion();
    handleProductoActivarPost((int) $mProd[1]);
}

if ($metodo === 'POST' && preg_match('#^/maestros/productos/(\d+)/desactivar$#', $ruta, $mProd)) {
    Auth::requerirSesion();
    handleProductoDesactivarPost((int) $mProd[1]);
}

// --- Ventas > Emision unitaria (M3) ---
if ($metodo === 'GET' && $ruta === '/ventas/cliente-por-rut') {
    Auth::requerirSesion();
    handleClientePorRutGet();
}

if ($metodo === 'GET' && $ruta === '/ventas/resultado') {
    Auth::requerirSesion();
    handleEmisionResultadoGet();
}

if ($metodo === 'GET' && $ruta === '/ventas/factura') {
    Auth::requerirSesion();
    handleEmisionGet(33);
}
if ($metodo === 'POST' && $ruta === '/ventas/factura') {
    Auth::requerirSesion();
    handleEmisionPost(33);
}

// Factura exenta (34). Mismo par GET/POST y los MISMOS handlers que los otros
// tres tipos: handleEmisionGet/handleEmisionPost ya son genericos en $tipoDte.
// Lo unico propio del 34 vive donde corresponde -- el forzado de lineas exentas
// en armarDocumentoEmision() y la validacion en el motor --, no en una ruta
// especial.
if ($metodo === 'GET' && $ruta === '/ventas/factura-exenta') {
    Auth::requerirSesion();
    handleEmisionGet(34);
}
if ($metodo === 'POST' && $ruta === '/ventas/factura-exenta') {
    Auth::requerirSesion();
    handleEmisionPost(34);
}

if ($metodo === 'GET' && $ruta === '/ventas/nota-credito') {
    Auth::requerirSesion();
    handleEmisionGet(61);
}
if ($metodo === 'POST' && $ruta === '/ventas/nota-credito') {
    Auth::requerirSesion();
    handleEmisionPost(61);
}

if ($metodo === 'GET' && $ruta === '/ventas/nota-debito') {
    Auth::requerirSesion();
    handleEmisionGet(56);
}
if ($metodo === 'POST' && $ruta === '/ventas/nota-debito') {
    Auth::requerirSesion();
    handleEmisionPost(56);
}

// --- Informes ---
// Tres bloques para seis informes: la clave viaja en la URL y INFORMES es la
// lista blanca, asi que no hay ruta que construir por informe. El formato de
// descarga va como SUB-RUTA (/pdf, /excel) siguiendo el patron que ya usan
// /ventas/panel-emision/{tipo}/{folio}/pdf y /certificacion/intercambio/*.xml;
// los filtros van por query string, como en /auditoria.
if ($metodo === 'GET' && $ruta === '/informes') {
    Auth::requerirSesion();
    handleInformesIndexGet();
}

if ($metodo === 'GET' && preg_match('#^/informes/([a-z-]+)/(pdf|excel)$#', $ruta, $mInfDesc)) {
    Auth::requerirSesion();
    if (! isset(INFORMES[$mInfDesc[1]])) {
        http_response_code(404);
        exit;
    }
    handleInformeDescargaGet($mInfDesc[1], $mInfDesc[2]);
}

if ($metodo === 'GET' && preg_match('#^/informes/([a-z-]+)$#', $ruta, $mInf)) {
    Auth::requerirSesion();
    if (! isset(INFORMES[$mInf[1]])) {
        http_response_code(404);
        exit;
    }
    handleInformeGet($mInf[1]);
}

// --- Ventas > Panel de emision (M5) ---
if ($metodo === 'GET' && $ruta === '/ventas/panel-emision') {
    Auth::requerirSesion();
    handleDocumentosListadoGet();
}

if ($metodo === 'GET' && $ruta === '/ventas/correos') {
    Auth::requerirSesion();
    handleCorreosListadoGet();
}

if ($metodo === 'POST' && preg_match('#^/ventas/correos/(\d+)/reintentar$#', $ruta, $mCorreo)) {
    Auth::requerirSesion();
    handleCorreoReintentarPost((int) $mCorreo[1]);
}

// Las dos acciones masivas van por ruta LITERAL, sin id: apuntan a la cuenta
// entera y no hay nada que pasarles. Las literales no chocan con las de arriba
// porque aquellas exigen (\d+) en ese tramo.
if ($metodo === 'POST' && $ruta === '/ventas/correos/reintentar-fallidos') {
    Auth::requerirSesion();
    handleCorreosReintentarFallidosPost();
}

if ($metodo === 'POST' && $ruta === '/ventas/correos/buscar-destinatarios') {
    Auth::requerirSesion();
    handleCorreosBuscarDestinatariosPost();
}

if ($metodo === 'POST' && preg_match('#^/ventas/correos/(\d+)/buscar-destinatario$#', $ruta, $mBuscar)) {
    Auth::requerirSesion();
    handleCorreoBuscarDestinatarioPost((int) $mBuscar[1]);
}

if ($metodo === 'GET' && preg_match('#^/ventas/panel-emision/(\d+)/(\d+)$#', $ruta, $mDoc)) {
    Auth::requerirSesion();
    handleDocumentoDetalleGet((int) $mDoc[1], (int) $mDoc[2]);
}

if ($metodo === 'GET' && preg_match('#^/ventas/panel-emision/(\d+)/(\d+)/pdf$#', $ruta, $mDocPdf)) {
    Auth::requerirSesion();
    handleDocumentoPdfGet((int) $mDocPdf[1], (int) $mDocPdf[2]);
}

if ($metodo === 'GET' && preg_match('#^/ventas/panel-emision/(\d+)/(\d+)/xml$#', $ruta, $mDocXml)) {
    Auth::requerirSesion();
    handleDocumentoXmlGet((int) $mDocXml[1], (int) $mDocXml[2]);
}

if ($metodo === 'POST' && preg_match('#^/ventas/panel-emision/(\d+)/(\d+)/estado-sii$#', $ruta, $mDocEstado)) {
    Auth::requerirSesion();
    handleDocumentoEstadoSiiPost((int) $mDocEstado[1], (int) $mDocEstado[2]);
}

// --- Ventas > Carga masiva de notas de venta (M4) ---
if ($metodo === 'GET' && $ruta === '/ventas/carga-masiva') {
    Auth::requerirSesion();
    handleCargaMasivaGet();
}

if ($metodo === 'POST' && $ruta === '/ventas/carga-masiva') {
    Auth::requerirSesion();
    handleCargaMasivaPost();
}

if ($metodo === 'GET' && $ruta === '/ventas/carga-masiva/plantilla') {
    Auth::requerirSesion();
    handlePlantillaExcelGet();
}

if ($metodo === 'GET' && preg_match('#^/ventas/carga-masiva/(\d+)$#', $ruta, $mLote)) {
    Auth::requerirSesion();
    handleCargaMasivaDetalleGet((int) $mLote[1]);
}

if ($metodo === 'GET' && $ruta === '/ventas/facturacion-masiva') {
    Auth::requerirSesion();
    handleFacturacionMasivaGet();
}

if ($metodo === 'POST' && $ruta === '/ventas/facturacion-masiva/confirmar-sublote') {
    Auth::requerirSesion();
    handleFacturacionMasivaConfirmarSubLotePost();
}

if ($metodo === 'GET' && $ruta === '/empresa') {
    Auth::requerirSesion();
    handleEmpresaGet();
}

if ($metodo === 'POST' && $ruta === '/empresa') {
    Auth::requerirSesion();
    handleEmpresaPost();
}

if ($metodo === 'GET' && $ruta === '/empresa/importar-datos-sii') {
    Auth::requerirSesion();
    handleEmpresaImportarDatosSiiGet();
}

if ($metodo === 'POST' && $ruta === '/empresa/importar-datos-sii') {
    Auth::requerirSesion();
    handleEmpresaImportarDatosSiiPost();
}

if ($metodo === 'GET' && $ruta === '/certificado') {
    Auth::requerirSesion();
    handleCertificadoGet();
}

if ($metodo === 'POST' && $ruta === '/certificado') {
    Auth::requerirSesion();
    handleCertificadoPost();
}

if ($metodo === 'GET' && $ruta === '/caf') {
    Auth::requerirSesion();
    handleCafGet();
}

if ($metodo === 'POST' && $ruta === '/caf') {
    Auth::requerirSesion();
    handleCafPost();
}

// Paso 2 de la carga de CAF. El CSRF ya lo verifico el router mas arriba, una
// sola vez para todos los POST.
if ($metodo === 'POST' && $ruta === '/caf/confirmar') {
    Auth::requerirSesion();
    confirmarCafPost('certificacion', '/caf');
}

// ---------------------------------------------------------------------------
// Rutas de PRODUCCION. Enlazadas desde el menu, en el subgrupo "Produccion"
// de "Configuracion empresa" (ver definicionMenu()).
//
// SIN GATE DE CERTIFICACION, a proposito: son las rutas que LLEVAN a completar
// produccion, no funciones que dependan de estar ya en produccion. Exigir la
// certificacion terminada las volveria inalcanzables justo cuando hay que
// usarlas. Cada una conserva su propio guard de cadena: /certificado-produccion
// exige emisor de produccion, /caf-produccion exige emisor y certificado de
// produccion, y /apikeys-produccion exige exigirProduccionCompleto().
//
// Por eso el menu las marca en ambar: el aviso de que aqui los folios son
// reales es visual, no un bloqueo.
// ---------------------------------------------------------------------------
if ($metodo === 'GET' && $ruta === '/empresa-produccion') {
    Auth::requerirSesion();
    handleEmpresaProduccionGet();
}

if ($metodo === 'POST' && $ruta === '/empresa-produccion') {
    Auth::requerirSesion();
    handleEmpresaProduccionPost();
}

if ($metodo === 'GET' && $ruta === '/certificado-produccion') {
    Auth::requerirSesion();
    handleCertificadoProduccionGet();
}

if ($metodo === 'POST' && $ruta === '/certificado-produccion') {
    Auth::requerirSesion();
    handleCertificadoProduccionPost();
}

if ($metodo === 'GET' && $ruta === '/caf-produccion') {
    Auth::requerirSesion();
    handleCafProduccionGet();
}

if ($metodo === 'POST' && $ruta === '/caf-produccion') {
    Auth::requerirSesion();
    handleCafProduccionPost();
}

if ($metodo === 'POST' && $ruta === '/caf-produccion/confirmar') {
    Auth::requerirSesion();
    confirmarCafPost('produccion', '/caf-produccion');
}

if ($metodo === 'GET' && $ruta === '/apikeys-produccion') {
    Auth::requerirSesion();
    handleApiKeysProduccionGet();
}

if ($metodo === 'POST' && $ruta === '/apikeys-produccion/generar') {
    Auth::requerirSesion();
    handleApiKeysProduccionGenerarPost();
}

if ($metodo === 'POST' && $ruta === '/apikeys-produccion/revocar') {
    Auth::requerirSesion();
    handleApiKeysProduccionRevocarPost();
}

if ($metodo === 'GET' && $ruta === '/apikeys') {
    Auth::requerirSesion();
    handleApiKeysGet();
}

if ($metodo === 'POST' && $ruta === '/apikeys/generar') {
    Auth::requerirSesion();
    handleApiKeysGenerarPost();
}

if ($metodo === 'POST' && $ruta === '/apikeys/revocar') {
    Auth::requerirSesion();
    handleApiKeysRevocarPost();
}

if ($metodo === 'GET' && $ruta === '/certificacion-elegir') {
    Auth::requerirSesion();
    handleCertificacionElegirGet();
}

if ($metodo === 'GET' && $ruta === '/certificacion') {
    Auth::requerirSesion();
    handleCertificacionGet();
}

if ($metodo === 'GET' && preg_match('#^/certificacion/etapa/([^/]+)$#', $ruta, $mCertEtapa)) {
    Auth::requerirSesion();
    handleCertificacionEtapaGet($mCertEtapa[1]);
}

if ($metodo === 'POST' && $ruta === '/certificacion/actualizar') {
    Auth::requerirSesion();
    handleCertificacionActualizarPost();
}

if ($metodo === 'POST' && $ruta === '/certificacion/marcar-sok') {
    Auth::requerirSesion();
    handleCertificacionMarcarSokPost();
}

if ($metodo === 'POST' && $ruta === '/certificacion/confirmar-etapa') {
    Auth::requerirSesion();
    handleCertificacionConfirmarEtapaPost();
}

if ($metodo === 'POST' && $ruta === '/certificacion/actualizar-libro') {
    Auth::requerirSesion();
    handleCertificacionActualizarLibroPost();
}

if ($metodo === 'POST' && $ruta === '/certificacion/emitir-libro-ventas') {
    Auth::requerirSesion();
    handleCertificacionEmitirLibroVentasPost();
}

if ($metodo === 'POST' && $ruta === '/certificacion/emitir-libro-compras') {
    Auth::requerirSesion();
    handleCertificacionEmitirLibroComprasPost();
}

if ($metodo === 'GET' && $ruta === '/certificacion/set-pruebas') {
    Auth::requerirSesion();
    handleSetPruebasGet();
}

if ($metodo === 'POST' && $ruta === '/certificacion/set-pruebas') {
    Auth::requerirSesion();
    handleSetPruebasPost();
}

if ($metodo === 'POST' && $ruta === '/certificacion/set-pruebas/emitir') {
    Auth::requerirSesion();
    handleSetPruebasEmitirPost();
}

if ($metodo === 'GET' && $ruta === '/certificacion/simulacion') {
    Auth::requerirSesion();
    handleCertificacionSimulacionGet();
}

if ($metodo === 'POST' && $ruta === '/certificacion/simulacion/emitir') {
    Auth::requerirSesion();
    handleCertificacionSimulacionEmitirPost();
}

// ---------------------------------------------------------------------------
// Boleta (39/41): proceso APARTE de las 6 etapas de certificacion de factura
// de arriba (ver handleCertificacionBoletaGet()).
// ---------------------------------------------------------------------------
if ($metodo === 'GET' && $ruta === '/certificacion/boleta') {
    Auth::requerirSesion();
    handleCertificacionBoletaGet();
}

if ($metodo === 'POST' && $ruta === '/certificacion/boleta/confirmar-etapa') {
    Auth::requerirSesion();
    handleCertificacionBoletaConfirmarEtapaPost();
}

if ($metodo === 'GET' && $ruta === '/certificacion/boleta/set') {
    Auth::requerirSesion();
    handleCertificacionBoletaSetGet();
}

if ($metodo === 'POST' && $ruta === '/certificacion/boleta/set/emitir') {
    Auth::requerirSesion();
    handleCertificacionBoletaSetEmitirPost();
}

if ($metodo === 'GET' && $ruta === '/certificacion/boleta/rvd') {
    Auth::requerirSesion();
    handleCertificacionBoletaRvdGet();
}

if ($metodo === 'POST' && $ruta === '/certificacion/boleta/rvd/emitir') {
    Auth::requerirSesion();
    handleCertificacionBoletaRvdEmitirPost();
}

if ($metodo === 'GET' && $ruta === '/certificacion/intercambio') {
    Auth::requerirSesion();
    handleIntercambioGet();
}

if ($metodo === 'POST' && $ruta === '/certificacion/intercambio') {
    Auth::requerirSesion();
    handleIntercambioPost();
}

if ($metodo === 'GET' && preg_match('#^/certificacion/intercambio/(acuse|resultado|recibos)\.xml$#', $ruta, $mIntercambioDescarga)) {
    Auth::requerirSesion();
    handleIntercambioDescargarGet($mIntercambioDescarga[1]);
}

if ($metodo === 'GET' && $ruta === '/certificacion/muestras-impresas') {
    Auth::requerirSesion();
    handleMuestrasImpresasGet();
}

// Descarga directa del ZIP como respuesta del propio POST (sin persistir: la
// generacion es local e idempotente, no tiene efectos de red -- ver
// handleMuestrasImpresasPost()). Ruta con extension .zip: pasa por
// panel/router.php igual que las descargas .xml de intercambio ya existentes
// (el router deja pasar a index.php cualquier ruta que no sea un archivo
// FISICO real dentro de panel/public/, y no existe ningun archivo asi en
// disco -- confirmado, no hizo falta tocar el router).
if ($metodo === 'POST' && $ruta === '/certificacion/muestras-impresas.zip') {
    Auth::requerirSesion();
    handleMuestrasImpresasPost();
}

if ($metodo === 'GET' && $ruta === '/certificacion-aprobada') {
    Auth::requerirSesion();
    handleCertificacionAprobadaGet();
}

if ($metodo === 'POST' && $ruta === '/certificacion-aprobada/confirmar') {
    Auth::requerirSesion();
    handleCertificacionAprobadaConfirmarPost();
}

if ($metodo === 'GET' && $ruta === '/panel') {
    Auth::requerirSesion();
    handlePanelGet();
}

// --- Configuracion > Usuarios (M6) ---
if ($metodo === 'GET' && $ruta === '/configuracion/usuarios') {
    Auth::requerirSesion();
    handleUsuariosListadoGet();
}

if ($metodo === 'POST' && $ruta === '/configuracion/usuarios') {
    Auth::requerirSesion();
    handleUsuarioInvitarPost();
}

if ($metodo === 'POST' && preg_match('#^/configuracion/usuarios/(\d+)/activar$#', $ruta, $mUsu)) {
    Auth::requerirSesion();
    handleUsuarioActivarPost((int) $mUsu[1]);
}

if ($metodo === 'POST' && preg_match('#^/configuracion/usuarios/(\d+)/desactivar$#', $ruta, $mUsu)) {
    Auth::requerirSesion();
    handleUsuarioDesactivarPost((int) $mUsu[1]);
}

// --- Auditoria de tenant (M6) ---
if ($metodo === 'GET' && $ruta === '/auditoria') {
    Auth::requerirSesion();
    handleAuditoriaTenantGet();
}

// ---------------------------------------------------------------------------
// Rutas de SUPERADMIN: a proposito NO enlazadas desde ningun lado del panel
// normal de tenant (el que ve Auth::cuentaId() de una cuenta cualquiera).
// Cada handler exige exigirSuperadmin() (403 si el rol no corresponde, sin
// pasar por Auth::requerirSesion()/redirect a /login).
// ---------------------------------------------------------------------------
if ($metodo === 'GET' && $ruta === '/admin/tenants') {
    handleAdminTenantsGet();
}

if ($metodo === 'POST' && $ruta === '/admin/tenants/suspender') {
    handleAdminTenantsSuspenderPost();
}

if ($metodo === 'POST' && $ruta === '/admin/tenants/reactivar') {
    handleAdminTenantsReactivarPost();
}

if ($metodo === 'POST' && $ruta === '/admin/tenants/revertir-etapa') {
    handleAdminTenantsRevertirEtapaPost();
}

if ($metodo === 'GET' && $ruta === '/admin/auditoria') {
    handleAdminAuditoriaGet();
}

http_response_code(404);
echo '404 - ruta no encontrada';
exit;

// ===========================================================================
//  Handler: GET/POST /registro
// ===========================================================================
function handleRegistroPost(): void
{
    $email    = trim((string) ($_POST['email'] ?? ''));
    $pass     = (string) ($_POST['password'] ?? '');
    $confirma = (string) ($_POST['password_confirmacion'] ?? '');

    $errores = [];

    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Email invalido.';
    }
    if (strlen($pass) < 8) {
        $errores[] = 'La contrasena debe tener al menos 8 caracteres.';
    }
    if ($pass !== $confirma) {
        $errores[] = 'Las contrasenas no coinciden.';
    }

    $pdo = Db::conexion();

    if ($errores === []) {
        $stmt = $pdo->prepare('SELECT 1 FROM cuenta WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetchColumn() !== false) {
            $errores[] = 'Ese email ya esta registrado.';
        }
    }
    if ($errores === []) {
        $stmt = $pdo->prepare('SELECT 1 FROM usuario WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetchColumn() !== false) {
            $errores[] = 'Ese email ya esta registrado.';
        }
    }

    if ($errores !== []) {
        vista('registro', ['errores' => $errores, 'email' => $email]);
        return;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO cuenta (email, nombre, estado, created_at) '
            . "VALUES (:email, :nombre, 'activa', NOW())"
        )->execute([':email' => $email, ':nombre' => $email]);
        $cuentaId = (int) $pdo->lastInsertId();

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO usuario (cuenta_id, email, password_hash, rol, estado, created_at) '
            . "VALUES (:cuenta_id, :email, :hash, 'owner', 'activo', NOW())"
        )->execute([':cuenta_id' => $cuentaId, ':email' => $email, ':hash' => $hash]);
        $usuarioId = (int) $pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('panel registro fallo: ' . $e->getMessage());
        vista('registro', [
            'errores' => ['No se pudo completar el registro. Intenta nuevamente.'],
            'email'   => $email,
        ]);
        return;
    }

    Auth::login($usuarioId, $cuentaId);
    Csrf::regenerarToken();
    redirigir('/panel');
}

// ===========================================================================
//  Handler: POST /login
// ===========================================================================
function handleLoginPost(): void
{
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');

    $pdo  = Db::conexion();
    $stmt = $pdo->prepare(
        'SELECT id, cuenta_id, password_hash, estado FROM usuario WHERE email = :email LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Mensaje generico en TODOS los casos de fallo: no revela si el email existe.
    if (
        $row === false
        || $row['estado'] !== 'activo'
        || ! password_verify($pass, $row['password_hash'])
    ) {
        vista('login', ['error' => 'Credenciales invalidas.', 'email' => $email]);
        return;
    }

    Auth::login((int) $row['id'], (int) $row['cuenta_id']);
    Csrf::regenerarToken();
    redirigir('/panel');
}

// ===========================================================================
//  Handler: GET /empresa
// ===========================================================================
function handleEmpresaGet(): void
{
    $pdo  = Db::conexion();
    $stmt = $pdo->prepare(
        'SELECT rut_emisor, razon_social, giro, acteco, dir_origen, cmna_origen, '
        . '       resolucion_fecha, resolucion_numero '
        . "FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmt->execute([':cuenta_id' => Auth::cuentaId()]);
    $emisor = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Precarga OPCIONAL desde /empresa/importar-datos-sii ("Usar estos
    // datos"): SOLO si todavia no existe una fila guardada. Si ya existe,
    // la fila real de la BD manda siempre -- sin cambios de comportamiento
    // para el flujo manual existente.
    if ($emisor === null && isset($_GET['razon_social'])) {
        $emisor = [
            'rut_emisor'        => (string) ($_GET['rut_emisor'] ?? ''),
            'razon_social'      => (string) ($_GET['razon_social'] ?? ''),
            'giro'              => (string) ($_GET['giro'] ?? ''),
            'acteco'            => (string) ($_GET['acteco'] ?? ''),
            'dir_origen'        => (string) ($_GET['dir_origen'] ?? ''),
            'cmna_origen'       => (string) ($_GET['cmna_origen'] ?? ''),
            'resolucion_fecha'  => '',
            'resolucion_numero' => '',
        ];
    }

    vista('empresa', ['errores' => [], 'emisor' => $emisor]);
}

// ===========================================================================
//  Handler: GET /empresa/importar-datos-sii
//
//  Sub-estacion OPCIONAL de la estacion 2 (mismo patron de
//  /certificacion/set-pruebas): muestra el formulario de subida del archivo
//  de "Datos para Construccion DTE" (pe_construccion_dte) que entrega el
//  SII, para previsualizar sus datos ANTES de aplicar nada. NO reemplaza el
//  flujo manual de /empresa: el tenant puede seguir tipeando a mano.
// ===========================================================================
function handleEmpresaImportarDatosSiiGet(): void
{
    vista('empresa-importar-datos-sii', ['error' => null, 'datos' => null]);
}

// ===========================================================================
//  Handler: POST /empresa/importar-datos-sii
//
//  Recibe el archivo y lo parsea con DatosContribuyenteSiiParser (src/Sii/,
//  reusado TAL CUAL) -- NO guarda nada en la BD ni construye ningun payload
//  aqui, solo muestra el preview. El boton "Usar estos datos" del preview
//  apunta a /empresa con los valores como query string (ver
//  handleEmpresaGet()); resolucion_fecha/resolucion_numero NUNCA se tocan:
//  el archivo del SII no los trae, siguen siendo responsabilidad manual.
// ===========================================================================
function handleEmpresaImportarDatosSiiPost(): void
{
    $archivo = $_FILES['archivo'] ?? null;
    if (
        ! is_array($archivo)
        || ! isset($archivo['error'], $archivo['tmp_name'])
        || $archivo['error'] !== UPLOAD_ERR_OK
        || ! is_uploaded_file($archivo['tmp_name'])
    ) {
        vista('empresa-importar-datos-sii', [
            'error' => 'Debes seleccionar el archivo de Datos para Construccion DTE (pe_construccion_dte) que descargaste del SII.',
            'datos' => null,
        ]);
    }

    $contenido = file_get_contents($archivo['tmp_name']);
    if ($contenido === false || $contenido === '') {
        vista('empresa-importar-datos-sii', ['error' => 'No se pudo leer el archivo subido.', 'datos' => null]);
    }

    try {
        $datos = (new DatosContribuyenteSiiParser())->parse($contenido);
    } catch (RuntimeException $e) {
        vista('empresa-importar-datos-sii', [
            'error' => 'El archivo no corresponde al formato esperado de Datos para Construccion DTE: ' . $e->getMessage(),
            'datos' => null,
        ]);
    }

    vista('empresa-importar-datos-sii', ['error' => null, 'datos' => $datos]);
}

// ===========================================================================
//  Handler: POST /empresa
//
//  Ambiente SIEMPRE 'certificacion' en esta etapa (fijado por el servidor, no
//  lo elige el cliente). cuenta_id sale de la sesion. Relacion 1:1 cuenta-
//  emisor: si la cuenta ya tiene fila en certificacion, se actualiza esa fila
//  en vez de insertar una nueva.
// ===========================================================================
function handleEmpresaPost(): void
{
    $cuentaId = Auth::cuentaId();
    $ambiente = 'certificacion';

    $rutCrudo  = trim((string) ($_POST['rut_emisor'] ?? ''));
    $rut       = Rut::normalizar($rutCrudo);
    $razon     = trim((string) ($_POST['razon_social'] ?? ''));
    $giro      = trim((string) ($_POST['giro'] ?? ''));
    $actecoRaw = trim((string) ($_POST['acteco'] ?? ''));
    $dir       = trim((string) ($_POST['dir_origen'] ?? ''));
    $cmna      = trim((string) ($_POST['cmna_origen'] ?? ''));
    $resFecha  = trim((string) ($_POST['resolucion_fecha'] ?? ''));
    $resNumRaw = trim((string) ($_POST['resolucion_numero'] ?? ''));

    $errores = [];

    if (! Rut::valido($rut)) {
        $errores['rut_emisor'] = 'RUT invalido (formato NNNNNNNN-DV, digito verificador incorrecto).';
    }
    if ($razon === '') {
        $errores['razon_social'] = 'La razon social es obligatoria.';
    }
    if ($giro === '') {
        $errores['giro'] = 'El giro es obligatorio.';
    }
    if (! ctype_digit($actecoRaw)) {
        $errores['acteco'] = 'El codigo de actividad economica debe ser un numero entero.';
    }
    if ($dir === '') {
        $errores['dir_origen'] = 'La direccion es obligatoria.';
    }
    if ($cmna === '') {
        $errores['cmna_origen'] = 'La comuna es obligatoria.';
    }
    if (! fechaValida($resFecha)) {
        $errores['resolucion_fecha'] = 'Fecha de resolucion invalida (formato YYYY-MM-DD).';
    }
    if (! ctype_digit($resNumRaw)) {
        $errores['resolucion_numero'] = 'El numero de resolucion debe ser un numero entero (0 o mayor).';
    }

    $datosForm = [
        'rut_emisor'        => $rutCrudo,
        'razon_social'      => $razon,
        'giro'              => $giro,
        'acteco'            => $actecoRaw,
        'dir_origen'        => $dir,
        'cmna_origen'       => $cmna,
        'resolucion_fecha'  => $resFecha,
        'resolucion_numero' => $resNumRaw,
    ];

    if ($errores !== []) {
        vista('empresa', ['errores' => $errores, 'emisor' => $datosForm]);
        return;
    }

    $acteco = (int) $actecoRaw;
    $resNum = (int) $resNumRaw;
    $pdo    = Db::conexion();

    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = :amb LIMIT 1'
        );
        $stmt->execute([':cuenta_id' => $cuentaId, ':amb' => $ambiente]);
        $existenteId = $stmt->fetchColumn();

        if ($existenteId !== false) {
            $upd = $pdo->prepare(
                'UPDATE dte_emisor SET rut_emisor = :rut, razon_social = :razon, giro = :giro, '
                . 'acteco = :acteco, dir_origen = :dir, cmna_origen = :cmna, '
                . 'resolucion_fecha = :resfecha, resolucion_numero = :resnum '
                . 'WHERE id = :id'
            );
            $upd->execute([
                ':rut'      => $rut,
                ':razon'    => $razon,
                ':giro'     => $giro,
                ':acteco'   => $acteco,
                ':dir'      => $dir,
                ':cmna'     => $cmna,
                ':resfecha' => $resFecha,
                ':resnum'   => $resNum,
                ':id'       => $existenteId,
            ]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO dte_emisor '
                . '(cuenta_id, rut_emisor, ambiente, razon_social, giro, acteco, dir_origen, cmna_origen, '
                . ' resolucion_fecha, resolucion_numero) '
                . 'VALUES (:cuenta_id, :rut, :amb, :razon, :giro, :acteco, :dir, :cmna, :resfecha, :resnum)'
            );
            $ins->execute([
                ':cuenta_id' => $cuentaId,
                ':rut'       => $rut,
                ':amb'       => $ambiente,
                ':razon'     => $razon,
                ':giro'      => $giro,
                ':acteco'    => $acteco,
                ':dir'       => $dir,
                ':cmna'      => $cmna,
                ':resfecha'  => $resFecha,
                ':resnum'    => $resNum,
            ]);
        }
    } catch (PDOException $e) {
        error_log('panel empresa guardar fallo: ' . $e->getMessage());
        // 23000 = violacion de UNIQUE (uk_emisor: rut_emisor+ambiente ya usado por otra cuenta).
        $mensaje = $e->getCode() === '23000'
            ? 'Ese RUT ya esta registrado en el sistema.'
            : 'No se pudo guardar. Intenta nuevamente.';
        vista('empresa', ['errores' => ['rut_emisor' => $mensaje], 'emisor' => $datosForm]);
        return;
    }

    redirigir('/panel');
}

// ===========================================================================
//  Handler: GET /empresa-produccion
//
//  Estacion 7 (PRODUCCION). Si ya existe una fila de produccion se muestra como
//  "ya configurado" (no se permite reeditar aqui: los datos de produccion son la
//  Resolucion REAL del SII, no se tocan por error).
//
//  DOS ORIGENES POSIBLES PARA EL EMISOR, y de ahi salen los dos modos del
//  formulario:
//
//   1. CON fila de certificacion (el camino de siempre, y el unico que existia).
//      El formulario viene PRECARGADO con sus datos y el rut_emisor NO se pide:
//      es la MISMA empresa. Nada de este caso cambia.
//
//   2. SIN fila de certificacion: una empresa que llega YA AUTORIZADA por el
//      SII y nunca paso por el circuito de certificacion. Antes esto redirigia
//      a /empresa y la obligaba a crear una fila de certificacion que no le
//      corresponde -- ese era el hueco. Ahora la precarga viene VACIA y el
//      rut_emisor SI se pide, porque no hay ningun otro lugar de donde sacarlo.
//
//  'rutEditable' le dice a la vista cual de los dos modos pintar. Con fila de
//  certificacion es false y el markup queda exactamente como estaba.
// ===========================================================================
function handleEmpresaProduccionGet(): void
{
    $cuentaId = Auth::cuentaId();
    $pdo      = Db::conexion();

    $stmtCert = $pdo->prepare(
        'SELECT rut_emisor, razon_social, giro, acteco, dir_origen, cmna_origen '
        . "FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmtCert->execute([':cuenta_id' => $cuentaId]);
    $emisorCert = $stmtCert->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtProd = $pdo->prepare(
        'SELECT rut_emisor, razon_social, giro, acteco, dir_origen, cmna_origen, '
        . '       resolucion_fecha, resolucion_numero '
        . "FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'produccion' LIMIT 1"
    );
    $stmtProd->execute([':cuenta_id' => $cuentaId]);
    $emisorProd = $stmtProd->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($emisorProd !== null) {
        vista('empresa-produccion', [
            'yaConfigurado' => true,
            'produccion'    => $emisorProd,
            'errores'       => [],
            'emisor'        => null,
            'rutEditable'   => false,
        ]);
    }

    $vacio = [
        'rut_emisor'  => '',
        'razon_social' => '',
        'giro'        => '',
        'acteco'      => '',
        'dir_origen'  => '',
        'cmna_origen' => '',
    ];

    vista('empresa-produccion', [
        'yaConfigurado' => false,
        'produccion'    => null,
        'errores'       => [],
        'emisor'        => ($emisorCert ?? $vacio) + ['resolucion_fecha' => '', 'resolucion_numero' => ''],
        'rutEditable'   => $emisorCert === null,
    ]);
}

// ===========================================================================
//  Handler: POST /empresa-produccion
//
//  DE DONDE SALE EL rut_emisor. El diseno original lo tomaba SIEMPRE de la fila
//  de certificacion y nunca del POST, por dos razones que siguen vigentes:
//
//   1. INTEGRIDAD ENTRE AMBIENTES: es la MISMA empresa en los dos. Si el RUT
//      viniera del formulario, un tenant podria certificar con un RUT y producir
//      con otro, y todo lo que cruza ambientes por rut_emisor -- certificado,
//      CAF, documentos emitidos -- quedaria partido en dos mundos.
//   2. NO CONFIAR EN EL CLIENTE PARA LA IDENTIDAD: mismo patron que el resto del
//      panel, donde cuenta_id sale de la sesion y ambiente lo fija el servidor.
//
//  LA RAZON 1 NO SE TOCA: si hay fila de certificacion, el RUT sigue saliendo de
//  ahi y el formulario se ignora, exactamente como antes.
//
//  LA RAZON 2 SE RELAJA SOLO cuando NO hay fila de certificacion, porque
//  entonces no existe ninguna otra fuente: una empresa ya autorizada por el SII
//  no tiene por que haber pasado por el circuito. En ese caso el RUT se pide, y
//  se compensa con lo mismo que ya hace handleEmpresaPost(): Rut::normalizar()
//  mas Rut::valido() (formato y digito verificador, modulo 11), y la restriccion
//  uk_emisor(rut_emisor, ambiente) como red -- un RUT ya tomado en produccion
//  por otra cuenta revienta con 23000 y se traduce abajo.
//
//  resolucion_fecha/resolucion_numero son la Resolucion REAL de autorizacion
//  del SII (NO se inventan: salen del correo/portal de autorizacion) -- por
//  eso son obligatorios aqui, a diferencia de /empresa donde en certificacion
//  representan solo la fecha de POSTULACION. Idempotente: si ya existe fila
//  de produccion, no se inserta de nuevo (evita duplicar/pisar una Resolucion
//  real ya cargada por un doble submit).
// ===========================================================================
function handleEmpresaProduccionPost(): void
{
    $cuentaId = Auth::cuentaId();
    $pdo      = Db::conexion();

    $stmtCert = $pdo->prepare(
        'SELECT rut_emisor, razon_social, giro, acteco, dir_origen, cmna_origen '
        . "FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmtCert->execute([':cuenta_id' => $cuentaId]);
    $emisorCert = $stmtCert->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtProd = $pdo->prepare(
        "SELECT 1 FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'produccion' LIMIT 1"
    );
    $stmtProd->execute([':cuenta_id' => $cuentaId]);
    if ($stmtProd->fetchColumn() !== false) {
        redirigir('/empresa-produccion');
    }

    $razon     = trim((string) ($_POST['razon_social'] ?? ''));
    $giro      = trim((string) ($_POST['giro'] ?? ''));
    $actecoRaw = trim((string) ($_POST['acteco'] ?? ''));
    $dir       = trim((string) ($_POST['dir_origen'] ?? ''));
    $cmna      = trim((string) ($_POST['cmna_origen'] ?? ''));
    $resFecha  = trim((string) ($_POST['resolucion_fecha'] ?? ''));
    $resNumRaw = trim((string) ($_POST['resolucion_numero'] ?? ''));

    $errores = [];

    // Con fila de certificacion el formulario NO manda el RUT y se ignora si lo
    // mandara. Sin ella, se pide y se valida igual que en handleEmpresaPost():
    // mismo metodo, mismo mensaje. $rutEcho es lo que se re-pinta si hay error;
    // tambien como en handleEmpresaPost(), el usuario ve lo que TECLEO, no la
    // version normalizada.
    if ($emisorCert !== null) {
        $rut     = (string) $emisorCert['rut_emisor'];
        $rutEcho = $rut;
    } else {
        $rutEcho = trim((string) ($_POST['rut_emisor'] ?? ''));
        $rut     = Rut::normalizar($rutEcho);
        if (! Rut::valido($rut)) {
            $errores['rut_emisor'] = 'RUT invalido (formato NNNNNNNN-DV, digito verificador incorrecto).';
        }
    }

    if ($razon === '') {
        $errores['razon_social'] = 'La razon social es obligatoria.';
    }
    if ($giro === '') {
        $errores['giro'] = 'El giro es obligatorio.';
    }
    if (! ctype_digit($actecoRaw)) {
        $errores['acteco'] = 'El codigo de actividad economica debe ser un numero entero.';
    }
    if ($dir === '') {
        $errores['dir_origen'] = 'La direccion es obligatoria.';
    }
    if ($cmna === '') {
        $errores['cmna_origen'] = 'La comuna es obligatoria.';
    }
    if (! fechaValida($resFecha)) {
        $errores['resolucion_fecha'] = 'Fecha de resolucion invalida (formato YYYY-MM-DD).';
    }
    if (! ctype_digit($resNumRaw) || (int) $resNumRaw <= 0) {
        $errores['resolucion_numero'] = 'El numero de resolucion debe ser un numero entero positivo.';
    }

    $datosForm = [
        'rut_emisor'        => $rutEcho,
        'razon_social'      => $razon,
        'giro'              => $giro,
        'acteco'            => $actecoRaw,
        'dir_origen'        => $dir,
        'cmna_origen'       => $cmna,
        'resolucion_fecha'  => $resFecha,
        'resolucion_numero' => $resNumRaw,
    ];

    if ($errores !== []) {
        vista('empresa-produccion', [
            'yaConfigurado' => false,
            'produccion'    => null,
            'errores'       => $errores,
            'emisor'        => $datosForm,
            'rutEditable'   => $emisorCert === null,
        ]);
    }

    try {
        $pdo->prepare(
            'INSERT INTO dte_emisor '
            . '(cuenta_id, rut_emisor, ambiente, razon_social, giro, acteco, dir_origen, cmna_origen, '
            . ' resolucion_fecha, resolucion_numero) '
            . "VALUES (:cuenta_id, :rut, 'produccion', :razon, :giro, :acteco, :dir, :cmna, :resfecha, :resnum)"
        )->execute([
            ':cuenta_id' => $cuentaId,
            ':rut'       => $rut,
            ':razon'     => $razon,
            ':giro'      => $giro,
            ':acteco'    => (int) $actecoRaw,
            ':dir'       => $dir,
            ':cmna'      => $cmna,
            ':resfecha'  => $resFecha,
            ':resnum'    => (int) $resNumRaw,
        ]);
    } catch (PDOException $e) {
        error_log('panel empresa-produccion guardar fallo: ' . $e->getMessage());
        // 23000 = violacion de UNIQUE (uk_emisor: rut_emisor+ambiente ya usado por otra cuenta).
        $mensaje = $e->getCode() === '23000'
            ? 'Ese RUT ya esta registrado en ambiente de produccion.'
            : 'No se pudo guardar. Intenta nuevamente.';
        vista('empresa-produccion', [
            'yaConfigurado' => false,
            'produccion'    => null,
            'errores'       => ['rut_emisor' => $mensaje],
            'emisor'        => $datosForm,
            'rutEditable'   => $emisorCert === null,
        ]);
    }

    redirigir('/empresa-produccion');
}

// ===========================================================================
//  Handler: GET /certificado
//
//  Requiere que la etapa 2 (datos de empresa) este completa: si la cuenta no
//  tiene fila en dte_emisor (ambiente certificacion), redirige a /empresa.
// ===========================================================================
function handleCertificadoGet(): void
{
    procesarCertificadoGet('certificacion', 'certificado', '/empresa');
}

// ===========================================================================
//  Handler: GET /certificado-produccion
//
//  Mismo patron que GET /certificado, ambiente produccion: si no existe fila
//  dte_emisor de produccion, redirige a /empresa-produccion (NO a /empresa).
// ===========================================================================
function handleCertificadoProduccionGet(): void
{
    procesarCertificadoGet('produccion', 'certificado-produccion', '/empresa-produccion');
}

/**
 * Logica compartida de GET /certificado y GET /certificado-produccion:
 * exige que exista la fila dte_emisor del ambiente correspondiente (si no,
 * redirige a $rutaEmpresa) y renderiza $vista.
 */
function procesarCertificadoGet(string $ambiente, string $vista, string $rutaEmpresa): void
{
    $pdo  = Db::conexion();
    $stmt = $pdo->prepare(
        'SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = :amb LIMIT 1'
    );
    $stmt->execute([':cuenta_id' => Auth::cuentaId(), ':amb' => $ambiente]);
    if ($stmt->fetchColumn() === false) {
        redirigir($rutaEmpresa);
    }

    vista($vista, ['error' => null]);
}

// ===========================================================================
//  Handler: POST /certificado
// ===========================================================================
function handleCertificadoPost(): void
{
    procesarCertificadoPost('certificacion', 'certificado', '/empresa');
}

// ===========================================================================
//  Handler: POST /certificado-produccion
//
//  Misma logica EXACTA que POST /certificado (ver procesarCertificadoPost()),
//  solo con $ambiente='produccion' fijo en vez de 'certificacion'. El
//  certificado digital (.pfx) es el MISMO archivo en cert y produccion en la
//  practica (no cambia entre ambientes), pero se guarda como fila separada
//  (uk_cert_emisor: rut_emisor+ambiente) para no mezclar ambientes.
// ===========================================================================
function handleCertificadoProduccionPost(): void
{
    procesarCertificadoPost('produccion', 'certificado-produccion', '/empresa-produccion');
}

/**
 * Logica compartida de POST /certificado y POST /certificado-produccion:
 * envelope encryption: una DEK aleatoria (32B) cifra el cert/pkey en PEM; la
 * DEK se envuelve (cifra) con la KEK maestra (CRYPTO_MASTER_KEY) y se guarda
 * en dte_certificado.dek_envuelta. La clave del .pfx NUNCA se persiste ni se
 * loguea; el archivo se lee de memoria desde tmp_name, nunca se copia a disco.
 *
 * $vista es el nombre de la vista a re-renderizar en caso de error (para que
 * produccion muestre certificado-produccion.php, no certificado.php).
 * $rutaEmpresa es a donde redirigir si la cuenta aun no tiene fila dte_emisor
 * de este ambiente.
 */
function procesarCertificadoPost(string $ambiente, string $vista, string $rutaEmpresa): void
{
    $cuentaId = Auth::cuentaId();

    $pdo  = Db::conexion();
    $stmt = $pdo->prepare(
        'SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = :amb LIMIT 1'
    );
    $stmt->execute([':cuenta_id' => $cuentaId, ':amb' => $ambiente]);
    $rutEmisor = $stmt->fetchColumn();
    if ($rutEmisor === false) {
        redirigir($rutaEmpresa);
    }

    // --- a. Verificar que llego archivo + clave ---
    $archivo = $_FILES['certificado'] ?? null;
    $clave   = (string) ($_POST['clave'] ?? '');

    if (
        ! is_array($archivo)
        || ! isset($archivo['error'], $archivo['tmp_name'])
        || $archivo['error'] !== UPLOAD_ERR_OK
        || ! is_uploaded_file($archivo['tmp_name'])
    ) {
        vista($vista, ['error' => 'Debes seleccionar un archivo de certificado (.pfx/.p12) valido.']);
    }
    if ($clave === '') {
        vista($vista, ['error' => 'La clave del certificado es obligatoria.']);
    }

    // --- b. Leer el .pfx A MEMORIA (nunca move_uploaded_file, nunca a disco propio) ---
    $contenidoPfx = file_get_contents($archivo['tmp_name']);
    if ($contenidoPfx === false || $contenidoPfx === '') {
        vista($vista, ['error' => 'No se pudo leer el archivo subido.']);
    }

    // --- c. Abrir el PKCS12: valida clave Y formato de una sola vez ---
    $certs = [];
    if (! openssl_pkcs12_read($contenidoPfx, $certs, $clave)) {
        // NUNCA logear la clave ni el contenido del archivo.
        error_log(sprintf('panel certificado (%s): PKCS12 invalido o clave incorrecta (cuenta %d)', $ambiente, $cuentaId));
        vista($vista, ['error' => 'Certificado o clave invalidos.']);
    }
    $contenidoPfx = null;

    // --- d. Extraer cert y pkey en PEM ---
    $certPem = $certs['cert'] ?? null;
    $pkeyPem = $certs['pkey'] ?? null;
    $certs   = null;
    if (! is_string($certPem) || $certPem === '' || ! is_string($pkeyPem) || $pkeyPem === '') {
        error_log(sprintf('panel certificado (%s): PKCS12 sin cert/pkey extraible (cuenta %d)', $ambiente, $cuentaId));
        vista($vista, ['error' => 'Certificado o clave invalidos.']);
    }

    // --- e. Extraccion tolerante del RUT del FIRMANTE (sender) desde el certificado ---
    //
    //  El certificado pertenece a una PERSONA NATURAL autorizada a firmar (el
    //  representante), cuyo RUT es casi siempre DISTINTO al rut_emisor de la
    //  empresa (ej. cert 13520634-2 firma para empresa 77724622-4). NO se
    //  compara contra rut_emisor: se guarda como rut_sender, el "sender" que
    //  el motor envia al SII (antes una constante fija, FACT_RUT_SENDER).
    //
    //  CertificadoRutSenderExtractor prueba 2 metodos, en orden: (1)
    //  subject.serialNumber (el metodo historico, cubre la mayoria de los
    //  certificados SII), (2) fallback a la extension subjectAltName
    //  (otherName con el OID chileno de RUN 1.3.6.1.4.1.8321.1) para
    //  certificados que NO llevan el RUT en el serialNumber (ej.
    //  13407848-0.pfx). Si ninguno encuentra nada, rut_sender queda NULL:
    //  no bloquea la subida del certificado.
    $rutSender = CertificadoRutSenderExtractor::extraer($certPem);
    if ($rutSender === null) {
        error_log(sprintf('panel certificado (%s): no se pudo extraer RUT del certificado para cuenta %d', $ambiente, $cuentaId));
    }

    // --- f-h. Envelope encryption: DEK aleatoria cifra los PEM; la KEK envuelve la DEK ---
    $dek = random_bytes(32);
    try {
        $cryptoDek   = new CertificadoCrypto($dek);
        $certCifrado = $cryptoDek->cifrar($certPem);
        $pkeyCifrado = $cryptoDek->cifrar($pkeyPem);

        $cryptoKek   = new CertificadoCrypto(kekMaestra());
        $dekEnvuelta = $cryptoKek->cifrar($dek);
    } catch (CertificadoCryptoException $e) {
        error_log('panel certificado (' . $ambiente . '): fallo de cifrado - ' . $e->getMessage());
        vista($vista, ['error' => 'No se pudo procesar el certificado. Intenta nuevamente.']);
    }

    // --- i. Guardar: respeta uk_cert_emisor(rut_emisor, ambiente); UPDATE si ya existe, INSERT si no ---
    try {
        $sel = $pdo->prepare(
            'SELECT id FROM dte_certificado WHERE rut_emisor = :rut AND ambiente = :amb LIMIT 1'
        );
        $sel->execute([':rut' => $rutEmisor, ':amb' => $ambiente]);
        $existenteId = $sel->fetchColumn();

        if ($existenteId !== false) {
            $upd = $pdo->prepare(
                'UPDATE dte_certificado SET cert_data_cifrado = :cert, pkey_data_cifrado = :pkey, '
                . 'dek_envuelta = :dek, rut_sender = :sender WHERE id = :id'
            );
            $upd->execute([
                ':cert'   => $certCifrado,
                ':pkey'   => $pkeyCifrado,
                ':dek'    => $dekEnvuelta,
                ':sender' => $rutSender,
                ':id'     => $existenteId,
            ]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO dte_certificado '
                . '(rut_emisor, ambiente, cert_data_cifrado, pkey_data_cifrado, dek_envuelta, rut_sender) '
                . 'VALUES (:rut, :amb, :cert, :pkey, :dek, :sender)'
            );
            $ins->execute([
                ':rut'    => $rutEmisor,
                ':amb'    => $ambiente,
                ':cert'   => $certCifrado,
                ':pkey'   => $pkeyCifrado,
                ':dek'    => $dekEnvuelta,
                ':sender' => $rutSender,
            ]);
        }
    } catch (PDOException $e) {
        error_log('panel certificado (' . $ambiente . '): fallo al guardar - ' . $e->getMessage());
        vista($vista, ['error' => 'No se pudo guardar el certificado. Intenta nuevamente.']);
    }

    redirigir($ambiente === 'produccion' ? '/certificado-produccion' : '/panel');
}

// ===========================================================================
//  Handler: GET /caf
// ===========================================================================
function handleCafGet(): void
{
    procesarCafGet('certificacion', 'caf', '/empresa', '/certificado');
}

// ===========================================================================
//  Handler: GET /caf-produccion
//
//  Mismo patron que GET /caf, ambiente produccion: redirige a
//  /empresa-produccion o /certificado-produccion (nunca a las de
//  certificacion) si falta una etapa anterior de produccion.
// ===========================================================================
function handleCafProduccionGet(): void
{
    procesarCafGet('produccion', 'caf-produccion', '/empresa-produccion', '/certificado-produccion');
}

/**
 * Logica compartida de GET /caf y GET /caf-produccion: requiere emisor Y
 * certificado del ambiente correspondiente (si falta alguno, redirige a
 * $rutaEmpresa/$rutaCertificado), y renderiza $vista con el listado de CAF de
 * ESE ambiente (listarCafs() con $ambiente).
 */
function procesarCafGet(string $ambiente, string $vista, string $rutaEmpresa, string $rutaCertificado): void
{
    // Volver a la pantalla de CAF descarta cualquier revision a medio hacer.
    // Asi "Cancelar" es simplemente navegar aqui, y un CAF pendiente nunca
    // sobrevive a que el usuario se vaya del flujo.
    unset($_SESSION['caf_pendiente']);

    $pdo  = Db::conexion();
    $stmt = $pdo->prepare(
        'SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = :amb LIMIT 1'
    );
    $stmt->execute([':cuenta_id' => Auth::cuentaId(), ':amb' => $ambiente]);
    $rutEmisor = $stmt->fetchColumn();
    if ($rutEmisor === false) {
        redirigir($rutaEmpresa);
    }

    $stmtCert = $pdo->prepare(
        'SELECT 1 FROM dte_certificado WHERE rut_emisor = :rut AND ambiente = :amb LIMIT 1'
    );
    $stmtCert->execute([':rut' => $rutEmisor, ':amb' => $ambiente]);
    if ($stmtCert->fetchColumn() === false) {
        redirigir($rutaCertificado);
    }

    vista($vista, [
        'error' => null,
        'cafs'  => listarCafs($pdo, (string) $rutEmisor, $ambiente),
        // Resultado del paso 2 (confirmarCafPost), que redirige aqui despues
        // de guardar o de fallar. Antes esta vista no podia confirmar nada:
        // la carga redirigia sin flash y el usuario volvia a una tabla sin
        // aviso. Con el flujo en dos pasos el mensaje si existe.
        'flash' => flashTomar(),
    ]);
}

// ===========================================================================
//  Handler: POST /caf
// ===========================================================================
function handleCafPost(): void
{
    procesarCafPost('certificacion', 'caf', '/empresa', '/certificado');
}

// ===========================================================================
//  Handler: POST /caf-produccion
//
//  Misma logica EXACTA que POST /caf (ver procesarCafPost()), solo con
//  $ambiente='produccion' fijo. El CAF de produccion es un archivo NUEVO y
//  DISTINTO del de certificacion (folios distintos, emitido por el SII para
//  el ambiente real) -- no se reutiliza el mismo XML entre ambientes en un
//  caso real (solo se prueba el MECANISMO con archivos de certificacion, ver
//  PARTE E de la tarea que agrego esto).
// ===========================================================================
function handleCafProduccionPost(): void
{
    procesarCafPost('produccion', 'caf-produccion', '/empresa-produccion', '/certificado-produccion');
}

/**
 * Logica compartida de POST /caf y POST /caf-produccion.
 *
 * El CAF del SII viene en ISO-8859-1 SIN atributo encoding; su firma FRMA es
 * sobre esos bytes exactos. Por eso: se parsea una COPIA convertida a UTF-8
 * solo si hace falta (igual que scripts/cargar_caf.php), pero se CIFRA Y
 * GUARDA el original sin tocar -- convertir el encoding invalidaria el CAF al
 * timbrar (ver TedBuilder, que firma el DD con la RSASK de este mismo CAF).
 * Envelope encryption identica a /certificado: DEK aleatoria cifra los bytes
 * originales, la KEK maestra envuelve la DEK.
 */
function procesarCafPost(string $ambiente, string $vista, string $rutaEmpresa, string $rutaCertificado): void
{
    $pdo  = Db::conexion();
    $stmt = $pdo->prepare(
        'SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = :amb LIMIT 1'
    );
    $stmt->execute([':cuenta_id' => Auth::cuentaId(), ':amb' => $ambiente]);
    $rutEmisor = $stmt->fetchColumn();
    if ($rutEmisor === false) {
        redirigir($rutaEmpresa);
    }

    $stmtCert = $pdo->prepare(
        'SELECT 1 FROM dte_certificado WHERE rut_emisor = :rut AND ambiente = :amb LIMIT 1'
    );
    $stmtCert->execute([':rut' => $rutEmisor, ':amb' => $ambiente]);
    if ($stmtCert->fetchColumn() === false) {
        redirigir($rutaCertificado);
    }

    // --- a. Verificar que llego archivo ---
    $archivo = $_FILES['caf'] ?? null;
    if (
        ! is_array($archivo)
        || ! isset($archivo['error'], $archivo['tmp_name'])
        || $archivo['error'] !== UPLOAD_ERR_OK
        || ! is_uploaded_file($archivo['tmp_name'])
    ) {
        vista($vista, [
            'error' => 'Debes seleccionar un archivo CAF (.xml) valido.',
            'cafs'  => listarCafs($pdo, (string) $rutEmisor, $ambiente),
        ]);
    }

    // --- b. Leer los BYTES ORIGINALES a memoria (nunca move_uploaded_file, nunca a disco propio) ---
    $original = file_get_contents($archivo['tmp_name']);
    if ($original === false || trim($original) === '') {
        vista($vista, [
            'error' => 'No se pudo leer el archivo subido.',
            'cafs'  => listarCafs($pdo, (string) $rutEmisor, $ambiente),
        ]);
    }

    // --- c. Copia SOLO para parsear; NUNCA se cifra esta version convertida ---
    $paraParsear = mb_check_encoding($original, 'UTF-8')
        ? $original
        : mb_convert_encoding($original, 'UTF-8', 'ISO-8859-1');

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $xmlOk = $dom->loadXML($paraParsear);
    libxml_clear_errors();
    libxml_use_internal_errors(false);
    if (! $xmlOk) {
        vista($vista, [
            'error' => 'El archivo no es un CAF valido.',
            'cafs'  => listarCafs($pdo, (string) $rutEmisor, $ambiente),
        ]);
    }

    // --- d. Extraer TD/RE/D/H ---
    $td = cafTexto($dom, 'TD');
    $re = cafTexto($dom, 'RE');
    $d  = cafTexto($dom, 'D');
    $h  = cafTexto($dom, 'H');
    if ($td === '' || $re === '' || $d === '' || $h === '') {
        vista($vista, [
            'error' => 'CAF mal formado: faltan datos de tipo, RUT o rango de folios.',
            'cafs'  => listarCafs($pdo, (string) $rutEmisor, $ambiente),
        ]);
    }

    // --- e. Validar tipo/rango y que el RUT del CAF sea el de la empresa (no el del firmante) ---
    $tipo  = (int) $td;
    $desde = (int) $d;
    $hasta = (int) $h;
    if ($tipo <= 0 || $desde <= 0 || $hasta < $desde) {
        vista($vista, [
            'error' => 'Valores invalidos en el CAF (tipo o rango de folios).',
            'cafs'  => listarCafs($pdo, (string) $rutEmisor, $ambiente),
        ]);
    }
    if (Rut::normalizar($re) !== Rut::normalizar((string) $rutEmisor)) {
        vista($vista, [
            'error' => 'El RUT del CAF no corresponde a tu empresa.',
            'cafs'  => listarCafs($pdo, (string) $rutEmisor, $ambiente),
        ]);
    }

    // --- f. Envelope encryption sobre los BYTES ORIGINALES (NO la copia UTF-8) ---
    $dek = random_bytes(32);
    try {
        $cifrado     = (new CertificadoCrypto($dek))->cifrar($original);
        $dekEnvuelta = (new CertificadoCrypto(kekMaestra()))->cifrar($dek);
    } catch (CertificadoCryptoException $e) {
        error_log('panel caf (' . $ambiente . '): fallo de cifrado - ' . $e->getMessage());
        vista($vista, [
            'error' => 'No se pudo procesar el CAF. Intenta nuevamente.',
            'cafs'  => listarCafs($pdo, (string) $rutEmisor, $ambiente),
        ]);
    }

    // --- g. NO se guarda todavia: el CAF queda pendiente de confirmacion ---
    //
    // Cargar un CAF fija el folio desde el que Sinergia va a emitir documentos
    // tributarios reales, y ese dato no se puede corregir despues sin apoyo.
    // Por eso el flujo es de DOS PASOS y este handler solo prepara la revision.
    //
    // El XML NO viaja al paso 2 por HTTP: contiene la <RSASK>, la clave privada
    // del CAF, y ponerla en un campo oculto la expondria en el HTML, en el
    // historial del navegador y en cualquier log intermedio. Se guarda en
    // sesion, y se guarda YA CIFRADO -- exactamente el mismo par
    // (ciphertext + DEK envuelta) que terminaria en la base de datos. La KEK
    // maestra NO esta en la sesion: vive en el entorno, asi que quien leyera el
    // archivo de sesion del servidor tampoco podria descifrarlo.
    $_SESSION['caf_pendiente'] = [
        'ambiente'   => $ambiente,
        'rut_emisor' => (string) $rutEmisor,
        'tipo'       => $tipo,
        'desde'      => $desde,
        'hasta'      => $hasta,
        'cifrado'    => $cifrado,
        'dek'        => $dekEnvuelta,
        // Huella del archivo original: deja rastro de QUE archivo exacto se
        // confirmo, y detectaria una corrupcion del dato en sesion.
        'huella'     => hash('sha256', $original),
        'creado_at'  => time(),
    ];

    vista('caf-revision', [
        'ambiente'  => $ambiente,
        'tipo'      => $tipo,
        'desde'     => $desde,
        'hasta'     => $hasta,
        'declarado' => '',
        'error'     => null,
        'navActivo' => $ambiente === 'produccion' ? 'config.caf-prod' : 'config.caf',
    ]);
}

// ===========================================================================
//  Handler: POST /caf/confirmar y POST /caf-produccion/confirmar
//
//  Paso 2 de la carga de CAF. Guarda lo que el usuario acaba de revisar.
//
//  El XML nunca vuelve a viajar por HTTP: quedo cifrado en sesion en el paso 1
//  y aqui solo se lee. Lo unico que llega del cliente es el proximo folio
//  declarado (opcional) y el token CSRF, que el router ya verifico antes de
//  llegar hasta aqui.
// ===========================================================================
function confirmarCafPost(string $ambiente, string $rutaExito): void
{
    $pdo       = Db::conexion();
    $pendiente = $_SESSION['caf_pendiente'] ?? null;

    // Sin pendiente, de otro ambiente, o vencido: se vuelve a empezar. No se
    // adivina ni se reconstruye nada -- guardar un CAF que el usuario no
    // reviso en ESTE flujo es justo lo que el paso de confirmacion evita.
    if (
        ! is_array($pendiente)
        || ($pendiente['ambiente'] ?? '') !== $ambiente
        || (time() - (int) ($pendiente['creado_at'] ?? 0)) > CAF_PENDIENTE_TTL
    ) {
        unset($_SESSION['caf_pendiente']);
        flashSet('error', 'La revision del CAF expiro o no se encontro. Vuelve a subir el archivo.');
        redirigir($rutaExito);
    }

    $rutEmisor = (string) $pendiente['rut_emisor'];
    $tipo      = (int) $pendiente['tipo'];
    $desde     = (int) $pendiente['desde'];
    $hasta     = (int) $pendiente['hasta'];

    // --- Proximo folio declarado: OPCIONAL ---
    //
    // Vacio = CAF nuevo, sin folios usados antes: se arranca en folio_desde,
    // exactamente el comportamiento que tenia el sistema antes de esta
    // funcionalidad.
    $declaradoRaw = trim((string) ($_POST['proximo_folio'] ?? ''));
    $error        = null;

    if ($declaradoRaw === '') {
        $proximoInicial = $desde;
    } elseif (! ctype_digit($declaradoRaw)) {
        $error = 'El proximo folio debe ser un numero entero, sin puntos ni espacios.';
    } else {
        $proximoInicial = (int) $declaradoRaw;
        if ($proximoInicial < $desde || $proximoInicial > $hasta) {
            $error = sprintf(
                'El proximo folio debe estar dentro del rango que autoriza este CAF (%d a %d).',
                $desde,
                $hasta
            );
        }
    }

    if ($error !== null) {
        // Se re-renderiza la MISMA pantalla de revision. El CAF sigue en
        // sesion: un error de tipeo no obliga a volver a subir el archivo.
        vista('caf-revision', [
            'ambiente'  => $ambiente,
            'tipo'      => $tipo,
            'desde'     => $desde,
            'hasta'     => $hasta,
            'declarado' => $declaradoRaw,
            'error'     => $error,
            'navActivo' => $ambiente === 'produccion' ? 'config.caf-prod' : 'config.caf',
        ]);
    }

    // --- Guardar en transaccion (dte_caf + dte_folio) ---
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO dte_caf (rut_emisor, tipo_dte, ambiente, folio_desde, folio_hasta, caf_xml_cifrado, dek_envuelta) '
            . 'VALUES (:rut, :tipo, :amb, :desde, :hasta, :xml, :dek)'
        )->execute([
            ':rut'   => $rutEmisor,
            ':tipo'  => $tipo,
            ':amb'   => $ambiente,
            ':desde' => $desde,
            ':hasta' => $hasta,
            ':xml'   => $pendiente['cifrado'],
            ':dek'   => $pendiente['dek'],
        ]);
        $cafId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO dte_folio (caf_id, rut_emisor, tipo_dte, ambiente, '
            . 'proximo_folio, proximo_folio_inicial, folio_hasta) '
            . 'VALUES (:caf, :rut, :tipo, :amb, :prox, :proxIni, :hasta)'
        )->execute([
            ':caf'     => $cafId,
            ':rut'     => $rutEmisor,
            ':tipo'    => $tipo,
            ':amb'     => $ambiente,
            // El contador arranca aqui...
            ':prox'    => $proximoInicial,
            // ...y se recuerda con que valor arranco, para que el dashboard
            // pueda distinguir lo emitido EN Sinergia de lo que el emisor ya
            // habia gastado con su proveedor anterior.
            ':proxIni' => $proximoInicial,
            ':hasta'   => $hasta,
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('panel caf (' . $ambiente . '): fallo al guardar - ' . $e->getMessage());
        // 23000 = violacion de UNIQUE (uk_caf_rango: mismo rut/tipo/ambiente/rango ya cargado).
        $mensaje = $e->getCode() === '23000'
            ? 'Este CAF ya esta cargado (mismo tipo y rango).'
            : 'No se pudo guardar el CAF. Intenta nuevamente.';
        unset($_SESSION['caf_pendiente']);
        flashSet('error', $mensaje);
        redirigir($rutaExito);
    }

    // Se consume una sola vez: nunca queda un pendiente que se pueda confirmar
    // dos veces con un refresh.
    unset($_SESSION['caf_pendiente']);
    flashSet('exito', 'CAF cargado correctamente.');
    redirigir($rutaExito);
}

// ===========================================================================
//  Handler: GET /apikeys
//
//  Requiere onboarding base completo (empresa + certificado + >=1 CAF). Nunca
//  muestra un secreto de una key ya existente (no se guarda en claro): solo
//  el POST /apikeys/generar puede pasar 'keyNueva' a la vista, y solo en el
//  request donde recien se genero.
// ===========================================================================
function handleApiKeysGet(): void
{
    $cuentaId = Auth::cuentaId();
    $pdo      = Db::conexion();
    exigirOnboardingCompleto($pdo, $cuentaId);

    vista('apikeys', [
        'keys'     => listarApiKeys($pdo, $cuentaId),
        'keyNueva' => null,
        'error'    => null,
    ]);
}

// ===========================================================================
//  Handler: POST /apikeys/generar
//
//  Genera una API key de ambiente 'certificacion', scopeada al rut_emisor de
//  la cuenta. SOLO se persiste key_hash (sha256 del secreto) + prefijo; el
//  secreto en claro vive nada mas en la variable local de este request y en
//  el render de esta misma respuesta (nunca en sesion, BD ni error_log).
// ===========================================================================
function handleApiKeysGenerarPost(): void
{
    $cuentaId  = Auth::cuentaId();
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, $cuentaId);

    // --- b. Secreto: base64url de 32 bytes aleatorios (alta entropia, sin punto) ---
    $secreto = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    // --- c. Prefijo con marca de ambiente ("cert_" + 8 hex); verifica unicidad ---
    $prefijo = null;
    for ($intento = 0; $intento < 5; $intento++) {
        $candidato = 'cert_' . bin2hex(random_bytes(4));
        $chequeo   = $pdo->prepare('SELECT 1 FROM api_key WHERE prefijo = :prefijo LIMIT 1');
        $chequeo->execute([':prefijo' => $candidato]);
        if ($chequeo->fetchColumn() === false) {
            $prefijo = $candidato;
            break;
        }
    }
    if ($prefijo === null) {
        error_log(sprintf('panel apikeys: no se pudo generar un prefijo unico tras 5 intentos (cuenta %d)', $cuentaId));
        vista('apikeys', [
            'keys'     => listarApiKeys($pdo, $cuentaId),
            'keyNueva' => null,
            'error'    => 'No se pudo generar una key en este momento. Intenta nuevamente.',
        ]);
    }

    // --- d. Hash del secreto (sha256 hex, formato que exige resolverTenant() en public/index.php) ---
    $keyHash = hash('sha256', $secreto);

    // --- e. Guardar: SOLO key_hash + prefijo van a BD. El secreto NUNCA. ---
    try {
        $pdo->prepare(
            "INSERT INTO api_key (cuenta_id, key_hash, prefijo, rut_emisor_scope, ambiente, estado) "
            . "VALUES (:cuenta_id, :hash, :prefijo, :rut, 'certificacion', 'activa')"
        )->execute([
            ':cuenta_id' => $cuentaId,
            ':hash'      => $keyHash,
            ':prefijo'   => $prefijo,
            ':rut'       => $rutEmisor,
        ]);
    } catch (PDOException $e) {
        error_log('panel apikeys: fallo al guardar - ' . $e->getMessage());
        vista('apikeys', [
            'keys'     => listarApiKeys($pdo, $cuentaId),
            'keyNueva' => null,
            'error'    => 'No se pudo guardar la nueva key. Intenta nuevamente.',
        ]);
    }

    // --- f. Mostrar la key completa UNA vez, solo en el render de esta respuesta ---
    //     (sin redirect: si redirigieramos a GET /apikeys, tendriamos que pasar
    //     la key por sesion o query string, y NINGUNA de esas dos es aceptable
    //     para un secreto).
    vista('apikeys', [
        'keys'     => listarApiKeys($pdo, $cuentaId),
        'keyNueva' => $prefijo . '.' . $secreto,
        'error'    => null,
    ]);
}

// ===========================================================================
//  Handler: POST /apikeys/revocar
//
//  Revoca por id, scopeado a cuenta_id en el propio UPDATE (revocar el id de
//  otra cuenta simplemente actualiza 0 filas, sin filtrar si el id existe).
// ===========================================================================
function handleApiKeysRevocarPost(): void
{
    $cuentaId = Auth::cuentaId();
    $id       = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {
        Db::conexion()
            ->prepare("UPDATE api_key SET estado = 'revocada' WHERE id = :id AND cuenta_id = :cuenta_id")
            ->execute([':id' => $id, ':cuenta_id' => $cuentaId]);
    }

    redirigir('/apikeys');
}

// ===========================================================================
//  Handler: GET /apikeys-produccion
//
//  Duplicado de GET /apikeys para el ambiente de PRODUCCION -- funcion NUEVA
//  e independiente (handleApiKeysGet() no se toca). Requiere
//  exigirProduccionCompleto() (empresa + certificado + >=1 CAF, los 3 de
//  produccion).
// ===========================================================================
function handleApiKeysProduccionGet(): void
{
    $cuentaId = Auth::cuentaId();
    $pdo      = Db::conexion();
    exigirProduccionCompleto($pdo, $cuentaId);

    vista('apikeys-produccion', [
        'keys'     => listarApiKeysProduccion($pdo, $cuentaId),
        'keyNueva' => null,
        'error'    => null,
    ]);
}

// ===========================================================================
//  Handler: POST /apikeys-produccion/generar
//
//  Duplicado de POST /apikeys/generar (handleApiKeysGenerarPost() no se
//  toca): misma logica exacta (secreto aleatorio, hash sha256, key mostrada
//  UNA sola vez), con 2 diferencias: ambiente='produccion' en el INSERT, y
//  prefijo 'prod_' + 8 hex (en vez de 'cert_'), mismo modelo test/live que ya
//  usa el proyecto.
// ===========================================================================
function handleApiKeysProduccionGenerarPost(): void
{
    $cuentaId  = Auth::cuentaId();
    $pdo       = Db::conexion();
    $rutEmisor = exigirProduccionCompleto($pdo, $cuentaId);

    // --- b. Secreto: base64url de 32 bytes aleatorios (alta entropia, sin punto) ---
    $secreto = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    // --- c. Prefijo con marca de ambiente ("prod_" + 8 hex); verifica unicidad ---
    $prefijo = null;
    for ($intento = 0; $intento < 5; $intento++) {
        $candidato = 'prod_' . bin2hex(random_bytes(4));
        $chequeo   = $pdo->prepare('SELECT 1 FROM api_key WHERE prefijo = :prefijo LIMIT 1');
        $chequeo->execute([':prefijo' => $candidato]);
        if ($chequeo->fetchColumn() === false) {
            $prefijo = $candidato;
            break;
        }
    }
    if ($prefijo === null) {
        error_log(sprintf('panel apikeys-produccion: no se pudo generar un prefijo unico tras 5 intentos (cuenta %d)', $cuentaId));
        vista('apikeys-produccion', [
            'keys'     => listarApiKeysProduccion($pdo, $cuentaId),
            'keyNueva' => null,
            'error'    => 'No se pudo generar una key en este momento. Intenta nuevamente.',
        ]);
    }

    // --- d. Hash del secreto (sha256 hex, formato que exige resolverTenant() en public/index.php) ---
    $keyHash = hash('sha256', $secreto);

    // --- e. Guardar: SOLO key_hash + prefijo van a BD. El secreto NUNCA. ---
    try {
        $pdo->prepare(
            "INSERT INTO api_key (cuenta_id, key_hash, prefijo, rut_emisor_scope, ambiente, estado) "
            . "VALUES (:cuenta_id, :hash, :prefijo, :rut, 'produccion', 'activa')"
        )->execute([
            ':cuenta_id' => $cuentaId,
            ':hash'      => $keyHash,
            ':prefijo'   => $prefijo,
            ':rut'       => $rutEmisor,
        ]);
    } catch (PDOException $e) {
        error_log('panel apikeys-produccion: fallo al guardar - ' . $e->getMessage());
        vista('apikeys-produccion', [
            'keys'     => listarApiKeysProduccion($pdo, $cuentaId),
            'keyNueva' => null,
            'error'    => 'No se pudo guardar la nueva key. Intenta nuevamente.',
        ]);
    }

    // --- f. Mostrar la key completa UNA vez, solo en el render de esta respuesta ---
    vista('apikeys-produccion', [
        'keys'     => listarApiKeysProduccion($pdo, $cuentaId),
        'keyNueva' => $prefijo . '.' . $secreto,
        'error'    => null,
    ]);
}

// ===========================================================================
//  Handler: POST /apikeys-produccion/revocar
//
//  Duplicado de POST /apikeys/revocar (handleApiKeysRevocarPost() no se
//  toca): misma logica, scopeado a cuenta_id en el UPDATE.
// ===========================================================================
function handleApiKeysProduccionRevocarPost(): void
{
    $cuentaId = Auth::cuentaId();
    $id       = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {
        Db::conexion()
            ->prepare("UPDATE api_key SET estado = 'revocada' WHERE id = :id AND cuenta_id = :cuenta_id")
            ->execute([':id' => $id, ':cuenta_id' => $cuentaId]);
    }

    redirigir('/apikeys-produccion');
}

// ===========================================================================
//  SOLO SUPERADMIN: dashboard de vigilancia de tenants + auditoria.
//
//  Todo el codigo de esta seccion es NUEVO y ADITIVO: no reemplaza ni llama
//  desde ningun handler/vista existente del tenant. Cada handler empieza con
//  exigirSuperadmin(), que responde 403 (nunca redirige a /login) si la
//  sesion actual no tiene usuario.rol = 'superadmin'.
// ===========================================================================

/**
 * Exige que el usuario de la sesion actual tenga usuario.rol = 'superadmin'.
 * Responde 403 y termina la ejecucion si no (sea porque no hay sesion, o
 * porque la hay pero el rol no es superadmin) -- NUNCA redirige a /login:
 * un redirect confirmaria que la ruta existe y requiere autenticacion a
 * cualquiera que la sondee sin sesion; un 403 uniforme no distingue esos
 * casos.
 */
function exigirSuperadmin(PDO $pdo): void
{
    if (! Auth::autenticado()) {
        http_response_code(403);
        echo '403 - No autorizado.';
        exit;
    }

    $stmt = $pdo->prepare('SELECT rol FROM usuario WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => Auth::usuarioId()]);
    $rol = $stmt->fetchColumn();

    if ($rol !== 'superadmin') {
        http_response_code(403);
        echo '403 - No autorizado.';
        exit;
    }
}

/**
 * Registra una fila en el changelog admin_auditoria (append-only: un solo
 * INSERT, ninguna fila se actualiza despues). Reutilizable desde cualquier
 * accion administrativa futura, no solo suspender/reactivar cuenta.
 *
 * @param array<string,mixed>|null $valorAnterior Snapshot ANTES del cambio (null si no aplica, ej. una creacion).
 * @param array<string,mixed>|null $valorNuevo    Snapshot DESPUES del cambio (null si no aplica, ej. una eliminacion).
 */
function registrarAuditoria(
    PDO $pdo,
    int $usuarioId,
    string $accion,
    string $entidadTipo,
    int $entidadId,
    ?array $valorAnterior,
    ?array $valorNuevo
): void {
    $pdo->prepare(
        'INSERT INTO admin_auditoria (usuario_id, accion, entidad_tipo, entidad_id, valor_anterior, valor_nuevo) '
        . 'VALUES (:usuario_id, :accion, :entidad_tipo, :entidad_id, :anterior, :nuevo)'
    )->execute([
        ':usuario_id'   => $usuarioId,
        ':accion'       => $accion,
        ':entidad_tipo' => $entidadTipo,
        ':entidad_id'   => $entidadId,
        ':anterior'     => $valorAnterior !== null ? json_encode($valorAnterior, JSON_UNESCAPED_UNICODE) : null,
        ':nuevo'        => $valorNuevo !== null ? json_encode($valorNuevo, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

/**
 * Nombre + clase de color de las 6 etapas de certificacion de UN rut_emisor,
 * MISMA convencion que el bloque ya existente en panel/views/certificacion.php
 * (completada=verde, activa=azul=primera etapa no completada, no-gestionada
 * =gris) -- extraida aqui como funcion PURA (sin PDO) para que
 * GET /admin/tenants pueda mostrar el resumen compacto de TODAS las cuentas
 * sin reinventar el criterio. certificacion.php NO se toca; sigue con su
 * bloque inline igual que antes.
 *
 * @param array{simulacion:array{confirmada:bool},intercambio:array{confirmada:bool},muestrasImpresas:array{confirmada:bool}} $etapasManuales Salida de calcularEtapasManuales().
 * @return list<array{nombre:string,clase:string}>
 */
function resumenEtapasBarra(bool $todosAprobados, array $etapasManuales, ?string $certConfirmadaAt): array
{
    $etapas = [
        ['nombre' => 'Set de Prueba',            'completada' => $todosAprobados],
        ['nombre' => 'Simulacion',               'completada' => $etapasManuales['simulacion']['confirmada']],
        ['nombre' => 'Intercambio',              'completada' => $etapasManuales['intercambio']['confirmada']],
        ['nombre' => 'Muestras Impresas',        'completada' => $etapasManuales['muestrasImpresas']['confirmada']],
        ['nombre' => 'Declaracion Cumplimiento', 'completada' => $certConfirmadaAt !== null],
        ['nombre' => 'Autorizacion',             'completada' => $certConfirmadaAt !== null],
    ];

    $todasAnterioresCompletas = true;
    foreach ($etapas as &$e) {
        if ($e['completada']) {
            $e['clase'] = 'etapa--completada';
        } elseif ($todasAnterioresCompletas) {
            $e['clase'] = 'etapa--activa';
        } else {
            $e['clase'] = 'etapa--no-gestionada';
        }
        if (! $e['completada']) {
            $todasAnterioresCompletas = false;
        }
    }
    unset($e);

    return $etapas;
}

// ===========================================================================
//  Handler: GET /admin/tenants (SOLO SUPERADMIN)
//
//  Recorre TODAS las cuentas (no solo Auth::cuentaId(), a diferencia de todo
//  el resto del panel) y arma, por cuenta, el mismo resumen de certificacion
//  que ya usa el tenant individual: reutiliza setBasicoAprobado(),
//  libroAprobado(), calcularEtapasManuales() y obtenerCertificacionConfirmadaAt()
//  TAL CUAL, por cada rut_emisor encontrado -- no reinventa el calculo de en
//  que etapa esta cada empresa. Solo lectura: ninguna fila se modifica aqui.
// ===========================================================================
function handleAdminTenantsGet(): void
{
    $pdo = Db::conexion();
    exigirSuperadmin($pdo);

    $cuentas = $pdo->query(
        'SELECT id, email, nombre, estado, created_at FROM cuenta ORDER BY created_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $resumen = [];
    foreach ($cuentas as $cuenta) {
        $cuentaId = (int) $cuenta['id'];

        $stmtEmisores = $pdo->prepare(
            "SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion'"
        );
        $stmtEmisores->execute([':cuenta_id' => $cuentaId]);
        $rutsEmisor = $stmtEmisores->fetchAll(PDO::FETCH_COLUMN);

        $emisores = [];
        foreach ($rutsEmisor as $rutEmisor) {
            $rutEmisor = (string) $rutEmisor;

            $agrupado       = agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor));
            $sokPorTrackId  = (new MySqlSetBasicoSokRepository($pdo))->confirmadosPorTrackId($rutEmisor, Ambiente::Certificacion);
            $setBasico      = setBasicoAprobado($agrupado['envios'], $sokPorTrackId);
            $ventas         = libroAprobado(listarLibros($pdo, $rutEmisor, 'VENTA'));
            $compras        = libroAprobado(listarLibros($pdo, $rutEmisor, 'COMPRA'));
            $todosAprobados = $setBasico['aprobado'] && $ventas['aprobado'] && $compras['aprobado'];

            $etapasManuales   = calcularEtapasManuales(obtenerEtapasManualesRaw($pdo, $cuentaId), $todosAprobados);
            $certConfirmadaAt = obtenerCertificacionConfirmadaAt($pdo, $cuentaId);

            // Mismas consultas de produccion que pinta la estacion 7 del
            // dashboard del tenant, centralizadas en estadoProduccion() para
            // que superadmin y tenant no puedan divergir.
            //
            // De las 4 aqui solo se usan certificado y CAF: son las que van por
            // rut_emisor y por eso viven dentro de este loop. La api_key va por
            // CUENTA y se consulta mas abajo, fuera del loop, porque una cuenta
            // sin emisores igual tiene que reportarla.
            $prod = estadoProduccion($pdo, $cuentaId, $rutEmisor);

            $emisores[] = [
                'rutEmisor'           => $rutEmisor,
                'setBasico'           => $setBasico,
                'libroVentas'         => $ventas,
                'libroCompras'        => $compras,
                'todosAprobados'      => $todosAprobados,
                'etapasManuales'      => $etapasManuales,
                'certConfirmadaAt'    => $certConfirmadaAt,
                'barra'               => resumenEtapasBarra($todosAprobados, $etapasManuales, $certConfirmadaAt),
                'tieneCertProduccion' => $prod['certificado'],
                'tieneCafProduccion'  => $prod['caf'],
            ];
        }

        // Mismo criterio que estadoProduccion(): solo keys EXTERNAS activas.
        // Sin el filtro de tipo, la key de servicio que el panel genera solo al
        // emitir (migracion 017, invisible al usuario) marcaria esta columna
        // como si el tenant hubiera creado una credencial.
        $stmtApiKeyProd = $pdo->prepare(
            'SELECT 1 FROM api_key '
            . "WHERE cuenta_id = :cuenta_id AND ambiente = 'produccion' "
            . "AND tipo = 'externa' AND estado = 'activa' LIMIT 1"
        );
        $stmtApiKeyProd->execute([':cuenta_id' => $cuentaId]);

        $resumen[] = [
            'cuenta'                => $cuenta,
            'emisores'              => $emisores,
            'tieneApiKeyProduccion' => $stmtApiKeyProd->fetchColumn() !== false,
        ];
    }

    vista('admin-tenants', ['resumen' => $resumen, 'flash' => flashTomar()]);
}

// ===========================================================================
//  Handler: POST /admin/tenants/suspender (SOLO SUPERADMIN)
//  Handler: POST /admin/tenants/reactivar (SOLO SUPERADMIN)
//
//  Unica accion de mutacion de este panel de superadmin. Comparten
//  cambiarEstadoCuentaAdmin(): lee el snapshot COMPLETO de la fila cuenta
//  ANTES de cambiar el estado (valor_anterior), actualiza, y registra la
//  auditoria con el snapshot antes/despues. Fuera de alcance a proposito:
//  que pasa si una cuenta suspendida sigue usando el resto del panel (tarea
//  aparte) -- hoy solo se deja el estado y su auditoria.
// ===========================================================================
function handleAdminTenantsSuspenderPost(): void
{
    $pdo = Db::conexion();
    exigirSuperadmin($pdo);
    cambiarEstadoCuentaAdmin($pdo, 'suspendida', 'cuenta.suspender');
}

function handleAdminTenantsReactivarPost(): void
{
    $pdo = Db::conexion();
    exigirSuperadmin($pdo);
    cambiarEstadoCuentaAdmin($pdo, 'activa', 'cuenta.reactivar');
}

/**
 * Logica compartida de suspender/reactivar cuenta. usuario_id de la
 * auditoria es SIEMPRE Auth::usuarioId() (el superadmin de la sesion
 * actual), nunca un valor del POST.
 */
function cambiarEstadoCuentaAdmin(PDO $pdo, string $nuevoEstado, string $accion): void
{
    $cuentaId = (int) ($_POST['cuenta_id'] ?? 0);
    if ($cuentaId <= 0) {
        redirigir('/admin/tenants');
    }

    $stmt = $pdo->prepare('SELECT id, email, nombre, estado, created_at FROM cuenta WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $cuentaId]);
    $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($anterior === false) {
        redirigir('/admin/tenants');
    }

    $pdo->prepare('UPDATE cuenta SET estado = :estado WHERE id = :id')
        ->execute([':estado' => $nuevoEstado, ':id' => $cuentaId]);

    $nuevo = $anterior;
    $nuevo['estado'] = $nuevoEstado;

    registrarAuditoria($pdo, Auth::usuarioId(), $accion, 'cuenta', $cuentaId, $anterior, $nuevo);

    redirigir('/admin/tenants');
}

// ===========================================================================
//  Handler: POST /admin/tenants/revertir-etapa (SOLO SUPERADMIN)
//
//  Camino INVERSO de las confirmaciones manuales de las etapas 2-4
//  (handleCertificacionConfirmarEtapaPost()) y de la 5/6
//  (handleCertificacionAprobadaConfirmarPost()) -- NINGUNO de esos 2
//  handlers se toca; esto es codigo nuevo y aditivo. Correccion
//  administrativa para cuando el tenant (o el propio superadmin) confirmo
//  algo por error: pone el campo de vuelta a NULL, con su propia auditoria.
// ===========================================================================
function handleAdminTenantsRevertirEtapaPost(): void
{
    $pdo = Db::conexion();
    exigirSuperadmin($pdo);

    // Whitelist FIJA de columnas revertibles: $campo NUNCA se interpola
    // directo de $_POST, solo selecciona una entrada de este mapa (mismo
    // criterio ya usado en handleCertificacionConfirmarEtapaPost()). El
    // valor es la columna 'track_id' pareja a limpiar junto (null si el
    // campo no tiene una).
    $camposRevertibles = [
        'certificacion_confirmada_at'      => null,
        'simulacion_confirmada_at'         => 'simulacion_track_id',
        'intercambio_confirmado_at'        => null,
        'muestras_impresas_confirmadas_at' => null,
    ];

    $rutEmisor = trim((string) ($_POST['rut_emisor'] ?? ''));
    $campo     = (string) ($_POST['campo'] ?? '');

    if ($rutEmisor === '' || ! array_key_exists($campo, $camposRevertibles)) {
        redirigir('/admin/tenants');
    }
    $trackCol = $camposRevertibles[$campo];

    $stmt = $pdo->prepare(
        'SELECT id, certificacion_confirmada_at, simulacion_confirmada_at, simulacion_track_id, '
        . '       intercambio_confirmado_at, muestras_impresas_confirmadas_at '
        . "FROM dte_emisor WHERE rut_emisor = :rut AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmt->execute([':rut' => $rutEmisor]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fila === false) {
        redirigir('/admin/tenants');
    }

    $valorAnterior = [$campo => $fila[$campo]];
    if ($trackCol !== null) {
        $valorAnterior[$trackCol] = $fila[$trackCol];
    }

    $sql = "UPDATE dte_emisor SET {$campo} = NULL"
        . ($trackCol !== null ? ", {$trackCol} = NULL" : '')
        . ' WHERE id = :id';
    $pdo->prepare($sql)->execute([':id' => $fila['id']]);

    $valorNuevo = [$campo => null];
    if ($trackCol !== null) {
        $valorNuevo[$trackCol] = null;
    }

    registrarAuditoria($pdo, Auth::usuarioId(), 'etapa.revertir', 'dte_emisor', (int) $fila['id'], $valorAnterior, $valorNuevo);

    flashSet('ok', sprintf('Campo %s revertido para el RUT %s.', $campo, $rutEmisor));
    redirigir('/admin/tenants');
}

// ===========================================================================
//  Handler: GET /admin/auditoria (SOLO SUPERADMIN)
//
//  Lista cronologica (mas reciente primero) del changelog admin_auditoria.
//  Solo lectura, sin filtros por ahora.
// ===========================================================================
function handleAdminAuditoriaGet(): void
{
    $pdo = Db::conexion();
    exigirSuperadmin($pdo);

    $filas = $pdo->query(
        'SELECT a.id, a.usuario_id, u.email AS usuario_email, a.accion, a.entidad_tipo, a.entidad_id, '
        . '       a.valor_anterior, a.valor_nuevo, a.created_at '
        . 'FROM admin_auditoria a '
        . 'LEFT JOIN usuario u ON u.id = a.usuario_id '
        . 'ORDER BY a.created_at DESC, a.id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);

    vista('admin-auditoria', ['filas' => $filas]);
}

/**
 * Convierte una fecha de BD (aaaa-mm-dd, o un timestamp completo como
 * aaaa-mm-dd hh:ii:ss) al formato dd-mm-aaaa que exige el SII en "Declarar
 * Avance" (pe_avance1, campo "Fecha Envio").
 */
function formatearFechaAvanceSii(string $fecha): string
{
    return (new DateTimeImmutable($fecha))->format('d-m-Y');
}

/**
 * Fecha (dd-mm-aaaa) del envio APROBADO de un componente, para la tabla de
 * "Declarar Avance": null si el componente aun no esta aprobado.
 *
 * @param list<array<string,mixed>> $filas         Envios/libros del componente (con 'trackId'/'track_id' y el campo de fecha).
 * @param array{aprobado:bool,trackId:?string} $veredicto Salida de setBasicoAprobado()/libroAprobado().
 * @param string $campoTrackId Nombre del campo de trackId en cada fila ('trackId' para envios de set basico, 'track_id' para libros).
 * @param string $campoFecha   Nombre del campo de fecha en cada fila.
 */
function fechaEnvioAprobado(array $filas, array $veredicto, string $campoTrackId, string $campoFecha): ?string
{
    if (! $veredicto['aprobado']) {
        return null;
    }
    foreach ($filas as $fila) {
        if ($fila[$campoTrackId] === $veredicto['trackId']) {
            return formatearFechaAvanceSii((string) $fila[$campoFecha]);
        }
    }

    return null;
}

// ===========================================================================
//  Handler: GET /certificacion
//
//  Progreso de certificacion del SET BASICO (factura/NC/ND), agrupado por
//  ENVIO (track_id) -- no por documento, ver agruparEmitidosPorEnvio(). Requiere
//  onboarding base completo (empresa + certificado + >=1 CAF).
// ===========================================================================
/**
 * Calculo puro (solo lectura de BD, sin flash ni efectos de sesion) de todas
 * las variables que necesita la certificacion de factura. Compartido por GET
 * /certificacion (resumen) y GET /certificacion/etapa/{n} (vista por etapa)
 * para no duplicar este calculo -- ver handleCertificacionGet() y
 * handleCertificacionEtapaGet(). flashTomar() se queda FUERA de este helper
 * a proposito: es stateful (consume el flash de sesion una sola vez) y cada
 * handler debe llamarlo el mismo una sola vez, no este helper compartido.
 */
function calcularDatosCertificacion(PDO $pdo, int $cuentaId, string $rutEmisor): array
{
    $agrupado      = agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor));
    $librosVentas  = listarLibros($pdo, $rutEmisor, 'VENTA');
    $librosCompras = listarLibros($pdo, $rutEmisor, 'COMPRA');
    $sokPorTrackId = (new MySqlSetBasicoSokRepository($pdo))->confirmadosPorTrackId($rutEmisor, Ambiente::Certificacion);

    $setBasico            = setBasicoAprobado($agrupado['envios'], $sokPorTrackId);
    $libroVentasAprobado  = libroAprobado($librosVentas);
    $libroComprasAprobado = libroAprobado($librosCompras);
    $todosAprobados       = $setBasico['aprobado'] && $libroVentasAprobado['aprobado'] && $libroComprasAprobado['aprobado'];

    // Set Basico EPR+3-tipos pero TODAVIA sin SOK: habilita la opcion de
    // riesgo "emitir Libro sin esperar SOK" en las tarjetas de Libro de
    // Ventas/Compras (ver CertificacionEstadoResolver::setBasicoEnviadoSinReparos()).
    // Solo tiene sentido mostrarla cuando el Set Basico normal AUN no esta
    // aprobado (si ya lo esta, el boton normal ya funciona, no hace falta
    // la opcion de riesgo).
    $setBasicoSinSok = $setBasico['aprobado']
        ? ['aprobado' => false, 'trackId' => null]
        : CertificacionEstadoResolver::setBasicoEnviadoSinReparos($agrupado['envios']);

    // Etapas 2-4 (Simulacion, Intercambio, Muestras Impresas): confirmacion
    // MANUAL del tenant, igual criterio que certificacion_confirmada_at (etapa
    // 6) -- ver migracion 010_emisor_etapas_manuales.sql. Encadenadas igual
    // que Set Basico -> Libro de Ventas/Compras (ver calcularEtapasManuales()).
    $etapasManuales             = calcularEtapasManuales(obtenerEtapasManualesRaw($pdo, $cuentaId), $todosAprobados);
    $certificacionConfirmadaAt  = obtenerCertificacionConfirmadaAt($pdo, $cuentaId);

    // Fecha del envio APROBADO de cada componente, en el formato dd-mm-aaaa
    // que pide el SII en "Declarar Avance" (pe_avance1). Set Basico usa la
    // fecha_emision de los documentos (dte_emitido, via agruparEmitidosPorEnvio());
    // los libros usan created_at de dte_libro -- es el momento en que
    // MySqlLibroRepository::registrar() persiste, llamado inmediatamente
    // despues de que el SII responde OK al envio (ver enviarLibroIecv() en
    // public/index.php), asi que refleja la fecha real de envio al SII.
    $fechaSetBasico    = fechaEnvioAprobado($agrupado['envios'], $setBasico, 'trackId', 'fechaEmision');
    $fechaLibroVentas  = fechaEnvioAprobado($librosVentas, $libroVentasAprobado, 'track_id', 'created_at');
    $fechaLibroCompras = fechaEnvioAprobado($librosCompras, $libroComprasAprobado, 'track_id', 'created_at');

    return [
        'envios'                     => $agrupado['envios'],
        'sinTrackId'                 => $agrupado['sinTrackId'],
        'sokPorTrackId'              => $sokPorTrackId,
        'setBasico'                  => $setBasico,
        'setBasicoSinSok'            => $setBasicoSinSok,
        'fechaSetBasico'             => $fechaSetBasico,
        'librosVentas'               => $librosVentas,
        'libroVentasAprobado'        => $libroVentasAprobado,
        'fechaLibroVentas'           => $fechaLibroVentas,
        'librosCompras'              => $librosCompras,
        'libroComprasAprobado'       => $libroComprasAprobado,
        'fechaLibroCompras'          => $fechaLibroCompras,
        'todosAprobados'             => $todosAprobados,
        'etapasManuales'             => $etapasManuales,
        'certificacionConfirmadaAt'  => $certificacionConfirmadaAt,
    ];
}

// ===========================================================================
//  Handler: GET /certificacion-elegir
//
//  Pagina intermedia de eleccion entre Factura y Boleta -- 2 procesos
//  INDEPENDIENTES ante el SII (ver /certificacion vs /certificacion/boleta),
//  cada uno con su propia barra de 6 pasos. Resumen COMPACTO de cada uno,
//  reusando EXACTAMENTE el mismo calculo que ya usan las paginas de detalle
//  (calcularDatosCertificacion()+resumenEtapasBarra() para factura,
//  listarBoletasEmitidas()+calcularEtapasBoletaManuales()+resumenEtapasBoletaBarra()
//  para boleta) -- no se recalcula ningun estado nuevo aqui, solo se
//  presenta mas compacto. No es ruta que se pueda bloquear: /certificacion y
//  /certificacion/boleta siguen accesibles directo por URL (ver punto 5 de
//  la tarea), esta pagina solo cambia el punto de entrada normal desde el
//  dashboard (ver handlePanelGet()).
// ===========================================================================
function handleCertificacionElegirGet(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirOnboardingCompleto($pdo, $cuentaId);

    $datosFactura      = calcularDatosCertificacion($pdo, $cuentaId, $rutEmisor);
    $segmentosFactura  = resumenEtapasBarra($datosFactura['todosAprobados'], $datosFactura['etapasManuales'], $datosFactura['certificacionConfirmadaAt']);
    $completadasFactura = count(array_filter($segmentosFactura, static fn (array $s): bool => $s['completada']));
    $textoFactura       = textoEtapaActiva($segmentosFactura);

    $boletasEmitidas   = listarBoletasEmitidas($pdo, $rutEmisor);
    $rvdUltimo         = (new MySqlBoletaRvdRepository($pdo))->ultimo($rutEmisor, Ambiente::Certificacion);
    $setEmitido        = $boletasEmitidas !== [];
    $rvdEnviado        = $rvdUltimo !== null;
    $etapasBoleta      = calcularEtapasBoletaManuales(obtenerEtapasBoletaManualesRaw($pdo, $cuentaId), $setEmitido, $rvdEnviado);
    $segmentosBoleta   = resumenEtapasBoletaBarra(existeCafBoleta($pdo, $rutEmisor), $setEmitido, $rvdEnviado, $etapasBoleta);
    $completadasBoleta = count(array_filter($segmentosBoleta, static fn (array $s): bool => $s['completada']));
    $textoBoleta       = textoEtapaActiva($segmentosBoleta);

    vista('certificacion-elegir', [
        'segmentosFactura'   => $segmentosFactura,
        'completadasFactura' => $completadasFactura,
        'textoFactura'       => $textoFactura,
        'segmentosBoleta'    => $segmentosBoleta,
        'completadasBoleta'  => $completadasBoleta,
        'textoBoleta'        => $textoBoleta,
    ]);
}

function handleCertificacionGet(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirOnboardingCompleto($pdo, $cuentaId);

    // Solo lectura de BD + el flash dejado por un POST anterior (si hay). NO
    // se consulta el SII aqui: esta ruta es un GET idempotente. etapaActual
    // en null: el resumen no esta "dentro" de ninguna etapa especifica (ver
    // partials/certificacion/_barra-etapas.php).
    vista('certificacion', array_merge(
        ['flash' => flashTomar(), 'etapaActual' => null],
        calcularDatosCertificacion($pdo, $cuentaId, $rutEmisor),
    ));
}

/**
 * GET /certificacion/etapa/{n} (n=1..6): vista de UNA sola etapa de la
 * certificacion de factura, con la barra de 6 etapas arriba para navegar
 * entre ellas. Usa el mismo calculo que el resumen (calcularDatosCertificacion())
 * -- no se duplica logica de BD, solo cambia que partials renderiza la vista.
 * n invalido (no numerico, 0, o > 6) redirige al resumen.
 */
function handleCertificacionEtapaGet(string $nCrudo): void
{
    if (! ctype_digit($nCrudo)) {
        redirigir('/certificacion');
    }
    $n = (int) $nCrudo;
    if ($n < 1 || $n > 6) {
        redirigir('/certificacion');
    }

    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirOnboardingCompleto($pdo, $cuentaId);

    vista('certificacion-etapa', array_merge(
        ['flash' => flashTomar(), 'etapaActual' => $n],
        calcularDatosCertificacion($pdo, $cuentaId, $rutEmisor),
    ));
}

// ===========================================================================
//  Handler: POST /certificacion/actualizar
//
//  "Actualizar estado": consulta el ENVIO al SII EN VIVO por track_id y
//  persiste el resultado en TODAS las filas de dte_emitido que comparten ese
//  track_id (el estado es del envio completo, no de un documento suelto; ver
//  agruparEmitidosPorEnvio()). Reusa los MISMOS componentes del motor que usa
//  public/index.php (MySqlEmisorRepository::obtenerCertificado() -- ya
//  descifra envelope --, SiiAutenticador::obtenerToken(),
//  SiiConsultor::consultarEnvio()): NO se reimplementa cifrado, firma ni
//  consulta SOAP, se llama a las mismas clases del motor via el autoloader de
//  Composer. Scope estricto: track_id + rut_emisor + ambiente de ESTA cuenta,
//  igual que el resto del panel.
// ===========================================================================
function handleCertificacionActualizarPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());

    $trackId = trim((string) ($_POST['track_id'] ?? ''));
    if ($trackId === '') {
        redirigirPrg('/certificacion');
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM dte_emitido WHERE track_id = :track AND rut_emisor = :rut AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmt->execute([':track' => $trackId, ':rut' => $rutEmisor]);
    if ($stmt->fetchColumn() === false) {
        // No existe, o no pertenece a esta cuenta: mismo destino, sin filtrar cual.
        redirigirPrg('/certificacion');
    }

    $certTls = __DIR__ . '/../../fullchain.pem';
    $keyTls  = __DIR__ . '/../../key.pem';
    if (! is_file($certTls) || ! is_readable($certTls) || ! is_file($keyTls) || ! is_readable($keyTls)) {
        flashSet('error', 'Certificado TLS mutuo no disponible en el servidor.');
        redirigirPrg('/certificacion');
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        error_log('panel certificacion: CRYPTO_MASTER_KEY ausente o mal configurada');
        flashSet('error', 'Error de configuracion del servidor (llave de cifrado). Contacta al administrador.');
        redirigirPrg('/certificacion');
    }
    $crypto = new CertificadoCrypto($bin);
    $emisor = new MySqlEmisorRepository($pdo, $crypto);
    $http   = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);

    // La llamada al SII va aislada: si falla, no se toca dte_emitido -- el
    // estado previo persistido queda intacto.
    try {
        $cert  = $emisor->obtenerCertificado($rutEmisor, Ambiente::Certificacion);
        $token = (new SiiAutenticador($http, new XmlSigner()))->obtenerToken($cert, Ambiente::Certificacion);
        $res   = (new SiiConsultor($http))->consultarEnvio($rutEmisor, $trackId, $token, Ambiente::Certificacion);
    } catch (SiiAutenticacionException $e) {
        error_log('panel certificacion: fallo de autenticacion con el SII - ' . $e->glosaSii);
        flashSet('error', 'No se pudo autenticar con el SII para consultar el estado. Intenta nuevamente.');
        redirigirPrg('/certificacion');
    } catch (Throwable $e) {
        error_log('panel certificacion: fallo consulta SII - ' . $e->getMessage());
        flashSet('error', 'No se pudo consultar el estado en el SII. Intenta nuevamente.');
        redirigirPrg('/certificacion');
    }

    // Persistir en TODAS las filas de este envio (mismo track_id), scope del
    // tenant: el estado es del envio completo, no de una fila suelta.
    $pdo->prepare(
        "UPDATE dte_emitido SET estado = :estado WHERE track_id = :track AND rut_emisor = :rut AND ambiente = 'certificacion'"
    )->execute([':estado' => $res['estado'], ':track' => $trackId, ':rut' => $rutEmisor]);

    flashSet('ok', sprintf('Estado actualizado: %s (envio %s)', $res['estado'], $trackId));
    redirigirPrg('/certificacion');
}

// ===========================================================================
//  Handler: POST /certificacion/marcar-sok
//
//  Confirmacion MANUAL del tenant de que un envio del Set Basico paso la
//  revision de CONTENIDO del SII (SOK) -- NUNCA se infiere ni se consulta al
//  SII aqui (no hay webservice para esto). Scope estricto: el track_id debe
//  pertenecer a un dte_emitido de ESTA cuenta, igual que
//  handleCertificacionActualizarPost(). Ver setBasicoAprobado() y
//  009_dte_set_basico_sok.sql para el porque de esta marca.
// ===========================================================================
function handleCertificacionMarcarSokPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());

    $trackId = trim((string) ($_POST['track_id'] ?? ''));
    if ($trackId === '') {
        redirigirPrg('/certificacion');
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM dte_emitido WHERE track_id = :track AND rut_emisor = :rut AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmt->execute([':track' => $trackId, ':rut' => $rutEmisor]);
    if ($stmt->fetchColumn() === false) {
        // No existe, o no pertenece a esta cuenta: mismo destino, sin filtrar cual.
        redirigirPrg('/certificacion');
    }

    (new MySqlSetBasicoSokRepository($pdo))->marcar($rutEmisor, Ambiente::Certificacion, $trackId);

    flashSet('ok', sprintf('Envio %s marcado como SOK.', $trackId));
    redirigirPrg('/certificacion');
}

// ===========================================================================
//  Handler: POST /certificacion/confirmar-etapa
//
//  Confirmacion MANUAL de una de las etapas 2-4 de la certificacion de
//  FACTURA (Simulacion, Intercambio, Muestras Impresas), que hoy se gestionan
//  FUERA del panel (portal del SII / correo) y no tienen ningun webservice
//  que las confirme -- mismo patron que handleCertificacionAprobadaConfirmarPost()
//  para la etapa 6 (certificacion_confirmada_at): NUNCA se infiere, es una
//  declaracion explicita del tenant tras el checkbox obligatorio.
//
//  Encadenada igual que Set Basico -> Libro de Ventas/Compras: cada etapa
//  exige que la ANTERIOR ya este confirmada (ver calcularEtapasManuales()).
//  La guardia se recalcula aqui en el servidor, nunca se confia en lo que
//  haya llegado del formulario.
// ===========================================================================
function handleCertificacionConfirmarEtapaPost(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirOnboardingCompleto($pdo, $cuentaId);

    // Columnas fijas del propio codigo (whitelist), NUNCA tomadas de $_POST:
    // $etapa solo selecciona una entrada de este mapa, jamas se interpola
    // directo. trackCol=null (Intercambio/Muestras Impresas) porque no hay
    // forma de asociarles un track ID -- solo Simulacion permite guardar uno.
    $campos = [
        'simulacion'        => ['at' => 'simulacion_confirmada_at', 'trackCol' => 'simulacion_track_id', 'clave' => 'simulacion'],
        'intercambio'       => ['at' => 'intercambio_confirmado_at', 'trackCol' => null, 'clave' => 'intercambio'],
        'muestras-impresas' => ['at' => 'muestras_impresas_confirmadas_at', 'trackCol' => null, 'clave' => 'muestrasImpresas'],
    ];

    $etapa = (string) ($_POST['etapa'] ?? '');
    if (! isset($campos[$etapa])) {
        redirigirPrg('/certificacion');
    }
    $campo    = $campos[$etapa]['at'];
    $trackCol = $campos[$etapa]['trackCol'];

    $raw = obtenerEtapasManualesRaw($pdo, $cuentaId);
    if ($raw[$campo] !== null) {
        flashSet('ok', 'Esta etapa ya estaba confirmada.');
        redirigirPrg('/certificacion');
    }

    if (empty($_POST['confirmo'])) {
        flashSet('error', 'Debes marcar la casilla de confirmacion para registrar esta etapa.');
        redirigirPrg('/certificacion');
    }

    $agrupado       = agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor));
    $sokPorTrackId  = (new MySqlSetBasicoSokRepository($pdo))->confirmadosPorTrackId($rutEmisor, Ambiente::Certificacion);
    $setBasico      = setBasicoAprobado($agrupado['envios'], $sokPorTrackId);
    $ventas         = libroAprobado(listarLibros($pdo, $rutEmisor, 'VENTA'));
    $compras        = libroAprobado(listarLibros($pdo, $rutEmisor, 'COMPRA'));
    $todosAprobados = $setBasico['aprobado'] && $ventas['aprobado'] && $compras['aprobado'];

    $etapasManuales = calcularEtapasManuales($raw, $todosAprobados);
    if (! $etapasManuales[$campos[$etapa]['clave']]['habilitada']) {
        flashSet('error', 'Esta etapa todavia no esta habilitada: falta confirmar la etapa anterior.');
        redirigirPrg('/certificacion');
    }

    if ($trackCol !== null) {
        $trackId = trim((string) ($_POST['track_id'] ?? ''));
        $sql = "UPDATE dte_emisor SET {$campo} = NOW()"
             . ($trackId !== '' ? ", {$trackCol} = :track_id" : '')
             . " WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND cuenta_id = :cuenta_id AND {$campo} IS NULL";
        $params = [':rut' => $rutEmisor, ':cuenta_id' => $cuentaId];
        if ($trackId !== '') {
            $params[':track_id'] = $trackId;
        }
        $pdo->prepare($sql)->execute($params);
    } else {
        $pdo->prepare(
            "UPDATE dte_emisor SET {$campo} = NOW() WHERE rut_emisor = :rut AND ambiente = 'certificacion' "
            . "AND cuenta_id = :cuenta_id AND {$campo} IS NULL"
        )->execute([':rut' => $rutEmisor, ':cuenta_id' => $cuentaId]);
    }

    flashSet('ok', 'Etapa confirmada.');
    redirigirPrg('/certificacion');
}

// ===========================================================================
//  Handler: POST /certificacion/actualizar-libro
//
//  Analogo a handleCertificacionActualizarPost(), pero para dte_libro: mismas
//  clases del motor (MySqlEmisorRepository::obtenerCertificado(),
//  SiiAutenticador::obtenerToken(), SiiConsultor::consultarEnvio() -- el mismo
//  servicio QueryEstUp.jws que usa GET /api/v1/libro/{trackId}/estado-sii del
//  motor, ver public/index.php::consultarEstadoSiiLibro()), reusadas via el
//  autoloader de Composer, NUNCA por HTTP. Scope estricto: track_id debe
//  existir en dte_libro para rut_emisor+ambiente de ESTA cuenta
//  (LibroRepositoryInterface::existeTrackId()).
// ===========================================================================
function handleCertificacionActualizarLibroPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    $libros    = new MySqlLibroRepository($pdo);

    $trackId = trim((string) ($_POST['track_id'] ?? ''));
    if ($trackId === '') {
        redirigirPrg('/certificacion');
    }

    if (! $libros->existeTrackId($rutEmisor, Ambiente::Certificacion, $trackId)) {
        // No existe, o no pertenece a esta cuenta: mismo destino, sin filtrar cual.
        redirigirPrg('/certificacion');
    }

    $certTls = __DIR__ . '/../../fullchain.pem';
    $keyTls  = __DIR__ . '/../../key.pem';
    if (! is_file($certTls) || ! is_readable($certTls) || ! is_file($keyTls) || ! is_readable($keyTls)) {
        flashSet('error', 'Certificado TLS mutuo no disponible en el servidor.');
        redirigirPrg('/certificacion');
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        error_log('panel certificacion (libro): CRYPTO_MASTER_KEY ausente o mal configurada');
        flashSet('error', 'Error de configuracion del servidor (llave de cifrado). Contacta al administrador.');
        redirigirPrg('/certificacion');
    }
    $crypto = new CertificadoCrypto($bin);
    $emisor = new MySqlEmisorRepository($pdo, $crypto);
    $http   = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);

    // La llamada al SII va aislada: si falla, no se toca dte_libro -- el
    // estado previo persistido queda intacto.
    try {
        $cert  = $emisor->obtenerCertificado($rutEmisor, Ambiente::Certificacion);
        $token = (new SiiAutenticador($http, new XmlSigner()))->obtenerToken($cert, Ambiente::Certificacion);
        $res   = (new SiiConsultor($http))->consultarEnvio($rutEmisor, $trackId, $token, Ambiente::Certificacion);
    } catch (SiiAutenticacionException $e) {
        error_log('panel certificacion (libro): fallo de autenticacion con el SII - ' . $e->glosaSii);
        flashSet('error', 'No se pudo autenticar con el SII para consultar el estado. Intenta nuevamente.');
        redirigirPrg('/certificacion');
    } catch (Throwable $e) {
        error_log('panel certificacion (libro): fallo consulta SII - ' . $e->getMessage());
        flashSet('error', 'No se pudo consultar el estado en el SII. Intenta nuevamente.');
        redirigirPrg('/certificacion');
    }

    $libros->actualizarEstado($rutEmisor, Ambiente::Certificacion, $trackId, $res['estado']);

    flashSet('ok', sprintf('Estado actualizado: %s (envio %s)', $res['estado'], $trackId));
    redirigirPrg('/certificacion');
}

/**
 * Convierte el payload plano de un builder (misma forma que el body de
 * POST /api/v1/libro) a los DTOs Libro/LineaLibro que exige
 * LibroService::enviarLibro(). Mapeo identico al que hace enviarLibroIecv()
 * (motor, public/index.php) desde el body JSON -- replicado aqui porque el
 * panel es un front controller separado que no puede invocar esa funcion.
 */
function libroDesdeArrayPanel(array $payload): Libro
{
    $lineas = array_map(static fn (array $l): LineaLibro => new LineaLibro(
        tpoDoc:         $l['tpoDoc'],
        nroDoc:         $l['nroDoc'],
        fecha:          new DateTimeImmutable($l['fecha']),
        rutContraparte: $l['rutContraparte'],
        razonSocial:    $l['razonSocial'],
        mntExe:         $l['mntExe'],
        mntNeto:        $l['mntNeto'],
        mntIva:         $l['mntIva'],
        mntTotal:       $l['mntTotal'],
        ivaUsoComun:    $l['ivaUsoComun'] ?? null,
        codIvaNoRec:    $l['codIvaNoRec'] ?? null,
        mntIvaNoRec:    $l['mntIvaNoRec'] ?? null,
        codOtroImp:     $l['codOtroImp'] ?? null,
        mntOtroImp:     $l['mntOtroImp'] ?? null,
        tasaOtroImp:    $l['tasaOtroImp'] ?? 19,
    ), $payload['lineas']);

    return new Libro(
        tipoOperacion:          TipoOperacionLibro::from($payload['tipoOperacion']),
        periodoTributario:      $payload['periodoTributario'],
        tipoLibro:              TipoLibro::from($payload['tipoLibro']),
        tipoEnvio:              TipoEnvioLibro::from($payload['tipoEnvio']),
        folioNotificacion:      $payload['folioNotificacion'],
        lineas:                 $lineas,
        factorProporcionalidad: $payload['factorProporcionalidad'] ?? null,
    );
}

/**
 * Wiring compartido para emitir un Libro IECV directo (sin HTTP interno):
 * mismo patron mTLS + MySqlEmisorRepository + LibroService que ya usa
 * scripts/enviar_libro.php, y persistencia con MySqlLibroRepository (mismo
 * repo que ya usa la estacion 5 para listar libros). Identidad
 * (rut_emisor/ambiente/rutSender) SIEMPRE del tenant autenticado.
 */
function emitirLibroPanel(PDO $pdo, string $rutEmisor, array $payload, string $tipoOperacionParaRepo, bool $modoSinSok = false): never
{
    $certTls = __DIR__ . '/../../fullchain.pem';
    $keyTls  = __DIR__ . '/../../key.pem';
    if (! is_file($certTls) || ! is_readable($certTls) || ! is_file($keyTls) || ! is_readable($keyTls)) {
        flashSet('error', 'Certificado TLS mutuo no disponible en el servidor.');
        redirigirPrg('/certificacion');
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        error_log('panel libro: CRYPTO_MASTER_KEY ausente o mal configurada');
        flashSet('error', 'Error de configuracion del servidor (llave de cifrado). Contacta al administrador.');
        redirigirPrg('/certificacion');
    }

    $rutSender = resolverRutSenderTenant($pdo, $rutEmisor, Ambiente::Certificacion);
    if ($rutSender === null) {
        flashSet('error', 'El certificado del emisor no tiene RUT de firmante (sender). Vuelve a cargar el certificado.');
        redirigirPrg('/certificacion');
    }

    $crypto  = new CertificadoCrypto($bin);
    $service = new LibroService(
        new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]),
        new MySqlEmisorRepository($pdo, $crypto),
    );

    $cred = new Credenciales(
        rutEmisor: $rutEmisor,
        apiToken:  'no-usado-por-sii-directo',
        ambiente:  Ambiente::Certificacion,
        rutSender: $rutSender,
    );

    $libro = libroDesdeArrayPanel($payload);

    try {
        $res = $service->enviarLibro($libro, $cred);
    } catch (SiiAutenticacionException $e) {
        error_log('panel libro: fallo de autenticacion con el SII - ' . $e->glosaSii);
        flashSet('error', 'No se pudo autenticar con el SII para emitir el libro. Intenta nuevamente.');
        redirigirPrg('/certificacion');
    } catch (EnvioRechazadoException $e) {
        flashSet('error', sprintf('El SII rechazo el envio del libro (status %s, trackId %s).', $e->status, $e->trackId ?? 'sin trackId'));
        redirigirPrg('/certificacion');
    } catch (Throwable $e) {
        error_log('panel libro: fallo el envio - ' . $e->getMessage());
        flashSet('error', 'No se pudo emitir el libro: ' . $e->getMessage());
        redirigirPrg('/certificacion');
    }

    // El SII ya acepto el envio: persistir es best-effort (mismo criterio que
    // enviarLibroIecv() en public/index.php) -- un fallo aqui no debe ocultar
    // que el SII SI acepto el libro.
    try {
        (new MySqlLibroRepository($pdo))->registrar(
            rutEmisor:         $rutEmisor,
            ambiente:          Ambiente::Certificacion,
            tipoOperacion:     $tipoOperacionParaRepo,
            periodoTributario: $payload['periodoTributario'],
            tipoLibro:         $payload['tipoLibro'],
            tipoEnvio:         $payload['tipoEnvio'],
            folioNotificacion: $payload['folioNotificacion'],
            trackId:           $res['trackId'] ?? null,
            estado:            'enviado',
            xml:               $res['xml'],
        );
    } catch (Throwable $e) {
        error_log('panel libro: dte_libro registrar fallo (trackId ' . ($res['trackId'] ?? 'null') . '): ' . $e->getMessage());
    }

    $mensaje = sprintf('Libro enviado. Track ID: %s, status: %s.', $res['trackId'] !== '' ? $res['trackId'] : '(vacio)', $res['status']);
    if ($modoSinSok) {
        $mensaje .= ' ATENCION: se emitio en modo "sin esperar SOK" -- si el Set Basico es rechazado en '
            . 'contenido (SRH) mas adelante, este libro tambien queda invalido y hay que rehacer ambos.';
    }
    flashSet('ok', $mensaje);
    redirigirPrg('/certificacion');
}

// ===========================================================================
//  Handler: POST /certificacion/emitir-libro-ventas
//
//  Construye el Libro de Ventas con los documentos YA EMITIDOS del envio
//  APROBADO del Set Basico (dte_emitido) -- NO vuelve a tocar el archivo
//  parseado salvo para leer el numero de atencion del Libro de Ventas.
//  setBasicoAprobado()/agruparEmitidosPorEnvio() son las MISMAS funciones que
//  ya usa la estacion 5, no se reimplementa ese chequeo.
// ===========================================================================
function handleCertificacionEmitirLibroVentasPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());

    // "Emitir sin esperar SOK": SOLO si el formulario de riesgo especifico es
    // el que se envio (campo 'modo' === 'sin_sok', nunca se infiere) Y el
    // checkbox de riesgo tambien llego marcado -- si llega 'modo=sin_sok'
    // pero SIN el checkbox, se rechaza explicito en vez de degradar en
    // silencio al criterio normal. El flujo normal (sin ese campo) usa
    // setBasicoAprobado() exactamente igual que antes.
    $modoSinSok = ($_POST['modo'] ?? '') === 'sin_sok';
    if ($modoSinSok && empty($_POST['acepto_riesgo'])) {
        flashSet('error', 'Debes marcar la casilla "Entiendo el riesgo" para emitir sin esperar SOK.');
        redirigirPrg('/certificacion');
    }

    $envios = agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor))['envios'];
    if ($modoSinSok) {
        $setBasico = CertificacionEstadoResolver::setBasicoEnviadoSinReparos($envios);
    } else {
        $sokPorTrackId = (new MySqlSetBasicoSokRepository($pdo))->confirmadosPorTrackId($rutEmisor, Ambiente::Certificacion);
        $setBasico = setBasicoAprobado($envios, $sokPorTrackId);
    }
    if (! $setBasico['aprobado']) {
        flashSet('error', 'El Set Basico debe estar aprobado (EPR y confirmado SOK) antes de poder emitir el Libro de Ventas.');
        redirigirPrg('/certificacion');
    }

    $archivo = (new MySqlSetPruebasArchivoRepository($pdo))->obtener($rutEmisor, Ambiente::Certificacion);
    if ($archivo === null) {
        flashSet('error', 'No hay ningun archivo de set de pruebas cargado (falta el numero de atencion del Libro de Ventas).');
        redirigirPrg('/certificacion');
    }
    try {
        $parseado = (new SetPruebasParser())->parse($archivo['contenido']);
    } catch (Throwable $e) {
        flashSet('error', 'El archivo guardado no se pudo interpretar: ' . $e->getMessage());
        redirigirPrg('/certificacion');
    }
    if ($parseado->numeroAtencionLibroVentas === null) {
        flashSet('error', 'El archivo no trae el numero de atencion del Libro de Ventas.');
        redirigirPrg('/certificacion');
    }

    // Mismo orden en que se emitieron (ver LibroVentasPayloadBuilder: el SII
    // acepto el orden de EMISION del lote, no un orden numerico por tipoDte).
    $stmt = $pdo->prepare(
        "SELECT tipo_dte, folio, fecha_emision, neto, iva, total FROM dte_emitido "
        . "WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND track_id = :track "
        . 'ORDER BY id ASC'
    );
    $stmt->execute([':rut' => $rutEmisor, ':track' => $setBasico['trackId']]);
    $documentos = array_map(static fn (array $r): array => [
        'tipoDte'      => (int) $r['tipo_dte'],
        'folio'        => (int) $r['folio'],
        'fechaEmision' => (string) $r['fecha_emision'],
        'neto'         => (int) $r['neto'],
        'iva'          => (int) $r['iva'],
        'total'        => (int) $r['total'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $resultado = (new LibroVentasPayloadBuilder())->construir($documentos, $parseado->numeroAtencionLibroVentas);
    if ($resultado->errores !== []) {
        flashSet('error', 'No se emitio nada: ' . implode(' ', $resultado->errores));
        redirigirPrg('/certificacion');
    }

    emitirLibroPanel($pdo, $rutEmisor, $resultado->payload, 'VENTA', $modoSinSok);
}

// ===========================================================================
//  Handler: POST /certificacion/emitir-libro-compras
//
//  Construye el Libro de Compras con SetPruebasParseado->casosLibroCompras
//  (los documentos de PROVEEDORES que el SII dicta en el archivo) -- NO usa
//  dte_emitido para nada. Requiere el Set Basico ya aprobado (misma guardia
//  que Libro de Ventas, aunque el Libro de Compras no dependa tecnicamente de
//  esos documentos: es la misma secuencia de certificacion del SII).
// ===========================================================================
function handleCertificacionEmitirLibroComprasPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());

    // "Emitir sin esperar SOK": mismo criterio que Libro de Ventas (ver
    // handleCertificacionEmitirLibroVentasPost()) -- SOLO si el formulario de
    // riesgo especifico envio 'modo=sin_sok' Y el checkbox de riesgo tambien
    // llego marcado.
    $modoSinSok = ($_POST['modo'] ?? '') === 'sin_sok';
    if ($modoSinSok && empty($_POST['acepto_riesgo'])) {
        flashSet('error', 'Debes marcar la casilla "Entiendo el riesgo" para emitir sin esperar SOK.');
        redirigirPrg('/certificacion');
    }

    $envios = agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor))['envios'];
    if ($modoSinSok) {
        $setBasico = CertificacionEstadoResolver::setBasicoEnviadoSinReparos($envios);
    } else {
        $sokPorTrackId = (new MySqlSetBasicoSokRepository($pdo))->confirmadosPorTrackId($rutEmisor, Ambiente::Certificacion);
        $setBasico = setBasicoAprobado($envios, $sokPorTrackId);
    }
    if (! $setBasico['aprobado']) {
        flashSet('error', 'El Set Basico debe estar aprobado (EPR y confirmado SOK) antes de poder emitir el Libro de Compras.');
        redirigirPrg('/certificacion');
    }

    $archivo = (new MySqlSetPruebasArchivoRepository($pdo))->obtener($rutEmisor, Ambiente::Certificacion);
    if ($archivo === null) {
        flashSet('error', 'No hay ningun archivo de set de pruebas cargado.');
        redirigirPrg('/certificacion');
    }
    try {
        $parseado = (new SetPruebasParser())->parse($archivo['contenido']);
    } catch (Throwable $e) {
        flashSet('error', 'El archivo guardado no se pudo interpretar: ' . $e->getMessage());
        redirigirPrg('/certificacion');
    }

    $resultado = (new LibroComprasPayloadBuilder())->construir($parseado, new DateTimeImmutable());
    if ($resultado->errores !== []) {
        flashSet('error', 'No se emitio nada: ' . implode(' ', $resultado->errores));
        redirigirPrg('/certificacion');
    }

    emitirLibroPanel($pdo, $rutEmisor, $resultado->payload, 'COMPRA', $modoSinSok);
}

// ===========================================================================
//  Handler: GET /certificacion/set-pruebas
//
//  Sub-estacion de la 5: preview de SOLO LECTURA del archivo
//  SIISetDePruebas<RUT>.txt que el SII entrega al tenant (el archivo con los
//  8 casos del set basico + libro de ventas/compras). Reusa
//  SetPruebasParser::parse() TAL CUAL (src/Sii/SetPruebasParser.php, validado
//  8/8 contra el archivo real de EASY AGENDA SPA, preserva tildes/enes
//  exactas) -- no se reimplementa nada del parseo aqui. El archivo se guarda
//  crudo (dte_set_pruebas_archivo) y se re-parsea en cada visita, para no
//  duplicar la fuente de verdad. Este paso NO emite nada al SII (Paso 3,
//  aparte): no hay boton de emision funcional en esta pantalla.
// ===========================================================================
function handleSetPruebasGet(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    $repo      = new MySqlSetPruebasArchivoRepository($pdo);

    $archivo     = $repo->obtener($rutEmisor, Ambiente::Certificacion);
    $parseado    = null;
    $errorParseo = null;
    if ($archivo !== null) {
        try {
            $parseado = (new SetPruebasParser())->parse($archivo['contenido']);
        } catch (Throwable $e) {
            $errorParseo = 'El archivo guardado no se pudo interpretar: ' . $e->getMessage();
        }
    }

    vista('set-pruebas', [
        'flash'       => flashTomar(),
        'archivo'     => $archivo,
        'parseado'    => $parseado,
        'errorParseo' => $errorParseo,
        'error'       => null,
    ]);
}

// ===========================================================================
//  Handler: POST /certificacion/set-pruebas
//
//  Recibe el archivo subido y lo pasa TAL CUAL (bytes originales, sin forzar
//  encoding -- SetPruebasParser::parse() ya hace la conversion ISO-8859-1 ->
//  UTF-8 internamente) a SetPruebasParser::parse(). Si el archivo es
//  irreconocible por completo (RuntimeException: no tiene ni siquiera "SET
//  BASICO - NUMERO DE ATENCION"), se muestra un error claro -- nunca un 500 ni
//  una pantalla en blanco. Si parsea, aunque sea con advertencias en
//  secciones puntuales, se persiste (reemplaza el archivo anterior de este
//  tenant+ambiente, ver MySqlSetPruebasArchivoRepository::guardar()) y se
//  redirige (PRG) al preview, que las mostrara.
// ===========================================================================
function handleSetPruebasPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    $repo      = new MySqlSetPruebasArchivoRepository($pdo);

    $archivo = $_FILES['archivo'] ?? null;
    if (
        ! is_array($archivo)
        || ! isset($archivo['error'], $archivo['tmp_name'], $archivo['name'])
        || $archivo['error'] !== UPLOAD_ERR_OK
        || ! is_uploaded_file($archivo['tmp_name'])
    ) {
        vista('set-pruebas', [
            'flash'       => null,
            'archivo'     => $repo->obtener($rutEmisor, Ambiente::Certificacion),
            'parseado'    => null,
            'errorParseo' => null,
            'error'       => 'Debes seleccionar el archivo SIISetDePruebas<RUT>.txt que recibiste del SII.',
        ]);
    }

    // Bytes originales a memoria, sin conversion (mismo patron que /caf):
    // SetPruebasParser ya asume ISO-8859-1 y hace la conversion internamente.
    $contenido = file_get_contents($archivo['tmp_name']);
    if ($contenido === false || $contenido === '') {
        vista('set-pruebas', [
            'flash'       => null,
            'archivo'     => $repo->obtener($rutEmisor, Ambiente::Certificacion),
            'parseado'    => null,
            'errorParseo' => null,
            'error'       => 'No se pudo leer el archivo subido.',
        ]);
    }

    try {
        $parseado = (new SetPruebasParser())->parse($contenido);
    } catch (RuntimeException $e) {
        vista('set-pruebas', [
            'flash'       => null,
            'archivo'     => $repo->obtener($rutEmisor, Ambiente::Certificacion),
            'parseado'    => null,
            'errorParseo' => null,
            'error'       => 'El archivo no corresponde al formato de SIISetDePruebas<RUT>.txt esperado: ' . $e->getMessage(),
        ]);
    }

    $repo->guardar($rutEmisor, Ambiente::Certificacion, basename((string) $archivo['name']), $contenido);

    flashSet('ok', $parseado->advertencias === []
        ? 'Archivo cargado y parseado correctamente.'
        : sprintf('Archivo cargado. El parser encontro %d advertencia(s); revisalas abajo.', count($parseado->advertencias)));
    redirigirPrg('/certificacion/set-pruebas');
}

/**
 * RUT del firmante (sender) del emisor, SIEMPRE del tenant autenticado (nunca
 * hardcodeado): mismo dato/consulta que resolverRutSender() en public/index.php
 * (motor), replicado aqui porque el panel es un front controller separado que
 * no puede invocar funciones sueltas de ese archivo.
 */
function resolverRutSenderTenant(PDO $pdo, string $rutEmisor, Ambiente $ambiente): ?string
{
    $stmt = $pdo->prepare(
        'SELECT rut_sender FROM dte_certificado WHERE rut_emisor = :rut AND ambiente = :amb LIMIT 1'
    );
    $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value]);
    $rutSender = $stmt->fetchColumn();

    return ($rutSender === false || trim((string) $rutSender) === '') ? null : (string) $rutSender;
}

// ===========================================================================
//  Handler: POST /certificacion/set-pruebas/emitir
//
//  Emite el SET BASICO completo (los documentos del archivo parseado) en UN
//  solo EnvioDTE, reemplazando el armado manual de JSON que hoy hace un
//  humano. Reusa SetPruebasParser + SetBasicoPayloadBuilder TAL CUAL
//  (src/Sii/), y LoteDteEmisor para invocar el motor DIRECTO -- misma logica
//  de asignacion de folios + resolucion de refIndiceLote que POST
//  /api/v1/dte/lote, sin HTTP interno (ver LoteDteEmisor, que documenta la
//  pequena duplicacion deliberada con emitirDteLote()). Si el builder
//  reporta casos ambiguos (SetBasicoPayloadResultado::$errores), NO se emite
//  nada: se muestran al tenant. Identidad (rut_emisor/ambiente/rutSender)
//  SIEMPRE del tenant autenticado, igual que el resto del panel.
// ===========================================================================
function handleSetPruebasEmitirPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    $repo      = new MySqlSetPruebasArchivoRepository($pdo);

    $archivo = $repo->obtener($rutEmisor, Ambiente::Certificacion);
    if ($archivo === null) {
        flashSet('error', 'No hay ningun archivo de set de pruebas cargado.');
        redirigirPrg('/certificacion/set-pruebas');
    }

    try {
        $parseado = (new SetPruebasParser())->parse($archivo['contenido']);
    } catch (Throwable $e) {
        flashSet('error', 'El archivo guardado no se pudo interpretar: ' . $e->getMessage());
        redirigirPrg('/certificacion/set-pruebas');
    }

    $resultado = (new SetBasicoPayloadBuilder())->construir($parseado, new DateTimeImmutable());
    if ($resultado->errores !== []) {
        flashSet(
            'error',
            sprintf('No se emitio nada: %d caso(s) requieren revision manual antes de poder construir el lote.', count($resultado->errores)),
            ['erroresConstruccion' => $resultado->errores],
        );
        redirigirPrg('/certificacion/set-pruebas');
    }

    $certTls = __DIR__ . '/../../fullchain.pem';
    $keyTls  = __DIR__ . '/../../key.pem';
    if (! is_file($certTls) || ! is_readable($certTls) || ! is_file($keyTls) || ! is_readable($keyTls)) {
        flashSet('error', 'Certificado TLS mutuo no disponible en el servidor.');
        redirigirPrg('/certificacion/set-pruebas');
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        error_log('panel set-pruebas emitir: CRYPTO_MASTER_KEY ausente o mal configurada');
        flashSet('error', 'Error de configuracion del servidor (llave de cifrado). Contacta al administrador.');
        redirigirPrg('/certificacion/set-pruebas');
    }

    $rutSender = resolverRutSenderTenant($pdo, $rutEmisor, Ambiente::Certificacion);
    if ($rutSender === null) {
        flashSet('error', 'El certificado del emisor no tiene RUT de firmante (sender). Vuelve a cargar el certificado.');
        redirigirPrg('/certificacion/set-pruebas');
    }

    $crypto     = new CertificadoCrypto($bin);
    $folios     = new MySqlFolioRepository($pdo, fn (string $c): string => $crypto->descifrar($c), cryptoKek: $crypto);
    $emisor     = new MySqlEmisorRepository($pdo, $crypto);
    $facturador = new SiiDirectoFacturador(
        new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]),
        $folios,
        $emisor,
        dteEmitido: new MySqlDteEmitidoRepository($pdo),
    );

    $cred = new Credenciales(
        rutEmisor: $rutEmisor,
        apiToken:  'no-usado-por-sii-directo',
        ambiente:  Ambiente::Certificacion,
        rutSender: $rutSender,
    );

    try {
        $res = (new LoteDteEmisor($facturador, $folios))->emitir($resultado->payload['documentos'], $cred, new DateTimeImmutable());
    } catch (SiiAutenticacionException $e) {
        error_log('panel set-pruebas emitir: fallo de autenticacion con el SII - ' . $e->glosaSii);
        flashSet('error', 'No se pudo autenticar con el SII para emitir el set basico. Intenta nuevamente.');
        redirigirPrg('/certificacion/set-pruebas');
    } catch (EnvioRechazadoException $e) {
        flashSet('error', sprintf('El SII rechazo el envio (status %s, trackId %s).', $e->status, $e->trackId ?? 'sin trackId'));
        redirigirPrg('/certificacion/set-pruebas');
    } catch (Throwable $e) {
        error_log('panel set-pruebas emitir: fallo la emision - ' . $e->getMessage());
        flashSet('error', 'No se pudo emitir el set basico: ' . $e->getMessage());
        redirigirPrg('/certificacion/set-pruebas');
    }

    flashSet(
        'ok',
        sprintf('Set Basico emitido. Track ID: %s (%d documentos).', $res['trackId'], count($res['documentos'])),
        ['resultadoEmision' => $res],
    );
    redirigirPrg('/certificacion/set-pruebas');
}

// ===========================================================================
//  Handler: GET /certificacion/simulacion
//
//  Sub-estacion OPCIONAL (mismo patron de /certificacion/set-pruebas):
//  preview de SOLO LECTURA del lote de Simulacion ANTES de emitirlo, con
//  selector de cantidad total (20-100, default 30). Reusa
//  SimulacionSetBuilder::construir() TAL CUAL -- no se reimplementa el
//  armado del lote aqui. Gateada a setBasicoEnviadoSinReparos() (EPR+3-tipos,
//  NO exige SOK: la Simulacion es una etapa independiente del Set Basico
//  segun el manual del SII, solo requiere que la etapa 1 este tecnicamente
//  completa). Este paso NO emite nada al SII: no hay boton funcional salvo
//  el de POST /certificacion/simulacion/emitir mas abajo.
// ===========================================================================
function handleCertificacionSimulacionGet(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());

    $envios    = agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor))['envios'];
    $setBasico = CertificacionEstadoResolver::setBasicoEnviadoSinReparos($envios);
    if (! $setBasico['aprobado']) {
        flashSet('error', 'El Set Basico debe estar en EPR con los 3 tipos de documento antes de poder generar el Set de Simulacion.');
        redirigirPrg('/certificacion');
    }

    $total = (int) ($_GET['total'] ?? 30);
    if ($total < 20 || $total > 100) {
        $total = 30;
    }

    try {
        $documentos = (new SimulacionSetBuilder())->construir($total);
    } catch (Throwable $e) {
        flashSet('error', 'No se pudo generar la vista previa: ' . $e->getMessage());
        redirigirPrg('/certificacion');
    }

    vista('certificacion-simulacion', [
        'flash'      => flashTomar(),
        'total'      => $total,
        'documentos' => $documentos,
    ]);
}

// ===========================================================================
//  Handler: POST /certificacion/simulacion/emitir
//
//  Emite el Set de Simulacion completo (SimulacionSetBuilder::construir())
//  en UN solo EnvioDTE, invocando el motor DIRECTO en PHP -- MISMO patron
//  exacto que handleSetPruebasEmitirPost() (LoteDteEmisor + SiiDirectoFacturador,
//  ver el comentario de esa funcion): sin HTTP interno, sin API key.
//  Identidad (rut_emisor/ambiente/rutSender) SIEMPRE del tenant autenticado.
//  Gateada igual que el preview: setBasicoEnviadoSinReparos(), NO exige SOK.
// ===========================================================================
function handleCertificacionSimulacionEmitirPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());

    $envios    = agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor))['envios'];
    $setBasico = CertificacionEstadoResolver::setBasicoEnviadoSinReparos($envios);
    if (! $setBasico['aprobado']) {
        flashSet('error', 'El Set Basico debe estar en EPR con los 3 tipos de documento antes de poder emitir el Set de Simulacion.');
        redirigirPrg('/certificacion');
    }

    $total = (int) ($_POST['total'] ?? 30);
    if ($total < 20 || $total > 100) {
        flashSet('error', 'La cantidad de documentos debe estar entre 20 y 100.');
        redirigirPrg('/certificacion/simulacion');
    }

    try {
        $documentos = (new SimulacionSetBuilder())->construir($total);
    } catch (Throwable $e) {
        flashSet('error', 'No se pudo construir el lote: ' . $e->getMessage());
        redirigirPrg('/certificacion/simulacion');
    }

    $certTls = __DIR__ . '/../../fullchain.pem';
    $keyTls  = __DIR__ . '/../../key.pem';
    if (! is_file($certTls) || ! is_readable($certTls) || ! is_file($keyTls) || ! is_readable($keyTls)) {
        flashSet('error', 'Certificado TLS mutuo no disponible en el servidor.');
        redirigirPrg('/certificacion/simulacion');
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        error_log('panel simulacion emitir: CRYPTO_MASTER_KEY ausente o mal configurada');
        flashSet('error', 'Error de configuracion del servidor (llave de cifrado). Contacta al administrador.');
        redirigirPrg('/certificacion/simulacion');
    }

    $rutSender = resolverRutSenderTenant($pdo, $rutEmisor, Ambiente::Certificacion);
    if ($rutSender === null) {
        flashSet('error', 'El certificado del emisor no tiene RUT de firmante (sender). Vuelve a cargar el certificado.');
        redirigirPrg('/certificacion/simulacion');
    }

    $crypto     = new CertificadoCrypto($bin);
    $folios     = new MySqlFolioRepository($pdo, fn (string $c): string => $crypto->descifrar($c), cryptoKek: $crypto);
    $emisor     = new MySqlEmisorRepository($pdo, $crypto);
    $facturador = new SiiDirectoFacturador(
        new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]),
        $folios,
        $emisor,
        dteEmitido: new MySqlDteEmitidoRepository($pdo),
    );

    $cred = new Credenciales(
        rutEmisor: $rutEmisor,
        apiToken:  'no-usado-por-sii-directo',
        ambiente:  Ambiente::Certificacion,
        rutSender: $rutSender,
    );

    try {
        $res = (new LoteDteEmisor($facturador, $folios))->emitir($documentos, $cred, new DateTimeImmutable());
    } catch (SiiAutenticacionException $e) {
        error_log('panel simulacion emitir: fallo de autenticacion con el SII - ' . $e->glosaSii);
        flashSet('error', 'No se pudo autenticar con el SII para emitir la Simulacion. Intenta nuevamente.');
        redirigirPrg('/certificacion/simulacion');
    } catch (EnvioRechazadoException $e) {
        flashSet('error', sprintf('El SII rechazo el envio (status %s, trackId %s).', $e->status, $e->trackId ?? 'sin trackId'));
        redirigirPrg('/certificacion/simulacion');
    } catch (Throwable $e) {
        error_log('panel simulacion emitir: fallo la emision - ' . $e->getMessage());
        flashSet('error', 'No se pudo emitir la Simulacion: ' . $e->getMessage());
        redirigirPrg('/certificacion/simulacion');
    }

    flashSet(
        'ok',
        sprintf(
            'Set de Simulacion emitido. Track ID: %s (%d documentos). Cuando el SII confirme el contenido, '
            . 'pega este Track ID en "Etapas 2-4" al confirmar Simulacion.',
            $res['trackId'],
            count($res['documentos']),
        ),
        ['simulacionTrackId' => $res['trackId']],
    );
    redirigirPrg('/certificacion');
}

// ===========================================================================
//  BOLETA (39/41): proceso APARTE de las 6 etapas de certificacion de
//  FACTURA (Set Basico/Libros/Simulacion/Intercambio/Muestras/Declaracion de
//  arriba) -- boleta tiene su propio circuito ante el SII, sin relacion con
//  esas 6 etapas. Esta es la PRIMERA pieza de la futura estacion 5b de
//  boleta: solo Set de Prueba (5 CASO fijos, universales) + RVD. El resto de
//  los pasos propios de boleta (Intercambio, Muestras, Declaracion
//  Cumplimiento) queda para una tarea aparte, sin nada en el panel todavia.
// ===========================================================================

/**
 * Boletas (39/41) ya persistidas en dte_emitido para este tenant, ambiente
 * certificacion -- fuente para el resumen de estado y para el calculo
 * dinamico del RVD (RvdResumenCalculator). Mismo criterio de scope minimo de
 * columnas que listarEmitidosFactura().
 *
 * $fecha acota a un dia (fecha_emision), como exige el RCOF: el RVD debe
 * declarar SOLO los folios emitidos ese dia, no el historico completo del
 * tenant. Con $fecha = null (default) devuelve todo, que es lo que necesitan
 * el resumen de estado y los pasos del set.
 *
 * @return list<array{tipoDte:int, folio:int, neto:int, iva:int, total:int}>
 */
function listarBoletasEmitidas(PDO $pdo, string $rutEmisor, ?string $fecha = null): array
{
    $stmt = $pdo->prepare(
        "SELECT tipo_dte, folio, neto, iva, total FROM dte_emitido "
        . "WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND tipo_dte IN (39, 41) "
        . ($fecha !== null ? 'AND fecha_emision = :fecha ' : '')
        . 'ORDER BY tipo_dte ASC, folio ASC'
    );
    $params = [':rut' => $rutEmisor];
    if ($fecha !== null) {
        $params[':fecha'] = $fecha;
    }
    $stmt->execute($params);

    return array_map(static fn (array $r): array => [
        'tipoDte' => (int) $r['tipo_dte'],
        'folio'   => (int) $r['folio'],
        'neto'    => (int) $r['neto'],
        'iva'     => (int) $r['iva'],
        'total'   => (int) $r['total'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Exige que exista CAF de BOLETA (tipo_dte=39) cargado para el tenant en
 * ambiente certificacion. Adaptacion de la comprobacion de CAF que ya hace
 * exigirOnboardingCompleto() (esa no filtra por tipo: cualquier CAF cargado,
 * ej de factura, la deja pasar -- boleta necesita su propio CAF).
 */
function existeCafBoleta(PDO $pdo, string $rutEmisor): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM dte_caf WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND tipo_dte = 39 LIMIT 1"
    );
    $stmt->execute([':rut' => $rutEmisor]);

    return $stmt->fetchColumn() !== false;
}

function exigirCafBoleta(PDO $pdo, string $rutEmisor): void
{
    if (! existeCafBoleta($pdo, $rutEmisor)) {
        flashSet('error', 'Necesitas cargar un CAF de Boleta (tipo 39) antes de continuar con esta seccion.');
        redirigir('/caf');
    }
}

/**
 * Fila cruda de las columnas *_at (+ track_id) de las etapas manuales 4-6 de
 * BOLETA (Revision/VoBo/Cumplimiento), ver migracion 013. Mismo patron que
 * obtenerEtapasManualesRaw() (factura), pero con las columnas propias de
 * boleta -- boleta_cumplimiento_confirmado_at es DISTINTA de
 * certificacion_confirmada_at (esa es la Declaracion de Cumplimiento de
 * FACTURA, procesos separados ante el SII).
 *
 * @return array{boleta_revision_solicitada_at:?string, boleta_revision_track_id:?string,
 *         boleta_vobo_at:?string, boleta_revision_resultado:?string, boleta_cumplimiento_confirmado_at:?string}
 */
function obtenerEtapasBoletaManualesRaw(PDO $pdo, int $cuentaId): array
{
    $stmt = $pdo->prepare(
        'SELECT boleta_revision_solicitada_at, boleta_revision_track_id, boleta_vobo_at, '
        . 'boleta_revision_resultado, '
        . "boleta_cumplimiento_confirmado_at FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmt->execute([':cuenta_id' => $cuentaId]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    return $fila !== false ? $fila : [
        'boleta_revision_solicitada_at'     => null,
        'boleta_revision_track_id'          => null,
        'boleta_vobo_at'                    => null,
        'boleta_revision_resultado'         => null,
        'boleta_cumplimiento_confirmado_at' => null,
    ];
}

/**
 * Encadena la habilitacion de las etapas manuales 4-6 de BOLETA: Revision
 * exige que el Set de Boleta este emitido Y el RVD ya enviado (pasos 1-3 de
 * la barra, ya existentes -- el correo del SII para revisar el Set exige
 * que el RVD del periodo ya este enviado); VoBo exige Revision confirmada;
 * Cumplimiento exige VoBo confirmado CON resultado='aprobado' (migracion
 * 014) -- si el SII rechazo (SRH), Cumplimiento se queda bloqueado: hay que
 * rehacer el Set/Revision, no tiene sentido declarar cumplimiento sobre un
 * set rechazado. Mismo espiritu que calcularEtapasManuales() (factura).
 *
 * @param array{boleta_revision_solicitada_at:?string, boleta_revision_track_id:?string,
 *        boleta_vobo_at:?string, boleta_revision_resultado:?string, boleta_cumplimiento_confirmado_at:?string} $raw
 * @return array{
 *     revision:array{confirmada:bool,fecha:?string,trackId:?string,habilitada:bool},
 *     vobo:array{confirmada:bool,fecha:?string,resultado:?string,habilitada:bool},
 *     cumplimiento:array{confirmada:bool,fecha:?string,habilitada:bool}
 * }
 */
function calcularEtapasBoletaManuales(array $raw, bool $setEmitido, bool $rvdEnviado): array
{
    $revisionConfirmada     = $raw['boleta_revision_solicitada_at'] !== null;
    $voboConfirmado         = $raw['boleta_vobo_at'] !== null;
    $resultado              = $raw['boleta_revision_resultado'];
    $cumplimientoConfirmado = $raw['boleta_cumplimiento_confirmado_at'] !== null;

    return [
        'revision' => [
            'confirmada' => $revisionConfirmada,
            'fecha'      => $raw['boleta_revision_solicitada_at'],
            'trackId'    => $raw['boleta_revision_track_id'],
            'habilitada' => $setEmitido && $rvdEnviado,
        ],
        'vobo' => [
            'confirmada' => $voboConfirmado,
            'fecha'      => $raw['boleta_vobo_at'],
            'resultado'  => $resultado,
            'habilitada' => $revisionConfirmada,
        ],
        'cumplimiento' => [
            'confirmada' => $cumplimientoConfirmado,
            'fecha'      => $raw['boleta_cumplimiento_confirmado_at'],
            'habilitada' => $voboConfirmado && $resultado === 'aprobado',
        ],
    ];
}

/**
 * Mismo criterio de color que resumenEtapasBarra() (factura), pero para los
 * 6 pasos PROPIOS de BOLETA (CAF/Set/RVD/Revision/VoBo/Cumplimiento) + un
 * cuarto color ("etapa--rechazada", rojo) para cuando el SII rechazo (SRH)
 * el Set en la revision de contenido -- factura no tiene este caso, por eso
 * es una funcion separada en vez de generalizar resumenEtapasBarra() (evita
 * tocar codigo ya usado por /admin/tenants).
 *
 * @param array{revision:array{confirmada:bool,habilitada:bool}, vobo:array{confirmada:bool,resultado:?string,habilitada:bool}, cumplimiento:array{confirmada:bool,habilitada:bool}} $etapasBoleta
 * @return list<array{nombre:string, completada:bool, clase:string}>
 */
function resumenEtapasBoletaBarra(bool $cafBoleta, bool $setEmitido, bool $rvdEnviado, array $etapasBoleta): array
{
    $rechazada = $etapasBoleta['vobo']['confirmada'] && $etapasBoleta['vobo']['resultado'] === 'rechazado';

    $pasos = [
        ['nombre' => 'CAF',          'completada' => $cafBoleta],
        ['nombre' => 'Set',          'completada' => $setEmitido],
        ['nombre' => 'RVD',          'completada' => $rvdEnviado],
        ['nombre' => 'Revision',     'completada' => $etapasBoleta['revision']['confirmada']],
        ['nombre' => 'VoBo',         'completada' => $etapasBoleta['vobo']['confirmada'] && ! $rechazada, 'rechazada' => $rechazada],
        ['nombre' => 'Cumplimiento', 'completada' => $etapasBoleta['cumplimiento']['confirmada']],
    ];

    $todasAnterioresCompletas = true;
    foreach ($pasos as &$p) {
        if (! empty($p['rechazada'])) {
            $p['clase'] = 'etapa--rechazada';
        } elseif ($p['completada']) {
            $p['clase'] = 'etapa--completada';
        } elseif ($todasAnterioresCompletas) {
            $p['clase'] = 'etapa--activa';
        } else {
            $p['clase'] = 'etapa--no-gestionada';
        }
        if (! $p['completada']) {
            $todasAnterioresCompletas = false;
        }
    }
    unset($p);

    return $pasos;
}

/**
 * Texto corto de "donde esta" un proceso (factura o boleta), a partir de los
 * segmentos ya coloreados por resumenEtapasBarra()/resumenEtapasBoletaBarra():
 * la primera etapa "etapa--activa" es la mas temprana no completada; si hay
 * una "etapa--rechazada" se prioriza ese mensaje (mas urgente que "en
 * progreso"); si no queda ninguna pendiente, el proceso esta completo. Usada
 * por /certificacion-elegir para el resumen compacto de cada tarjeta.
 *
 * @param list<array{nombre:string, clase:string}> $segmentos
 */
function textoEtapaActiva(array $segmentos): string
{
    $total = count($segmentos);
    foreach ($segmentos as $s) {
        if ($s['clase'] === 'etapa--rechazada') {
            return sprintf('Rechazada en %s', $s['nombre']);
        }
    }
    foreach ($segmentos as $i => $s) {
        if ($s['clase'] === 'etapa--activa') {
            return sprintf('Etapa %d de %d - %s', $i + 1, $total, $s['nombre']);
        }
    }

    return sprintf('Completo (%d/%d)', $total, $total);
}

// ===========================================================================
//  Handler: GET /certificacion/boleta
//
//  Resumen de la certificacion de boleta: 2 links (Set de Prueba, RVD) + su
//  estado (emitido/no emitido), leido de lo que ya existe (dte_emitido para
//  el Set, dte_boleta_rvd para el RVD -- migracion 012).
// ===========================================================================
function handleCertificacionBoletaGet(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirOnboardingCompleto($pdo, $cuentaId);

    $boletasEmitidas = listarBoletasEmitidas($pdo, $rutEmisor);
    $rvd             = (new MySqlBoletaRvdRepository($pdo))->ultimo($rutEmisor, Ambiente::Certificacion);
    $setEmitido      = $boletasEmitidas !== [];
    $rvdEnviado      = $rvd !== null;

    $etapasBoleta = calcularEtapasBoletaManuales(
        obtenerEtapasBoletaManualesRaw($pdo, $cuentaId),
        $setEmitido,
        $rvdEnviado,
    );

    vista('boleta', [
        'flash'           => flashTomar(),
        'setEmitido'      => $setEmitido,
        'cantidadEmitida' => count($boletasEmitidas),
        'rvd'             => $rvd,
        'cafBoleta'       => existeCafBoleta($pdo, $rutEmisor),
        'etapasBoleta'    => $etapasBoleta,
    ]);
}

// ===========================================================================
//  Handler: POST /certificacion/boleta/confirmar-etapa
//
//  Confirmacion MANUAL de los pasos 4-6 de boleta (Revision del Set en
//  certBolElectDteInternet/?SET=2, VoBo del SII, Declaracion de Cumplimiento
//  DE BOLETA -- distinta de la de factura) -- mismo patron EXACTO que
//  handleCertificacionConfirmarEtapaPost() (factura): whitelist fija de
//  campos (nunca interpolados desde $_POST directo), encadenamiento
//  recalculado en el servidor via calcularEtapasBoletaManuales(), nunca se
//  confia en lo que haya llegado del formulario.
// ===========================================================================
function handleCertificacionBoletaConfirmarEtapaPost(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirOnboardingCompleto($pdo, $cuentaId);

    $campos = [
        'revision'     => ['at' => 'boleta_revision_solicitada_at', 'trackCol' => 'boleta_revision_track_id', 'resultadoCol' => null],
        'vobo'         => ['at' => 'boleta_vobo_at', 'trackCol' => null, 'resultadoCol' => 'boleta_revision_resultado'],
        'cumplimiento' => ['at' => 'boleta_cumplimiento_confirmado_at', 'trackCol' => null, 'resultadoCol' => null],
    ];

    $etapa = (string) ($_POST['etapa'] ?? '');
    if (! isset($campos[$etapa])) {
        redirigirPrg('/certificacion/boleta');
    }
    $campo        = $campos[$etapa]['at'];
    $trackCol     = $campos[$etapa]['trackCol'];
    $resultadoCol = $campos[$etapa]['resultadoCol'];

    $raw = obtenerEtapasBoletaManualesRaw($pdo, $cuentaId);
    if ($raw[$campo] !== null) {
        flashSet('ok', 'Esta etapa ya estaba confirmada.');
        redirigirPrg('/certificacion/boleta');
    }

    if (empty($_POST['confirmo'])) {
        flashSet('error', 'Debes marcar la casilla de confirmacion para registrar esta etapa.');
        redirigirPrg('/certificacion/boleta');
    }

    $boletasEmitidas = listarBoletasEmitidas($pdo, $rutEmisor);
    $rvdUltimo       = (new MySqlBoletaRvdRepository($pdo))->ultimo($rutEmisor, Ambiente::Certificacion);
    $etapasBoleta    = calcularEtapasBoletaManuales($raw, $boletasEmitidas !== [], $rvdUltimo !== null);

    if (! $etapasBoleta[$etapa]['habilitada']) {
        flashSet('error', 'Esta etapa todavia no esta habilitada: falta completar el paso anterior.');
        redirigirPrg('/certificacion/boleta');
    }

    if ($trackCol !== null) {
        $trackId = trim((string) ($_POST['track_id'] ?? ''));
        if ($trackId === '') {
            flashSet('error', 'Debes informar el Track ID del Set de Boleta.');
            redirigirPrg('/certificacion/boleta');
        }
        $pdo->prepare(
            "UPDATE dte_emisor SET {$campo} = NOW(), {$trackCol} = :track_id "
            . "WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND cuenta_id = :cuenta_id AND {$campo} IS NULL"
        )->execute([':rut' => $rutEmisor, ':cuenta_id' => $cuentaId, ':track_id' => $trackId]);
    } elseif ($resultadoCol !== null) {
        // vobo: whitelist fija de valores, NUNCA se interpola $_POST['resultado']
        // directo (solo selecciona una entrada de este array, igual criterio
        // que $campos con $etapa).
        $resultado = (string) ($_POST['resultado'] ?? '');
        if (! in_array($resultado, ['aprobado', 'rechazado'], true)) {
            flashSet('error', 'Debes indicar si el SII aprobo o rechazo la revision.');
            redirigirPrg('/certificacion/boleta');
        }
        $pdo->prepare(
            "UPDATE dte_emisor SET {$campo} = NOW(), {$resultadoCol} = :resultado "
            . "WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND cuenta_id = :cuenta_id AND {$campo} IS NULL"
        )->execute([':rut' => $rutEmisor, ':cuenta_id' => $cuentaId, ':resultado' => $resultado]);
    } else {
        $pdo->prepare(
            "UPDATE dte_emisor SET {$campo} = NOW() WHERE rut_emisor = :rut AND ambiente = 'certificacion' "
            . "AND cuenta_id = :cuenta_id AND {$campo} IS NULL"
        )->execute([':rut' => $rutEmisor, ':cuenta_id' => $cuentaId]);
    }

    flashSet('ok', 'Etapa confirmada.');
    redirigirPrg('/certificacion/boleta');
}

// ===========================================================================
//  Handler: GET /certificacion/boleta/set
//
//  Preview del Set de Prueba de Boleta: 5 CASO fijos y universales (ver
//  BoletaSetPruebasBuilder -- confirmado diff vacio entre el set de EASY
//  AGENDA y el de sinergia, sin NUMERO DE ATENCION ni variacion por tenant,
//  a diferencia del Set de Pruebas de factura). NO hace falta parsear ningun
//  archivo ni pedir datos por formulario.
// ===========================================================================
function handleCertificacionBoletaSetGet(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    exigirCafBoleta($pdo, $rutEmisor);

    vista('boleta-set', [
        'flash' => flashTomar(),
        'casos' => (new BoletaSetPruebasBuilder())->casos(),
    ]);
}

// ===========================================================================
//  Handler: POST /certificacion/boleta/set/emitir
//
//  Emite el Set de Prueba de Boleta completo (BoletaSetPruebasBuilder, 5
//  CASO fijos) en UN solo EnvioBOLETA, invocando BoletaFacturador->emitirLote()
//  DIRECTO en PHP -- mismo patron sin API key/HTTP interno ya usado en Set
//  Basico/Simulacion, pero con la clase propia de boleta (canal REST
//  apicert/pangal, NO SiiDirectoFacturador/maullin -- ver docblock de
//  BoletaFacturador). Identidad (rut_emisor/ambiente/rutSender) SIEMPRE del
//  tenant autenticado via resolverRutSenderTenant(), NUNCA hardcodeada (a
//  diferencia de scripts/emitir_set_boletas_ea.php, que hardcodea el RUT del
//  firmante -- confirmado con Daniel que ese valor es su RUT real de
//  firmante autorizado para multiples empresas, pero igual el panel debe
//  resolverlo dinamicamente por tenant, nunca copiarlo como constante).
// ===========================================================================
function handleCertificacionBoletaSetEmitirPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    exigirCafBoleta($pdo, $rutEmisor);

    $certTls = __DIR__ . '/../../fullchain.pem';
    $keyTls  = __DIR__ . '/../../key.pem';
    if (! is_file($certTls) || ! is_readable($certTls) || ! is_file($keyTls) || ! is_readable($keyTls)) {
        flashSet('error', 'Certificado TLS mutuo no disponible en el servidor.');
        redirigirPrg('/certificacion/boleta/set');
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        error_log('panel boleta set emitir: CRYPTO_MASTER_KEY ausente o mal configurada');
        flashSet('error', 'Error de configuracion del servidor (llave de cifrado). Contacta al administrador.');
        redirigirPrg('/certificacion/boleta/set');
    }

    $rutSender = resolverRutSenderTenant($pdo, $rutEmisor, Ambiente::Certificacion);
    if ($rutSender === null) {
        flashSet('error', 'El certificado del emisor no tiene RUT de firmante (sender). Vuelve a cargar el certificado.');
        redirigirPrg('/certificacion/boleta/set');
    }

    $crypto     = new CertificadoCrypto($bin);
    $folios     = new MySqlFolioRepository($pdo, fn (string $c): string => $crypto->descifrar($c), cryptoKek: $crypto);
    $emisor     = new MySqlEmisorRepository($pdo, $crypto);
    $facturador = new BoletaFacturador(
        new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]),
        $folios,
        $emisor,
        dteEmitido: new MySqlDteEmitidoRepository($pdo),
    );

    $cred = new Credenciales(
        rutEmisor: $rutEmisor,
        apiToken:  'no-usado-por-sii-directo',
        ambiente:  Ambiente::Certificacion,
        rutSender: $rutSender,
    );

    try {
        $documentos = (new BoletaSetPruebasBuilder())->construirDocumentos();
        $res        = $facturador->emitirLote($documentos, $cred);
    } catch (SiiAutenticacionException $e) {
        error_log('panel boleta set emitir: fallo de autenticacion con el SII - ' . $e->glosaSii);
        flashSet('error', 'No se pudo autenticar con el SII para emitir el Set de Boleta. Intenta nuevamente.');
        redirigirPrg('/certificacion/boleta/set');
    } catch (EnvioRechazadoException $e) {
        flashSet('error', sprintf('El SII rechazo el envio (status %s, trackId %s).', $e->status, $e->trackId ?? 'sin trackId'));
        redirigirPrg('/certificacion/boleta/set');
    } catch (Throwable $e) {
        error_log('panel boleta set emitir: fallo la emision - ' . $e->getMessage());
        flashSet('error', 'No se pudo emitir el Set de Boleta: ' . $e->getMessage());
        redirigirPrg('/certificacion/boleta/set');
    }

    flashSet('ok', sprintf('Set de Boleta emitido. Track ID: %s (%d boletas).', $res['trackId'], count($res['boletas'])));
    redirigirPrg('/certificacion/boleta');
}

// ===========================================================================
//  Handler: GET /certificacion/boleta/rvd
//
//  Preview del RVD (ConsumoFolios) calculado DINAMICAMENTE a partir de las
//  boletas ya persistidas en dte_emitido (RvdResumenCalculator) -- reemplaza
//  la verificacion a mano con calculadora que dejo documentada
//  scripts/emitir_rvd_boleta_ea.php. Requiere CAF de boleta Y que el Set de
//  Boleta (paso A) ya se haya emitido para este tenant.
// ===========================================================================
function handleCertificacionBoletaRvdGet(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    exigirCafBoleta($pdo, $rutEmisor);

    // Solo las boletas emitidas HOY: el RCOF declara los folios de ese dia,
    // no el historico del tenant (si no, un RVD fechado hoy sale con folios y
    // montos de dias anteriores).
    $boletas = listarBoletasEmitidas($pdo, $rutEmisor, date('Y-m-d'));
    if ($boletas === []) {
        flashSet('error', 'Primero debes emitir el Set de Boleta antes de generar el RVD.');
        redirigir('/certificacion/boleta');
    }

    try {
        $resumenes = (new RvdResumenCalculator())->calcular($boletas);
    } catch (Throwable $e) {
        flashSet('error', 'No se pudo calcular el resumen del RVD: ' . $e->getMessage());
        redirigir('/certificacion/boleta');
    }

    vista('boleta-rvd', [
        'flash'     => flashTomar(),
        'resumenes' => $resumenes,
        'fecha'     => date('Y-m-d'),
    ]);
}

// ===========================================================================
//  Handler: POST /certificacion/boleta/rvd/emitir
//
//  Construye, firma y envia el RVD DIRECTO en PHP -- mismo patron de
//  scripts/emitir_rvd_boleta_ea.php: RvdBuilder + SiiAutenticador +
//  SiiUploader (canal SOAP CLASICO, maullin/palena), NUNCA BoletaAutenticador/
//  BoletaUploader (REST): el spec REST de boleta no tiene endpoint de
//  ConsumoFolios/RVD, confirmado contra docs/44_API_Boleta_Electronica_OpenAPI_Spec.yaml.
//  A diferencia del script viejo, el resumen (neto/iva/exento/total/folios)
//  se calcula DINAMICAMENTE desde dte_emitido (RvdResumenCalculator) -- ya
//  no se verifica a mano con calculadora.
// ===========================================================================
function handleCertificacionBoletaRvdEmitirPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    exigirCafBoleta($pdo, $rutEmisor);

    // Solo las boletas emitidas HOY: el RCOF declara los folios de ese dia,
    // no el historico del tenant (si no, un RVD fechado hoy sale con folios y
    // montos de dias anteriores).
    $boletas = listarBoletasEmitidas($pdo, $rutEmisor, date('Y-m-d'));
    if ($boletas === []) {
        flashSet('error', 'Primero debes emitir el Set de Boleta antes de generar el RVD.');
        redirigirPrg('/certificacion/boleta');
    }

    try {
        $resumenes = (new RvdResumenCalculator())->calcular($boletas);
    } catch (Throwable $e) {
        flashSet('error', 'No se pudo calcular el resumen del RVD: ' . $e->getMessage());
        redirigirPrg('/certificacion/boleta');
    }

    $certTls = __DIR__ . '/../../fullchain.pem';
    $keyTls  = __DIR__ . '/../../key.pem';
    if (! is_file($certTls) || ! is_readable($certTls) || ! is_file($keyTls) || ! is_readable($keyTls)) {
        flashSet('error', 'Certificado TLS mutuo no disponible en el servidor.');
        redirigirPrg('/certificacion/boleta/rvd');
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        error_log('panel boleta rvd emitir: CRYPTO_MASTER_KEY ausente o mal configurada');
        flashSet('error', 'Error de configuracion del servidor (llave de cifrado). Contacta al administrador.');
        redirigirPrg('/certificacion/boleta/rvd');
    }

    $rutSender = resolverRutSenderTenant($pdo, $rutEmisor, Ambiente::Certificacion);
    if ($rutSender === null) {
        flashSet('error', 'El certificado del emisor no tiene RUT de firmante (sender). Vuelve a cargar el certificado.');
        redirigirPrg('/certificacion/boleta/rvd');
    }

    $crypto = new CertificadoCrypto($bin);
    $emisor = new MySqlEmisorRepository($pdo, $crypto);
    $fecha  = date('Y-m-d');
    $signer = new XmlSigner();
    $rvdBuilder = new RvdBuilder();

    try {
        $cert  = $emisor->obtenerCertificado($rutEmisor, Ambiente::Certificacion);
        $datos = $emisor->obtenerDatosEmisor($rutEmisor, Ambiente::Certificacion);

        $doc   = $rvdBuilder->build($datos, $rutSender, $fecha, 1, $resumenes, Ambiente::Certificacion);
        $docCF = $doc->getElementsByTagNameNS('http://www.sii.cl/SiiDte', 'DocumentoConsumoFolios')->item(0);
        if (! $docCF instanceof DOMElement) {
            throw new RuntimeException('No se encontro DocumentoConsumoFolios en el documento generado.');
        }
        $idCF = $docCF->getAttribute('ID');
        $signer->insertarEsqueletoFirma($docCF, $idCF, $cert);
        $rvdBuilder->agregarSchemaLocation($doc);
        $signer->congelar($doc);
        $signer->calcularDigestYFirmar($doc, $idCF, $cert);

        $utf8   = XmlSigner::limpiarPrefijosDsig((string) $doc->saveXML());
        $iso    = (string) mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
        $xmlRvd = (string) preg_replace(
            '/(<\?xml[^>]*encoding=")UTF-8("[^>]*\?>)/i',
            '${1}ISO-8859-1${2}',
            $iso,
            1,
        );

        $http      = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);
        $token     = (new SiiAutenticador($http, $signer))->obtenerToken($cert, Ambiente::Certificacion);
        $resultado = (new SiiUploader($http))->subir($xmlRvd, $token, $rutSender, $rutEmisor, Ambiente::Certificacion);
    } catch (SiiAutenticacionException $e) {
        error_log('panel boleta rvd emitir: fallo de autenticacion con el SII - ' . $e->glosaSii);
        flashSet('error', 'No se pudo autenticar con el SII para enviar el RVD. Intenta nuevamente.');
        redirigirPrg('/certificacion/boleta/rvd');
    } catch (Throwable $e) {
        error_log('panel boleta rvd emitir: fallo el envio - ' . $e->getMessage());
        flashSet('error', 'No se pudo enviar el RVD: ' . $e->getMessage());
        redirigirPrg('/certificacion/boleta/rvd');
    }

    (new MySqlBoletaRvdRepository($pdo))->registrar(
        rutEmisor: $rutEmisor,
        ambiente:  Ambiente::Certificacion,
        fechaRvd:  $fecha,
        trackId:   $resultado['trackId'] ?? null,
        estado:    'enviado',
        xml:       $xmlRvd,
    );

    flashSet('ok', sprintf('RVD enviado. Track ID: %s.', $resultado['trackId'] ?? '(sin trackId)'));
    redirigirPrg('/certificacion/boleta');
}

/**
 * Extrae el numero de intercambio del texto libre "SET INTERCAMBIO NUMERO <n>"
 * (NmbItem del Detalle de cada Documento del EnvioDTE recibido -- confirmado
 * en intercambio_4955508.xml; NO es un campo estructurado de EnvioDteParser,
 * por eso se busca aqui con un regex best-effort sobre el XML crudo en vez de
 * tocar EnvioDteParser -- se reusa TAL CUAL, sin agregarle nada).
 */
function extraerNumeroIntercambio(string $xml): ?int
{
    return preg_match('/SET INTERCAMBIO NUMERO\s+(\d+)/i', $xml, $m) === 1 ? (int) $m[1] : null;
}

/**
 * Documentos del EnvioDTE con un flag 'aceptado' agregado (RUTRecep del
 * documento === rut_emisor del tenant): mismo criterio que usan
 * RespuestaDteBuilder/EnvioRecibosBuilder internamente, replicado aqui SOLO
 * para mostrarlo en el preview (no se reimplementa la logica de la
 * respuesta, solo se refleja cual sera el resultado).
 *
 * @return list<array<string,mixed>>
 */
function documentosIntercambioConEstado(EnvioDteParser $envio, string $rutEmisor): array
{
    return array_map(
        static fn (array $d): array => $d + ['aceptado' => $d['RUTRecep'] === $rutEmisor],
        $envio->documentos,
    );
}

// ===========================================================================
//  Handler: GET /certificacion/intercambio
//
//  Sub-estacion de la 5: sube el EnvioDTE que el SII entrega al tenant en
//  www4.sii.cl/pfeInternet ("SET DE INTERCAMBIO") y genera las 3 respuestas
//  (RecepcionEnvio, ResultadoDTE, EnvioRecibos) que el tenant debe subir a
//  mano al MISMO portal (no hay API del SII para eso: el ultimo paso queda
//  fuera de este sistema por diseno). Reusa EnvioDteParser/RespuestaDteBuilder/
//  EnvioRecibosBuilder TAL CUAL. Se re-parsea el archivo guardado en cada
//  visita (no se persiste la estructura parseada), mismo criterio que
//  set-pruebas.
// ===========================================================================
function handleIntercambioGet(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    $repo      = new MySqlIntercambioRespuestaRepository($pdo);

    $fila        = $repo->obtener($rutEmisor, Ambiente::Certificacion);
    $documentos  = null;
    $errorParseo = null;
    if ($fila !== null) {
        try {
            $envio = new EnvioDteParser();
            $envio->loadXML($fila['archivo_envio_original']);
            $documentos = documentosIntercambioConEstado($envio, $rutEmisor);
        } catch (Throwable $e) {
            $errorParseo = 'El archivo guardado no se pudo interpretar: ' . $e->getMessage();
        }
    }

    vista('intercambio', [
        'flash'       => flashTomar(),
        'fila'        => $fila,
        'documentos'  => $documentos,
        'errorParseo' => $errorParseo,
        'error'       => null,
    ]);
}

// ===========================================================================
//  Handler: POST /certificacion/intercambio
//
//  Genera las 3 respuestas directo (sin HTTP interno): identidad SIEMPRE del
//  tenant autenticado -- rutResponde = rut_emisor via exigirOnboardingCompleto(),
//  rutRecibe = RutEmisor del envio recibido, mailContacto = email de la
//  CUENTA (no hay precedente de leerlo por cuenta_id en el panel; se agrega
//  la consulta aqui), certificado via MySqlEmisorRepository (mismo patron de
//  toda la estacion 5), rutFirma via resolverRutSenderTenant() (ya existente,
//  reusado tal cual). Si EnvioRecibosBuilder no encuentra ningun documento
//  dirigido al tenant, lanza RuntimeException -- se captura y se muestra
//  claro, no un 500.
// ===========================================================================
function handleIntercambioPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    $repo      = new MySqlIntercambioRespuestaRepository($pdo);

    $archivo = $_FILES['archivo'] ?? null;
    if (
        ! is_array($archivo)
        || ! isset($archivo['error'], $archivo['tmp_name'])
        || $archivo['error'] !== UPLOAD_ERR_OK
        || ! is_uploaded_file($archivo['tmp_name'])
    ) {
        vista('intercambio', [
            'flash' => null, 'fila' => $repo->obtener($rutEmisor, Ambiente::Certificacion),
            'documentos' => null, 'errorParseo' => null,
            'error' => 'Debes seleccionar el archivo XML del EnvioDTE descargado del SII.',
        ]);
    }

    $xmlOriginal = file_get_contents($archivo['tmp_name']);
    if ($xmlOriginal === false || trim($xmlOriginal) === '') {
        vista('intercambio', [
            'flash' => null, 'fila' => $repo->obtener($rutEmisor, Ambiente::Certificacion),
            'documentos' => null, 'errorParseo' => null,
            'error' => 'No se pudo leer el archivo subido.',
        ]);
    }

    try {
        $envio = new EnvioDteParser();
        $envio->loadXML($xmlOriginal);
    } catch (Throwable $e) {
        vista('intercambio', [
            'flash' => null, 'fila' => $repo->obtener($rutEmisor, Ambiente::Certificacion),
            'documentos' => null, 'errorParseo' => null,
            'error' => 'El archivo no es un EnvioDTE valido: ' . $e->getMessage(),
        ]);
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        error_log('panel intercambio: CRYPTO_MASTER_KEY ausente o mal configurada');
        flashSet('error', 'Error de configuracion del servidor (llave de cifrado). Contacta al administrador.');
        redirigirPrg('/certificacion/intercambio');
    }
    $crypto = new CertificadoCrypto($bin);
    $emisor = new MySqlEmisorRepository($pdo, $crypto);

    try {
        $cert  = $emisor->obtenerCertificado($rutEmisor, Ambiente::Certificacion);
        $datos = $emisor->obtenerDatosEmisor($rutEmisor, Ambiente::Certificacion);
    } catch (Throwable $e) {
        flashSet('error', 'No se pudo obtener el certificado/datos del emisor: ' . $e->getMessage());
        redirigirPrg('/certificacion/intercambio');
    }

    $rutFirma = resolverRutSenderTenant($pdo, $rutEmisor, Ambiente::Certificacion);
    if ($rutFirma === null) {
        flashSet('error', 'El certificado del emisor no tiene RUT de firmante (sender). Vuelve a cargar el certificado.');
        redirigirPrg('/certificacion/intercambio');
    }

    $stmtEmail = $pdo->prepare('SELECT email FROM cuenta WHERE id = :id LIMIT 1');
    $stmtEmail->execute([':id' => Auth::cuentaId()]);
    $mailContacto = (string) ($stmtEmail->fetchColumn() ?: '');

    $car = [
        'RutResponde'  => $rutEmisor,
        'RutRecibe'    => $envio->caratula['RutEmisor'] ?? '',
        'IdRespuesta'  => 1,
        'MailContacto' => $mailContacto,
    ];

    try {
        $xmlAcuse     = (new RespuestaDteBuilder(new XmlSigner()))->acuseRecibo($envio, $car, $cert);
        $xmlResultado = (new RespuestaDteBuilder(new XmlSigner()))->aceptacionRechazo($envio, $car, $cert);
        $xmlRecibos   = (new EnvioRecibosBuilder(new XmlSigner()))->generar($envio, $car, $cert, $datos->dirOrigen, $rutFirma);
    } catch (Throwable $e) {
        // Incluye el caso documentado de EnvioRecibosBuilder: "ningun
        // documento dirigido a <rut>" cuando ningun RUTRecep del envio
        // coincide con el tenant.
        flashSet('error', 'No se pudieron generar las respuestas del intercambio: ' . $e->getMessage());
        redirigirPrg('/certificacion/intercambio');
    }

    $repo->guardar(
        $rutEmisor,
        Ambiente::Certificacion,
        extraerNumeroIntercambio($xmlOriginal),
        $xmlOriginal,
        $xmlAcuse,
        $xmlResultado,
        $xmlRecibos,
    );

    flashSet('ok', 'Respuestas del intercambio generadas correctamente.');
    redirigirPrg('/certificacion/intercambio');
}

// ===========================================================================
//  Handler: GET /certificacion/intercambio/{acuse|resultado|recibos}.xml
//
//  Descarga uno de los 3 XML generados. Scope estricto: solo el rut_emisor
//  del tenant autenticado.
// ===========================================================================
function handleIntercambioDescargarGet(string $cual): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());
    $fila      = (new MySqlIntercambioRespuestaRepository($pdo))->obtener($rutEmisor, Ambiente::Certificacion);
    if ($fila === null) {
        http_response_code(404);
        echo '404 - no hay respuesta de intercambio generada';
        exit;
    }

    $contenido = match ($cual) {
        'acuse'     => $fila['respuesta_acuse'],
        'resultado' => $fila['respuesta_resultado'],
        'recibos'   => $fila['respuesta_recibos'],
    };

    header('Content-Type: application/xml; charset=ISO-8859-1');
    header('Content-Disposition: attachment; filename="respuesta_' . $cual . '.xml"');
    echo $contenido;
    exit;
}

/**
 * Arma la lista de documentos a incluir en las Muestras Impresas: TODOS los
 * del Set Basico ($trackIdSetBasico) + 1 por TIPO de la Simulacion
 * ($trackIdSimulacion, modo "porTipo" -- igual que
 * scripts/generar_muestras_pdf.php: el manual del SII exige "todos" los del
 * set de pruebas + "una muestra de cada tipo" de la simulacion, ver
 * docs/CERTIFICACION_MUESTRAS_IMPRESAS.md). $trackIdSimulacion puede ser
 * null (simulacion aun no identificada o ambigua sin resolver): en ese caso
 * solo se arma el Set Basico.
 *
 * @return list<array{tipoDte:int, folio:int, xml:string, origen:string}>
 */
function planificarDocumentosMuestras(PDO $pdo, string $rutEmisor, string $trackIdSetBasico, ?string $trackIdSimulacion): array
{
    $documentos = [];

    $stmt = $pdo->prepare(
        "SELECT tipo_dte, folio, xml FROM dte_emitido "
        . "WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND track_id = :track ORDER BY id ASC"
    );
    $stmt->execute([':rut' => $rutEmisor, ':track' => $trackIdSetBasico]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $documentos[] = [
            'tipoDte' => (int) $r['tipo_dte'], 'folio' => (int) $r['folio'],
            'xml' => (string) $r['xml'], 'origen' => 'prueba',
        ];
    }

    if ($trackIdSimulacion !== null) {
        $stmtTipos = $pdo->prepare(
            "SELECT tipo_dte, MIN(folio) AS folio FROM dte_emitido "
            . "WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND track_id = :track "
            . 'GROUP BY tipo_dte ORDER BY tipo_dte ASC'
        );
        $stmtTipos->execute([':rut' => $rutEmisor, ':track' => $trackIdSimulacion]);
        foreach ($stmtTipos->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tipoDte = (int) $r['tipo_dte'];
            $folio   = (int) $r['folio'];
            $stmtXml = $pdo->prepare(
                "SELECT xml FROM dte_emitido WHERE rut_emisor = :rut AND ambiente = 'certificacion' "
                . 'AND tipo_dte = :tipo AND folio = :folio LIMIT 1'
            );
            $stmtXml->execute([':rut' => $rutEmisor, ':tipo' => $tipoDte, ':folio' => $folio]);
            $xml = $stmtXml->fetchColumn();
            if ($xml !== false) {
                $documentos[] = ['tipoDte' => $tipoDte, 'folio' => $folio, 'xml' => (string) $xml, 'origen' => 'simulacion'];
            }
        }
    }

    return $documentos;
}

// ===========================================================================
//  Handler: GET /certificacion/muestras-impresas
//
//  Sub-estacion de la 5: previsualiza y genera el ZIP de PDF para la etapa 4
//  (Documentos Impresos, www4.sii.cl/pdfdteInternet). Solo funciona con el
//  Set Basico aprobado (setBasicoAprobado(), reusada tal cual). La Simulacion
//  no tiene equivalente rastreado en el panel: se identifica con
//  simulacionAprobada() (ver docblock); si es ambigua, la vista debe mostrar
//  un selector en vez de adivinar.
// ===========================================================================
function handleMuestrasImpresasGet(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());

    $envios        = agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor))['envios'];
    $sokPorTrackId = (new MySqlSetBasicoSokRepository($pdo))->confirmadosPorTrackId($rutEmisor, Ambiente::Certificacion);
    $setBasico     = setBasicoAprobado($envios, $sokPorTrackId);
    $simulacion    = simulacionAprobada($envios, $setBasico['trackId']);

    $planificado = null;
    if ($setBasico['aprobado'] && ! $simulacion['ambiguo']) {
        $planificado = planificarDocumentosMuestras($pdo, $rutEmisor, $setBasico['trackId'], $simulacion['trackId']);
    }

    vista('muestras-impresas', [
        'flash'       => flashTomar(),
        'setBasico'   => $setBasico,
        'simulacion'  => $simulacion,
        'planificado' => $planificado,
        'error'       => null,
    ]);
}

// ===========================================================================
//  Handler: POST /certificacion/muestras-impresas.zip
//
//  Genera los PDF (Set Basico completo + 1 por tipo de la Simulacion, con
//  copia cedible para las facturas) y los devuelve empaquetados en un ZIP
//  como respuesta DIRECTA de este POST (no se persiste nada: es generacion
//  local e idempotente a partir de dte_emitido, sin efectos de red -- un
//  refresh que reenvie el POST solo regenera el mismo ZIP). Reusa
//  DtePdfGenerator TAL CUAL via MuestrasImpresasZipBuilder. NO sube nada al
//  SII: la subida a www4.sii.cl/pdfdteInternet sigue siendo manual.
// ===========================================================================
function handleMuestrasImpresasPost(): void
{
    $pdo       = Db::conexion();
    $rutEmisor = exigirOnboardingCompleto($pdo, Auth::cuentaId());

    $envios        = agruparEmitidosPorEnvio(listarEmitidosFactura($pdo, $rutEmisor))['envios'];
    $sokPorTrackId = (new MySqlSetBasicoSokRepository($pdo))->confirmadosPorTrackId($rutEmisor, Ambiente::Certificacion);
    $setBasico     = setBasicoAprobado($envios, $sokPorTrackId);
    if (! $setBasico['aprobado']) {
        flashSet('error', 'El Set Basico debe estar aprobado (EPR y confirmado SOK) antes de generar las muestras impresas.');
        redirigirPrg('/certificacion/muestras-impresas');
    }

    $simulacion        = simulacionAprobada($envios, $setBasico['trackId']);
    $trackIdSimulacion = $simulacion['trackId'];
    if ($simulacion['ambiguo']) {
        $elegido = trim((string) ($_POST['track_id_simulacion'] ?? ''));
        $valido  = false;
        foreach ($simulacion['candidatos'] as $c) {
            if ($c['trackId'] === $elegido) {
                $valido = true;
                break;
            }
        }
        if (! $valido) {
            flashSet('error', 'Debes elegir cual envio corresponde a la Simulacion (hay mas de un candidato posible).');
            redirigirPrg('/certificacion/muestras-impresas');
        }
        $trackIdSimulacion = $elegido;
    }

    $documentos = planificarDocumentosMuestras($pdo, $rutEmisor, $setBasico['trackId'], $trackIdSimulacion);

    if (! class_exists(ZipArchive::class)) {
        flashSet('error', 'La extension ZipArchive de PHP no esta disponible en el servidor. Contacta al administrador.');
        redirigirPrg('/certificacion/muestras-impresas');
    }

    try {
        $resultado = (new MuestrasImpresasZipBuilder())->construir($documentos);
    } catch (Throwable $e) {
        flashSet('error', 'No se pudieron generar las muestras impresas: ' . $e->getMessage());
        redirigirPrg('/certificacion/muestras-impresas');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="muestras_impresas_' . $rutEmisor . '.zip"');
    header('Content-Length: ' . strlen($resultado['zip']));
    echo $resultado['zip'];
    exit;
}

// ===========================================================================
//  Handler: GET /certificacion-aprobada
//
//  Estacion 6. El SII no expone webservice que informe la aprobacion, asi que
//  esta pantalla NUNCA afirma "certificado" por su cuenta: solo refleja la
//  confirmacion explicita del tenant (certificacion_confirmada_at). Solo
//  lectura de BD; sin llamadas al SII.
// ===========================================================================
function handleCertificacionAprobadaGet(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirOnboardingCompleto($pdo, $cuentaId);

    $confirmadaAt = obtenerCertificacionConfirmadaAt($pdo, $cuentaId);

    // Bloqueada mientras la estacion 5 no este completa (salvo que ya este
    // confirmada: en ese caso se muestra el estado completado igual).
    if ($confirmadaAt === null && ! certificacionCompleta($pdo, $rutEmisor)) {
        redirigir('/certificacion');
    }

    vista('certificacion_aprobada', [
        'flash'        => flashTomar(),
        'confirmadaAt' => $confirmadaAt,
    ]);
}

// ===========================================================================
//  Handler: POST /certificacion-aprobada/confirmar
//
//  Registra la confirmacion del tenant. rut_emisor y cuenta_id SIEMPRE del
//  tenant autenticado, nunca del POST. Patron PRG (flashSet + redirigirPrg).
// ===========================================================================
function handleCertificacionAprobadaConfirmarPost(): void
{
    $pdo       = Db::conexion();
    $cuentaId  = Auth::cuentaId();
    $rutEmisor = exigirOnboardingCompleto($pdo, $cuentaId);

    // Idempotente: si ya estaba confirmada, no se falla ni se duplica.
    if (obtenerCertificacionConfirmadaAt($pdo, $cuentaId) !== null) {
        flashSet('ok', 'La certificacion ya estaba confirmada.');
        redirigirPrg('/certificacion-aprobada');
    }

    // Guardia servidor: sin los 3 componentes aprobados (set basico + libro de
    // ventas + libro de compras) no se puede confirmar, aunque el POST llegue igual.
    if (! certificacionCompleta($pdo, $rutEmisor)) {
        flashSet('error', 'Aun no completas los 3 componentes de la certificacion (Set Basico, Libro de Ventas y Libro de Compras aceptados por el SII). No se registro la confirmacion.');
        redirigirPrg('/certificacion');
    }

    if (empty($_POST['confirmo'])) {
        flashSet('error', 'Debes marcar la casilla de confirmacion para registrar la certificacion.');
        redirigirPrg('/certificacion-aprobada');
    }

    $pdo->prepare(
        "UPDATE dte_emisor SET certificacion_confirmada_at = NOW() "
        . "WHERE rut_emisor = :rut AND ambiente = 'certificacion' AND cuenta_id = :cuenta_id "
        . 'AND certificacion_confirmada_at IS NULL'
    )->execute([':rut' => $rutEmisor, ':cuenta_id' => $cuentaId]);

    flashSet('ok', 'Confirmacion registrada: tu empresa quedo marcada como certificada ante el SII.');
    redirigirPrg('/certificacion-aprobada');
}

// ===========================================================================
//  DASHBOARD DE GESTION -- metricas de produccion (solo lectura).
//
//  Todo este bloque es ADITIVO: son consultas SELECT nuevas sobre tablas que
//  ya existen. No toca el motor ni su contrato de API, no modifica ninguna
//  fila y no cambia ningun handler de emision/certificacion.
//
//  AISLAMIENTO: cada consulta lleva SIEMPRE rut_emisor + ambiente='produccion'
//  en el WHERE. dte_emitido/dte_folio/dte_caf NO tienen cuenta_id: se escopan
//  por rut_emisor, que se resuelve desde la cuenta de la sesion (nunca del
//  request). El indice idx_periodo (rut_emisor, ambiente, fecha_emision) cubre
//  los filtros por periodo.
//
//  ZONA HORARIA: los limites del periodo se calculan SIEMPRE en PHP
//  (America/Santiago), nunca con CURDATE()/NOW() de MySQL, que corre en UTC.
//  Entre las 20:00 CL y medianoche el dia UTC ya avanzo, y en un cambio de mes
//  eso mandaria las metricas al periodo equivocado.
// ===========================================================================

/**
 * Limites del periodo elegido y del periodo inmediatamente anterior (el que se
 * usa para la comparacion). $cual es 'actual' o 'anterior'.
 *
 * @return array{clave:string, desde:string, hasta:string, etiqueta:string, prevDesde:string, prevHasta:string, prevEtiqueta:string}
 */
function dashPeriodo(string $cual): array
{
    $clave = $cual === 'anterior' ? 'anterior' : 'actual';
    $hoy   = new DateTimeImmutable('today');
    $base  = $clave === 'anterior'
        ? $hoy->modify('first day of last month')
        : $hoy->modify('first day of this month');
    $prev = $base->modify('first day of last month');

    $etiquetar = static function (DateTimeImmutable $d): string {
        return DASH_MESES[(int) $d->format('n')] . ' ' . $d->format('Y');
    };

    return [
        'clave'        => $clave,
        'desde'        => $base->format('Y-m-d'),
        'hasta'        => $base->modify('last day of this month')->format('Y-m-d'),
        'etiqueta'     => $etiquetar($base),
        'prevDesde'    => $prev->format('Y-m-d'),
        'prevHasta'    => $prev->modify('last day of this month')->format('Y-m-d'),
        'prevEtiqueta' => $etiquetar($prev),
    ];
}

/**
 * Q1. Agregado por tipo de documento en un rango de fechas.
 *
 * LOS RECHAZADOS NO SUMAN (EstadoContable). Un envio rechazado se corrige y se
 * reemite, asi que los mismos montos quedan dos veces en la tabla: medido en
 * produccion, un cliente con 68 rechazados y 68 buenos por LAS MISMAS ventas
 * veia el doble exacto de lo que habia vendido.
 *
 * @param bool $soloRechazados invierte el filtro y devuelve SOLO lo excluido.
 *        Existe para que el monto rechazado se calcule con ESTA MISMA consulta
 *        y no con una copia: asi lo que se resta del total y lo que se muestra
 *        como restado no pueden diferir ni por un peso.
 *
 * @return array<int,array{documentos:int, neto:int, iva:int, total:int}> indexado por tipo_dte
 */
function dashMetricasPorTipo(
    PDO $pdo,
    string $rutEmisor,
    string $desde,
    string $hasta,
    bool $soloRechazados = false
): array {
    $stmt = $pdo->prepare(
        'SELECT tipo_dte, '
        . '       COUNT(*)   AS documentos, '
        . '       SUM(neto)  AS neto, '
        . '       SUM(iva)   AS iva, '
        . '       SUM(total) AS total '
        . 'FROM dte_emitido '
        . "WHERE rut_emisor = :rut AND ambiente = 'produccion' "
        . '  AND fecha_emision BETWEEN :desde AND :hasta '
        . ($soloRechazados
            ? EstadoContable::sqlSoloRechazados()
            : EstadoContable::sqlExcluirRechazados())
        . 'GROUP BY tipo_dte ORDER BY tipo_dte'
    );
    $stmt->execute([':rut' => $rutEmisor, ':desde' => $desde, ':hasta' => $hasta]);

    $porTipo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $porTipo[(int) $fila['tipo_dte']] = [
            'documentos' => (int) $fila['documentos'],
            'neto'       => (int) $fila['neto'],
            'iva'        => (int) $fila['iva'],
            'total'      => (int) $fila['total'],
        ];
    }

    return $porTipo;
}

/**
 * REGLA DE NEGOCIO DEL DASHBOARD.
 *
 * Vive aqui, en PHP, y NO dentro del SQL, a proposito: es una decision de
 * negocio, no de consulta. Si manana cambia, se cambia en este unico lugar y
 * queda a la vista de quien lea el codigo. La UI ademas imprime la formula
 * debajo de la cifra, para que el numero no sea una caja negra.
 *
 *   Neto del periodo = (33 factura + 39 boleta + 56 nota de debito) - (61 nota de credito)
 *   IVA debito       = mismo criterio, aplicado sobre la columna iva
 *
 * Las notas de credito REBAJAN lo facturado: sumarlas como una venta mas
 * inflaria los ingresos. Por eso el dashboard nunca muestra un unico "total
 * facturado" sin desglose: cada tipo se ve por separado y el neto va aparte,
 * etiquetado.
 *
 * @param array<int,array{documentos:int, neto:int, iva:int, total:int}> $porTipo
 *
 * @return array{documentos:int, netoPeriodo:int, ivaDebito:int, porTipo:array<int,array{documentos:int, neto:int, iva:int, total:int}>, formula:string}
 */
function dashResumen(array $porTipo): array
{
    $vacio = ['documentos' => 0, 'neto' => 0, 'iva' => 0, 'total' => 0];

    // Normaliza: los 4 tipos conocidos siempre presentes, en cero si no hubo.
    $normalizado = [];
    foreach (TipoDte::MANEJADOS as $tipo) {
        $normalizado[$tipo] = $porTipo[$tipo] ?? $vacio;
    }
    // Un tipo inesperado (no mapeado) igual se conserva: no se pierde plata.
    foreach ($porTipo as $tipo => $datos) {
        if (! isset($normalizado[$tipo])) {
            $normalizado[$tipo] = $datos;
        }
    }

    $documentos = 0;
    $neto       = 0;
    $iva        = 0;
    foreach ($normalizado as $tipo => $datos) {
        $documentos += $datos['documentos'];
        $signo = $tipo === DASH_TIPO_NOTA_CREDITO ? -1 : 1;
        $neto += $signo * $datos['neto'];
        $iva  += $signo * $datos['iva'];
    }

    return [
        'documentos'  => $documentos,
        'netoPeriodo' => $neto,
        'ivaDebito'   => $iva,
        'porTipo'     => $normalizado,
        'formula'     => 'Factura + Boleta + Nota de debito - Nota de credito',
    ];
}

/**
 * Q3. Folios de produccion por tipo de documento.
 *
 * La condicion de "disponible" NO se inventa aqui: es exactamente la misma que
 * aplica MySqlFolioRepository al asignar un folio real
 * (estado 'agotado' O proximo_folio > folio_hasta -> ese CAF no sirve). El
 * estado 'agotado' se marca de forma diferida, asi que filtrar solo por estado
 * reportaria folios que en realidad ya no existen.
 *
 * USADOS Y TOTAL SE MIDEN CONTRA proximo_folio_inicial, NO CONTRA folio_desde.
 * En un CAF normal los dos valen lo mismo y el resultado es identico al de
 * siempre. La diferencia aparece con un CAF MIGRADO desde otro proveedor, que
 * arranca a mitad de rango: ahi folio_desde contaria como consumo propio los
 * folios que el emisor gasto ANTES de llegar aqui, e inflaria el porcentaje
 * usado desde el primer dia. El semaforo mide lo que Sinergia puede emitir.
 *
 * @return list<array{tipo:int, disponibles:int, usados:int, totalRango:int, cafs:int, pctDisponible:int, nivel:string}>
 */
function dashFoliosPorTipo(PDO $pdo, string $rutEmisor): array
{
    $stmt = $pdo->prepare(
        'SELECT f.tipo_dte, '
        . "       SUM(CASE WHEN c.estado <> 'agotado' AND f.proximo_folio <= f.folio_hasta "
        . '                THEN f.folio_hasta - f.proximo_folio + 1 ELSE 0 END) AS disponibles, '
        . '       SUM(GREATEST(f.proximo_folio - f.proximo_folio_inicial, 0))   AS usados, '
        . '       SUM(f.folio_hasta - f.proximo_folio_inicial + 1)              AS total_rango, '
        . '       COUNT(*)                                                      AS cafs '
        . 'FROM dte_folio f '
        . 'INNER JOIN dte_caf c ON c.id = f.caf_id '
        . "WHERE f.rut_emisor = :rut AND f.ambiente = 'produccion' "
        . 'GROUP BY f.tipo_dte ORDER BY f.tipo_dte'
    );
    $stmt->execute([':rut' => $rutEmisor]);

    $salida = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $disponibles = (int) $fila['disponibles'];
        $totalRango  = (int) $fila['total_rango'];
        $pct         = $totalRango > 0 ? (int) round($disponibles * 100 / $totalRango) : 0;

        if ($disponibles === 0 || $pct < DASH_FOLIOS_UMBRAL_ROJO) {
            $nivel = 'rojo';
        } elseif ($pct < DASH_FOLIOS_UMBRAL_AMBAR) {
            $nivel = 'ambar';
        } else {
            $nivel = 'ok';
        }

        $salida[] = [
            'tipo'          => (int) $fila['tipo_dte'],
            'disponibles'   => $disponibles,
            'usados'        => (int) $fila['usados'],
            'totalRango'    => $totalRango,
            'cafs'          => (int) $fila['cafs'],
            'pctDisponible' => $pct,
            'nivel'         => $nivel,
        ];
    }

    return $salida;
}

/**
 * Q4. Serie diaria del periodo: ventas y notas de credito por separado, mas el
 * neto. Los dias sin documentos NO vienen del SQL; los rellena
 * dashSerieCompleta() para que el eje temporal no mienta sobre la densidad.
 *
 * @return array<string,array{documentos:int, ventas:int, notasCredito:int, neto:int}> indexado por fecha
 */
function dashVentasPorDia(PDO $pdo, string $rutEmisor, string $desde, string $hasta): array
{
    $stmt = $pdo->prepare(
        'SELECT fecha_emision, '
        . '       COUNT(*) AS documentos, '
        . '       SUM(CASE WHEN tipo_dte = :nc1 THEN 0 ELSE total END)      AS ventas, '
        . '       SUM(CASE WHEN tipo_dte = :nc2 THEN total ELSE 0 END)      AS notas_credito, '
        . '       SUM(CASE WHEN tipo_dte = :nc3 THEN -total ELSE total END) AS neto '
        . 'FROM dte_emitido '
        . "WHERE rut_emisor = :rut AND ambiente = 'produccion' "
        . '  AND fecha_emision BETWEEN :desde AND :hasta '
        // Los rechazados no suman: si no se excluyeran, el grafico mostraria un
        // pico el dia del rechazo y otro igual el dia de la reemision.
        . EstadoContable::sqlExcluirRechazados()
        . 'GROUP BY fecha_emision ORDER BY fecha_emision'
    );
    $stmt->execute([
        ':rut'   => $rutEmisor,
        ':desde' => $desde,
        ':hasta' => $hasta,
        ':nc1'   => DASH_TIPO_NOTA_CREDITO,
        ':nc2'   => DASH_TIPO_NOTA_CREDITO,
        ':nc3'   => DASH_TIPO_NOTA_CREDITO,
    ]);

    $porFecha = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $porFecha[(string) $fila['fecha_emision']] = [
            'documentos'   => (int) $fila['documentos'],
            'ventas'       => (int) $fila['ventas'],
            'notasCredito' => (int) $fila['notas_credito'],
            'neto'         => (int) $fila['neto'],
        ];
    }

    return $porFecha;
}

/**
 * Rellena con ceros todos los dias del rango que no tuvieron documentos.
 *
 * @param array<string,array{documentos:int, ventas:int, notasCredito:int, neto:int}> $porFecha
 *
 * @return list<array{fecha:string, dia:string, documentos:int, ventas:int, notasCredito:int, neto:int}>
 */
function dashSerieCompleta(array $porFecha, string $desde, string $hasta): array
{
    $cursor = new DateTimeImmutable($desde);
    $fin    = new DateTimeImmutable($hasta);
    $serie  = [];

    while ($cursor <= $fin) {
        $fecha = $cursor->format('Y-m-d');
        $datos = $porFecha[$fecha] ?? ['documentos' => 0, 'ventas' => 0, 'notasCredito' => 0, 'neto' => 0];
        $serie[] = ['fecha' => $fecha, 'dia' => $cursor->format('j')] + $datos;
        $cursor  = $cursor->modify('+1 day');
    }

    return $serie;
}

/**
 * Q5. Distribucion CRUDA de dte_emitido.estado en el periodo.
 *
 * Sin ninguna interpretacion: el codigo se muestra tal cual lo guardo el motor
 * ('enviado', 'EPR', 'DOK', 'desconocido', lo que devuelva el SII), igual que
 * ya hace el listado de M5. Traducirlos a aceptado/rechazado/pendiente seria
 * una regla de negocio que hoy no existe en el proyecto.
 *
 * @return list<array{estado:string, documentos:int}>
 */
function dashDistribucionEstado(PDO $pdo, string $rutEmisor, string $desde, string $hasta): array
{
    $stmt = $pdo->prepare(
        'SELECT estado, COUNT(*) AS documentos '
        . 'FROM dte_emitido '
        . "WHERE rut_emisor = :rut AND ambiente = 'produccion' "
        . '  AND fecha_emision BETWEEN :desde AND :hasta '
        . 'GROUP BY estado ORDER BY documentos DESC, estado ASC'
    );
    $stmt->execute([':rut' => $rutEmisor, ':desde' => $desde, ':hasta' => $hasta]);

    $salida = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $salida[] = [
            'estado'     => (string) $fila['estado'],
            'documentos' => (int) $fila['documentos'],
        ];
    }

    return $salida;
}

/**
 * Q6. Top 5 receptores por neto del periodo, agrupando por RUT NORMALIZADO.
 *
 * La expresion SQL replica exactamente a Rut::normalizar():
 *   strtoupper(str_replace(['.', ' '], '', trim($rut)))
 * Sin eso, el mismo cliente cargado una vez con puntos y otra sin puntos
 * apareceria como dos filas distintas (dte_emitido.receptor_rut solo pasa por
 * trim al emitir, ver armarDocumentoEmision()).
 *
 * La razon social se resuelve contra el maestro con buscarPorRuts(), escopado
 * por cuenta_id: el nombre de un cliente de otra cuenta no puede filtrarse
 * aunque el RUT coincidiera. Si el receptor no esta en el maestro se muestra
 * solo el RUT, sin fallar.
 *
 * @return list<array{rut:string, razonSocial:?string, documentos:int, neto:int}>
 */
function dashTopClientes(PDO $pdo, int $cuentaId, string $rutEmisor, string $desde, string $hasta): array
{
    $stmt = $pdo->prepare(
        "SELECT UPPER(REPLACE(REPLACE(TRIM(receptor_rut), '.', ''), ' ', '')) AS rut_normalizado, "
        . '       COUNT(*) AS documentos, '
        . '       SUM(CASE WHEN tipo_dte = :nc THEN -total ELSE total END) AS neto '
        . 'FROM dte_emitido '
        . "WHERE rut_emisor = :rut AND ambiente = 'produccion' "
        . '  AND fecha_emision BETWEEN :desde AND :hasta '
        // Los rechazados no suman: sin esto un cliente al que se le rechazo y
        // reemitio un lote apareceria arriba del ranking por el doble.
        . EstadoContable::sqlExcluirRechazados()
        . 'GROUP BY rut_normalizado ORDER BY neto DESC LIMIT 5'
    );
    $stmt->execute([
        ':rut'   => $rutEmisor,
        ':desde' => $desde,
        ':hasta' => $hasta,
        ':nc'    => DASH_TIPO_NOTA_CREDITO,
    ]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($filas === []) {
        return [];
    }

    $ruts  = array_map(static fn (array $f): string => (string) $f['rut_normalizado'], $filas);
    $mapa  = clienteRepo()->buscarPorRuts($cuentaId, $ruts);

    $salida = [];
    foreach ($filas as $fila) {
        $rut = (string) $fila['rut_normalizado'];
        $salida[] = [
            'rut'         => $rut,
            'razonSocial' => isset($mapa[$rut]) ? (string) $mapa[$rut]['razon_social'] : null,
            'documentos'  => (int) $fila['documentos'],
            'neto'        => (int) $fila['neto'],
        ];
    }

    return $salida;
}

/**
 * Q7. Documentos de produccion en TODA la historia del emisor (sin periodo).
 *
 * Decide el estado vacio del dashboard. Sin filtro de fecha a proposito: hay
 * que distinguir "nunca emitiste nada" (mensaje de bienvenida) de "no emitiste
 * este mes pero tienes historial" (KPIs en cero con comparacion real).
 *
 * ESTA ES LA UNICA CONSULTA DEL DASHBOARD QUE **NO** EXCLUYE LOS RECHAZADOS, Y
 * ES DELIBERADO. No suma ventas: responde "¿emitiste alguna vez?", y emitir es
 * emitir -- el folio de un documento rechazado se quemo igual.
 *
 * Y filtrarla tendria una consecuencia concreta y mala: si devuelve 0, el
 * handler reemplaza el dashboard ENTERO por la pantalla de bienvenida. Un
 * cliente cuyos unicos documentos fueron rechazados veria "Aun no has emitido
 * documentos" y perderia de vista la tarjeta que le explica por que -- se
 * recrearia el mismo punto ciego que esta entrega viene a cerrar, y encima
 * justo con el cliente que mas lo necesita.
 */
function dashTotalHistorico(PDO $pdo, string $rutEmisor): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM dte_emitido WHERE rut_emisor = :rut AND ambiente = 'produccion'"
    );
    $stmt->execute([':rut' => $rutEmisor]);

    return (int) $stmt->fetchColumn();
}

/**
 * Variacion contra el periodo anterior.
 *
 * Si la base es cero NO se calcula porcentaje: no existe "+100%" respecto de
 * nada. Se informa explicitamente que no hay base de comparacion, en vez de
 * mostrar un numero inventado.
 *
 * @return array{tipo:string, texto:string}
 */
function dashDelta(int $actual, int $anterior): array
{
    if ($anterior === 0) {
        return [
            'tipo'  => 'sin_base',
            'texto' => $actual === 0 ? 'sin datos del mes anterior' : 'sin base de comparacion',
        ];
    }

    $pct = (int) round(($actual - $anterior) * 100 / abs($anterior));
    if ($pct === 0) {
        return ['tipo' => 'igual', 'texto' => 'igual que el mes anterior'];
    }

    return [
        'tipo'  => $pct > 0 ? 'sube' : 'baja',
        'texto' => ($pct > 0 ? '+' : '') . $pct . '% vs mes anterior',
    ];
}

/** Razon social del emisor de produccion, para el header de contexto. */
function dashRazonSocialProduccion(PDO $pdo, int $cuentaId): string
{
    $stmt = $pdo->prepare(
        "SELECT razon_social FROM dte_emisor WHERE cuenta_id = :c AND ambiente = 'produccion' LIMIT 1"
    );
    $stmt->execute([':c' => $cuentaId]);

    return (string) ($stmt->fetchColumn() ?: '');
}

// ===========================================================================
//  INFORMES: consultas propias
//
//  Los cinco primeros informes reusan TAL CUAL las funciones dash* de arriba,
//  que NO se modifican: el dashboard y los informes leen exactamente los mismos
//  numeros. Aqui viven solo las dos consultas que el dashboard no tiene.
// ===========================================================================

/**
 * Informe 6. Detalle documento a documento del periodo.
 *
 * Mismo scope que las consultas del dashboard (rut_emisor + produccion + rango)
 * y misma decision de arquitectura: lee dte_emitido DIRECTO, no via el motor.
 *
 * A diferencia de dashTopClientes(), aqui NO se normaliza el RUT en SQL: esta
 * consulta no agrupa, asi que no hace falta que la normalizacion ocurra dentro
 * de un GROUP BY. Se usa Rut::normalizar() en PHP, que es la fuente unica del
 * proyecto, y asi no se duplica la regla en dos lenguajes.
 *
 * LO QUE NO PUEDE MOSTRAR: monto exento. dte_emitido no lo guarda (mismo motivo
 * por el que no aparece en documentos-listado.php). No se estima.
 *
 * @return list<array{tipoDte:int, folio:int, fechaEmision:string, receptorRut:string,
 *                    razonSocial:?string, neto:int, iva:int, total:int, estado:string}>
 */
function informeDetalleDocumentos(
    PDO $pdo,
    int $cuentaId,
    string $rutEmisor,
    string $desde,
    string $hasta
): array {
    $stmt = $pdo->prepare(
        'SELECT tipo_dte, folio, fecha_emision, receptor_rut, neto, iva, total, estado '
        . 'FROM dte_emitido '
        . "WHERE rut_emisor = :rut AND ambiente = 'produccion' "
        . '  AND fecha_emision BETWEEN :desde AND :hasta '
        // Los rechazados no aparecen. Este informe NO es el listado -- lleva
        // una fila de totales al pie, y esa fila tiene que cuadrar con el
        // dashboard. Para ver los rechazados esta el Panel de emision, que si
        // los muestra, y la tarjeta de documentos con problemas.
        . EstadoContable::sqlExcluirRechazados()
        . 'ORDER BY fecha_emision ASC, tipo_dte ASC, folio ASC'
    );
    $stmt->execute([':rut' => $rutEmisor, ':desde' => $desde, ':hasta' => $hasta]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($filas === []) {
        return [];
    }

    // Un mismo receptor puede venir escrito con y sin puntos en filas
    // distintas: normalizar en PHP hace que las dos formas resuelvan la misma
    // razon social contra el maestro.
    $normalizados = [];
    foreach ($filas as $f) {
        $normalizados[] = Rut::normalizar((string) $f['receptor_rut']);
    }
    $mapa = clienteRepo()->buscarPorRuts($cuentaId, $normalizados);

    $salida = [];
    foreach ($filas as $i => $f) {
        $rut = $normalizados[$i];
        $salida[] = [
            'tipoDte'      => (int) $f['tipo_dte'],
            'folio'        => (int) $f['folio'],
            'fechaEmision' => (string) $f['fecha_emision'],
            'receptorRut'  => $rut,
            'razonSocial'  => isset($mapa[$rut]) ? (string) $mapa[$rut]['razon_social'] : null,
            'neto'         => (int) $f['neto'],
            'iva'          => (int) $f['iva'],
            'total'        => (int) $f['total'],
            'estado'       => (string) $f['estado'],
        ];
    }

    return $salida;
}

/**
 * Informe 3. Clientes por facturacion, SIN limite.
 *
 * Hermana de dashTopClientes(), NO su reemplazo. Esa funcion se deja intacta a
 * proposito: su LIMIT 5 es parte de lo que el dashboard promete ("top 5"), y
 * parametrizarla la convertiria en una funcion de dos caras al servicio de dos
 * consumidores con requisitos distintos.
 *
 * Mismo SQL salvo el LIMIT, mismo criterio de signo para notas de credito
 * (DASH_TIPO_NOTA_CREDITO resta) y mismo cruce a buscarPorRuts(). Aqui SI se
 * normaliza en SQL, porque la normalizacion tiene que ocurrir dentro del
 * GROUP BY para que las variantes con y sin puntos caigan en el mismo grupo.
 *
 * @return list<array{rut:string, razonSocial:?string, documentos:int, neto:int}>
 */
function informeClientes(
    PDO $pdo,
    int $cuentaId,
    string $rutEmisor,
    string $desde,
    string $hasta
): array {
    $stmt = $pdo->prepare(
        "SELECT UPPER(REPLACE(REPLACE(TRIM(receptor_rut), '.', ''), ' ', '')) AS rut_normalizado, "
        . '       COUNT(*) AS documentos, '
        . '       SUM(CASE WHEN tipo_dte = :nc THEN -total ELSE total END) AS neto '
        . 'FROM dte_emitido '
        . "WHERE rut_emisor = :rut AND ambiente = 'produccion' "
        . '  AND fecha_emision BETWEEN :desde AND :hasta '
        // Mismo criterio que dashTopClientes(), del que este informe es la
        // version sin LIMIT: si uno filtrara y el otro no, el informe
        // contradiria a la tarjeta que dice resumir.
        . EstadoContable::sqlExcluirRechazados()
        . 'GROUP BY rut_normalizado ORDER BY neto DESC'
    );
    $stmt->execute([
        ':rut'   => $rutEmisor,
        ':desde' => $desde,
        ':hasta' => $hasta,
        ':nc'    => DASH_TIPO_NOTA_CREDITO,
    ]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($filas === []) {
        return [];
    }

    $ruts = array_map(static fn (array $f): string => (string) $f['rut_normalizado'], $filas);
    $mapa = clienteRepo()->buscarPorRuts($cuentaId, $ruts);

    $salida = [];
    foreach ($filas as $fila) {
        $rut = (string) $fila['rut_normalizado'];
        $salida[] = [
            'rut'         => $rut,
            'razonSocial' => isset($mapa[$rut]) ? (string) $mapa[$rut]['razon_social'] : null,
            'documentos'  => (int) $fila['documentos'],
            'neto'        => (int) $fila['neto'],
        ];
    }

    return $salida;
}

// ===========================================================================
//  INFORMES: estructura y salida
// ===========================================================================

/** Miles con punto, sin decimales. Mismo formato que el resto del panel. */
function informeMonto(int|float $v): string
{
    return number_format((float) $v, 0, ',', '.');
}

/**
 * Formatea UNA celda para mostrarla (pantalla o PDF).
 *
 * informeColumnasYFilas() devuelve los valores NUMERICOS EN CRUDO, no como
 * texto ya formateado, y esto es deliberado: si las filas llevaran "100.000",
 * PhpSpreadsheet interpretaria el punto como separador DECIMAL al escribir el
 * .xlsx y la celda terminaria valiendo 100. Se detecto exactamente asi, leyendo
 * de vuelta un Excel descargado.
 *
 * Asi que el numero viaja crudo hasta el ultimo momento: la pantalla y el PDF
 * lo formatean aqui, y el Excel lo escribe como numero y le aplica el formato
 * #,##0 de celda, que es lo que permite sumar en la planilla.
 */
function informeCelda(mixed $valor, bool $esNumerica): string
{
    if (! $esNumerica || $valor === '' || $valor === null) {
        return (string) $valor;
    }

    return informeMonto(is_numeric($valor) ? 0 + $valor : 0);
}

/**
 * FUENTE UNICA de la estructura de cada informe.
 *
 * Devuelve columnas, filas y totales ya formateados como texto. Lo consumen los
 * TRES formatos -- vista previa en pantalla, PDF y Excel -- justamente para que
 * no puedan desincronizarse: si una columna cambia, cambia en los tres a la vez.
 *
 * Los anchos de columna estan en mm y suman ~273 (A4 horizontal menos margenes:
 * 297 - 24). Solo los usa el PDF; la vista y el Excel los ignoran.
 *
 * @param array $datos salida cruda de la consulta correspondiente (dash... o informe...)
 *
 * @return array{columnas:list<array{titulo:string, ancho:float, alineacion:string}>,
 *               filas:list<list<string>>, totales:list<string>|null}
 */
function informeColumnasYFilas(string $clave, array $datos): array
{
    $der = 'R';
    $izq = 'L';

    switch ($clave) {
        case 'facturacion':
            // $datos = salida de dashMetricasPorTipo(): [tipo => [documentos, neto, iva, total]]
            $columnas = [
                ['titulo' => 'Tipo de documento', 'ancho' => 93, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Documentos',        'ancho' => 45, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'Neto',              'ancho' => 45, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'IVA',               'ancho' => 45, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'Total',             'ancho' => 45, 'alineacion' => $der, 'num' => true],
            ];
            $filas = [];
            foreach ($datos as $tipo => $d) {
                $filas[] = [
                    nombreTipoDte((int) $tipo),
                    (int) $d['documentos'],
                    (int) $d['neto'],
                    (int) $d['iva'],
                    (int) $d['total'],
                ];
            }
            // El total NO es una suma plana: dashResumen() aplica la regla de
            // negocio (las notas de credito RESTAN). Se reusa esa funcion en vez
            // de sumar aqui, para que el informe no pueda contradecir al
            // dashboard.
            $r = dashResumen($datos);
            $totales = [
                'Neto del periodo (' . $r['formula'] . ')',
                (int) $r['documentos'],
                (int) $r['netoPeriodo'],
                (int) $r['ivaDebito'],
                '',
            ];
            break;

        case 'ventas-dia':
            // $datos = salida de dashSerieCompleta(): list con fecha/dia/...
            $columnas = [
                ['titulo' => 'Fecha',            'ancho' => 63, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Documentos',       'ancho' => 52, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'Ventas',           'ancho' => 52, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'Notas de credito', 'ancho' => 53, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'Neto',             'ancho' => 53, 'alineacion' => $der, 'num' => true],
            ];
            $filas = [];
            $tDoc = $tVen = $tNc = $tNeto = 0;
            foreach ($datos as $d) {
                $filas[] = [
                    (string) $d['fecha'],
                    (int) $d['documentos'],
                    (int) $d['ventas'],
                    (int) $d['notasCredito'],
                    (int) $d['neto'],
                ];
                $tDoc  += (int) $d['documentos'];
                $tVen  += (int) $d['ventas'];
                $tNc   += (int) $d['notasCredito'];
                $tNeto += (int) $d['neto'];
            }
            $totales = ['Total', $tDoc, $tVen, $tNc, $tNeto];
            break;

        case 'clientes':
            // $datos = salida de informeClientes()
            $columnas = [
                ['titulo' => 'RUT',          'ancho' => 55, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Razon social', 'ancho' => 128, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Documentos',   'ancho' => 45, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'Neto',         'ancho' => 45, 'alineacion' => $der, 'num' => true],
            ];
            $filas = [];
            $tDoc = $tNeto = 0;
            foreach ($datos as $d) {
                $filas[] = [
                    (string) $d['rut'],
                    // null = el receptor no esta en el maestro de clientes. Se
                    // dice, no se inventa un nombre.
                    $d['razonSocial'] ?? 'No esta en tu maestro de clientes',
                    (int) $d['documentos'],
                    (int) $d['neto'],
                ];
                $tDoc  += (int) $d['documentos'];
                $tNeto += (int) $d['neto'];
            }
            $totales = ['Total', '', $tDoc, $tNeto];
            break;

        case 'estados':
            // $datos = salida de dashDistribucionEstado()
            $columnas = [
                ['titulo' => 'Estado en el SII', 'ancho' => 183, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Documentos',       'ancho' => 90, 'alineacion' => $der, 'num' => true],
            ];
            $filas = [];
            $tDoc = 0;
            foreach ($datos as $d) {
                $filas[] = [(string) $d['estado'], (int) $d['documentos']];
                $tDoc += (int) $d['documentos'];
            }
            $totales = ['Total', $tDoc];
            break;

        case 'detalle':
            // $datos = salida de informeDetalleDocumentos()
            $columnas = [
                ['titulo' => 'Fecha',        'ancho' => 26, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Tipo',         'ancho' => 47, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Folio',        'ancho' => 20, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'RUT receptor', 'ancho' => 30, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Razon social', 'ancho' => 70, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Neto',         'ancho' => 27, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'IVA',          'ancho' => 25, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'Total',        'ancho' => 28, 'alineacion' => $der, 'num' => true],
            ];
            $filas = [];
            $tNeto = $tIva = $tTotal = 0;
            foreach ($datos as $d) {
                $filas[] = [
                    (string) $d['fechaEmision'],
                    nombreTipoDte((int) $d['tipoDte']),
                    (int) $d['folio'],
                    (string) $d['receptorRut'],
                    $d['razonSocial'] ?? 'No esta en tu maestro',
                    (int) $d['neto'],
                    (int) $d['iva'],
                    (int) $d['total'],
                ];
                $tNeto  += (int) $d['neto'];
                $tIva   += (int) $d['iva'];
                $tTotal += (int) $d['total'];
            }
            // Suma plana a proposito: es el total de lo LISTADO, no el neto del
            // periodo. Las notas de credito aparecen como fila propia con su
            // monto positivo, igual que en el Panel de emision.
            $totales = ['Total de lo listado', '', '', '', '', $tNeto, $tIva, $tTotal];
            break;

        case 'folios':
            // $datos = salida de dashFoliosPorTipo()
            $columnas = [
                ['titulo' => 'Tipo de documento', 'ancho' => 83, 'alineacion' => $izq, 'num' => false],
                ['titulo' => 'Disponibles',       'ancho' => 40, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'Usados',            'ancho' => 40, 'alineacion' => $der, 'num' => true],
                // No es el rango impreso en el CAF, sino lo que Sinergia puede
                // emitir: en un CAF migrado excluye los folios que el emisor ya
                // habia gastado con su proveedor anterior (ver
                // dashFoliosPorTipo, que agrega sobre proximo_folio_inicial).
                ['titulo' => 'Folios asignados a Sinergia', 'ancho' => 40, 'alineacion' => $der, 'num' => true],
                ['titulo' => 'CAF cargados',      'ancho' => 35, 'alineacion' => $der, 'num' => true],
                ['titulo' => '% disponible',      'ancho' => 35, 'alineacion' => $der, 'num' => false],
            ];
            $filas = [];
            foreach ($datos as $d) {
                $filas[] = [
                    nombreTipoDte((int) $d['tipo']),
                    (int) $d['disponibles'],
                    (int) $d['usados'],
                    (int) $d['totalRango'],
                    (int) $d['cafs'],
                    $d['pctDisponible'] . '%',
                ];
            }
            // Sin fila de totales: sumar folios de tipos distintos no significa
            // nada (no son la misma serie).
            $totales = null;
            break;

        default:
            $columnas = [];
            $filas    = [];
            $totales  = null;
    }

    return ['columnas' => $columnas, 'filas' => $filas, 'totales' => $totales];
}

/**
 * Nombre del archivo de una descarga de informe.
 *
 * El proyecto NO tiene un helper compartido para esto: los seis
 * Content-Disposition que ya existen arman su nombre en linea, cada uno con su
 * convencion. Este helper es solo para informes; unificar los otros seis es una
 * limpieza aparte que no se mezcla con esta tarea.
 */
function nombreArchivoInforme(string $clave, ?string $desde, ?string $hasta, string $ext): string
{
    $sufijo = ($desde !== null && $hasta !== null) ? "{$desde}_{$hasta}" : date('Y-m-d');

    return "informe_{$clave}_{$sufijo}.{$ext}";
}

/**
 * Ejecuta la consulta que corresponde a la clave y devuelve los datos crudos.
 *
 * Las cinco primeras llaman a funciones dash* SIN MODIFICAR: es lo que garantiza
 * que un informe y el dashboard nunca muestren cifras distintas del mismo dato.
 */
function informeDatos(string $clave, PDO $pdo, int $cuentaId, string $rutEmisor, ?string $desde, ?string $hasta): array
{
    return match ($clave) {
        'facturacion' => dashMetricasPorTipo($pdo, $rutEmisor, (string) $desde, (string) $hasta),
        'ventas-dia'  => dashSerieCompleta(
            dashVentasPorDia($pdo, $rutEmisor, (string) $desde, (string) $hasta),
            (string) $desde,
            (string) $hasta
        ),
        'clientes'    => informeClientes($pdo, $cuentaId, $rutEmisor, (string) $desde, (string) $hasta),
        'estados'     => dashDistribucionEstado($pdo, $rutEmisor, (string) $desde, (string) $hasta),
        'detalle'     => informeDetalleDocumentos($pdo, $cuentaId, $rutEmisor, (string) $desde, (string) $hasta),
        'folios'      => dashFoliosPorTipo($pdo, $rutEmisor),
        default       => [],
    };
}

/**
 * Rango efectivo de un informe.
 *
 * Misma validacion que handleAuditoriaGet(): si cualquiera de las dos fechas no
 * es valida, se descartan LAS DOS (un rango a medias filtraria de forma
 * impredecible) y se cae al mes en curso via dashPeriodo().
 *
 * @return array{desde:?string, hasta:?string, etiqueta:string}
 */
function informePeriodo(string $clave): array
{
    if (! (INFORMES[$clave]['periodo'] ?? false)) {
        // El informe no admite periodo: se ignora cualquier desde/hasta que
        // venga por query string en vez de aplicarlo a medias.
        return ['desde' => null, 'hasta' => null, 'etiqueta' => ''];
    }

    $desdeRaw = trim((string) ($_GET['desde'] ?? ''));
    $hastaRaw = trim((string) ($_GET['hasta'] ?? ''));
    $desde    = ($desdeRaw !== '' && fechaValida($desdeRaw)) ? $desdeRaw : null;
    $hasta    = ($hastaRaw !== '' && fechaValida($hastaRaw)) ? $hastaRaw : null;

    if ($desde === null || $hasta === null || $desde > $hasta) {
        $p     = dashPeriodo('actual');
        $desde = $p['desde'];
        $hasta = $p['hasta'];
    }

    return ['desde' => $desde, 'hasta' => $hasta, 'etiqueta' => "{$desde} a {$hasta}"];
}

// ===========================================================================
//  Handlers de informes
//
//  Los tres exigen produccion completa, igual que el Panel de emision: los seis
//  informes leen dte_emitido con ambiente='produccion', asi que un tenant en
//  certificacion no tiene nada que ver aqui.
// ===========================================================================

/** GET /informes -- landing con las seis tarjetas. */
function handleInformesIndexGet(): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    exigirProduccionCompleto($pdo, $cuentaId);

    vista('informes-index', [
        'informes'  => INFORMES,
        'navActivo' => 'informes',
    ]);
}

/** GET /informes/{clave} -- filtros + vista previa. */
function handleInformeGet(string $clave): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    $rut      = exigirProduccionCompleto($pdo, $cuentaId);

    $periodo = informePeriodo($clave);
    $datos   = informeDatos($clave, $pdo, $cuentaId, $rut, $periodo['desde'], $periodo['hasta']);
    $tabla   = informeColumnasYFilas($clave, $datos);

    vista('informe', [
        'clave'       => $clave,
        'definicion'  => INFORMES[$clave],
        'columnas'    => $tabla['columnas'],
        'filas'       => $tabla['filas'],
        'totales'     => $tabla['totales'],
        'desde'       => $periodo['desde'] ?? '',
        'hasta'       => $periodo['hasta'] ?? '',
        'razonSocial' => dashRazonSocialProduccion($pdo, $cuentaId),
        'rutEmisor'   => $rut,
        'navActivo'   => 'informes.' . $clave,
    ]);
}

/** GET /informes/{clave}/pdf y /excel -- descarga. */
function handleInformeDescargaGet(string $clave, string $formato): void
{
    $pdo      = Db::conexion();
    $cuentaId = Auth::cuentaId();
    $rut      = exigirProduccionCompleto($pdo, $cuentaId);

    $periodo = informePeriodo($clave);
    $datos   = informeDatos($clave, $pdo, $cuentaId, $rut, $periodo['desde'], $periodo['hasta']);
    $tabla   = informeColumnasYFilas($clave, $datos);
    $titulo  = INFORMES[$clave]['label'];
    $nombre  = nombreArchivoInforme($clave, $periodo['desde'], $periodo['hasta'], $formato === 'pdf' ? 'pdf' : 'xlsx');

    if ($formato === 'pdf') {
        informePdfSalida(
            $titulo,
            dashRazonSocialProduccion($pdo, $cuentaId),
            $rut,
            $periodo['etiqueta'],
            $tabla,
            $nombre
        );
    }

    informeExcelSalida($titulo, $tabla, $nombre);
}

/**
 * Emite el PDF del informe y termina la request.
 *
 * @param array{columnas:list<array{titulo:string, ancho:float, alineacion:string}>,
 *              filas:list<list<string>>, totales:list<string>|null} $tabla
 */
function informePdfSalida(
    string $titulo,
    string $razonSocial,
    string $rutEmisor,
    string $periodo,
    array $tabla,
    string $nombreArchivo
): never {
    $pdf = new InformePdf($titulo, $razonSocial, $rutEmisor, $periodo);
    $pdf->tabla($tabla['columnas'], $tabla['filas'], $tabla['totales']);

    $binario = $pdf->Output('', 'S');

    header('Content-Type: application/pdf');
    header(sprintf('Content-Disposition: attachment; filename="%s"', $nombreArchivo));
    header('Content-Length: ' . strlen($binario));
    echo $binario;
    exit;
}

/**
 * Emite el .xlsx del informe y termina la request.
 *
 * Mismo patron que handlePlantillaExcelGet(): encabezados en negrita,
 * freezePane bajo la fila 1, autoSize por columna y Content-Disposition de
 * descarga. La diferencia es que aqui van las filas reales del informe.
 *
 * @param array{columnas:list<array{titulo:string, ancho:float, alineacion:string}>,
 *              filas:list<list<string>>, totales:list<string>|null} $tabla
 */
function informeExcelSalida(string $titulo, array $tabla, string $nombreArchivo): never
{
    $libro = new Spreadsheet();
    $hoja  = $libro->getActiveSheet();
    // setTitle limita a 31 caracteres y prohibe algunos simbolos; el label de
    // un informe puede pasarse de largo.
    $hoja->setTitle(substr(str_replace(['/', '\\', '?', '*', ':', '[', ']'], '-', $titulo), 0, 31));

    $encabezados = array_map(static fn (array $c): string => $c['titulo'], $tabla['columnas']);
    $hoja->fromArray($encabezados, null, 'A1');

    $fila = 2;
    foreach ($tabla['filas'] as $f) {
        $hoja->fromArray($f, null, 'A' . $fila);
        $fila++;
    }
    // $fila quedo apuntando a la SIGUIENTE fila libre. La ultima escrita es la
    // anterior, salvo que ademas se escriba la de totales.
    $ultimaFila = $fila - 1;
    if ($tabla['totales'] !== null && $tabla['filas'] !== []) {
        $hoja->fromArray($tabla['totales'], null, 'A' . $fila);
        $ultimaCol = chr(64 + max(1, count($tabla['columnas'])));
        $hoja->getStyle("A{$fila}:{$ultimaCol}{$fila}")->getFont()->setBold(true);
        $ultimaFila = $fila;
    }

    $ultima = chr(64 + max(1, count($tabla['columnas'])));
    $hoja->getStyle("A1:{$ultima}1")->getFont()->setBold(true);
    $hoja->freezePane('A2');

    // Las columnas numericas llevan el valor CRUDO (ver informeCelda()): el
    // separador de miles lo pone Excel como formato de celda, no el texto. Asi
    // la planilla se puede sumar y ordenar, que es el motivo de ofrecer Excel.
    foreach ($tabla['columnas'] as $i => $c) {
        if (! ($c['num'] ?? false)) {
            continue;
        }
        $letra = chr(65 + $i);
        $hoja->getStyle("{$letra}2:{$letra}{$ultimaFila}")
            ->getNumberFormat()
            ->setFormatCode('#,##0');
    }

    foreach (range('A', $ultima) as $col) {
        $hoja->getColumnDimension($col)->setAutoSize(true);
    }

    $libro->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header(sprintf('Content-Disposition: attachment; filename="%s"', $nombreArchivo));
    (new XlsxWriter($libro))->save('php://output');
    exit;
}

// ===========================================================================
//  Handler: GET /panel
//
//  Dos dashboards distintos detras de la misma ruta:
//
//    - Progreso: mientras el tenant NO tenga completos los 3 pasos obligatorios
//      de produccion.
//    - Gestion (metricas reales): cuando los tiene.
//
//  El switch usa EXACTAMENTE la misma condicion que exigirProduccionCompleto()
//  (empresa + certificado + CAF de produccion), via estadoProduccion(). No se
//  inventa un tercer criterio de "esta en produccion". La api_key externa no
//  participa: no bloquea emitir, asi que tampoco puede decidir esto.
//
//  Y DOS STEPPERS distintos dentro del dashboard de progreso, segun por que
//  camino entro el tenant:
//
//    - CERTIFICACION (7 estaciones, el de siempre): el default. Lo ve todo el
//      que tenga fila de certificacion, y tambien el que no tenga ninguna fila
//      -- una cuenta recien creada sigue viendo lo mismo que veia antes.
//    - PRODUCCION (6 filas, estacionesProduccion()): solo el que tiene fila de
//      PRODUCCION y NO tiene de certificacion. Llego ya autorizado por el SII y
//      el circuito de certificacion no le corresponde; hasta ahora se le pintaba
//      igual y ese era el lazo que quedaba abierto.
//
//  SI APARECE LA FILA DE CERTIFICACION, GANA CERTIFICACION. Un tenant que
//  empezo por produccion y despues decide certificarse pasa al stepper de 7:
//  ese circuito es mas largo y ya lleva produccion embebida en su estacion 7,
//  asi que no se pierde nada de lo cargado. El de produccion no tiene donde
//  meter las estaciones 5 y 6.
// ===========================================================================
function handlePanelGet(): void
{
    $cuentaId = Auth::cuentaId();
    $pdo      = Db::conexion();

    $stmt = $pdo->prepare(
        "SELECT 1 FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion' LIMIT 1"
    );
    $stmt->execute([':cuenta_id' => $cuentaId]);
    $tieneEmisor = $stmt->fetchColumn() !== false;

    // PUEDE EMITIR: el MISMO predicado que usan el guard de las rutas operativas
    // (exigirProduccionCompleto) y el menu lateral. Una sola fuente de verdad
    // para las tres pantallas; si divergen, vuelve el bug que ya arreglamos.
    $emision     = estadoEmisionProduccion($pdo, $cuentaId);
    $puedeEmitir = $emision['falta'] === null;

    // POR QUE CAMINO VA ESTE TENANT. No hay columna ni bandera: lo dicen las
    // filas que existen, que es el unico dato que no puede quedar desincronizado
    // con la realidad.
    $rutProduccion    = rutEmisorProduccion($pdo, $cuentaId);
    $caminoProduccion = ! $tieneEmisor && $rutProduccion !== null;

    // TODAVIA NO HAY NINGUNA FILA: no hay camino que inferir, porque el tenant
    // no ha hecho nada de lo que inferirlo. En vez de suponerle uno -- que es lo
    // que hacia el stepper de 7 estaciones, suponiendo certificacion -- se le
    // ofrecen los dos y elige el.
    //
    // Sale por aca antes de calcular la cadena de certificacion entera: ninguna
    // de esas consultas puede devolver otra cosa que false sin fila de emisor, y
    // ninguna de esas estaciones se va a pintar.
    if (! $tieneEmisor && $rutProduccion === null) {
        vista('panel-camino');
    }

    // Estacion 3 (certificado): solo se consulta si la etapa 2 esta completa;
    // requiere el rut_emisor de la cuenta para buscar en dte_certificado.
    $tieneCertificado = false;
    if ($tieneEmisor) {
        $stmtRut = $pdo->prepare(
            "SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = :cuenta_id AND ambiente = 'certificacion' LIMIT 1"
        );
        $stmtRut->execute([':cuenta_id' => $cuentaId]);
        $rutEmisor = $stmtRut->fetchColumn();

        $stmtCert = $pdo->prepare(
            "SELECT 1 FROM dte_certificado WHERE rut_emisor = :rut AND ambiente = 'certificacion' LIMIT 1"
        );
        $stmtCert->execute([':rut' => $rutEmisor]);
        $tieneCertificado = $stmtCert->fetchColumn() !== false;
    }

    // FALLBACK DEL RUT. Hasta aqui $rutEmisor solo existe si hay fila de
    // certificacion. Una empresa que llego YA AUTORIZADA por el SII no la tiene,
    // y sin este fallback su rut quedaba en null: estadoProduccion() no se
    // llamaba, el dashboard no entraba nunca en modo gestion y le mostraba un
    // circuito de certificacion que no le corresponde. El emisor con el que
    // emite de verdad es el de la fila de PRODUCCION, y de ahi sale.
    //
    // El fallback sale de rutEmisorProduccion() y ya no de $emision['rut'].
    // Para quien puede emitir son el mismo dato -- misma fila -- pero
    // $emision['rut'] es null mientras falte algo, y justamente al que le falta
    // algo es al que hay que pintarle su avance. Con la fuente anterior, un
    // tenant con empresa de produccion cargada y sin certificado quedaba otra
    // vez sin rut y sin nada que mostrar.
    if (! isset($rutEmisor) || $rutEmisor === false || $rutEmisor === null) {
        $rutEmisor = $rutProduccion;
    }

    // Estacion 4 (CAF): solo se consulta si la etapa 3 esta completa; reusa
    // $rutEmisor (solo se llega aqui con $tieneCertificado=true si $tieneEmisor
    // ya fue true, asi que $rutEmisor ya quedo asignado arriba).
    $tieneCaf = false;
    if ($tieneCertificado) {
        $stmtCaf = $pdo->prepare(
            "SELECT 1 FROM dte_caf WHERE rut_emisor = :rut AND ambiente = 'certificacion' LIMIT 1"
        );
        $stmtCaf->execute([':rut' => $rutEmisor]);
        $tieneCaf = $stmtCaf->fetchColumn() !== false;
    }

    // Estacion 5 (en certificacion): solo se consulta si hay CAF (etapa 4
    // completa). "Completada" = los 3 componentes que exige el SII estan
    // aprobados -- Set Basico + Libro de Ventas + Libro de Compras -- ver
    // certificacionCompleta(). Misma funcion que usa la guardia de la
    // estacion 6: garantiza que ambas queden consistentes entre si.
    $estacion5Completa = false;
    if ($tieneCaf) {
        $estacion5Completa = certificacionCompleta($pdo, $rutEmisor);
    }

    // Estacion 6 (certificacion aprobada): el SII no informa la aprobacion por
    // webservice, asi que "completada" SOLO refleja la confirmacion explicita
    // del tenant (certificacion_confirmada_at). Bloqueada mientras la estacion
    // 5 no este completa.
    $certConfirmada = false;
    if ($tieneEmisor) {
        $certConfirmada = obtenerCertificacionConfirmadaAt($pdo, $cuentaId) !== null;
    }

    // Estacion 7 (en produccion): mientras la estacion 6 no este confirmada se
    // comporta igual que antes ("Proximamente"), porque los 4 pasos de
    // produccion no tienen sentido antes de que el SII autorice al
    // contribuyente. Ya confirmada, deja de ser un placeholder y muestra el
    // avance real de esos 4 pasos.
    //
    // El estado agregado mira SOLO los 3 obligatorios (los que
    // exigirProduccionCompleto() verifica). La api_key externa no bloquea
    // emitir, asi que no puede impedir que la estacion se vea completa.
    // El estado de produccion se calcula en cuanto hay emisor, porque decide el
    // switch de dashboard. Los SUB-PASOS de la estacion 7, en cambio, solo se
    // muestran con la certificacion ya confirmada: ese criterio no cambia.
    $estacion7 = ['titulo' => 'En produccion', 'estado' => 'inactiva'];

    // Antes esto colgaba de $tieneEmisor -- la fila de CERTIFICACION -- y por eso
    // un tenant preautorizado no llegaba nunca aqui. Ahora basta con tener un rut
    // de emisor, venga de donde venga.
    if ($rutEmisor !== null) {
        $estadoProd = estadoProduccion($pdo, $cuentaId, (string) $rutEmisor);

        if ($certConfirmada) {
            $estacion7 = [
                'titulo'             => 'En produccion',
                'estado'             => $puedeEmitir ? 'completado' : 'pendiente',
                'subpasos'           => subpasosProduccion($estadoProd),
                'obligatoriosListos' => $puedeEmitir,
            ];
        }
    }

    // El stepper de produccion se arma con $estadoProd, que solo existe si hubo
    // rut. $caminoProduccion implica fila de produccion, e implica por lo tanto
    // que el fallback de arriba dejo $rutEmisor no-null: cuando esta rama corre,
    // $estadoProd esta definido.
    if ($caminoProduccion) {
        $estaciones = estacionesProduccion($estadoProd, $puedeEmitir);
    } else {
        $estaciones = [
            ['titulo' => 'Registrado',                        'estado' => 'completado'],
            ['titulo' => 'Datos de empresa cargados',         'estado' => $tieneEmisor ? 'completado' : 'pendiente', 'enlace' => '/empresa'],
            ['titulo' => 'Certificado digital',               'estado' => $tieneCertificado ? 'completado' : ($tieneEmisor ? 'pendiente' : 'inactiva'), 'enlace' => '/certificado'],
            ['titulo' => 'CAF de certificacion',              'estado' => $tieneCaf ? 'completado' : ($tieneCertificado ? 'pendiente' : 'inactiva'), 'enlace' => '/caf'],
            ['titulo' => 'En certificacion (sets de prueba)', 'estado' => $estacion5Completa ? 'completado' : ($tieneCaf ? 'pendiente' : 'inactiva'), 'enlace' => '/certificacion-elegir'],
            ['titulo' => 'Certificacion aprobada',            'estado' => $certConfirmada ? 'completado' : ($estacion5Completa ? 'pendiente' : 'inactiva'), 'enlace' => '/certificacion-aprobada'],
            $estacion7,
        ];
    }

    // --- Dashboard de GESTION: solo con los 3 pasos de produccion completos ---
    //
    // La condicion es la misma que deja emitir. Si el servidor deja emitir, la UI
    // lo dice: nada de mostrar el stepper de certificacion a quien ya opera.
    if ($puedeEmitir) {
        $periodo   = dashPeriodo((string) ($_GET['periodo'] ?? 'actual'));
        $rut       = (string) $rutEmisor;
        $historico = dashTotalHistorico($pdo, $rut);

        // Nunca emitio nada: estado vacio explicito. El progreso de
        // certificacion se conserva colapsado debajo hasta la primera emision,
        // para que el tenant no pierda de vista lo que acaba de completar.
        if ($historico === 0) {
            vista('panel-gestion', [
                'vacio'       => true,
                'contexto'    => [
                    'razonSocial' => dashRazonSocialProduccion($pdo, $cuentaId),
                    'rut'         => $rut,
                    'periodo'     => $periodo,
                ],
                'estaciones'  => $estaciones,
                'resumen'     => null,
                'deltas'      => null,
                'folios'      => dashFoliosPorTipo($pdo, $rut),
                'serie'       => [],
                'estados'     => [],
                'topClientes' => [],
                'rechazados'  => null,
            ]);
        }

        $porTipo     = dashMetricasPorTipo($pdo, $rut, $periodo['desde'], $periodo['hasta']);
        $porTipoPrev = dashMetricasPorTipo($pdo, $rut, $periodo['prevDesde'], $periodo['prevHasta']);
        $resumen     = dashResumen($porTipo);
        $resumenPrev = dashResumen($porTipoPrev);

        vista('panel-gestion', [
            'vacio'    => false,
            'contexto' => [
                'razonSocial' => dashRazonSocialProduccion($pdo, $cuentaId),
                'rut'         => $rut,
                'periodo'     => $periodo,
            ],
            'estaciones' => $estaciones,
            'resumen'    => $resumen,
            'deltas'     => [
                'documentos' => dashDelta($resumen['documentos'], $resumenPrev['documentos']),
                'neto'       => dashDelta($resumen['netoPeriodo'], $resumenPrev['netoPeriodo']),
                'iva'        => dashDelta($resumen['ivaDebito'], $resumenPrev['ivaDebito']),
            ],
            'folios'      => dashFoliosPorTipo($pdo, $rut),
            'serie'       => dashSerieCompleta(
                dashVentasPorDia($pdo, $rut, $periodo['desde'], $periodo['hasta']),
                $periodo['desde'],
                $periodo['hasta']
            ),
            'estados'     => dashDistribucionEstado($pdo, $rut, $periodo['desde'], $periodo['hasta']),
            'topClientes' => dashTopClientes($pdo, $cuentaId, $rut, $periodo['desde'], $periodo['hasta']),
            // LA CONTRAPARTIDA DE EXCLUIR: lo que se saco del total, con su
            // monto. Se calcula con la MISMA consulta y la MISMA funcion de
            // resumen que los KPI, solo que con el filtro invertido, asi que por
            // construccion es exactamente la diferencia entre el total de antes
            // y el de ahora. Si se calculara aparte podrian no cuadrar, y un
            // descuadre aqui seria peor que no mostrar nada.
            'rechazados'  => dashResumen(
                dashMetricasPorTipo($pdo, $rut, $periodo['desde'], $periodo['hasta'], true)
            ),
        ]);
    }

    // "Credenciales de API" no es una estacion numerada del ciclo de 7: es una
    // seccion aparte que solo aparece cuando el onboarding base esta completo.
    //
    // DESACOPLADA DEL CAF DE CERTIFICACION. Antes la condicion era $tieneCaf a
    // secas, o sea un hecho del circuito de certificacion decidiendo una seccion
    // que nada tiene que ver con el ambiente. En el camino de produccion esa
    // pregunta no significa nada -- $tieneCaf es false siempre, porque no hay
    // fila de certificacion de la que colgar -- asi que la tarjeta quedaba
    // apagada por accidente y no por criterio.
    //
    // Ahora cada camino responde lo suyo: en certificacion sigue siendo
    // exactamente $tieneCaf, el criterio de siempre; en produccion la tarjeta
    // sobra, porque la fila 5 del stepper YA es el acceso a las API keys de
    // produccion, y dos accesos a lo mismo en la misma pantalla solo confunden.
    $mostrarApiKeys = $caminoProduccion ? false : $tieneCaf;

    vista('panel', ['estaciones' => $estaciones, 'mostrarApiKeys' => $mostrarApiKeys]);
}
