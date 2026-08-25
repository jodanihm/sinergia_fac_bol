<?php

declare(strict_types=1);

/**
 * Lee una expresion de cron de cinco campos y responde las dos preguntas que
 * hace quien mira la pantalla de tareas programadas: "cuando corre esto" y
 * "cuando vuelve a correr".
 *
 * POR QUE SE CALCULA Y NO SE ESCRIBE A MANO. La alternativa era poner
 * 'cada 5 minutos' como texto en panel/datos/tareas_programadas.php, al lado de
 * la expresion. Serian dos datos que dicen lo mismo, y el dia que alguien
 * cambie el paso de cinco a diez minutos en /etc/cron.d y actualice la
 * expresion pero no la frase, la pantalla mentiria con toda seguridad. Aqui la frase SALE de la
 * expresion, asi que no pueden discrepar.
 *
 * LA HORA ES LA DE CHILE, y eso importa mas de lo que parece. cron corre en el
 * host con TZ=America/Santiago y los contenedores tienen date.timezone en lo
 * mismo (docker/Dockerfile.panel), asi que DateTimeImmutable sin zona explicita
 * ya esta en hora de Chile. Se pasa el "desde" por parametro en vez de leer el
 * reloj adentro para que el test pueda fijar un instante: una funcion que
 * consulta la hora sola no se puede probar sin esperar.
 *
 * QUE NO HACE. No sabe si la tarea CORRIO. Esto es un calendario, no un
 * monitor: proyecta lo que cron va a hacer si el host esta vivo y el
 * contenedor arriba. La evidencia de ejecucion vive en el log de cada tarea,
 * en el host, fuera del alcance del contenedor del panel.
 *
 * DIA DEL MES Y DIA DE SEMANA, la regla rara de cron. Si los DOS campos estan
 * restringidos, la tarea corre cuando calza CUALQUIERA de los dos, no cuando
 * calzan ambos ('0 0 13 * 5' es el viernes 13... y todos los dias 13, y todos
 * los viernes). Es contraintuitivo y es como se comporta cron de verdad, asi
 * que se replica: una agenda que discrepe del cron real no sirve para nada.
 */
final class AgendaCron
{
    /** Limite de dias que se recorren buscando la proxima coincidencia. */
    private const HORIZONTE_DIAS = 4 * 366;

    /** @var array<int, string> */
    private const NOMBRE_MES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /** @var array<int, string> */
    private const NOMBRE_DIA = [
        0 => 'domingo', 1 => 'lunes', 2 => 'martes', 3 => 'miercoles',
        4 => 'jueves', 5 => 'viernes', 6 => 'sabado',
    ];

    /**
     * Las proximas $cuantas ejecuciones posteriores a $desde (estricto: si
     * $desde cae justo en una ejecucion, esa ya paso y no se devuelve).
     *
     * @return list<DateTimeImmutable>
     * @throws InvalidArgumentException si la expresion no es de cinco campos validos
     */
    public static function proximas(string $expresion, DateTimeImmutable $desde, int $cuantas = 3): array
    {
        $campos = self::campos($expresion);

        // Al minuto exacto y un minuto adelante: cron dispara en segundo 0, y
        // sin truncar los segundos "las 10:05:30" se comparia contra 10:05 y
        // devolveria como futura una ejecucion que ya ocurrio.
        $cursor = $desde->setTime((int) $desde->format('H'), (int) $desde->format('i'))
                        ->modify('+1 minute');

        $encontradas = [];
        $dia         = $cursor->setTime(0, 0);

        for ($n = 0; $n < self::HORIZONTE_DIAS && count($encontradas) < $cuantas; $n++, $dia = $dia->modify('+1 day')) {
            if (!self::diaCalza($dia, $campos)) {
                continue;
            }

            foreach ($campos['hora'] as $hora) {
                foreach ($campos['minuto'] as $minuto) {
                    $momento = $dia->setTime($hora, $minuto);

                    // El primer dia arranca a mitad de camino: las horas
                    // anteriores al cursor son de hoy pero ya pasaron.
                    if ($momento < $cursor) {
                        continue;
                    }

                    $encontradas[] = $momento;

                    if (count($encontradas) === $cuantas) {
                        return $encontradas;
                    }
                }
            }
        }

        return $encontradas;
    }

    /**
     * Cuanto falta para $momento, dicho como lo diria una persona ("en 4
     * minutos", "en 2 horas"). Redondea hacia abajo a la unidad que se
     * muestra: prometer menos espera de la que hay hace que quien mira crea
     * que la tarea se atraso cuando todavia no le tocaba.
     */
    public static function faltan(DateTimeImmutable $desde, DateTimeImmutable $momento): string
    {
        $segundos = $momento->getTimestamp() - $desde->getTimestamp();

        if ($segundos <= 0) {
            return 'ahora';
        }

        $minutos = intdiv($segundos, 60);

        if ($minutos < 1) {
            return 'menos de un minuto';
        }

        if ($minutos < 60) {
            return $minutos === 1 ? '1 minuto' : "{$minutos} minutos";
        }

        $horas = intdiv($minutos, 60);

        if ($horas < 24) {
            return $horas === 1 ? '1 hora' : "{$horas} horas";
        }

        $dias = intdiv($horas, 24);

        return $dias === 1 ? '1 dia' : "{$dias} dias";
    }

    /**
     * La expresion dicha en castellano, para quien no lee cron.
     *
     * Se reconocen las formas frecuentes ('cada 5 minutos', 'todos los dias a
     * las 07:00') y para el resto se arma una frase por partes. Nunca devuelve
     * vacio: una celda en blanco en la pantalla parece un error del sistema,
     * cuando en realidad la expresion es solo poco comun.
     *
     * @throws InvalidArgumentException si la expresion no es de cinco campos validos
     */
    public static function enPalabras(string $expresion): string
    {
        $campos = self::campos($expresion);
        [$min, $hor, $dom, $mes, $dow] = self::partes($expresion);

        $todosLosDias = $dom === '*' && $mes === '*' && $dow === '*';

        // "cada N minutos", la forma mas comun en este proyecto.
        if ($todosLosDias && $hor === '*' && preg_match('#^\*/(\d+)$#', $min, $m) === 1) {
            $n = (int) $m[1];

            return $n === 1 ? 'cada minuto' : "cada {$n} minutos";
        }

        // "cada N horas, en el minuto M".
        if ($todosLosDias && count($campos['minuto']) === 1 && preg_match('#^\*/(\d+)$#', $hor, $m) === 1) {
            $n     = (int) $m[1];
            $cada  = $n === 1 ? 'cada hora' : "cada {$n} horas";

            return $cada . ', en el minuto ' . $campos['minuto'][0];
        }

        $horario = self::horarioEnPalabras($campos);

        if ($todosLosDias) {
            return 'todos los dias ' . $horario;
        }

        return self::diasEnPalabras($dom, $mes, $dow, $campos) . ' ' . $horario;
    }

    /**
     * Los cinco campos ya expandidos a listas de valores.
     *
     * @return array{minuto:list<int>, hora:list<int>, dom:list<int>, mes:list<int>, dow:list<int>, domLibre:bool, dowLibre:bool}
     */
    private static function campos(string $expresion): array
    {
        [$min, $hor, $dom, $mes, $dow] = self::partes($expresion);

        return [
            'minuto'   => self::expandir($min, 0, 59, 'minuto'),
            'hora'     => self::expandir($hor, 0, 23, 'hora'),
            'dom'      => self::expandir($dom, 1, 31, 'dia del mes'),
            'mes'      => self::expandir($mes, 1, 12, 'mes'),
            // El 7 es domingo igual que el 0: cron acepta las dos escrituras.
            'dow'      => self::normalizarDow(self::expandir($dow, 0, 7, 'dia de la semana')),
            'domLibre' => $dom === '*',
            'dowLibre' => $dow === '*',
        ];
    }

    /**
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string}
     */
    private static function partes(string $expresion): array
    {
        $partes = preg_split('/\s+/', trim($expresion), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($partes) !== 5) {
            throw new InvalidArgumentException(
                'La expresion de cron debe tener cinco campos, llegaron ' . count($partes) . ": '{$expresion}'."
            );
        }

        /** @var array{0:string, 1:string, 2:string, 3:string, 4:string} $partes */
        return $partes;
    }

    /**
     * Un campo ('*', '5', '1-4', '0,30', '1-9/2', '0-59/15') a la lista de
     * valores que representa, ordenada y sin repetidos.
     *
     * @return list<int>
     */
    private static function expandir(string $campo, int $min, int $max, string $nombre): array
    {
        $valores = [];

        foreach (explode(',', $campo) as $termino) {
            $termino = trim($termino);

            if ($termino === '') {
                throw new InvalidArgumentException("Campo de {$nombre} con un termino vacio.");
            }

            $paso  = 1;
            $rango = $termino;

            if (str_contains($termino, '/')) {
                [$rango, $textoPaso] = explode('/', $termino, 2);

                if (preg_match('/^\d+$/', $textoPaso) !== 1 || (int) $textoPaso === 0) {
                    throw new InvalidArgumentException("Paso invalido en el campo de {$nombre}: '{$termino}'.");
                }

                $paso = (int) $textoPaso;
            }

            if ($rango === '*') {
                $desde = $min;
                $hasta = $max;
            } elseif (preg_match('/^(\d+)-(\d+)$/', $rango, $m) === 1) {
                $desde = (int) $m[1];
                $hasta = (int) $m[2];
            } elseif (preg_match('/^\d+$/', $rango) === 1) {
                $desde = (int) $rango;
                // '5/10' significa "desde 5 hasta el tope, de a 10"; '5' solo,
                // un unico valor.
                $hasta = $paso === 1 ? $desde : $max;
            } else {
                throw new InvalidArgumentException("Termino no reconocido en el campo de {$nombre}: '{$termino}'.");
            }

            if ($desde < $min || $hasta > $max || $desde > $hasta) {
                throw new InvalidArgumentException(
                    "Rango fuera de limites en el campo de {$nombre}: '{$termino}' (permitido {$min}-{$max})."
                );
            }

            for ($v = $desde; $v <= $hasta; $v += $paso) {
                $valores[$v] = true;
            }
        }

        $lista = array_keys($valores);
        sort($lista);

        /** @var list<int> $lista */
        return $lista;
    }

    /**
     * @param list<int> $dow
     * @return list<int>
     */
    private static function normalizarDow(array $dow): array
    {
        $normal = [];

        foreach ($dow as $d) {
            $normal[$d === 7 ? 0 : $d] = true;
        }

        $lista = array_keys($normal);
        sort($lista);

        /** @var list<int> $lista */
        return $lista;
    }

    /**
     * @param array{minuto:list<int>, hora:list<int>, dom:list<int>, mes:list<int>, dow:list<int>, domLibre:bool, dowLibre:bool} $campos
     */
    private static function diaCalza(DateTimeImmutable $dia, array $campos): bool
    {
        if (!in_array((int) $dia->format('n'), $campos['mes'], true)) {
            return false;
        }

        $calzaDom = in_array((int) $dia->format('j'), $campos['dom'], true);
        $calzaDow = in_array((int) $dia->format('w'), $campos['dow'], true);

        // La regla rara de cron, explicada arriba: con los dos campos
        // restringidos basta con que calce uno.
        if (!$campos['domLibre'] && !$campos['dowLibre']) {
            return $calzaDom || $calzaDow;
        }

        return $calzaDom && $calzaDow;
    }

    /**
     * @param array{minuto:list<int>, hora:list<int>, dom:list<int>, mes:list<int>, dow:list<int>, domLibre:bool, dowLibre:bool} $campos
     */
    private static function horarioEnPalabras(array $campos): string
    {
        $cuantas = count($campos['hora']) * count($campos['minuto']);

        // Enumerar 288 horas no le sirve a nadie; se dice cuantas veces es.
        if ($cuantas > 6) {
            return "a {$cuantas} horas distintas del dia";
        }

        $horas = [];

        foreach ($campos['hora'] as $hora) {
            foreach ($campos['minuto'] as $minuto) {
                $horas[] = sprintf('%02d:%02d', $hora, $minuto);
            }
        }

        return 'a las ' . self::unir($horas);
    }

    /**
     * @param array{minuto:list<int>, hora:list<int>, dom:list<int>, mes:list<int>, dow:list<int>, domLibre:bool, dowLibre:bool} $campos
     */
    private static function diasEnPalabras(string $dom, string $mes, string $dow, array $campos): string
    {
        $trozos = [];

        if ($dow !== '*') {
            $nombres  = array_map(static fn (int $d): string => self::NOMBRE_DIA[$d], $campos['dow']);
            $trozos[] = 'los ' . self::unir($nombres);
        }

        if ($dom !== '*') {
            $trozos[] = 'los dias ' . self::unir(array_map('strval', $campos['dom']));
        }

        if ($mes !== '*') {
            $nombres  = array_map(static fn (int $m): string => self::NOMBRE_MES[$m], $campos['mes']);
            $trozos[] = 'en ' . self::unir($nombres);
        }

        // Con dia del mes Y dia de semana restringidos, cron suma en vez de
        // cruzar. Decirlo con 'o' evita que la frase prometa menos ejecuciones
        // de las que van a ocurrir.
        $union = (!$campos['domLibre'] && !$campos['dowLibre']) ? ' o ' : ', ';

        return implode($union, $trozos);
    }

    /**
     * @param list<string> $textos
     */
    private static function unir(array $textos): string
    {
        if (count($textos) <= 1) {
            return $textos[0] ?? '';
        }

        $ultimo = array_pop($textos);

        return implode(', ', $textos) . ' y ' . $ultimo;
    }
}
