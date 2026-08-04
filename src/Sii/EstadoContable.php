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

    private static function listaSql(): string
    {
        return implode(', ', array_map(
            static fn (string $e): string => "'" . str_replace("'", "''", $e) . "'",
            self::ESTADOS_RECHAZADOS
        ));
    }
}
