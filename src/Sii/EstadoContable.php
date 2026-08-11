<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

/**
 * Que documentos CUENTAN para un total de ventas, y cuales no.
 *
 * POR QUE EXISTE, Y CUANTO COSTO NO TENERLO
 * -----------------------------------------------------------------------------
 * Medido en produccion: 69 de 145 documentos estaban en RCT (rechazados por el
 * SII). Un cliente real tenia 68 documentos rechazados y 68 buenos por LAS
 * MISMAS ventas -- se rechazo el envio, se corrigio y se reemitio -- y el
 * dashboard sumaba los 136. Le mostraba el DOBLE EXACTO de lo que habia
 * vendido: 2.703.160 de mas.
 *
 * El folio de un documento rechazado se quemo igual, asi que la fila tiene que
 * seguir existiendo y siendo visible. Lo que no puede es sumar como venta.
 *
 *
 * EL CRITERIO ES "EXCLUIR LO QUE SE SABE RECHAZADO", NO "INCLUIR LO CONFIRMADO"
 * -----------------------------------------------------------------------------
 * La diferencia no es de estilo: decide si los totales existen o valen cero.
 *
 * 'enviado' significa que el SII RECIBIO el sobre (STATUS=0 en DTEUpload), no
 * que lo aceptara. Es un veredicto DESCONOCIDO. Y ni siquiera EPR es una
 * aceptacion: quiere decir que el SII termino de procesar el sobre, que adentro
 * puede tener documentos aceptados, rechazados y con reparos (ver
 * RegistroVeredictoSii). O sea que "confirmado como aceptado" es hoy un conjunto
 * casi vacio, y filtrar por el dejaria los totales en cero.
 *
 * Un documento recien emitido pasa por 'enviado' hasta quince minutos, que es lo
 * que tarda el cron del runner en preguntarle al SII. Durante esa ventana tiene
 * que contar: la venta ocurrio, y un total que parpadea segun la hora seria
 * peor que uno que incluya un rechazo ocasional.
 *
 *
 * LA LISTA ESTA ESCRITA A MANO. NO SE DERIVA DE RegistroVeredictoSii.
 * -----------------------------------------------------------------------------
 * Esto es lo mas importante de este archivo, y la tentacion de "reusar" la otra
 * clasificacion es exactamente el error que hay que no cometer: LOS DOS DEFAULTS
 * APUNTAN EN DIRECCIONES OPUESTAS.
 *
 *   Para AVISAR (RegistroVeredictoSii::esRechazo)  -> lo desconocido ALERTA.
 *       Un codigo que no sabemos leer puede ser un rechazo, y el costo de un
 *       correo de mas es ridiculo comparado con 68 documentos que nadie miro.
 *
 *   Para TOTALIZAR (esta clase)                    -> lo desconocido CUENTA.
 *       Un codigo que no sabemos leer puede ser una venta buena, y borrarla del
 *       total le miente al cliente sobre su propia facturacion.
 *
 * Y hay un caso concreto donde derivar una de la otra daria un resultado
 * DIRECTAMENTE MALO: DOK. Es la respuesta de getEstDte -- "Documento recibido
 * conforme" -- o sea la unica ACEPTACION por documento que este sistema llega a
 * registrar. Para el aviso cae en "rechazo" por el default conservador, porque
 * no esta en la lista de estados de sobre. Si los totales usaran el inverso de
 * esRechazo(), DOK quedaria EXCLUIDO: se borrarian del total justamente los
 * documentos que el SII confirmo uno por uno.
 *
 * Por eso la lista de abajo se lee y se mantiene sola, aunque se parezca a la
 * otra. Si algun dia el SII agrega un codigo de rechazo, hay que agregarlo en
 * los dos lugares A PROPOSITO, mirando cada uno con su criterio.
 */
final class EstadoContable
{
    /**
     * Estados que EXCLUYEN un documento de cualquier total de ventas.
     *
     * Escritos uno por uno, con lo que significan y con la fuente. Los ocho
     * primeros son codigos de ENVIO y su glosa sale de la enumeracion de
     * docs/44_API_Boleta_Electronica_OpenAPI_Spec.yaml, el unico listado oficial
     * que hay en el repo. El ultimo es de DOCUMENTO y sale de getEstDte.
     *
     *   RCT  Rechazado por Error en Caratula   <- los 69 de produccion
     *   RCH  Rechazado por errores en informacion
     *   RCO  Rechazado por consistencia
     *   RFR  Rechazado por error en firma
     *   RSC  Rechazado por Schema
     *   RPT  Envio Repetido Rechazado
     *   VOF  No se encontro el archivo .xml
     *   ANC  Documento anulado (getEstDte). No es un rechazo del SII sino una
     *        anulacion, pero para un TOTAL DE VENTAS da igual: no se vendio.
     *   DNK  Datos NO coinciden (getEstDte). El SII tiene el folio pero con
     *        otros datos; no se puede afirmar que esa venta sea la que dice
     *        nuestra base.
     *
     * QUE NO ESTA AQUI, Y POR QUE CADA UNO:
     *
     *   DOK          ACEPTACION por documento. Cuenta, y es el caso que hace
     *                que esta lista no pueda derivarse de la otra.
     *   EPR          El sobre se proceso. No es aceptacion, pero tampoco
     *                rechazo: cuenta hasta que sepamos leer sus contadores.
     *   'enviado'    Veredicto desconocido. Cuenta (ver el docblock).
     *   REC SOK FOK
     *   PRD CRT -11  El sobre sigue en proceso. Cuentan.
     *   'desconocido' El SII respondio sin ESTADO legible. Cuenta: no se le
     *                borra una venta al cliente porque no supimos parsear.
     *   RPR          "Aceptado con Reparos". SI cuenta -- el documento es
     *                valido y la venta existe. Nota deliberada: para el AVISO
     *                si dispara correo, porque alguien tiene que mirarlo. Es la
     *                prueba mas clara de que las dos listas son distintas.
     *   ''           Cadena vacia, la que dejaba el bug anterior a la migracion
     *                027. Cuenta, por el mismo motivo que 'desconocido'.
     *
     * @var list<string>
     */
    public const ESTADOS_RECHAZADOS = [
        'RCT',
        'RCH',
        'RCO',
        'RFR',
        'RSC',
        'RPT',
        'VOF',
        'ANC',
        'DNK',
    ];

    /** ¿Este documento queda fuera de los totales? */
    public static function esRechazado(string $estado): bool
    {
        return in_array(trim($estado), self::ESTADOS_RECHAZADOS, true);
    }

    /**
     * Fragmento SQL que EXCLUYE los rechazados. Se concatena a un WHERE que ya
     * tiene al menos una condicion.
     *
     * POR QUE UN FRAGMENTO Y NO UN METODO POR CONSULTA. El criterio estaba
     * repartido en nueve WHERE entre dos front controllers -- el motor y el
     * panel -- que ni siquiera comparten proceso. Es el mismo problema que los
     * seis mapas de nombres de tipo de documento, y con peor final: ahi una
     * copia desactualizada mostraba una etiqueta rara; aqui, un total distinto
     * segun la pantalla desde donde se mire.
     *
     * POR QUE LOS VALORES VAN LITERALES Y NO COMO PARAMETROS. La lista es una
     * constante de este archivo: no hay ni un byte que venga del usuario, asi
     * que no hay superficie de inyeccion. Y bindear obligaria a cada llamador a
     * agregar nueve parametros a su execute(), que es exactamente la friccion
     * que hace que alguien termine copiando el WHERE a mano en vez de usar
     * esto. El escape se aplica igual, por si algun dia la lista se toca.
     *
     * @param string $columna nombre de la columna, con o sin alias de tabla.
     */
    public static function sqlExcluirRechazados(string $columna = 'estado'): string
    {
        return ' AND ' . $columna . ' NOT IN (' . self::listaSql() . ') ';
    }

    /**
     * El complemento: SOLO los rechazados.
     *
     * Existe para que el monto excluido se pueda MOSTRAR. Sacar documentos del
     * total sin enseñarlos en ninguna parte seria recrear el mismo punto ciego
     * en otra forma: el cliente veria bajar su facturacion sin ninguna
     * explicacion a la vista.
     *
     * Va aqui y no en el panel para que las dos caras del filtro salgan
     * SIEMPRE de la misma lista. Si estuvieran en archivos distintos podrian
     * desincronizarse, y entonces lo excluido y lo mostrado como excluido no
     * cuadrarian -- que es peor que no mostrarlo.
     */
    public static function sqlSoloRechazados(string $columna = 'estado'): string
    {
        return ' AND ' . $columna . ' IN (' . self::listaSql() . ') ';
    }

    /**
     * Tipo de DTE que RESTA en cualquier total de ventas: la nota de credito.
     *
     * VIVE AQUI POR EL MISMO MOTIVO QUE LA LISTA DE ARRIBA. "Que documentos
     * cuentan para un total" y "con que signo cuentan" son la misma pregunta, y
     * tenerlas en archivos distintos es como estaba el filtro antes de esta
     * clase: repartido, y con un total distinto segun la pantalla.
     *
     * DUPLICACION CONOCIDA Y NO CERRADA: el panel tiene su propia
     * DASH_TIPO_NOTA_CREDITO = 61 (panel/public/index.php) y dashResumen()
     * aplica el signo en PHP. No se toca aqui porque eso es el camino que hoy
     * factura y se mira todos los dias; pero es la misma copia que
     * sqlExcluirRechazados() vino a eliminar, y el dia que alguien cambie una
     * sin la otra los numeros dejaran de cuadrar.
     */
    public const TIPO_NOTA_CREDITO = 61;

    /**
     * SUMA de una columna de dinero CON EL SIGNO de la nota de credito: resta lo
     * que la NC anula, suma el resto.
     *
     *   EstadoContable::sqlSumaConSigno('neto')
     *   -> SUM(CASE WHEN tipo_dte = 61 THEN -neto ELSE neto END)
     *
     * ES LA MISMA REGLA QUE dashResumen() APLICA EN PHP: "Neto del periodo =
     * (33 factura + 39 boleta + 56 nota de debito) - (61 nota de credito)". Sin
     * el signo, una anulacion SUMA en vez de restar y el cliente aparece con el
     * doble de lo que vendio.
     *
     * -------------------------------------------------------------------------
     * POR QUE EL MENOS VA PEGADO A LA COLUMNA DENTRO DEL CASE, Y NO COMO FACTOR
     * -------------------------------------------------------------------------
     * La primera version de este metodo devolvia el SIGNO suelto, para
     * multiplicarlo:  SUM((CASE WHEN tipo_dte = 61 THEN -1 ELSE 1 END) * neto).
     * Es aritmeticamente lo mismo y REVIENTA:
     *
     *   SQLSTATE[22003]: BIGINT UNSIGNED value is out of range in
     *   '((case when (tipo_dte = 61) then -(1) else 1 end) * dte_emitido.neto)'
     *
     * Las columnas de dinero de dte_emitido son UNSIGNED (neto, exento, iva,
     * impuesto_adicional, total), y en MySQL una multiplicacion en la que uno de
     * los operandos es unsigned se evalua en aritmetica unsigned: el producto
     * negativo se sale del rango y falla ANTES de llegar al SUM.
     *
     * ESTA FORMA ES LA DEL DASHBOARD, y no una variante inventada: es
     * literalmente la de dashTopClientes(),
     *   SUM(CASE WHEN tipo_dte = :nc THEN -total ELSE total END),
     * que lleva meses en produccion sumando notas de credito sin reventar. Ante
     * dos escrituras de la misma regla, manda la que ya funciona -- no un CAST
     * que habria que justificar.
     *
     * @param string $columna      columna o expresion a sumar; puede ir entre
     *                             parentesis, ej. '(iva + impuesto_adicional)'.
     * @param string $columnaTipo  columna del tipo de documento, con o sin alias.
     */
    public static function sqlSumaConSigno(string $columna, string $columnaTipo = 'tipo_dte'): string
    {
        return 'SUM(CASE WHEN ' . $columnaTipo . ' = ' . self::TIPO_NOTA_CREDITO
            . ' THEN -' . $columna . ' ELSE ' . $columna . ' END)';
    }

    private static function listaSql(): string
    {
        return implode(', ', array_map(
            static fn (string $e): string => "'" . str_replace("'", "''", $e) . "'",
            self::ESTADOS_RECHAZADOS
        ));
    }
}
