<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

/**
 * Determina si el SET BASICO de certificacion esta APROBADO.
 *
 * Extraida de panel/public/index.php (donde vivia como una funcion global
 * suelta) para poder escribir un test que reproduzca el bug real que este
 * criterio corrige, sin depender de requerir el front controller del panel
 * completo (que ejecuta el router al final del archivo).
 *
 * BUG REAL CORREGIDO AQUI (ver migracion 009_dte_set_basico_sok.sql): el
 * criterio anterior exigia solo estado==='EPR' + los 3 tipos de documento
 * (33/61/56) en un mismo envio. EPR = "Envio Procesado", el estado de EXITO
 * a nivel de ENVIO -- NO implica que el SII aprobo el CONTENIDO. Esa
 * revision de contenido llega por un correo aparte del SII (SOK = aprobado,
 * SRH = rechazado) y no se persistia en ningun lado del sistema. Con el
 * criterio anterior, si habia VARIOS envios en EPR+3-tipos (ej. un intento
 * rechazado en contenido y el intento real aprobado), se tomaba el primero
 * encontrado en el orden de agruparEmitidosPorEnvio() (ultimoId DESC) --
 * evidencia real: el envio 0253051518 (EASY AGENDA SPA) quedo en EPR pero
 * fue rechazado en contenido (SRH); el criterio anterior lo tomo como
 * "aprobado" en vez del envio realmente aprobado (0253079814, EPR + SOK),
 * simplemente porque estaba primero en la lista.
 *
 * El criterio ahora exige ADEMAS que el tenant haya marcado ESE track_id
 * especifico como SOK a mano (boton "Marcar como SOK", estacion 5), despues
 * de leer el correo real del SII para ese envio -- nunca se infiere.
 */
final class CertificacionEstadoResolver
{
    /**
     * Si hubiera MAS DE UN envio marcado SOK con los 3 tipos (no deberia
     * pasar, pero no se asume imposible): $envios ya viene ordenado por
     * ultimoId DESC (agruparEmitidosPorEnvio() del panel), asi que se toma
     * el PRIMERO que cumpla -- el mas reciente por insercion -- documentado
     * aqui explicitamente en vez de fallar o elegir arbitrariamente.
     *
     * @param list<array{trackId:string, estado:string, tipos:list<int>}> $envios Salida de agruparEmitidosPorEnvio()['envios'] del panel, ya ordenada por ultimoId DESC
     * @param array<string,string> $sokPorTrackId Salida de MySqlSetBasicoSokRepository::confirmadosPorTrackId(): track_id => confirmado_sok_at
     * @return array{aprobado:bool, trackId:?string}
     */
    public static function setBasicoAprobado(array $envios, array $sokPorTrackId): array
    {
        foreach ($envios as $envio) {
            $tieneLosTres = array_diff([33, 61, 56], $envio['tipos']) === [];
            if ($envio['estado'] === 'EPR' && $tieneLosTres && isset($sokPorTrackId[$envio['trackId']])) {
                return ['aprobado' => true, 'trackId' => $envio['trackId']];
            }
        }

        return ['aprobado' => false, 'trackId' => null];
    }

    /**
     * Version MAS LAXA de setBasicoAprobado(): exige estado==='EPR' + los 3
     * tipos de documento, SIN exigir SOK. Es LITERALMENTE el criterio
     * ANTERIOR (el que causo el incidente real descrito arriba) -- ahora
     * expuesto como una opcion EXPLICITA y consciente ("emitir Libro sin
     * esperar SOK", con advertencia de riesgo visible en el panel), no como
     * el comportamiento por defecto silencioso que era antes.
     * setBasicoAprobado() sigue siendo el criterio protegido y por defecto;
     * este metodo NO lo reemplaza ni lo modifica.
     *
     * Riesgo real de usar este criterio: si el SII rechaza despues el
     * contenido del Set Basico (SRH), cualquier Libro ya emitido con este
     * envio como base tambien queda invalido y hay que rehacer ambos.
     *
     * @param list<array{trackId:string, estado:string, tipos:list<int>}> $envios Salida de agruparEmitidosPorEnvio()['envios'] del panel, ya ordenada por ultimoId DESC
     * @return array{aprobado:bool, trackId:?string}
     */
    public static function setBasicoEnviadoSinReparos(array $envios): array
    {
        foreach ($envios as $envio) {
            $tieneLosTres = array_diff([33, 61, 56], $envio['tipos']) === [];
            if ($envio['estado'] === 'EPR' && $tieneLosTres) {
                return ['aprobado' => true, 'trackId' => $envio['trackId']];
            }
        }

        return ['aprobado' => false, 'trackId' => null];
    }
}
