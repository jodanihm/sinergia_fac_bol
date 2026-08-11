<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Las opciones validas de las cinco perillas, para armar el prompt.
 *
 * =============================================================================
 * POR QUE EXISTE: PARA QUE EL PROMPT NO TENGA UNA LISTA ESCRITA A MANO
 * =============================================================================
 *
 * Si el prompt enumerara las metricas por su cuenta, el dia que alguien agregue
 * una al repositorio el modelo seguiria sin conocerla -- o peor, seguiria
 * ofreciendo una que ya no existe y el usuario recibiria un rechazo por algo que
 * el sistema mismo le propuso.
 *
 * Asi que el vocabulario se CONSTRUYE con las constantes del repositorio en el
 * sitio donde se arma el traductor, y no se escribe aqui. Este objeto solo lo
 * transporta.
 *
 * ESO ES UNA CONVENCION DE CABLEADO, no una garantia del compilador -- el
 * traductor vive en src/ (paquete agnostico) y el repositorio en
 * integration/plantiflex/ (sistema anfitrion), y hacer que src/ dependa de
 * integration/ seria invertir la dependencia. La garantia la da un test que
 * compara este vocabulario contra las constantes del repositorio y se pone rojo
 * si divergen: tests/VocabularioConsultaTest.php.
 *
 * NO VALIDA NADA. Validar es del repositorio, que ya lo hace con lista cerrada
 * al recibir las perillas. Este objeto existe para CONTARLE al modelo que puede
 * pedir, no para decidir si lo que pidio sirve.
 */
final class VocabularioConsulta
{
    /**
     * @param list<string>          $metricas
     * @param list<string>          $agrupaciones
     * @param list<string>          $ordenes
     * @param int                   $limiteMax
     * @param array<string,string>  $imposibles agrupacion => por que no se puede
     */
    public function __construct(
        public readonly array $metricas,
        public readonly array $agrupaciones,
        public readonly array $ordenes,
        public readonly int $limiteMax,
        public readonly array $imposibles,
    ) {
    }

    /**
     * El bloque de texto que se le da al modelo. Se genera desde las listas, no
     * se escribe: es el punto entero de esta clase.
     */
    public function comoTexto(): string
    {
        $lineas = [
            'metrica: ' . implode(' | ', $this->metricas),
            'agruparPor: ' . implode(' | ', $this->agrupaciones),
            'orden: ' . implode(' | ', $this->ordenes),
            'limite: entero entre 1 y ' . $this->limiteMax,
            'desde y hasta: fechas AAAA-MM-DD',
        ];

        if ($this->imposibles !== []) {
            $lineas[] = '';
            $lineas[] = 'PREGUNTAS QUE NO SE PUEDEN RESPONDER CON ESTOS DATOS, con su motivo:';
            foreach ($this->imposibles as $que => $porque) {
                $lineas[] = "- {$que}: {$porque}";
            }
        }

        return implode("\n", $lineas);
    }
}
