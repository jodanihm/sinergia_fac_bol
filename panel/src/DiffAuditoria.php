<?php

declare(strict_types=1);

/**
 * Diff legible de las dos columnas JSON de admin_auditoria.
 *
 * POR QUE HACE FALTA. registrarAuditoria() guarda el snapshot COMPLETO de la
 * fila antes y despues, que es lo correcto para una bitacora: dentro de seis
 * meses nadie va a saber que columnas existian hoy, asi que guardar solo el
 * campo tocado seria guardar un dato que no se puede reinterpretar. Pero al
 * LEERLO, dos objetos JSON de ocho claves puestos uno al lado del otro obligan
 * a comparar a ojo para encontrar el unico campo que cambio. Suspender una
 * cuenta mueve 'estado' y nada mas; el resto es ruido que hay que descartar
 * manualmente cada vez.
 *
 * Esta clase separa las claves que CAMBIARON del snapshot completo. El
 * snapshot no se pierde: la vista lo sigue mostrando crudo detras de un
 * <details>, porque el diff es una ayuda de lectura y la bitacora es la fila.
 *
 * VIVE EN panel/src/ Y NO EN panel/public/index.php a proposito: es una
 * funcion pura, sin PDO ni sesion, y ahi puede tener tests. index.php es un
 * front controller que se EJECUTA al incluirse, asi que nada de lo que vive
 * adentro se puede probar en aislamiento. Mismo criterio que FechaExcel.
 */
final class DiffAuditoria
{
    /**
     * Compara los dos snapshots y devuelve solo las claves distintas.
     *
     * Un snapshot puede ser null legitimamente: valor_anterior es null en una
     * creacion y valor_nuevo lo es en una eliminacion. En esos casos todas las
     * claves del otro lado cuentan como cambio, que es exactamente lo que paso.
     *
     * SI EL JSON NO SE PUEDE LEER, NO SE INVENTA NADA: se devuelve
     * legible=false y la vista cae al JSON crudo. Una fila de auditoria escrita
     * por una version anterior del codigo, o corrompida, tiene que poder verse
     * igual -- es append-only y no se puede arreglar editandola.
     *
     * @return array{legible:bool, cambios:list<array{clave:string, antes:?string, despues:?string}>}
     *         antes/despues en null significan que la clave NO ESTABA en ese
     *         lado. Un null del propio JSON se formatea como la cadena 'null',
     *         para que los dos casos no se confundan.
     */
    public static function comparar(?string $anteriorJson, ?string $nuevoJson): array
    {
        $antes   = self::decodificar($anteriorJson);
        $despues = self::decodificar($nuevoJson);

        if ($antes === false || $despues === false) {
            return ['legible' => false, 'cambios' => []];
        }

        // Orden: primero las claves del estado NUEVO, en el orden en que se
        // escribieron, y despues las que solo existian antes (o sea, las que
        // se eliminaron). Se lee como se leeria el cambio contado en voz alta.
        $claves = array_keys($despues);
        foreach (array_keys($antes) as $clave) {
            if (! array_key_exists($clave, $despues)) {
                $claves[] = $clave;
            }
        }

        $cambios = [];
        foreach ($claves as $clave) {
            $valorAntes   = array_key_exists($clave, $antes) ? self::formatear($antes[$clave]) : null;
            $valorDespues = array_key_exists($clave, $despues) ? self::formatear($despues[$clave]) : null;

            if ($valorAntes === $valorDespues) {
                continue;
            }

            $cambios[] = [
                'clave'   => (string) $clave,
                'antes'   => $valorAntes,
                'despues' => $valorDespues,
            ];
        }

        return ['legible' => true, 'cambios' => $cambios];
    }

    /**
     * JSON -> array asociativo. null (columna vacia) es un caso VALIDO y da
     * array vacio; cualquier otra cosa que no sea un objeto/array da false, que
     * el llamador traduce a "no legible".
     *
     * @return array<string,mixed>|false
     */
    private static function decodificar(?string $json): array|false
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $valor = json_decode($json, true);

        return is_array($valor) ? $valor : false;
    }

    /**
     * Valor JSON -> texto comparable y mostrable.
     *
     * Todo termina en string a proposito: la comparacion se hace sobre el texto
     * final, asi que lo que el diff declara distinto es exactamente lo que se
     * VE distinto en pantalla. Comparar los valores crudos dejaria pares como
     * 1 y "1" marcados como cambio mostrando lo mismo dos veces.
     */
    private static function formatear(mixed $valor): string
    {
        if ($valor === null) {
            return 'null';
        }
        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }
        if (is_scalar($valor)) {
            return (string) $valor;
        }

        return (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
