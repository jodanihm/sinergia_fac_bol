<?php

declare(strict_types=1);

/**
 * Sonda de integraciones: golpea un endpoint de solo lectura y clasifica lo que
 * vuelve.
 *
 * LA REGLA QUE MANDA SOBRE TODAS: PROBAR NO PUEDE TENER EFECTOS. Nada de emitir
 * un documento, mandar un correo de prueba, consumir una semilla del SII ni
 * gastar una consulta de un plan pagado. Cada endpoint que se golpea esta
 * declarado en panel/datos/integraciones.php y elegido con ese criterio; esta
 * clase no inventa URLs ni metodos: siempre GET, siempre el que dice el
 * catalogo.
 *
 * DOS CLASES DE OK QUE NO SIGNIFICAN LO MISMO, y por eso el veredicto las
 * nombra distinto en vez de pintar dos verdes iguales:
 *
 *   'autenticada' -> un OK dice "la credencial sirve". Se pudo mandar la
 *                    credencial real contra un endpoint inofensivo.
 *   'alcance'     -> un OK dice SOLO "el host contesto". No se probo ninguna
 *                    credencial. Un panel que muestre esto como "todo bien"
 *                    esta mintiendo, y la mentira se descubre el dia que hay
 *                    que emitir.
 *
 * PARA 'alcance', CUALQUIER RESPUESTA HTTP ES UN EXITO, incluido un 500. La
 * pregunta es "llego y volvio algo", no "existe el endpoint que elegi": los
 * hosts del SII contestan 500 en la raiz y 404 en pangal/rahue estando sanos.
 * Lo que SI es una falla es no obtener respuesta: DNS que no resuelve, TLS que
 * no cierra, timeout.
 *
 * LA CREDENCIAL NO SALE DE AQUI NUNCA. Se lee del entorno, se pone en la
 * cabecera y se descarta. No se devuelve, no se registra, no se escribe en el
 * mensaje de error -- ni siquiera un pedazo. Lo unico que sale es si esta
 * puesta y cuantos caracteres tiene, que alcanza para detectar la falla mas
 * comun (una clave cortada al copiarla) sin revelar nada util.
 *
 * EL TIMEOUT ES CORTO Y NO ES NEGOCIABLE. Esta sonda corre dentro de un request
 * de php-fpm que tiene a alguien esperando del otro lado. Un servicio caido que
 * no cierra la conexion dejaria la pantalla colgada hasta el timeout del
 * navegador, y quien mira concluiria que el roto es el panel.
 */
final class Integraciones
{
    /** Segundos que se espera, como mucho, por una sonda. */
    public const TIMEOUT = 8;

    /** Segundos para establecer la conexion (DNS + TCP + TLS). */
    public const TIMEOUT_CONEXION = 4;

    /**
     * Prueba una integracion y devuelve el veredicto.
     *
     * @param array<string, mixed> $integracion una entrada del catalogo
     * @return array{estado:string, titulo:string, detalle:string, http:?int, ms:int}
     */
    public static function probar(array $integracion, ?callable $peticion = null): array
    {
        $sonda = $integracion['sonda'] ?? null;

        if (! is_array($sonda)) {
            return self::veredicto('sin_sonda', 'No se puede probar sin efectos',
                'Esta integracion no ofrece ningun endpoint de solo lectura que se pueda golpear sin '
                . 'consecuencias, asi que no hay boton: un diagnostico que emite un documento o gasta cupo '
                . 'no es un diagnostico.', null, 0);
        }

        $tipo = (string) $sonda['tipo'];
        $url  = self::resolverUrl((string) $sonda['url']);

        if ($url === null) {
            return self::veredicto('sin_config', 'Falta configuracion',
                'La sonda apunta a una variable de entorno que no esta definida en este contenedor.', null, 0);
        }

        $cabeceras = [];

        // Una sonda autenticada SIN credencial no se manda: daria un 401 que se
        // lee como "el servicio rechaza la clave" cuando en realidad no hay
        // clave. Son dos problemas distintos y llevan a buscar en dos lugares
        // distintos.
        if (($sonda['auth'] ?? null) !== null) {
            $credencial = self::credencial($integracion);

            if ($credencial === null) {
                return self::veredicto('sin_credencial', 'Falta la credencial',
                    'La variable ' . (string) $integracion['credencial'] . ' no esta definida en este '
                    . 'contenedor, asi que no hay nada que probar. No se mando ninguna peticion.', null, 0);
            }

            $cabeceras[] = $sonda['auth'] === 'bearer'
                ? 'Authorization: Bearer ' . $credencial
                : substr((string) $sonda['auth'], strlen('header:')) . ': ' . $credencial;
        }

        $peticion ??= self::class . '::pedir';
        $inicio   = microtime(true);
        [$http, $error] = $peticion($url, $cabeceras);
        $ms       = (int) round((microtime(true) - $inicio) * 1000);

        return self::clasificar($tipo, $http, $error, $ms);
    }

    /**
     * El veredicto a partir de lo que devolvio la peticion.
     *
     * @return array{estado:string, titulo:string, detalle:string, http:?int, ms:int}
     */
    public static function clasificar(string $tipo, ?int $http, string $error, int $ms): array
    {
        // Sin respuesta: no hay codigo HTTP que interpretar. Vale igual para
        // los dos tipos de sonda y es la unica falla inequivoca.
        if ($http === null || $http === 0) {
            return self::veredicto('caida', 'No responde',
                'No se obtuvo ninguna respuesta' . ($error !== '' ? ': ' . $error : '')
                . '. Puede ser el servicio caido, un problema de red del servidor, o un dominio que dejo '
                . 'de resolver.', null, $ms);
        }

        if ($tipo === 'alcance') {
            // Cualquier codigo cuenta: la pregunta era si el host contesta.
            return self::veredicto('alcanzado', 'Responde',
                "El host contesto (HTTP {$http}). Esto NO comprueba ninguna credencial: dice que hay red, "
                . 'que el TLS cierra y que del otro lado hay algo vivo.', $http, $ms);
        }

        if ($http >= 200 && $http < 300) {
            return self::veredicto('ok', 'Conexion correcta',
                "El servicio respondio HTTP {$http} a una peticion autenticada de solo lectura: la "
                . 'credencial sirve.', $http, $ms);
        }

        if ($http === 401 || $http === 403) {
            return self::veredicto('rechazada', 'Credencial rechazada',
                "El servicio contesta (HTTP {$http}), o sea que hay red, pero no acepta la credencial. "
                . 'Suele ser una clave vencida, revocada o de otro ambiente.', $http, $ms);
        }

        return self::veredicto('raro', 'Respuesta inesperada',
            "El servicio contesto HTTP {$http}, que no es ni un exito ni un rechazo de credencial. "
            . 'Puede ser un corte parcial del proveedor o un cambio en su API.', $http, $ms);
    }

    /**
     * Si la credencial esta puesta, y de que largo. NUNCA su valor.
     *
     * El largo alcanza para ver la falla mas comun -- una clave cortada al
     * copiarla o pegada con un salto de linea adentro -- y no revela nada
     * aprovechable.
     *
     * @param array<string, mixed> $integracion
     * @return array{puesta:bool, largo:int}
     */
    public static function estadoCredencial(array $integracion): array
    {
        $credencial = self::credencial($integracion);

        return ['puesta' => $credencial !== null, 'largo' => $credencial === null ? 0 : strlen($credencial)];
    }

    /** La clase CSS del semaforo, para que la lista y el flash pinten igual. */
    public static function claseEstado(string $estado): string
    {
        return match ($estado) {
            'ok', 'alcanzado'         => 'ok',
            'caida', 'rechazada'      => 'err',
            'raro', 'sin_credencial'  => 'warn',
            default                   => '',
        };
    }

    /**
     * @param array<string, mixed> $integracion
     */
    private static function credencial(array $integracion): ?string
    {
        $nombre = $integracion['credencial'] ?? null;

        if (! is_string($nombre) || $nombre === '') {
            return null;
        }

        $valor = getenv($nombre);

        return ($valor === false || $valor === '') ? null : $valor;
    }

    /**
     * Reemplaza {VAR} por la variable de entorno, para la sonda del motor, cuya
     * direccion depende del compose y no se puede escribir fija.
     */
    private static function resolverUrl(string $url): ?string
    {
        if (preg_match('/^\{([A-Z_][A-Z0-9_]*)\}(.*)$/', $url, $m) !== 1) {
            return $url;
        }

        $valor = getenv($m[1]);

        return ($valor === false || $valor === '') ? null : rtrim($valor, '/') . $m[2];
    }

    /**
     * La peticion de verdad. SIEMPRE GET, sin seguir redirecciones y sin cuerpo:
     * un GET a un endpoint declarado de solo lectura es lo mas inofensivo que se
     * puede hacer, y no seguir redirecciones evita mandar la credencial a un
     * host distinto del que se quiso golpear.
     *
     * @param list<string> $cabeceras
     * @return array{0:?int, 1:string}
     */
    private static function pedir(string $url, array $cabeceras): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return [null, 'no se pudo inicializar la peticion'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONEXION,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => $cabeceras,
            // El cuerpo no se usa para nada, pero pedir NOBODY convierte esto en
            // un HEAD y hay servicios que lo responden distinto (o no lo
            // responden). Se pide el GET y se descarta lo que vuelve.
            CURLOPT_NOBODY         => false,
        ]);

        curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        return [$http === 0 ? null : $http, $error];
    }

    /**
     * @return array{estado:string, titulo:string, detalle:string, http:?int, ms:int}
     */
    private static function veredicto(string $estado, string $titulo, string $detalle, ?int $http, int $ms): array
    {
        return ['estado' => $estado, 'titulo' => $titulo, 'detalle' => $detalle, 'http' => $http, 'ms' => $ms];
    }
}
