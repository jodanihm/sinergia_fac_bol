<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use Plantiflex\FacturacionCl\Sii\Rut;

/**
 * Alta o actualizacion de UN cliente del maestro por RUT, para sistemas
 * externos que tienen su propia lista de clientes y la quieren reflejar aqui
 * (el primero: el panel superadmin de EasyAgenda, que factura a sus centros).
 *
 * La usa PUT /api/v1/clientes/{rut} del motor. Vive fuera de public/index.php
 * por la misma razon que el resto de integration/: el front controller no se
 * puede incluir en un test sin despachar una ruta, y la regla de mezcla es
 * justo lo que hay que probar.
 *
 * LA REGLA QUE IMPORTA: CAMPO AUSENTE NO SE TOCA.
 * El sistema que sincroniza casi nunca tiene TODOS los datos. EasyAgenda, por
 * ejemplo, no guarda giro ni comuna. Si la sincronizacion reescribiera la fila
 * entera, cada pasada borraria el giro que alguien cargo a mano en el panel, y
 * sin giro la factura no se puede emitir (ver CLIENTE_CAMPOS_PARA_FACTURAR en
 * el panel). Por eso:
 *   - clave ausente del JSON     -> el valor guardado queda como esta
 *   - clave presente con null/'' -> se borra (queda NULL)
 *   - clave presente con texto   -> se escribe
 * razonSocial es la excepcion: es obligatoria en la tabla, asi que no se puede
 * borrar, y es obligatoria para CREAR.
 *
 * pago_link NO es sincronizable: es una preferencia de cobro de esta cuenta
 * sobre ese cliente, no un dato del cliente. Un alta nueva toma el default de
 * la tabla (1) y una actualizacion conserva lo que ya habia.
 *
 * SIN CAMBIOS = SIN ESCRITURA. Si lo que llega es igual a lo guardado no se
 * hace UPDATE: una sincronizacion diaria de cien clientes no debe mover el
 * updated_at de los cien, que es lo que dice cuando alguien toco la ficha.
 */
final class SincronizacionCliente
{
    /**
     * Nombre en el JSON del API => [columna en la tabla, largo maximo].
     * Los largos son los de las columnas (migraciones 015 y 057): validar aqui
     * evita que el strict mode de MySQL responda con un 500 a un dato largo.
     */
    public const CAMPOS = [
        'razonSocial'    => ['razon_social', 255],
        'nombreFantasia' => ['nombre_fantasia', 255],
        'giro'           => ['giro', 255],
        'direccion'      => ['direccion', 255],
        'comuna'         => ['comuna', 100],
        'email'          => ['email', 255],
        'telefono'       => ['telefono', 50],
    ];

    /** Columnas que compara plan() para decidir si hay algo que escribir. */
    private const COLUMNAS_EDITABLES = [
        'rut_cliente', 'razon_social', 'nombre_fantasia', 'giro', 'direccion', 'comuna', 'email', 'telefono',
    ];

    /**
     * Valida la entrada de un PUT. No toca la base.
     *
     * El RUT manda el de la RUTA; si el cuerpo trae 'rut' tiene que ser el
     * mismo, para que un error al armar la URL no termine escribiendo los datos
     * de un cliente en la ficha de otro.
     *
     * @param array<mixed> $cuerpo JSON ya decodificado
     *
     * @return array{rut:string, campos:array<string,?string>, activo:?bool, errores:array<string,string>}
     *         campos: SOLO los presentes en el cuerpo, por nombre de columna, ya
     *         recortados y con '' convertido en null.
     */
    public static function validar(string $rutDeLaRuta, array $cuerpo): array
    {
        $errores = [];

        $rut = Rut::normalizar($rutDeLaRuta);
        if (! Rut::valido($rut)) {
            $errores['rut'] = 'RUT invalido (formato NNNNNNNN-DV, digito verificador incorrecto).';
        }
        if (array_key_exists('rut', $cuerpo)) {
            if (! is_string($cuerpo['rut']) || Rut::normalizar($cuerpo['rut']) !== $rut) {
                $errores['rut'] = 'El RUT del cuerpo no coincide con el de la URL.';
            }
        }

        $campos = [];
        foreach (self::CAMPOS as $clave => [$columna, $largo]) {
            if (! array_key_exists($clave, $cuerpo)) {
                continue;
            }
            $v = $cuerpo[$clave];
            if ($v !== null && ! is_string($v)) {
                $errores[$clave] = "{$clave} debe ser texto o null.";
                continue;
            }
            $v = $v === null ? '' : trim($v);
            if (mb_strlen($v) > $largo) {
                $errores[$clave] = "{$clave} supera los {$largo} caracteres.";
                continue;
            }
            $campos[$columna] = $v === '' ? null : $v;
        }

        if (array_key_exists('razon_social', $campos) && $campos['razon_social'] === null) {
            $errores['razonSocial'] = 'La razon social no puede quedar vacia.';
        }
        if (($campos['email'] ?? null) !== null && ! filter_var($campos['email'], FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'El email no tiene un formato valido.';
        }

        $activo = null;
        if (array_key_exists('activo', $cuerpo)) {
            if (! is_bool($cuerpo['activo'])) {
                $errores['activo'] = 'activo debe ser true o false.';
            } else {
                $activo = $cuerpo['activo'];
            }
        }

        // Campos que este endpoint no conoce: se rechazan en vez de ignorarse,
        // para que un nombre mal escrito ('razon_social' en vez de 'razonSocial')
        // no se pierda en silencio y la ficha quede sin el dato.
        $conocidas = [...array_keys(self::CAMPOS), 'rut', 'activo'];
        foreach (array_keys($cuerpo) as $clave) {
            if (! in_array($clave, $conocidas, true)) {
                $errores[(string) $clave] = 'Campo desconocido. Validos: ' . implode(', ', $conocidas) . '.';
            }
        }

        return ['rut' => $rut, 'campos' => $campos, 'activo' => $activo, 'errores' => $errores];
    }

    /**
     * Decide que hacer, sin tocar la base: crear, actualizar o nada.
     *
     * @param array<string,mixed>|null $existente fila de MySqlClienteRepository (o null si no hay)
     * @param array<string,?string>    $campos    salida 'campos' de validar()
     *
     * @return array{accion:'crear'|'actualizar'|'sin_cambios', datos:array<string,mixed>, cambiarActivo:?bool, error:?string}
     *         datos: fila COMPLETA para crear()/actualizar() del repositorio.
     *         cambiarActivo: true/false si hay que activar/desactivar, null si no.
     */
    public static function plan(?array $existente, string $rut, array $campos, ?bool $activo): array
    {
        if ($existente === null) {
            if (($campos['razon_social'] ?? null) === null) {
                return ['accion' => 'crear', 'datos' => [], 'cambiarActivo' => null,
                        'error' => 'razonSocial es obligatoria para dar de alta un cliente nuevo.'];
            }
            $datos = ['rut_cliente' => $rut] + $campos;
            // Un alta que llega ya inactiva se crea y se desactiva: la tabla no
            // recibe 'activo' en el INSERT (nace en 1).
            return ['accion' => 'crear', 'datos' => $datos,
                    'cambiarActivo' => $activo === false ? false : null, 'error' => null];
        }

        $datos = $existente;
        foreach ($campos as $columna => $valor) {
            $datos[$columna] = $valor;
        }
        $datos['rut_cliente'] = $rut;

        $hayCambios = false;
        foreach (self::COLUMNAS_EDITABLES as $c) {
            if (($datos[$c] ?? null) !== ($existente[$c] ?? null)) {
                $hayCambios = true;
                break;
            }
        }

        $cambiarActivo = ($activo !== null && $activo !== (bool) $existente['activo']) ? $activo : null;

        if (! $hayCambios) {
            return ['accion' => $cambiarActivo === null ? 'sin_cambios' : 'actualizar',
                    'datos' => [], 'cambiarActivo' => $cambiarActivo, 'error' => null];
        }

        return ['accion' => 'actualizar', 'datos' => $datos, 'cambiarActivo' => $cambiarActivo, 'error' => null];
    }

    /**
     * Ejecuta el plan contra el repositorio. Devuelve la accion hecha y la fila
     * final, o lanza \InvalidArgumentException con el mensaje de plan().
     *
     * CARRERA EN EL ALTA: si dos PUT del mismo RUT nuevo llegan a la vez, el
     * segundo INSERT choca con el UNIQUE (cuenta_id, rut_cliente). En vez de
     * devolver error se relee y se vuelve a planear, que ahora sale como
     * actualizacion. Un solo reintento: el UNIQUE garantiza que despues del
     * choque la fila existe.
     *
     * @param array<string,?string> $campos
     *
     * @return array{accion:string, cliente:array<string,mixed>}
     */
    public static function aplicar(
        MySqlClienteRepository $repo,
        int $cuentaId,
        string $rut,
        array $campos,
        ?bool $activo,
    ): array {
        for ($intento = 0; $intento < 2; $intento++) {
            $existente = $repo->buscarPorRut($cuentaId, $rut);
            $plan      = self::plan($existente, $rut, $campos, $activo);
            if ($plan['error'] !== null) {
                throw new \InvalidArgumentException($plan['error']);
            }

            if ($plan['accion'] === 'crear') {
                try {
                    $id = $repo->crear($cuentaId, $plan['datos']);
                } catch (ClienteDuplicadoException) {
                    continue;
                }
            } else {
                $id = (int) $existente['id'];
                if ($plan['datos'] !== []) {
                    $repo->actualizar($cuentaId, $id, $plan['datos']);
                }
            }

            if ($plan['cambiarActivo'] === true) {
                $repo->activar($cuentaId, $id);
            } elseif ($plan['cambiarActivo'] === false) {
                $repo->desactivar($cuentaId, $id);
            }

            return ['accion' => $plan['accion'], 'cliente' => (array) $repo->buscarPorId($cuentaId, $id)];
        }

        throw new \RuntimeException('No se pudo dar de alta el cliente: el RUT aparecio y desaparecio entre dos lecturas.');
    }

    /**
     * Fila del repositorio -> forma publica del API (camelCase, sin pago_link
     * ni cuenta: el llamador no necesita saber de que cuenta es).
     *
     * @param array<string,mixed> $c
     *
     * @return array<string,mixed>
     */
    public static function aJson(array $c): array
    {
        return [
            'id'             => $c['id'],
            'rut'            => $c['rut_cliente'],
            'razonSocial'    => $c['razon_social'],
            'nombreFantasia' => $c['nombre_fantasia'],
            'giro'           => $c['giro'],
            'direccion'      => $c['direccion'],
            'comuna'         => $c['comuna'],
            'email'          => $c['email'],
            'telefono'       => $c['telefono'],
            'activo'         => $c['activo'],
            // Misma regla que SQL_INCOMPLETO del repositorio y que
            // CLIENTE_CAMPOS_PARA_FACTURAR del panel: filas viejas traen '' en
            // vez de NULL, y las dos cosas significan "falta".
            'listoParaFacturar' => trim((string) ($c['giro'] ?? '')) !== ''
                && trim((string) ($c['direccion'] ?? '')) !== ''
                && trim((string) ($c['comuna'] ?? '')) !== '',
            'actualizadoEn'  => $c['updated_at'],
        ];
    }
}
