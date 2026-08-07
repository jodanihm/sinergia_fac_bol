<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * UN BLOQUE de contadores del RESP_BODY de getEstUp: lo que el SII informa para
 * UN TIPO DE DOCUMENTO dentro de un sobre.
 *
 * Respuesta real (track 0253081988, 04-08-2026), ya desescapada:
 *
 *   <SII:RESP_BODY>
 *    <TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>22</INFORMADOS><ACEPTADOS>22</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>
 *    <TIPO_DOCTO>56</TIPO_DOCTO><INFORMADOS>2</INFORMADOS><ACEPTADOS>2</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>
 *    <TIPO_DOCTO>61</TIPO_DOCTO><INFORMADOS>6</INFORMADOS><ACEPTADOS>3</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>3</REPAROS>
 *   </SII:RESP_BODY>
 *
 * Ese tercer bloque -- 6 informados, 3 aceptados, 3 con reparos -- es el que
 * hasta hoy pasaba por bueno: el sobre entero decia EPR y nadie miraba adentro.
 *
 *
 * LOS CAMPOS SON NULLABLE, Y ESO ES EL PUNTO DE ESTA CLASE
 * -----------------------------------------------------------------------------
 * Los bloques del SII son PLANOS: no hay un elemento contenedor por tipo, son
 * cinco etiquetas que se repiten en secuencia. Si a un bloque le faltara un
 * campo, no hay ninguna estructura que lo delate -- y un 0 puesto por defecto se
 * leeria como "0 rechazados", que es exactamente la lectura que costo 68
 * documentos.
 *
 * Por eso lo que falta queda en null y completo() lo dice. Un bloque incompleto
 * NO se convierte en ceros: se marca, y sano() se niega a declararlo bueno. Es
 * la misma regla que ya sigue RegistroVeredictoSii para avisar -- lo que no se
 * puede afirmar bueno, alerta.
 *
 * NO TRAE FOLIOS, y no es una omision nuestra: el SII dice CUANTOS rechazo, no
 * CUALES. Averiguar cual exige getEstDte documento por documento, y eso es otra
 * entrega.
 *
 * Esa ausencia es tambien la razon por la que estos numeros NO tocan ningun
 * total: si de seis notas de credito hay tres observadas y no se sabe cuales,
 * excluir el bloque le restaria al cliente tres ventas buenas. Ver la migracion
 * 030.
 */
final class EstadisticaEnvioSii
{
    public function __construct(
        public readonly ?int $tipoDocto,
        public readonly ?int $informados,
        public readonly ?int $aceptados,
        public readonly ?int $rechazados,
        public readonly ?int $reparos,
    ) {
    }

    /** ¿Llegaron los cinco valores y son numeros? */
    public function completo(): bool
    {
        return $this->tipoDocto !== null
            && $this->informados !== null
            && $this->aceptados !== null
            && $this->rechazados !== null
            && $this->reparos !== null;
    }

    /**
     * ¿Este bloque no tiene nada que mirar?
     *
     * Un bloque INCOMPLETO no es sano: no sabemos si tenia rechazos. Devolver
     * true aqui seria decir "todo bien" sobre un dato que no pudimos leer.
     */
    public function sano(): bool
    {
        return $this->completo() && $this->rechazados === 0 && $this->reparos === 0;
    }

    /** Para el correo y el log: "tipo 61: 6 informados, 3 aceptados, 0 rechazados, 3 reparos". */
    public function resumen(): string
    {
        if (! $this->completo()) {
            return sprintf(
                'tipo %s: BLOQUE ILEGIBLE (informados=%s aceptados=%s rechazados=%s reparos=%s)',
                $this->tipoDocto === null ? '?' : (string) $this->tipoDocto,
                $this->informados ?? '?',
                $this->aceptados ?? '?',
                $this->rechazados ?? '?',
                $this->reparos ?? '?',
            );
        }

        return sprintf(
            'tipo %d: %d informados, %d aceptados, %d rechazados, %d reparos',
            $this->tipoDocto,
            $this->informados,
            $this->aceptados,
            $this->rechazados,
            $this->reparos,
        );
    }
}
