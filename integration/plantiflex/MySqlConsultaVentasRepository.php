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

    /**
     * 'documento' ES UNA AGRUPACION MAS, NO UNA PERILLA NUEVA.
     *
     * La objecion contra meterla aqui era que su fila tiene otra forma -- folio,
     * fecha, tipo, cliente -- y que 'metrica' se volvia absurda al listar
     * ("promedio por documento" no significa nada). Las dos desaparecen desde
     * que TODA consulta devuelve el desglose completo: 'metrica' ya no elige que
     * numero se calcula, sino por cual se ORDENA y cual se destaca. Con eso
     * 'documento' encaja sin agregar un sexto boton que el modelo pueda
     * equivocar, y sin combinaciones invalidas que haya que rechazar una a una.
     *
     * La fila de 'documento' trae claves de mas (folio, fecha, tipo, rut) ademas
     * de las de siempre; la pantalla pinta otra tabla cuando las ve.
     */
    public const AGRUPACIONES = ['cliente', 'mes', 'tipo', 'documento', 'ninguna'];

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

        // EL FILTRO DEL PERIODO Y DE LA CUENTA, UNO SOLO PARA LOS DOS CAMINOS.
        $donde = "WHERE rut_emisor = :rut AND ambiente = 'produccion' "
            . '  AND fecha_emision BETWEEN :desde AND :hasta '
            // REUSADO VERBATIM, no copiado. Ver el docblock de la clase. Aplica
            // IGUAL al listado: un RCT no puede aparecer en una lista de
            // documentos que dice ser lo que se facturo.
            . EstadoContable::sqlExcluirRechazados();

        $params = [':rut' => $rutEmisor, ':desde' => $p['desde'], ':hasta' => $p['hasta']];

        // UNA FILA DE MAS PARA SABER SI SE RECORTO. Sin esto no se puede
        // distinguir "hay exactamente 20" de "hay 4.000 y te mostre 20", y la
        // pantalla no podria avisar. Se pide n+1 y se descarta la sobrante.
        $limiteSql = $p['limite'] + 1;

        if ($p['agruparPor'] === 'documento') {
            $crudas = $this->filasDeDocumentos($donde, $params, $p, $limiteSql);
        } else {
            $crudas = $this->filasAgregadas($donde, $params, $p, $limiteSql);
        }

        $hayMas = count($crudas) > $p['limite'];
        if ($hayMas) {
            array_pop($crudas);
        }

        return [
            'filas' => $this->etiquetar($cuentaId, $p['agruparPor'], $crudas, $p['metrica']),
            'meta'  => $this->meta($p, $rutEmisor, null) + ['hayMas' => $hayMas],
        ];
    }

    /**
     * TODAS LAS CIFRAS EN CADA FILA, SIEMPRE.
     *
     * Antes se calculaba SOLO la metrica pedida y el resto no existia. Eso
     * obligaba al modelo a acertar entre 'monto' y 'neto' -- y si erraba, el
     * usuario veia un numero correcto que contestaba otra pregunta, sin forma de
     * notarlo. Ahora la metrica solo decide POR CUAL SE ORDENA y cual destaca la
     * pantalla; los cinco numeros viajan igual.
     *
     * El costo es cuatro SUM de mas sobre las mismas filas ya filtradas: nada.
     *
     * @param array<string,mixed> $p
     * @return list<array<string,mixed>>
     */
    private function filasAgregadas(string $donde, array $params, array $p, int $limiteSql): array
    {
        // 'cliente' REPLICA Rut::normalizar() en SQL, igual que dashTopClientes():
        // sin eso el mismo cliente cargado una vez con puntos y otra sin puntos
        // sale como dos filas.
        $grupo = match ($p['agruparPor']) {
            'cliente' => "UPPER(REPLACE(REPLACE(TRIM(receptor_rut), '.', ''), ' ', ''))",
            'mes'     => "DATE_FORMAT(fecha_emision, '%Y-%m')",
            'tipo'    => 'CAST(tipo_dte AS CHAR)',
            'ninguna' => null,
        };

        $sql = 'SELECT ' . ($grupo === null ? "'' AS grupo" : $grupo . ' AS grupo') . ', '
            . 'COUNT(*) AS documentos, '
            . EstadoContable::sqlSumaConSigno('neto') . ' AS neto, '
            . EstadoContable::sqlSumaConSigno('exento') . ' AS exento, '
            // Los parentesis son necesarios: sin ellos el menos del CASE solo
            // afectaria a iva y el impuesto adicional se sumaria en positivo.
            . EstadoContable::sqlSumaConSigno('(iva + impuesto_adicional)') . ' AS impuesto, '
            . EstadoContable::sqlSumaConSigno('total') . ' AS monto, '
            // NULLIF para no dividir por cero.
            . EstadoContable::sqlSumaConSigno('total') . ' / NULLIF(COUNT(*), 0) AS promedio '
            . 'FROM dte_emitido ' . $donde
            . ($grupo === null ? '' : 'GROUP BY grupo ')
            . 'ORDER BY ' . $this->ordenSql($p, $grupo !== null) . ' '
            . 'LIMIT ' . $limiteSql;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Documento a documento. NO agrega: cada fila es un DTE.
     *
     * LLEVA EL SIGNO POR FILA, no solo en los totales: si el listado mostrara la
     * nota de credito en positivo, sumar las filas a mano daria otra cosa que la
     * cifra de arriba y quien lo notara no sabria cual creer.
     *
     * @param array<string,mixed> $p
     * @return list<array<string,mixed>>
     */
    private function filasDeDocumentos(string $donde, array $params, array $p, int $limiteSql): array
    {
        $sql = 'SELECT folio, fecha_emision, tipo_dte, '
            . "UPPER(REPLACE(REPLACE(TRIM(receptor_rut), '.', ''), ' ', '')) AS grupo, "
            . '1 AS documentos, '
            . EstadoContable::sqlConSigno('neto') . ' AS neto, '
            . EstadoContable::sqlConSigno('exento') . ' AS exento, '
            . EstadoContable::sqlConSigno('(iva + impuesto_adicional)') . ' AS impuesto, '
            . EstadoContable::sqlConSigno('total') . ' AS monto, '
            . EstadoContable::sqlConSigno('total') . ' AS promedio '
            . 'FROM dte_emitido ' . $donde
            // Por defecto, el mas reciente primero: es lo que se espera de un
            // "muestrame los documentos de agosto".
            . 'ORDER BY ' . $this->ordenSql($p, false, 'fecha_emision DESC, folio DESC') . ' '
            . 'LIMIT ' . $limiteSql;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $p */
    private function ordenSql(array $p, bool $hayGrupo, string $porDefecto = 'monto DESC'): string
    {
        // La metrica se usa como NOMBRE DE COLUMNA del SELECT, y por eso puede ir
        // literal: sale de una lista cerrada validada, no del usuario.
        $col = $p['metrica'];

        return match ($p['orden']) {
            'metrica_desc' => "{$col} DESC",
            'metrica_asc'  => "{$col} ASC",
            'grupo_asc'    => $hayGrupo ? 'grupo ASC' : $porDefecto,
        };
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
    private function etiquetar(int $cuentaId, string $agruparPor, array $crudas, string $metrica = 'monto'): array
    {
        $nombres = [];
        // TAMBIEN EN EL LISTADO: el nombre del cliente de cada documento sale del
        // maestro, con la MISMA consulta en lote y el MISMO escopado por cuenta.
        if (in_array($agruparPor, ['cliente', 'documento'], true) && $crudas !== []) {
            $ruts    = array_map(static fn (array $f): string => (string) $f['grupo'], $crudas);
            $nombres = $this->clientes->buscarPorRuts($cuentaId, $ruts);
        }

        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $salida = [];
        foreach ($crudas as $f) {
            $grupo = (string) $f['grupo'];

            // EL DESGLOSE VIAJA SIEMPRE, en todas las agrupaciones. 'valor' se
            // conserva -- es la cifra de la metrica pedida -- para que quien ya
            // lo leia no tenga que cambiar.
            $desglose = [
                'documentos' => (int) $f['documentos'],
                'neto'       => (float) $f['neto'],
                'exento'     => (float) $f['exento'],
                'impuesto'   => (float) $f['impuesto'],
                'monto'      => (float) $f['monto'],
                'promedio'   => (float) $f['promedio'],
            ];

            $fila = [
                'grupo'      => $agruparPor === 'ninguna' ? null : $grupo,
                'etiqueta'   => match ($agruparPor) {
                    'cliente', 'documento' => isset($nombres[$grupo]) ? (string) $nombres[$grupo]['razon_social'] : $grupo,
                    'mes'     => ($meses[(int) substr($grupo, 5, 2)] ?? $grupo) . ' ' . substr($grupo, 0, 4),
                    'tipo'    => self::glosaTipo((int) $grupo),
                    'ninguna' => 'total del periodo',
                },
                'valor'      => (float) ($f[$metrica] ?? 0),
                'documentos' => $desglose['documentos'],
                'desglose'   => $desglose,
            ];

            // CLAVES DE MAS SOLO EN EL LISTADO. La pantalla las ve y pinta otra
            // tabla; ninguna otra agrupacion las trae.
            if ($agruparPor === 'documento') {
                $fila['folio']  = (int) $f['folio'];
                $fila['fecha']  = (string) $f['fecha_emision'];
                $fila['tipo']   = (int) $f['tipo_dte'];
                $fila['glosaTipo'] = self::glosaTipo((int) $f['tipo_dte']);
                $fila['rut']    = $grupo;
            }

            $salida[] = $fila;
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
