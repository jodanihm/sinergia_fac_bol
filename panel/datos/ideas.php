<?php

declare(strict_types=1);

/**
 * IDEAS -- dato mantenido a mano, sin base de datos.
 *
 * Este archivo SOLO devuelve un array: sin logica, sin consultas, sin salida.
 *
 * POR QUE LAS IDEAS SE SEPARARON DE LOS PENDIENTES, y por que siguen en un
 * archivo cuando los pendientes se fueron a la base (migracion 044). No es
 * simetria rota: son dos cosas distintas y solo una necesitaba una tabla.
 *
 *   UN PENDIENTE es trabajo aceptado que todavia no se hizo. Cambia de estado
 *   muchas veces sin que cambie el codigo -- se toma, se pausa, se cierra --, y
 *   por eso pide una tabla: editar PHP y reconstruir dos imagenes de docker
 *   para anotar que algo quedo en curso es tan caro que nadie lo hace.
 *
 *   UNA IDEA no es trabajo: es una pregunta sin responder. No tiene estados que
 *   recorrer, no se prioriza contra nada, y el dia que se decide que vale deja
 *   de ser una idea y nace como pendiente. Su ciclo de vida entero es "existe /
 *   ya se decidio", y eso no justifica una tabla, ni contadores, ni filtros.
 *
 * MEZCLARLAS ERA EL PROBLEMA. En la misma lista, una idea sin decidir se leia
 * como trabajo pendiente y engordaba el backlog con cosas que a lo mejor nunca
 * se hacen. La pregunta "cuanto falta" no se puede responder si la lista mezcla
 * lo comprometido con lo que solo se esta pensando.
 *
 * CICLO DE VIDA: al decidir una idea SE BORRA DE AQUI. Si se acepta, se crea el
 * pendiente correspondiente en el panel; si se descarta, se va y ya. Esta lista
 * es lo que falta DECIDIR, no un historial.
 *
 * Forma de cada idea:
 *   titulo   una linea
 *   detalle  que resolveria, y que hay que decidir antes de construirla
 *   estado   'nuevo' (sin mirar) | 'en_pausa' (se miro y se dejo para despues)
 */

return [
    [
        'titulo'  => 'Ultimo acceso de cada usuario',
        'detalle' => 'La ficha de cuenta iba a mostrarlo y la tabla usuario no tiene la columna: guarda '
            . 'created_at y activacion_expira, nada sobre el ultimo login. Saber quien no entra hace tres '
            . 'meses sirve para soporte y para detectar cuentas abandonadas. Es una columna nullable mas '
            . 'un UPDATE en handleLoginPost(). Requiere migracion.',
        'estado'  => 'nuevo',
    ],
    [
        'titulo'  => 'Pagina de APIs e integraciones',
        'detalle' => 'El panel hermano tiene una y quedo fuera de alcance a proposito. Aqui las '
            . 'credenciales de API ya se ven por cuenta en la ficha (solo el prefijo) y el motor se '
            . 'configura por variables de entorno, asi que todavia no esta claro que resolveria una '
            . 'pantalla propia. Decidir si vale antes de construirla.',
        'estado'  => 'en_pausa',
    ],
];
