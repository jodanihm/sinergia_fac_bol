<?php

declare(strict_types=1);

/**
 * Bitacora del panel de control: una fila por peticion a /admin/*.
 *
 * QUE PREGUNTA RESPONDE, Y POR QUE NO LA RESPONDIA admin_auditoria. Aquella
 * tabla (migracion 011, pantalla /admin/auditoria) guarda QUE CAMBIO: la
 * accion, la entidad y el antes/despues en JSON. Esta guarda QUE SE HIZO:
 * cada pantalla que se abrio, cuando, desde donde y como termino. Son dos
 * preguntas distintas y esta clase no toca la primera.
 *
 * La diferencia importa porque en ESTE panel mirar ya es un acto con
 * consecuencias: /admin/tenants/{id} muestra los datos de un contribuyente que
 * no es el suyo, /admin/base-datos el esquema entero, /admin/tenants/{id}/ver
 * deja recorrer el panel de una empresa cliente. Un registro que solo anota los
 * cambios da por no ocurrido todo eso -- y se nota en el numero: admin_auditoria
 * lleva 6 filas desde julio, no porque el panel se use poco, sino porque solo
 * seis de las cosas que se hacen ahi son "cambios".
 *
 * NUNCA GUARDA EL CUERPO DE LA PETICION NI LA RESPUESTA. Por el cuerpo viajan
 * las claves de POST /admin/login y los datos de alta de una cuenta; por la
 * respuesta, los datos de los contribuyentes que esta bitacora existe para
 * proteger. Se guarda el metodo, la ruta y el query string filtrado: alcanza
 * para decir que se hizo y no reproduce nada de lo que se escribio ni de lo que
 * se vio.
 *
 * LA BITACORA NO PUEDE VOLTEAR EL PANEL. El INSERT corre en un shutdown, con la
 * pagina ya entregada, y quien la llama atrapa cualquier Throwable y lo manda al
 * error_log. Que no se pueda registrar es un problema; que no se pueda ENTRAR
 * por eso seria peor. Por eso registrar() no traga sus propios errores: los deja
 * salir para que el unico lugar que decide que hacer con ellos sea el router.
 */
final class ActividadAdmin
{
    /** Todo lo que cuelga de aqui se registra. */
    public const PREFIJO = '/admin';

    /**
     * Claves cuyo VALOR nunca se guarda, ni siquiera un pedazo.
     *
     * Hoy ninguna ruta de /admin/* manda algo asi por la URL. La lista esta
     * para la que se escriba manana: un parametro sensible en un query string
     * termina en los logs de nginx, en el historial del navegador y -- sin
     * esto -- en una tabla que se consulta desde una pantalla.
     */
    private const CLAVES_SENSIBLES = ['token', 'clave', 'password', 'contrasena', 'secreto', 'csrf', 'key'];

    /** Tope de la columna 'parametros'. Se recorta con marca visible. */
    private const MAX_PARAMETROS = 500;

    /** Tope de la columna 'ruta'. */
    private const MAX_RUTA = 255;

    /** Si la ruta pertenece al panel de control. */
    public static function esDelPanel(string $ruta): bool
    {
        // El router ya quito la barra final, asi que '/admin' entra por la
        // igualdad y '/admin/loquesea' por el prefijo CON barra. Sin esa barra,
        // una ruta futura tipo /administracion quedaria registrada aqui sin que
        // nadie lo hubiera pedido.
        return $ruta === self::PREFIJO || str_starts_with($ruta, self::PREFIJO . '/');
    }

    /**
     * 'accion' si la peticion puede cambiar algo, 'lectura' si no.
     *
     * EL METODO ES EL CRITERIO COMPLETO en este router, y no es una suposicion:
     * es la misma propiedad sobre la que se apoya el corte central por cuenta
     * demo (ver panel/public/index.php) -- toda mutacion de estado es un POST y
     * los GET renderizan o listan.
     */
    public static function efecto(string $metodo): string
    {
        return in_array(strtoupper($metodo), ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'accion' : 'lectura';
    }

    /**
     * El query string listo para guardar: sin valores sensibles y acotado.
     *
     * Se guarda porque es lo que separa "abrio la ficha de la cuenta 3" de
     * "abrio la 7", o una busqueda de otra. Se decodifica para que se lea como
     * lo que es y no como %2F%2E%2E.
     */
    public static function parametros(string $queryString): string
    {
        if (trim($queryString) === '') {
            return '';
        }

        $partes = [];
        foreach (explode('&', $queryString) as $par) {
            if ($par === '') {
                continue;
            }

            [$clave, $valor] = array_pad(explode('=', $par, 2), 2, '');
            $clave = urldecode($clave);
            $valor = urldecode($valor);

            foreach (self::CLAVES_SENSIBLES as $sensible) {
                if (stripos($clave, $sensible) !== false) {
                    $valor = '(oculto)';
                    break;
                }
            }

            $partes[] = $valor === '' ? $clave : $clave . '=' . $valor;
        }

        $texto = implode('&', $partes);

        // El corte se MARCA. Un valor recortado en silencio se lee despues como
        // el valor completo, y en una auditoria eso es peor que no tenerlo.
        return strlen($texto) > self::MAX_PARAMETROS
            ? substr($texto, 0, self::MAX_PARAMETROS - 3) . '...'
            : $texto;
    }

    /**
     * La IP de quien pidio, o null si no se puede afirmar cual es.
     *
     * EL ORDEN NO ES CAPRICHOSO. Este panel se sirve detras del tunel de
     * Cloudflare: REMOTE_ADDR es siempre la punta del tunel -- la misma para
     * todos --, asi que guardarla sola seria guardar una constante. La que vale
     * es CF-Connecting-IP, que pone Cloudflare y no viaja desde el cliente.
     *
     * X-Forwarded-For se acepta despues y SOLO su primer valor: la cabecera es
     * una lista y los proxys van agregando al final. Se valida con
     * FILTER_VALIDATE_IP porque las tres son texto que llega de afuera; una
     * cabecera falsificada mete texto arbitrario en una tabla de auditoria.
     */
    public static function ip(array $server): ?string
    {
        $candidatos = [
            (string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''),
            explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))[0],
            (string) ($server['REMOTE_ADDR'] ?? ''),
        ];

        foreach ($candidatos as $candidato) {
            $candidato = trim($candidato);

            if ($candidato !== '' && filter_var($candidato, FILTER_VALIDATE_IP) !== false) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * Escribe la fila. UN SOLO INSERT, sin UPDATE posible: append-only.
     *
     * NO ATRAPA SUS ERRORES a proposito -- ver la cabecera de la clase: quien
     * llama decide, y hoy el router los manda al error_log para que una
     * bitacora caida no impida entrar al panel.
     */
    public static function registrar(
        PDO $pdo,
        ?int $usuarioId,
        string $metodo,
        string $ruta,
        string $queryString,
        int $http,
        int $ms,
        ?string $ip
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_actividad (usuario_id, metodo, ruta, parametros, efecto, http, ms, ip) '
            . 'VALUES (:usuario_id, :metodo, :ruta, :parametros, :efecto, :http, :ms, :ip)'
        );

        $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':metodo'     => substr(strtoupper($metodo), 0, 10),
            ':ruta'       => substr($ruta, 0, self::MAX_RUTA),
            ':parametros' => self::parametros($queryString),
            ':efecto'     => self::efecto($metodo),
            ':http'       => $http,
            ':ms'         => $ms,
            ':ip'         => $ip,
        ]);
    }

    /** La clase CSS del codigo HTTP, para que la lista pinte igual que el resto. */
    public static function claseHttp(int $http): string
    {
        if ($http >= 500) {
            return 'err';
        }
        if ($http >= 400) {
            return 'warn';
        }

        return $http >= 200 && $http < 400 ? 'ok' : '';
    }
}
