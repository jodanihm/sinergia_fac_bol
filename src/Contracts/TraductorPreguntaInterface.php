<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Contracts;

use Plantiflex\FacturacionCl\Dto\PreguntaTraducida;
use Plantiflex\FacturacionCl\Dto\VocabularioConsulta;
use Plantiflex\FacturacionCl\Exceptions\TraduccionPreguntaException;

/**
 * Traduce una pregunta en lenguaje natural a las cinco perillas de la consulta.
 *
 * INTERFAZ PROPIA Y NO EL SDK DEL PROVEEDOR, mismo criterio que
 * ConsultaContribuyenteInterface y BrevoMailer: el proveedor es una decision
 * comercial que puede cambiar, y su paquete traeria sus DTO por todo el arbol.
 * Cambiar de proveedor tiene que ser escribir otra implementacion de esto.
 *
 * LO QUE ESTA INTERFAZ NO HACE, Y ES DELIBERADO:
 *
 *   NO VALIDA. Las perillas que devuelve son lo que el modelo dijo, sin
 *   comprobar. Validar es del repositorio, que ya lo hace con lista cerrada y
 *   distingue "valor invalido" de "no se puede responder". Si esta capa validara
 *   tambien, habria dos validadores que pueden desincronizarse -- y el numero
 *   que ve el usuario dependeria de cual de los dos lo dejo pasar.
 *
 *   NO CONSULTA LA BASE. Ni la toca. Recibe texto y devuelve perillas.
 *
 *   NO RECIBE DATOS DEL TENANT. Ni cuenta_id, ni RUT, ni totales. La firma no
 *   los admite, que es la forma mas barata de garantizar que no viajen.
 */
interface TraductorPreguntaInterface
{
    /**
     * @param string $pregunta Lo que escribio el usuario, tal cual.
     * @param VocabularioConsulta $vocabulario Opciones validas, para el prompt.
     * @param string $hoy Fecha de referencia AAAA-MM-DD, para resolver "el mes
     *        pasado" o "los ultimos 6 meses". Se pasa y no se toma del reloj del
     *        servidor para que el resultado sea reproducible en un test.
     *
     * @throws TraduccionPreguntaException si no hay credencial, si el proveedor
     *         no respondio, o si respondio algo que no se pudo interpretar.
     */
    public function traducir(
        string $pregunta,
        VocabularioConsulta $vocabulario,
        string $hoy,
    ): PreguntaTraducida;
}
