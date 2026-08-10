<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

use PDO;

/**
 * El logo de la empresa: validarlo, guardarlo, leerlo y entregarselo a TCPDF.
 *
 * UN SOLO SITIO PARA LOS TRES LADOS. Lo usan el motor (endpoint del PDF), el
 * runner de correos (adjunto) y el panel (subida y muestras impresas), que son
 * tres procesos distintos. Si cada uno tuviera su propia validacion, el limite
 * de tamano valdria en uno y no en los otros -- que es exactamente la forma en
 * que este proyecto ya se quemo con la clasificacion de veredictos repartida.
 *
 *
 * EL LIMITE DE TAMANO ES LO QUE VIENE A CERRAR ESTA CLASE
 * -----------------------------------------------------------------------------
 * Hoy NO HAY NINGUN limite de tamano en ninguna subida del panel: ni el .pfx, ni
 * el CAF, ni el archivo del SII lo tienen, y no hay php.ini ni configuracion de
 * nginx versionada que fije upload_max_filesize. Todo depende de los defaults de
 * la imagen. Un .pfx invalido lo rechaza openssl_pkcs12_read; un PNG de 40 MB
 * pasaria cualquier validacion de formato y terminaria en la base Y EN CADA PDF
 * QUE SE GENERE, incluido cada adjunto de correo.
 */
final class LogoEmpresa
{
    /**
     * Tope de bytes del archivo subido.
     *
     * DE DONDE SALE EL NUMERO, porque no es redondo por casualidad: el logo se
     * dibuja a 30 mm de ancho (agregarEmisor(), parametro $w_img). A 300 dpi
     * -- mas resolucion de la que necesita una impresion normal -- 30 mm son
     * 354 pixeles de ancho. Un PNG de 354 px de ancho con transparencia y una
     * altura equivalente pesa unas pocas decenas de KB.
     *
     * 512 KB es mas de diez veces eso. Da margen para un logo con degradados o
     * subido a 600 dpi sin pensarlo, y sigue siendo un tamano que se puede
     * cargar en memoria, guardar en la base y adjuntar a un correo sin que a
     * nadie le moleste. Lo que NO deja pasar es la foto de 40 MB que alguien
     * arrastro por error, que es el caso real que hay que impedir.
     */
    public const MAX_BYTES = 512 * 1024;

    /**
     * Ancho con el que agregarEmisor() dibuja el logo. NO ES SOLO DOCUMENTACION:
     * es el default del parametro $w_img de agregarEmisor(), asi que cambiarla
     * cambia el dibujo. Y alimenta tres mensajes de error de validar(): si se
     * desincronizara del dibujo real, al usuario se le informaria un alto
     * resultante que no es el que va a ver en su PDF.
     */
    public const ANCHO_DIBUJO_MM = 40;

    /** Por debajo de esto no es un logo, es un icono roto. */
    public const MIN_PX = 20;

    /** Techo defensivo: nadie necesita mas para el ancho al que se imprime. */
    public const MAX_PX = 3000;

    /**
     * Alto maximo en proporcion al ancho.
     *
     * El manual de muestras impresas del SII pide que el logo no pase de un
     * QUINTO del documento.
     *
     * LA HOJA ES A4, 297 mm, NO LETTER. Esto estuvo mal escrito aqui y por poco
     * nos hace aprobar un limite fuera de norma, asi que queda anotado: LibreDTE
     * pide 'Letter' al construir el PDF, pero
     * TCPDF_STATIC::getPageSizeFromFormat() (tcpdf_static.php:2514) resuelve el
     * formato con un isset() SENSIBLE A MAYUSCULAS y la clave del arreglo es
     * 'LETTER' (tcpdf_static.php:2273). No encuentra 'Letter', cae en A4 POR
     * DEFECTO Y SIN AVISAR. Medido con getPageHeight(): 297,00 mm.
     *
     * El quinto, entonces, son 59,4 mm -- no los 55,9 de Letter.
     *
     * POR QUE 1,25 Y NO 1,5. Dibujado a 40 mm de ancho (antes eran 30), una
     * proporcion de 1,5 daria 60 mm: SE PASA del quinto. El maximo aritmetico
     * seria 1,485, pero un limite que cae 0,6 mm por debajo de un techo
     * normativo no es un limite, es una coincidencia. Con 1,25 el alto maximo
     * son 50 mm: 9 mm de margen bajo la regla del SII, y coincide con el piso
     * de setY(max(50, finEmisor)) del bloque de abajo.
     *
     * ESTO NO PROTEGE A LOS LOGOS YA CARGADOS. La validacion corre AL SUBIR, no
     * al dibujar: lo que ya esta en dte_logo se acepto midiendo contra 30 mm y
     * ahora se dibuja a 40, un 33% mas alto, sin revalidar. Por eso
     * agregarEmisor() baja el resto del bloque con
     * max(fin del nombre, getImageRBY()) en vez de con una constante.
     */
    public const MAX_PROPORCION_ALTO = 1.25;

    /**
     * Valida los bytes subidos. Devuelve el mensaje de error, o null si sirve.
     *
     * EL ORDEN IMPORTA: primero el tamano, porque es la comprobacion barata y la
     * que protege de un archivo gigante; recien despues se llama a
     * getimagesizefromstring(), que tiene que mirar el contenido.
     *
     * @return array{0: ?string, 1: ?array{0:int,1:int}} [error, [ancho, alto]]
     */
    public static function validar(string $bytes): array
    {
        $n = strlen($bytes);
        if ($n === 0) {
            return ['El archivo esta vacio.', null];
        }
        if ($n > self::MAX_BYTES) {
            return [sprintf(
                'El logo pesa %s y el maximo son %s. Reducelo antes de subirlo: se imprime a %d mm de ancho, '
                . 'asi que no necesita mas resolucion.',
                self::humano($n),
                self::humano(self::MAX_BYTES),
                self::ANCHO_DIBUJO_MM
            ), null];
        }

        // getimagesizefromstring() y no getimagesize(): el archivo NUNCA se copia
        // a disco, se valida sobre los bytes que ya estan en memoria. Mismo
        // criterio que el .pfx, que se lee de tmp_name y no se mueve nunca.
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return ['El archivo no es una imagen que podamos leer. Tiene que ser un PNG.', null];
        }

        // El fork llama a Image() con el tipo FIJO en 'PNG'. Un JPEG con nombre
        // .png reventaria al dibujar, no al subir -- y eso saldria como un PDF
        // roto para el cliente final, no como un error en pantalla.
        if (($info[2] ?? 0) !== IMAGETYPE_PNG) {
            return [sprintf(
                'El logo tiene que ser un PNG. El archivo que subiste es %s.',
                image_type_to_mime_type($info[2] ?? 0)
            ), null];
        }

        [$ancho, $alto] = [(int) $info[0], (int) $info[1]];

        if ($ancho < self::MIN_PX || $alto < self::MIN_PX) {
            return [sprintf(
                'El logo mide %dx%d pixeles y es demasiado chico (minimo %d).',
                $ancho, $alto, self::MIN_PX
            ), null];
        }
        if ($ancho > self::MAX_PX || $alto > self::MAX_PX) {
            return [sprintf(
                'El logo mide %dx%d pixeles y es demasiado grande (maximo %d). Se imprime a %d mm de ancho.',
                $ancho, $alto, self::MAX_PX, self::ANCHO_DIBUJO_MM
            ), null];
        }
        if ($alto > $ancho * self::MAX_PROPORCION_ALTO) {
            return [sprintf(
                'El logo es demasiado alto para su ancho (%dx%d). Al imprimirse a %d mm de ancho quedaria de %.0f mm '
                . 'de alto y se montaria sobre los datos de la empresa. El maximo es %.0f mm.',
                $ancho, $alto, self::ANCHO_DIBUJO_MM,
                self::ANCHO_DIBUJO_MM * ($alto / $ancho),
                self::ANCHO_DIBUJO_MM * self::MAX_PROPORCION_ALTO
            ), null];
        }

        return [null, [$ancho, $alto]];
    }

    /**
     * El logo de una empresa, o null si no tiene.
     *
     * SIN JOIN, a proposito: el RUT va como parametro. dte_logo.rut_emisor esta
     * en utf8mb4_unicode_ci y dte_emitido.rut_emisor en utf8mb4_0900_ai_ci --
     * unirlas por texto sin COLLATE explicito da "Illegal mix of collations".
     * Un parametro no tiene collation y el problema no existe. Ver la 031.
     */
    public static function leer(PDO $pdo, string $rutEmisor): ?string
    {
        $stmt = $pdo->prepare('SELECT png FROM dte_logo WHERE rut_emisor = :rut LIMIT 1');
        $stmt->execute([':rut' => $rutEmisor]);
        $png = $stmt->fetchColumn();

        return ($png === false || $png === null || $png === '') ? null : (string) $png;
    }

    /** Metadatos para la pantalla, sin traerse el blob. */
    public static function metadatos(PDO $pdo, string $rutEmisor): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT ancho_px, alto_px, bytes, updated_at FROM dte_logo WHERE rut_emisor = :rut LIMIT 1'
        );
        $stmt->execute([':rut' => $rutEmisor]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    /** Guarda o reemplaza. Asume que validar() ya dijo que si. */
    public static function guardar(PDO $pdo, string $rutEmisor, string $png, int $ancho, int $alto): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO dte_logo (rut_emisor, png, ancho_px, alto_px, bytes) '
            . 'VALUES (:rut, :png, :ancho, :alto, :bytes) '
            . 'ON DUPLICATE KEY UPDATE png = VALUES(png), ancho_px = VALUES(ancho_px), '
            . 'alto_px = VALUES(alto_px), bytes = VALUES(bytes)'
        );
        $stmt->bindValue(':rut', $rutEmisor);
        $stmt->bindValue(':png', $png, PDO::PARAM_LOB);
        $stmt->bindValue(':ancho', $ancho, PDO::PARAM_INT);
        $stmt->bindValue(':alto', $alto, PDO::PARAM_INT);
        $stmt->bindValue(':bytes', strlen($png), PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Quita el logo. Volver a no tenerlo es parte del alcance, no un extra. */
    public static function borrar(PDO $pdo, string $rutEmisor): int
    {
        $stmt = $pdo->prepare('DELETE FROM dte_logo WHERE rut_emisor = :rut');
        $stmt->execute([':rut' => $rutEmisor]);

        return $stmt->rowCount();
    }

    /**
     * Lo que espera TCPDF::Image(): los bytes con un '@' delante.
     *
     * Es la convencion documentada de TCPDF (tcpdf.php, "a '@' character
     * followed by the image data string") y es lo que evita tener que compartir
     * un volumen de disco entre el contenedor del motor y el del panel: el logo
     * viaja como blob desde la base hasta el renderizador sin tocar el
     * sistema de archivos de nadie.
     *
     * NULL entra y NULL sale: asi el llamador puede pasar el resultado directo
     * sin un if, y un documento sin logo recorre el mismo camino de siempre.
     */
    public static function paraTcpdf(?string $png): ?string
    {
        return ($png === null || $png === '') ? null : '@' . $png;
    }

    private static function humano(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? sprintf('%.1f MB', $bytes / 1024 / 1024)
            : sprintf('%d KB', (int) round($bytes / 1024));
    }
}
