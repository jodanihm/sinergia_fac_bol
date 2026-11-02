<?php

declare(strict_types=1);

/**
 * ADAPTA UNA PLANILLA CUALQUIERA A LA PLANTILLA DE NOTAS DE VENTA.
 *
 * Un cliente exporta sus ventas desde su propio sistema: otras columnas, otros
 * nombres, otro orden, un titulo arriba, montos con "$" y punto de miles. La
 * carga masiva exige la plantilla exacta. Esta clase hace el puente, y el
 * puente tiene UNA regla que manda sobre todas las demas:
 *
 *   CAMBIA LA FORMA, NUNCA EL SIGNIFICADO.
 *
 * Reordenar columnas, quitar puntos de un RUT, pasar "05.11.2026" a
 * "2026-11-05" o "$ 10.000" a "10000" es forma: el dato sigue diciendo lo
 * mismo. Adivinar que "Total" es el precio unitario, completar un RUT, o
 * decidir que "Efectivo" es CONTADO ya es significado -- y eso lo decide el
 * usuario, no esta clase. Cuando algo admite dos lecturas, se deja como venia
 * y se pregunta.
 *
 * POR ESO HAY CUATRO NIVELES DE EQUIVALENCIA DE COLUMNA, y solo los dos
 * primeros llegan preseleccionados:
 *   exacta     el encabezado ES el de la plantilla (o lo era una vez normalizado).
 *   sinonimo   un nombre que solo puede significar esa columna ("RUT Cliente").
 *   dudosa     una sola columna candidata, pero con una condicion que el
 *              archivo no permite comprobar ("Total" es precio_final SOLO si es
 *              el de una unidad). Se sugiere, no se elige.
 *   ambigua    mas de una columna candidata ("Precio": con o sin IVA?), o dos
 *              columnas del archivo que apuntan al mismo destino.
 *
 * LA CLASE ES PURA: no lee archivos, no toca la base, no conoce la sesion.
 * Recibe celdas ya leidas y devuelve filas con la forma de la plantilla, mas
 * la lista completa de lo que cambio. Eso es lo que permite mostrar la vista
 * previa y despues importar EXACTAMENTE lo mismo: las dos pasadas llaman a la
 * misma funcion con los mismos datos.
 *
 * NO VALIDA REGLAS DE NEGOCIO. Si un RUT tiene mal el digito verificador o una
 * fila no trae cantidad, eso lo dice validarFilaCargaMasiva(), la misma de la
 * carga normal. Aqui solo se ordena y se limpia; validar dos veces con dos
 * reglas distintas es como una pantalla termina diciendo "listo" de algo que
 * la otra rechaza.
 */
final class AdaptadorPlantilla
{
    /**
     * Sinonimos por columna de la plantilla, YA NORMALIZADOS (ver
     * normalizarEncabezado()). Un mismo nombre en dos columnas la vuelve
     * ambigua a proposito: "precio" esta en precio_unitario Y en precio_final
     * porque no dice si trae IVA.
     *
     * LO QUE NO ESTA, Y POR QUE:
     *   medio_pago  -> forma_pago: "medio" es tarjeta/transferencia; "forma" es
     *                  contado/credito. Son dos conceptos distintos.
     *   fecha_pago  -> fecha_vencimiento: el dia en que se pago no es el dia en
     *                  que vence.
     *   afecto      -> exento: dice lo contrario. Invertir una columna ya es
     *                  interpretar.
     */
    public const SINONIMOS = [
        'identificador_externo' => ['id', 'id_externo', 'identificador', 'codigo', 'cod', 'n_pedido', 'pedido',
            'id_pedido', 'n_orden', 'orden', 'id_orden', 'n_venta', 'id_venta', 'folio_interno', 'reserva',
            'id_reserva', 'n_reserva', 'n_nota', 'nota_venta', 'n_nota_venta', 'id_transaccion', 'n_operacion'],
        'rut_receptor'          => ['rut', 'rut_cliente', 'rut_comprador', 'rut_empresa', 'rut_receptor', 'run'],
        'razon_social_receptor' => ['razon_social', 'nombre_cliente', 'cliente', 'razon_social_cliente',
            'nombre_empresa', 'empresa', 'comprador', 'nombre_razon_social'],
        'giro_receptor'         => ['giro', 'giro_cliente', 'actividad', 'actividad_economica'],
        'direccion_receptor'    => ['direccion', 'domicilio', 'direccion_cliente', 'calle'],
        'comuna_receptor'       => ['comuna', 'comuna_cliente'],
        'email_receptor'        => ['email', 'correo', 'mail', 'e_mail', 'correo_electronico', 'email_cliente',
            'correo_cliente'],
        'fecha_nota'            => ['fecha', 'fecha_venta', 'fecha_emision', 'fecha_documento', 'fecha_pedido',
            'fecha_reserva', 'fecha_compra'],
        'producto_servicio'     => ['producto', 'servicio', 'descripcion', 'detalle', 'glosa', 'item',
            'nombre_producto', 'concepto', 'articulo', 'descripcion_producto', 'producto_o_servicio'],
        'cantidad'              => ['cant', 'unidades', 'qty', 'cantidad_unidades'],
        'precio_unitario'       => ['precio', 'valor', 'valor_unitario', 'precio_neto', 'valor_neto', 'neto',
            'precio_sin_iva', 'neto_unitario', 'precio_unitario_neto', 'valor_unitario_neto'],
        'exento'                => ['es_exento', 'exenta', 'exento_iva'],
        'forma_pago'            => ['forma_de_pago', 'condicion_pago', 'condicion_de_pago', 'condiciones_de_pago'],
        'fecha_vencimiento'     => ['vencimiento', 'fecha_venc', 'vence', 'fecha_de_vencimiento'],
        'folio_boleta_a_anular' => ['folio_boleta', 'n_boleta', 'boleta', 'boleta_a_anular'],
        'fecha_boleta_a_anular' => ['fecha_boleta'],
        'precio_final'          => ['precio', 'valor', 'valor_unitario', 'precio_con_iva', 'valor_con_iva',
            'precio_bruto', 'valor_bruto', 'precio_iva_incluido'],
    ];

    /**
     * Encabezados que se sugieren pero NO se eligen, con la condicion que el
     * usuario tiene que confirmar. Ver "dudosa" arriba.
     */
    public const DUDOSAS = [
        'total'       => ['precio_final', 'Solo si es lo que paga el cliente por UNA unidad. Si es cantidad por precio, no corresponde.'],
        'monto'       => ['precio_final', 'Solo si es lo que paga el cliente por UNA unidad, con IVA incluido.'],
        'monto_total' => ['precio_final', 'Solo si es lo que paga el cliente por UNA unidad. Si es cantidad por precio, no corresponde.'],
        'valor_total' => ['precio_final', 'Solo si es lo que paga el cliente por UNA unidad. Si es cantidad por precio, no corresponde.'],
        'subtotal'    => ['precio_unitario', 'Solo si es el precio SIN IVA de UNA unidad.'],
        'nombre'      => ['razon_social_receptor', 'Solo si es el nombre del cliente y no el del producto.'],
        'documento'   => ['identificador_externo', 'Solo si identifica la venta y no se repite.'],
        'n_documento' => ['identificador_externo', 'Solo si identifica la venta y no se repite.'],
    ];

    /**
     * Columnas sin las cuales la carga no puede funcionar. Una de las dos de
     * precio basta (ver validarFilaCargaMasiva()). Las del receptor no estan:
     * son obligatorias solo para clientes que no estan en el maestro, y eso se
     * sabe fila por fila, al validar.
     */
    public const OBLIGATORIAS = ['identificador_externo', 'rut_receptor', 'fecha_nota', 'producto_servicio', 'cantidad', 'forma_pago'];

    /**
     * Columnas que el usuario puede completar con UN valor para todo el archivo
     * cuando el archivo no las trae. Son condiciones del documento, no datos de
     * la venta: que todas las ventas del archivo fueron al contado lo sabe el
     * usuario y lo puede afirmar. Un monto, un RUT o un producto no: esos tienen
     * que venir del archivo, fila por fila.
     */
    public const COMPLETABLES = ['forma_pago', 'fecha_nota', 'fecha_vencimiento', 'exento'];

    /** Los valores que la carga acepta en las dos columnas de lista cerrada. */
    public const VALORES_FORMA_PAGO = ['CONTADO', 'CREDITO', 'SIN COSTO'];
    public const VALORES_EXENTO     = ['SI', 'NO'];

    /**
     * Como se le nombra cada columna de la plantilla A UN USUARIO. Los nombres
     * de la plantilla (razon_social_receptor) son para el archivo; en pantalla
     * y en los mensajes van estos.
     */
    public const ETIQUETAS = [
        'identificador_externo' => 'N° de venta',
        'rut_receptor'          => 'RUT del cliente',
        'razon_social_receptor' => 'Razon social del cliente',
        'giro_receptor'         => 'Giro del cliente',
        'direccion_receptor'    => 'Direccion del cliente',
        'comuna_receptor'       => 'Comuna del cliente',
        'email_receptor'        => 'Correo del cliente',
        'fecha_nota'            => 'Fecha de la venta',
        'producto_servicio'     => 'Producto o servicio',
        'cantidad'              => 'Cantidad',
        'precio_unitario'       => 'Precio unitario sin IVA',
        'exento'                => 'Exento de IVA (SI/NO)',
        'forma_pago'            => 'Forma de pago',
        'fecha_vencimiento'     => 'Fecha de vencimiento',
        'folio_boleta_a_anular' => 'Folio de boleta a anular',
        'fecha_boleta_a_anular' => 'Fecha de boleta a anular',
        'precio_final'          => 'Precio unitario con IVA',
    ];

    /** La etiqueta de una columna, o su nombre si no tiene. */
    public static function etiqueta(string $columna): string
    {
        return self::ETIQUETAS[$columna] ?? $columna;
    }

    /** Cuantas filas de arriba se miran buscando el encabezado. */
    public const FILAS_BUSQUEDA_ENCABEZADO = 15;

    private const TIPO_COLUMNA = [
        'rut_receptor'          => 'rut',
        'email_receptor'        => 'email',
        'fecha_nota'            => 'fecha',
        'fecha_vencimiento'     => 'fecha',
        'fecha_boleta_a_anular' => 'fecha',
        'cantidad'              => 'numero',
        'precio_unitario'       => 'numero',
        'precio_final'          => 'numero',
        'folio_boleta_a_anular' => 'numero',
        'forma_pago'            => 'forma_pago',
        'exento'                => 'exento',
    ];

    private const MESES = [
        'ene' => 1, 'enero' => 1, 'feb' => 2, 'febrero' => 2, 'mar' => 3, 'marzo' => 3, 'abr' => 4, 'abril' => 4,
        'may' => 5, 'mayo' => 5, 'jun' => 6, 'junio' => 6, 'jul' => 7, 'julio' => 7, 'ago' => 8, 'agosto' => 8,
        'sep' => 9, 'sept' => 9, 'septiembre' => 9, 'setiembre' => 9, 'oct' => 10, 'octubre' => 10,
        'nov' => 11, 'noviembre' => 11, 'dic' => 12, 'diciembre' => 12,
    ];

    /**
     * Falla en voz alta si la plantilla cambio y esta clase no se entero. Una
     * columna nueva sin sinonimos se podria mapear igual a mano, pero una que
     * desaparecio dejaria sinonimos apuntando a la nada.
     *
     * @param list<string> $columnasPlantilla
     */
    public static function verificarCatalogo(array $columnasPlantilla): void
    {
        $sobran = array_diff(array_keys(self::SINONIMOS), $columnasPlantilla);
        $faltan = array_diff($columnasPlantilla, array_keys(self::SINONIMOS));
        if ($sobran !== [] || $faltan !== []) {
            throw new LogicException(sprintf(
                'AdaptadorPlantilla::SINONIMOS no coincide con la plantilla (sobran: %s; faltan: %s).',
                implode(', ', $sobran) ?: '-',
                implode(', ', $faltan) ?: '-'
            ));
        }
    }

    /**
     * "N° Pedido " -> "n_pedido"; "Razón Social" -> "razon_social".
     * Minusculas, sin tildes, todo lo que no sea letra o numero pasa a "_", y
     * las formas de escribir "numero" (n°, nro, num, numero, no) quedan en "n".
     */
    public static function normalizarEncabezado(string $texto): string
    {
        $t = mb_strtolower(trim($texto), 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'º' => '', '°' => '']);
        $t = (string) preg_replace('/[^a-z0-9]+/', '_', $t);
        $t = trim($t, '_');
        $partes = $t === '' ? [] : explode('_', $t);
        if ($partes !== [] && in_array($partes[0], ['nro', 'num', 'numero', 'no', 'n'], true) && count($partes) > 1) {
            $partes[0] = 'n';
        }

        return implode('_', $partes);
    }

    /**
     * La fila del encabezado: la de mas celdas reconocibles entre las primeras
     * FILAS_BUSQUEDA_ENCABEZADO. Hacen falta al menos dos, para no tomar como
     * encabezado un titulo del tipo "Ventas noviembre".
     *
     * @param list<list<string|int|float>> $filas
     *
     * @return array{indice:int, puntaje:int}|null indice base 0
     */
    public static function detectarEncabezado(array $filas): ?array
    {
        $mejor = null;
        foreach (array_slice($filas, 0, self::FILAS_BUSQUEDA_ENCABEZADO) as $i => $fila) {
            $puntaje = 0;
            foreach ($fila as $celda) {
                if (is_string($celda) && self::candidatos(self::normalizarEncabezado($celda)) !== []) {
                    $puntaje++;
                }
            }
            if ($puntaje >= 2 && ($mejor === null || $puntaje > $mejor['puntaje'])) {
                $mejor = ['indice' => $i, 'puntaje' => $puntaje];
            }
        }

        return $mejor;
    }

    /**
     * Las columnas de la plantilla a las que podria corresponder un encabezado
     * ya normalizado, con el nivel de cada una.
     *
     * @return list<array{columna:string, nivel:string, condicion:?string}>
     */
    public static function candidatos(string $normalizado): array
    {
        if ($normalizado === '') {
            return [];
        }
        if (array_key_exists($normalizado, self::SINONIMOS)) {
            return [['columna' => $normalizado, 'nivel' => 'exacta', 'condicion' => null]];
        }
        $encontrados = [];
        foreach (self::SINONIMOS as $columna => $sinonimos) {
            if (in_array($normalizado, $sinonimos, true)) {
                $encontrados[] = ['columna' => $columna, 'nivel' => 'sinonimo', 'condicion' => null];
            }
        }
        if ($encontrados === [] && isset(self::DUDOSAS[$normalizado])) {
            [$columna, $condicion] = self::DUDOSAS[$normalizado];
            $encontrados[] = ['columna' => $columna, 'nivel' => 'dudosa', 'condicion' => $condicion];
        }

        return $encontrados;
    }

    /**
     * La propuesta de equivalencias para los encabezados de un archivo.
     *
     * Por columna del archivo: el destino propuesto (null si no hay uno seguro),
     * el nivel y los candidatos. Dos columnas del archivo que apuntan al mismo
     * destino quedan AMBAS ambiguas y sin destino: elegir una a ojo seria
     * descartar los datos de la otra sin preguntar.
     *
     * @param list<string> $encabezados
     *
     * @return list<array{origen:string, destino:?string, nivel:string, candidatos:list<string>, condicion:?string}>
     */
    public static function proponerEquivalencias(array $encabezados): array
    {
        $propuesta = [];
        foreach ($encabezados as $i => $encabezado) {
            $cands = self::candidatos(self::normalizarEncabezado($encabezado));
            $columnas = array_values(array_unique(array_column($cands, 'columna')));
            if ($cands === []) {
                $propuesta[$i] = ['origen' => $encabezado, 'destino' => null, 'nivel' => 'sin_uso', 'candidatos' => [], 'condicion' => null];
            } elseif (count($columnas) > 1) {
                $propuesta[$i] = ['origen' => $encabezado, 'destino' => null, 'nivel' => 'ambigua', 'candidatos' => $columnas, 'condicion' => null];
            } else {
                $nivel = $cands[0]['nivel'];
                $propuesta[$i] = [
                    'origen'     => $encabezado,
                    'destino'    => $nivel === 'dudosa' ? null : $columnas[0],
                    'nivel'      => $nivel,
                    'candidatos' => $columnas,
                    'condicion'  => $cands[0]['condicion'],
                ];
            }
        }

        // Choques: dos columnas del archivo con el mismo destino propuesto.
        $porDestino = [];
        foreach ($propuesta as $i => $p) {
            if ($p['destino'] !== null) {
                $porDestino[$p['destino']][] = $i;
            }
        }
        foreach ($porDestino as $destino => $indices) {
            if (count($indices) > 1) {
                foreach ($indices as $i) {
                    $propuesta[$i]['destino']    = null;
                    $propuesta[$i]['nivel']      = 'ambigua';
                    $propuesta[$i]['candidatos'] = [$destino];
                    $propuesta[$i]['condicion']  = 'Hay otra columna del archivo que tambien parece ser esta.';
                }
            }
        }

        return array_values($propuesta);
    }

    /**
     * Firma de un conjunto de encabezados: la misma para el mismo formato de
     * archivo aunque cambien mayusculas o tildes. Sirve para recordar las
     * equivalencias que el usuario ya confirmo para ese formato.
     *
     * @param list<string> $encabezados
     */
    public static function firma(array $encabezados): string
    {
        return hash('sha256', implode('|', array_map(self::normalizarEncabezado(...), $encabezados)));
    }

    /**
     * Revisa que unas equivalencias elegidas por el usuario se puedan aplicar.
     * Devuelve lo que falta resolver, en lenguaje de usuario; vacio = listo.
     *
     * @param array<int, string>    $mapeo       indice de columna del archivo => columna de la plantilla
     * @param array<string, string> $constantes  columna de la plantilla => valor para todas las filas
     *
     * @return list<string>
     */
    public static function pendientes(array $mapeo, array $constantes, bool $identificadorPorFila): array
    {
        $pendientes = [];

        $usos = array_count_values(array_values($mapeo));
        foreach ($usos as $destino => $veces) {
            if ($veces > 1) {
                $pendientes[] = sprintf('Elegiste "%s" para %d columnas del archivo. Deja solo una.', self::etiqueta((string) $destino), $veces);
            }
        }

        $cubiertas = array_merge(array_values($mapeo), array_keys(array_filter($constantes, static fn ($v): bool => trim((string) $v) !== '')));
        if ($identificadorPorFila) {
            $cubiertas[] = 'identificador_externo';
        }
        foreach (self::OBLIGATORIAS as $columna) {
            if (in_array($columna, $cubiertas, true)) {
                continue;
            }
            $pendientes[] = match (true) {
                $columna === 'identificador_externo'           => 'Falta el N° de venta: elige la columna que lo trae, o usa el numero de fila.',
                in_array($columna, self::COMPLETABLES, true)   => sprintf('Falta %s: elige la columna que la trae, o indicala para todas las ventas.', mb_strtolower(self::etiqueta($columna))),
                default                                        => sprintf('Falta %s: elige la columna del archivo que lo trae.', self::etiqueta($columna)),
            };
        }
        if (! in_array('precio_unitario', $cubiertas, true) && ! in_array('precio_final', $cubiertas, true)) {
            $pendientes[] = 'Falta el precio: elige la columna del precio, sin IVA o con IVA.';
        }

        foreach ($constantes as $columna => $valor) {
            if (! in_array($columna, self::COMPLETABLES, true)) {
                $pendientes[] = sprintf('%s no se puede indicar a mano: tiene que venir del archivo.', self::etiqueta($columna));
            } elseif (in_array($columna, array_values($mapeo), true) && trim((string) $valor) !== '') {
                $pendientes[] = sprintf('%s viene de una columna del archivo y ademas la indicaste a mano. Deja solo una.', self::etiqueta($columna));
            }
        }

        return $pendientes;
    }

    /**
     * Valores distintos de una columna de lista cerrada que la carga no
     * reconoceria tal como vienen. Son los que el usuario tiene que traducir
     * (equivalencias de valor): "Efectivo" puede ser CONTADO, pero eso lo dice
     * quien conoce el negocio.
     *
     * @param list<list<string|int|float>> $datos  filas de datos (sin encabezado)
     *
     * @return list<string>
     */
    public static function valoresSinReconocer(array $datos, int $indiceColumna, string $columnaDestino): array
    {
        $vistos = [];
        foreach ($datos as $fila) {
            $celda = $fila[$indiceColumna] ?? '';
            $crudo = trim(is_float($celda) || is_int($celda) ? self::numeroCanonico($celda) : (string) $celda);
            if ($crudo === '') {
                continue;
            }
            $reconocido = $columnaDestino === 'forma_pago' ? self::formaPago($crudo) : self::exento($crudo);
            if ($reconocido === null) {
                $vistos[$crudo] = true;
            }
        }

        return array_keys($vistos);
    }

    /**
     * EL CORAZON: las filas del archivo, con la forma de la plantilla.
     *
     * $datos son las filas DEBAJO del encabezado, tal como se leyeron. Cada fila
     * de salida lleva 'fila_origen', el numero de fila en la hoja original, para
     * que cada cambio y cada error apunten a donde el usuario lo puede buscar.
     *
     * Las filas completamente vacias se quitan (y se cuentan): la carga normal
     * ya las ignora, asi que quitarlas no cambia el resultado, solo lo dice.
     *
     * @param list<list<string|int|float>> $datos celdas de texto como string; las
     *                                           numericas del Excel como int/float
     * @param list<string>          $columnasPlantilla NOTA_VENTA_ENCABEZADOS
     * @param array<int, string>    $mapeo             indice en el archivo => columna de la plantilla
     * @param array<string, string> $constantes        columna => valor para todas las filas
     * @param array<string, array<string, string>> $equivalencias columna => [valor del archivo => valor de la plantilla]
     *
     * @return array{
     *   filas: list<array<string, string>>,
     *   cambios: list<array{fila:int, columna:string, antes:string, despues:string, tipo:string}>,
     *   vacias: list<int>,
     *   sin_interpretar: list<array{fila:int, columna:string, valor:string, motivo:string}>
     * }
     */
    public static function aplicar(
        array $datos,
        int $filaPrimerDato,
        array $columnasPlantilla,
        array $mapeo,
        array $constantes,
        array $equivalencias,
        ?string $prefijoIdentificador
    ): array {
        $filas = $cambios = $vacias = $sinInterpretar = [];

        foreach ($datos as $k => $fila) {
            $numeroFila = $filaPrimerDato + $k;
            if (self::filaVacia($fila)) {
                $vacias[] = $numeroFila;
                continue;
            }

            $salida = array_fill_keys($columnasPlantilla, '');
            foreach ($mapeo as $indice => $columna) {
                $celda = $fila[$indice] ?? '';
                if (is_int($celda) || is_float($celda)) {
                    // UN NUMERO DE VERDAD: se escribe tal cual, sin reglas de
                    // miles ni de decimales. No hay nada que interpretar.
                    $salida[$columna] = self::numeroCanonico($celda);
                    continue;
                }
                $crudo = (string) $celda;
                [$valor, $tipo, $motivo] = self::normalizarValor($columna, $crudo, $equivalencias[$columna] ?? []);
                $salida[$columna] = $valor;
                if ($tipo !== null) {
                    $cambios[] = ['fila' => $numeroFila, 'columna' => $columna, 'antes' => $crudo, 'despues' => $valor, 'tipo' => $tipo];
                }
                if ($motivo !== null) {
                    $sinInterpretar[] = ['fila' => $numeroFila, 'columna' => $columna, 'valor' => $crudo, 'motivo' => $motivo];
                }
            }

            foreach ($constantes as $columna => $valor) {
                $valor = trim((string) $valor);
                if ($valor === '' || $salida[$columna] !== '') {
                    continue;
                }
                $salida[$columna] = $valor;
                $cambios[] = ['fila' => $numeroFila, 'columna' => $columna, 'antes' => '', 'despues' => $valor, 'tipo' => 'valor_indicado'];
            }

            if ($prefijoIdentificador !== null && $salida['identificador_externo'] === '') {
                $salida['identificador_externo'] = $prefijoIdentificador . '-F' . $numeroFila;
                $cambios[] = ['fila' => $numeroFila, 'columna' => 'identificador_externo', 'antes' => '', 'despues' => $salida['identificador_externo'], 'tipo' => 'identificador_por_fila'];
            }

            $salida['fila_origen'] = (string) $numeroFila;
            $filas[] = $salida;
        }

        return ['filas' => $filas, 'cambios' => $cambios, 'vacias' => $vacias, 'sin_interpretar' => $sinInterpretar];
    }

    /**
     * Las columnas que no traen nada en ninguna fila de datos.
     *
     * Una columna asi no es fuente de ningun dato: asignarla a "N° de venta"
     * da exactamente lo mismo que no asignarla, pero esconde la pregunta que
     * si resuelve el problema (usar el numero de fila). Pasa con la plantilla
     * misma cuando el cliente deja una columna sin llenar.
     *
     * @param list<list<string|int|float>> $datos
     *
     * @return list<int>
     */
    public static function columnasVacias(array $datos, int $nColumnas): array
    {
        $conDatos = [];
        foreach ($datos as $fila) {
            foreach ($fila as $i => $v) {
                if (! isset($conDatos[$i]) && (is_int($v) || is_float($v) || trim((string) $v) !== '')) {
                    $conDatos[$i] = true;
                }
            }
        }

        return array_values(array_filter(range(0, max(0, $nColumnas - 1)), static fn (int $i): bool => $nColumnas > 0 && ! isset($conDatos[$i])));
    }

    /**
     * Un numero leido de una celda numerica, como texto sin perder nada:
     * 10000.0 -> "10000", 8403.3613445378 -> "8403.3613445378".
     */
    public static function numeroCanonico(int|float $n): string
    {
        if (is_int($n) || (floor($n) === $n && abs($n) < 1e15)) {
            return (string) (int) $n;
        }

        return rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
    }

    /** @param list<string|int|float> $fila */
    public static function filaVacia(array $fila): bool
    {
        foreach ($fila as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Un valor, con la forma que espera su columna.
     *
     * @param array<string, string> $equivalencias valor del archivo => valor de la plantilla
     *
     * @return array{0:string, 1:?string, 2:?string} [valor, tipo de cambio o null, motivo si no se pudo interpretar]
     */
    public static function normalizarValor(string $columna, string $crudo, array $equivalencias = []): array
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $crudo)));
        $tipoEspacios = $limpio !== $crudo ? 'espacios' : null;
        if ($limpio === '') {
            return ['', $tipoEspacios, null];
        }

        switch (self::TIPO_COLUMNA[$columna] ?? 'texto') {
            case 'rut':
                $rut = strtoupper((string) preg_replace('/[\s.]/', '', $limpio));
                if (preg_match('/^\d{7,8}[\dK]$/', $rut) === 1) {
                    $rut = substr($rut, 0, -1) . '-' . substr($rut, -1);
                }
                return [$rut, $rut !== $crudo ? 'formato_rut' : null, null];

            case 'email':
                return [$limpio, $tipoEspacios, null];

            case 'fecha':
                $iso = self::fecha($limpio);
                if ($iso === null) {
                    return [$limpio, $tipoEspacios, null]; // la validacion de la carga dira que no es fecha
                }
                return [$iso, $iso !== $crudo ? 'formato_fecha' : null, null];

            case 'numero':
                [$numero, $motivo] = self::numero($limpio);
                if ($numero === null) {
                    return [$limpio, $tipoEspacios, $motivo];
                }
                return [$numero, $numero !== $crudo ? 'formato_numero' : null, null];

            case 'forma_pago':
                if (isset($equivalencias[$limpio])) {
                    return [$equivalencias[$limpio], 'equivalencia_valor', null];
                }
                $valor = self::formaPago($limpio);
                if ($valor === null) {
                    return [$limpio, $tipoEspacios, null];
                }
                return [$valor, $valor !== $crudo ? 'formato_texto' : null, null];

            case 'exento':
                if (isset($equivalencias[$limpio])) {
                    return [$equivalencias[$limpio], 'equivalencia_valor', null];
                }
                $valor = self::exento($limpio);
                if ($valor === null) {
                    return [$limpio, $tipoEspacios, null];
                }
                return [$valor, $valor !== $crudo ? 'formato_texto' : null, null];

            default:
                return [$limpio, $tipoEspacios, null];
        }
    }

    /**
     * Una fecha escrita de cualquiera de las formas no ambiguas, a AAAA-MM-DD.
     * Null si no se reconoce o si no es una fecha real.
     *
     * DOS DIGITOS DE AÑO NO SE ACEPTAN: "05-11-26" puede ser 1926 o 2026, y
     * elegir es inventar. Tampoco mes/dia al estilo de EE.UU.: en Chile el dia
     * va primero, y es la misma convencion de FechaExcel.
     */
    public static function fecha(string $texto): ?string
    {
        $t = mb_strtolower(trim($texto), 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        // Con hora: se conserva la fecha si la hora no la cambia de dia (es
        // la misma fecha, solo que con mas detalle del necesario).
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ t]\d{1,2}:\d{2}(:\d{2})?$/', $t, $m) === 1) {
            $t = $m[1];
        }

        $y = $mes = $d = null;
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $t, $m) === 1) {
            [$y, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $t, $m) === 1) {
            [$d, $mes, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})(?:\s+de\s+|[-\/.\s]+)([a-z]+)\.?(?:\s+de\s+|[-\/.\s]+)(\d{4})$/', $t, $m) === 1
            && isset(self::MESES[$m[2]])) {
            [$d, $mes, $y] = [(int) $m[1], self::MESES[$m[2]], (int) $m[3]];
        }

        if ($y === null || ! checkdate($mes, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $mes, $d);
    }

    /**
     * Un numero escrito a la chilena (o con simbolos), a la forma que entiende
     * PHP: sin miles y con punto decimal. "$ 10.000" -> "10000";
     * "1.234,5" -> "1234.5"; "10,5" -> "10.5".
     *
     * LO AMBIGUO NO SE TOCA: "1,500" es 1500 para quien escribe al estilo
     * ingles y 1,5 para quien escribe a la chilena. Se devuelve null con el
     * motivo y la fila queda con el valor original, que la carga marcara como
     * error. El usuario lo corrige en su archivo; nadie elige por el.
     *
     * SOLO PARA TEXTO. Una celda que en el Excel ya es un numero llega como int
     * o float y no pasa por aqui (ver aplicar()): un 1.234 real no tiene miles
     * que quitar, y esta regla lo convertiria en 1234.
     *
     * @return array{0:?string, 1:?string} [numero o null, motivo si null]
     */
    public static function numero(string $texto): array
    {
        $t = trim((string) preg_replace('/(?:clp|\$|\s)/iu', '', $texto));
        if ($t === '') {
            return [null, 'no es un numero'];
        }

        $tienePunto = str_contains($t, '.');
        $tieneComa  = str_contains($t, ',');

        if ($tienePunto && $tieneComa) {
            $decimal = strrpos($t, ',') > strrpos($t, '.') ? ',' : '.';
            $miles   = $decimal === ',' ? '.' : ',';
            $patron  = '/^-?\d{1,3}(' . preg_quote($miles, '/') . '\d{3})*' . preg_quote($decimal, '/') . '\d+$/';
            if (preg_match($patron, $t) !== 1) {
                return [null, 'no tiene una forma de numero reconocible'];
            }
            $n = str_replace([$miles, $decimal], ['', '.'], $t);
            return [$n, null];
        }
        if ($tienePunto) {
            // Solo puntos y en grupos de tres: miles a la chilena ("10.000").
            if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $t) === 1) {
                return [str_replace('.', '', $t), null];
            }
            return is_numeric($t) ? [$t, null] : [null, 'no tiene una forma de numero reconocible'];
        }
        if ($tieneComa) {
            if (preg_match('/^-?\d{1,3}(,\d{3})+$/', $t) === 1) {
                return [null, 'la coma puede ser separador de miles o de decimales; escribelo sin separador de miles'];
            }
            $n = str_replace(',', '.', $t);
            return is_numeric($n) ? [$n, null] : [null, 'no tiene una forma de numero reconocible'];
        }

        return is_numeric($t) ? [$t, null] : [null, 'no es un numero'];
    }

    /** "contado " -> CONTADO; "Crédito" -> CREDITO. Null si no es uno de los tres valores. */
    public static function formaPago(string $texto): ?string
    {
        $t = strtoupper(strtr(mb_strtolower(trim($texto), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']));
        $t = (string) preg_replace('/\s+/', ' ', $t);
        if ($t === 'AL CONTADO') {
            $t = 'CONTADO';
        }

        return in_array($t, self::VALORES_FORMA_PAGO, true) ? $t : null;
    }

    /** "Sí"/"si"/"S" -> SI; "no"/"N" -> NO. Null para cualquier otra cosa ("x", "1"). */
    public static function exento(string $texto): ?string
    {
        $t = strtoupper(strtr(mb_strtolower(trim($texto), 'UTF-8'), ['í' => 'i']));

        return match ($t) {
            'SI', 'S' => 'SI',
            'NO', 'N' => 'NO',
            default   => null,
        };
    }
}
