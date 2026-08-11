<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use DateTimeImmutable;
use PDO;
use Plantiflex\FacturacionCl\Sii\EstadoContable;

/**
 * Consulta de ventas por PERILLAS, para el chat de consultas.
 *
 * =============================================================================
 * LA IA NO ESCRIBE SQL. ELIGE PERILLAS.
 * =============================================================================
 *
 * Esta clase recibe cinco perillas -- metrica, agrupacion, periodo, orden y
 * limite -- y arma la consulta ella misma. El peor caso de una eleccion mala es
 * un numero que contesta otra pregunta, no una tabla borrada ni datos de otro
 * tenant.
 *
 * EL cuenta_id LO PONE ESTA CLASE, NUNCA EL LLAMADOR NI EL MODELO. Y hay DOS
 * aislamientos, uno por tabla, porque dte_emitido no tiene cuenta_id:
 *
 *   1. dte_emitido se filtra por rut_emisor + ambiente='produccion'. El RUT lo
 *      resuelve esta clase desde cuenta_id contra dte_emisor, igual que hace
 *      rutEmisorProduccion() en el panel.
 *   2. El maestro cliente -- de donde sale el NOMBRE -- se consulta con
 *      buscarPorRuts($cuentaId, ...), que ya lleva cuenta_id en su WHERE. El
 *      nombre de un cliente de otra cuenta no puede filtrarse aunque el RUT
 *      coincidiera. Es el mismo par que usa dashTopClientes().
 *
 * =============================================================================
 * LOS DOS FILTROS QUE HACEN QUE EL NUMERO SEA EL MISMO DEL DASHBOARD
 * =============================================================================
 *
 * No basta con uno, y esto es lo que decide si el chat se puede desplegar: un
 * chat que diga un total distinto del que muestra el dashboard es peor que no
 * tenerlo.
 *
 *   (a) EXCLUIR LOS RECHAZADOS -> EstadoContable::sqlExcluirRechazados(), REUSADO
 *       VERBATIM. No se copia la lista: medido en produccion, 69 de 145
 *       documentos estaban en RCT y el dashboard mostraba el DOBLE EXACTO de lo
 *       vendido.
 *   (b) LA NOTA DE CREDITO RESTA -> EstadoContable::sqlSignoVenta(). Es la misma
 *       regla que dashResumen() aplica en PHP: "Factura + Boleta + Nota de
 *       debito - Nota de credito".
 *
 * EQUIVALENCIA EXACTA CON EL DASHBOARD, para poder compararlo:
 *   metrica 'neto'       == dashResumen()['netoPeriodo']
 *   metrica 'documentos' == dashResumen()['documentos']   (COUNT, sin signo)
 *
 * 'impuesto' NO es dashResumen()['ivaDebito']: aquel es solo IVA y este suma
 * ademas impuesto_adicional (el ILA). Son dos cifras distintas a proposito, no
 * un desajuste.
 *
 * =============================================================================
 * LO QUE NO SE PUEDE RESPONDER, Y SE DICE
 * =============================================================================
 *
 * NADA A NIVEL DE PRODUCTO. El detalle de un DTE emitido vive dentro del XML de
 * dte_emitido y no en columnas consultables: no hay tabla de lineas emitidas.
 * "Que producto vendi mas" es una pregunta que un usuario VA a hacer, y la
 * respuesta correcta es decir que no se puede -- no devolver el tipo de
 * documento mas vendido como si fuera lo mismo. Ver AGRUPACIONES_IMPOSIBLES.
 */
final class MySqlConsultaVentasRepository
{
    /** Las cinco perillas. Lista CERRADA: lo que no este aqui revienta. */
    public const PERILLAS = ['metrica', 'agruparPor', 'desde', 'hasta', 'orden', 'limite'];

    /**
     * Metrica => expresion SQL. Todas llevan el signo de la nota de credito
     * salvo 'documentos', que cuenta filas (una NC es un documento emitido).
     */
    public const METRICAS = ['monto', 'documentos', 'promedio', 'exento', 'neto', 'impuesto'];

    public const AGRUPACIONES = ['cliente', 'mes', 'tipo', 'ninguna'];

    public const ORDENES = ['metrica_desc', 'metrica_asc', 'grupo_asc'];

    /**
     * Tope de filas. 500 y no "sin limite": el resultado va a viajar a una
     * pantalla y, mas adelante, al contexto de un modelo. Una consulta sin tope
     * sobre un historico grande devuelve algo que nadie puede leer ni pagar.
     */
    public const LIMITE_MAX = 500;

    /**
     * Agrupaciones que se entienden pero NO SE PUEDEN RESPONDER, con el motivo
     * que hay que decirle al usuario.
     *
     * Existen como lista propia para que la respuesta sea EXPLICATIVA y no un
     * "valor invalido" generico: la pregunta es legitima, lo que falta es el
     * dato.
     */
    public const AGRUPACIONES_IMPOSIBLES = [
        'producto' => 'el detalle de cada documento emitido vive dentro de su XML y no en '
            . 'columnas consultables; no hay tabla de lineas emitidas, asi que no se puede '
            . 'agrupar ni sumar por producto',
        'vendedor' => 'no se registra quien emitio cada documento',
        'margen'   => 'no se registra el costo de lo vendido, asi que no hay margen que calcular',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly MySqlClienteRepository $clientes,
    ) {
    }

    /**
     * @param array<string,mixed> $perillas
     * @return array{
     *   filas: list<array{grupo:?string, etiqueta:string, valor:float, documentos:int}>,
     *   meta: array<string,mixed>
     * }
     *
     * @throws ConsultaVentasInvalidaException
     */
    public function consultar(int $cuentaId, array $perillas): array
    {
        $p = $this->validar($perillas);

        $rutEmisor = $this->rutEmisorProduccion($cuentaId);
        if ($rutEmisor === null) {
            // NO es un error de perilla: la cuenta existe y la pregunta es
            // valida, pero no tiene emisor de produccion, asi que no hay ni un
            // documento que contar. Se devuelve vacio con el motivo.
            return ['filas' => [], 'meta' => $this->meta($p, null, 'la cuenta no tiene emisor de produccion')];
        }

        // TODAS LAS METRICAS DE DINERO PASAN POR sqlSumaConSigno(), y ninguna
        // multiplica la columna por un signo: las cinco columnas de dinero de
        // dte_emitido son UNSIGNED y ese producto revienta con
        // "BIGINT UNSIGNED value is out of range" antes de llegar al SUM. La
        // forma correcta -- el menos pegado a la columna dentro del CASE -- es la
        // que usa el dashboard desde hace meses. Ver el docblock del metodo.
        $suma = static fn (string $columna): string => EstadoContable::sqlSumaConSigno($columna);

        $expr = match ($p['metrica']) {
            'monto'      => $suma('total'),
            'neto'       => $suma('neto'),
            'exento'     => $suma('exento'),
            // Los parentesis son necesarios: sin ellos el menos del CASE solo
            // afectaria a iva y el impuesto adicional se sumaria en positivo.
            'impuesto'   => $suma('(iva + impuesto_adicional)'),
            'documentos' => 'COUNT(*)',
            // NULLIF para no dividir por cero: un grupo sin filas no llega aqui,
            // pero la expresion tiene que ser correcta por si misma.
            'promedio'   => $suma('total') . ' / NULLIF(COUNT(*), 0)',
        };

        // La expresion de agrupacion. 'cliente' REPLICA Rut::normalizar() en SQL,
        // igual que dashTopClientes(): sin eso el mismo cliente cargado una vez
        // con puntos y otra sin puntos sale como dos filas.
        $grupo = match ($p['agruparPor']) {
            'cliente' => "UPPER(REPLACE(REPLACE(TRIM(receptor_rut), '.', ''), ' ', ''))",
            'mes'     => "DATE_FORMAT(fecha_emision, '%Y-%m')",
            'tipo'    => 'CAST(tipo_dte AS CHAR)',
            'ninguna' => null,
        };

        $orden = match ($p['orden']) {
            'metrica_desc' => 'valor DESC',
            'metrica_asc'  => 'valor ASC',
            'grupo_asc'    => $grupo === null ? 'valor DESC' : 'grupo ASC',
        };

        $sql = 'SELECT ' . ($grupo === null ? "'' AS grupo" : $grupo . ' AS grupo') . ', '
            . $expr . ' AS valor, COUNT(*) AS documentos '
            . 'FROM dte_emitido '
            . "WHERE rut_emisor = :rut AND ambiente = 'produccion' "
            . '  AND fecha_emision BETWEEN :desde AND :hasta '
            // REUSADO VERBATIM, no copiado. Ver el docblock de la clase.
            . EstadoContable::sqlExcluirRechazados()
            . ($grupo === null ? '' : 'GROUP BY grupo ')
            . 'ORDER BY ' . $orden . ' '
            . 'LIMIT ' . $p['limite'];

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':rut' => $rutEmisor, ':desde' => $p['desde'], ':hasta' => $p['hasta']]);
        $crudas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'filas' => $this->etiquetar($cuentaId, $p['agruparPor'], $crudas),
            'meta'  => $this->meta($p, $rutEmisor, null),
        ];
    }

    // =======================================================================
    //  VALIDACION: LISTA CERRADA
    // =======================================================================

    /**
     * @param array<string,mixed> $perillas
     * @return array{metrica:string, agruparPor:string, desde:string, hasta:string, orden:string, limite:int}
     */
    private function validar(array $perillas): array
    {
        // LO QUE NO SE RECONOCE REVIENTA. Misma forma que validarDocumentoDte()
        // despues de cerrar su lista blanca: proyectar en silencio deja pasar
        // una pregunta que se contesto con otra cosa.
        $desconocidas = array_diff(array_keys($perillas), self::PERILLAS);
        if ($desconocidas !== []) {
            throw ConsultaVentasInvalidaException::perillaDesconocida(
                array_map('strval', $desconocidas),
                self::PERILLAS
            );
        }

        $metrica = (string) ($perillas['metrica'] ?? '');
        if (! in_array($metrica, self::METRICAS, true)) {
            throw ConsultaVentasInvalidaException::valorInvalido('metrica', $metrica, self::METRICAS);
        }

        $agruparPor = (string) ($perillas['agruparPor'] ?? 'ninguna');
        if (isset(self::AGRUPACIONES_IMPOSIBLES[$agruparPor])) {
            // NO es "valor invalido": la pregunta se entiende y no hay datos.
            throw ConsultaVentasInvalidaException::noSePuedeResponder(
                "agrupando por {$agruparPor}",
                self::AGRUPACIONES_IMPOSIBLES[$agruparPor]
            );
        }
        if (! in_array($agruparPor, self::AGRUPACIONES, true)) {
            throw ConsultaVentasInvalidaException::valorInvalido('agruparPor', $agruparPor, self::AGRUPACIONES);
        }

        $orden = (string) ($perillas['orden'] ?? 'metrica_desc');
        if (! in_array($orden, self::ORDENES, true)) {
            throw ConsultaVentasInvalidaException::valorInvalido('orden', $orden, self::ORDENES);
        }

        foreach (['desde', 'hasta'] as $campo) {
            $v = (string) ($perillas[$campo] ?? '');
            if (! $this->fechaValida($v)) {
                throw ConsultaVentasInvalidaException::valorInvalido($campo, $v, ['AAAA-MM-DD']);
            }
        }
        $desde = (string) $perillas['desde'];
        $hasta = (string) $perillas['hasta'];
        if ($desde > $hasta) {
            throw ConsultaVentasInvalidaException::valorInvalido(
                'desde', $desde, ['una fecha anterior o igual a hasta (' . $hasta . ')']
            );
        }

        $limiteCrudo = $perillas['limite'] ?? 20;
        if (! is_int($limiteCrudo) && ! ctype_digit((string) $limiteCrudo)) {
            throw ConsultaVentasInvalidaException::valorInvalido(
                'limite', (string) $limiteCrudo, ['un entero entre 1 y ' . self::LIMITE_MAX]
            );
        }
        $limite = (int) $limiteCrudo;
        if ($limite < 1 || $limite > self::LIMITE_MAX) {
            throw ConsultaVentasInvalidaException::valorInvalido(
                'limite', (string) $limite, ['un entero entre 1 y ' . self::LIMITE_MAX]
            );
        }

        return compact('metrica', 'agruparPor', 'desde', 'hasta', 'orden', 'limite');
    }

    /** Fecha AAAA-MM-DD real de calendario, mismo criterio que fechaValida() del panel. */
    private function fechaValida(string $f): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) !== 1) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $f);

        return $d !== false && $d->format('Y-m-d') === $f;
    }

    // =======================================================================
    //  AISLAMIENTO Y ETIQUETAS
    // =======================================================================

    /**
     * RUT emisor de produccion de la cuenta. MISMA CONSULTA que
     * rutEmisorProduccion() del panel, incluido el LIMIT 1.
     *
     * OJO CON ESE LIMIT 1, y esta anotado porque no lo pude medir: si una cuenta
     * tuviera DOS emisores de produccion, esta consulta elige uno arbitrario --
     * no hay ORDER BY -- y el chat heredaria el mismo sesgo que ya tiene el
     * dashboard. Es una limitacion compartida, no una nueva.
     */
    private function rutEmisorProduccion(int $cuentaId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = :c AND ambiente = 'produccion' LIMIT 1"
        );
        $stmt->execute([':c' => $cuentaId]);
        $rut = $stmt->fetchColumn();

        return $rut === false ? null : (string) $rut;
    }

    /**
     * Pone la etiqueta legible de cada grupo.
     *
     * EL NOMBRE DEL CLIENTE SALE DEL MAESTRO, NO DE dte_emitido, porque esa
     * tabla NO GUARDA razon social: solo receptor_rut. Se resuelve con
     * buscarPorRuts($cuentaId, ...) -- una sola consulta, escopada por cuenta --
     * que es exactamente lo que hace dashTopClientes(). Asi el chat y el
     * dashboard muestran el MISMO nombre.
     *
     * Y se resuelve en PHP y no con un JOIN a proposito: cliente es
     * utf8mb4_unicode_ci y dte_emitido es utf8mb4_0900_ai_ci. Unir dos columnas
     * de texto de familias distintas sin COLLATE explicito es un error de MySQL.
     *
     * Un receptor que no este en el maestro se muestra por su RUT, sin fallar.
     *
     * @param list<array<string,mixed>> $crudas
     * @return list<array{grupo:?string, etiqueta:string, valor:float, documentos:int}>
     */
    private function etiquetar(int $cuentaId, string $agruparPor, array $crudas): array
    {
        $nombres = [];
        if ($agruparPor === 'cliente' && $crudas !== []) {
            $ruts    = array_map(static fn (array $f): string => (string) $f['grupo'], $crudas);
            $nombres = $this->clientes->buscarPorRuts($cuentaId, $ruts);
        }

        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $salida = [];
        foreach ($crudas as $f) {
            $grupo = (string) $f['grupo'];
            $salida[] = [
                'grupo'      => $agruparPor === 'ninguna' ? null : $grupo,
                'etiqueta'   => match ($agruparPor) {
                    'cliente' => isset($nombres[$grupo]) ? (string) $nombres[$grupo]['razon_social'] : $grupo,
                    'mes'     => ($meses[(int) substr($grupo, 5, 2)] ?? $grupo) . ' ' . substr($grupo, 0, 4),
                    'tipo'    => self::glosaTipo((int) $grupo),
                    'ninguna' => 'total del periodo',
                },
                'valor'      => (float) $f['valor'],
                'documentos' => (int) $f['documentos'],
            ];
        }

        return $salida;
    }

    /**
     * Glosa del tipo de documento.
     *
     * DUPLICACION CONOCIDA: el proyecto ya tuvo seis mapas de nombres de tipo y
     * se unificaron. Este es el septimo, y esta aqui SOLO porque el mapa
     * unificado vive en el panel (nombreTipoDte()) y esta clase no puede
     * llamarlo. Si alguien mueve ese mapa a src/, hay que borrar este.
     */
    private static function glosaTipo(int $tipo): string
    {
        return match ($tipo) {
            33 => 'Factura electronica',
            34 => 'Factura exenta',
            39 => 'Boleta electronica',
            41 => 'Boleta exenta',
            56 => 'Nota de debito',
            61 => 'Nota de credito',
            default => 'Tipo ' . $tipo,
        };
    }

    /** @param array<string,mixed> $p */
    private function meta(array $p, ?string $rutEmisor, ?string $sinDatos): array
    {
        return [
            'metrica'    => $p['metrica'],
            'agruparPor' => $p['agruparPor'],
            'desde'      => $p['desde'],
            'hasta'      => $p['hasta'],
            'orden'      => $p['orden'],
            'limite'     => $p['limite'],
            // Se devuelve para que la pantalla pueda decir de donde salio el
            // numero. El total no es una caja negra, mismo criterio que la
            // formula que el dashboard imprime bajo su cifra.
            'filtros'    => "ambiente=produccion, rut_emisor={$rutEmisor}, "
                . 'rechazados excluidos (EstadoContable), nota de credito con signo negativo',
            'sinDatos'   => $sinDatos,
        ];
    }
}
