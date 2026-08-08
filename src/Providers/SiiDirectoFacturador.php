<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Providers;

use DOMDocument;
use DOMElement;
use GuzzleHttp\ClientInterface;
use Plantiflex\FacturacionCl\Contracts\DteEmitidoRepositoryInterface;
use Plantiflex\FacturacionCl\Contracts\EmisorRepositoryInterface;
use Plantiflex\FacturacionCl\Contracts\FacturadorInterface;
use Plantiflex\FacturacionCl\Contracts\FolioRepositoryInterface;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\EstadoDte;
use Plantiflex\FacturacionCl\Dto\ResultadoEmision;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\CredencialesInvalidasException;
use Plantiflex\FacturacionCl\Exceptions\OperacionNoSoportadaException;
use Plantiflex\FacturacionCl\Sii\DocumentoDelSobre;
use Plantiflex\FacturacionCl\Sii\DteXmlBuilder;
use Plantiflex\FacturacionCl\Sii\EnvioDteBuilder;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\SiiConsultor;
use Plantiflex\FacturacionCl\Sii\SiiUploader;
use Plantiflex\FacturacionCl\Sii\TedBuilder;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use RuntimeException;
use Throwable;

/**
 * Implementacion del FacturadorInterface que habla DIRECTO al SII (sin LibreDte
 * ni API Gateway).
 *
 * emitir() implementa el flujo completo de emision al SII:
 *   1. Reunir dependencias (CAF, cert+datos, token) SIN quemar folio.
 *   2. Asignar folio (se quema aqui).
 *   3. Construir el DTE individual (DteXmlBuilder).
 *   4. Firmar el <Documento> (XmlSigner::firmarNodo, ID "F{folio}T{tipo}").
 *   5. Construir el EnvioDTE (EnvioDteBuilder).
 *   6. Firmar el <SetDTE> (XmlSigner::firmarNodo, ID "SetDoc").
 *   7-8. Upload multipart al SII (SiiUploader) y procesar TRACKID/STATUS.
 *   9. Marcar folio usado (exito segun STATUS).
 *   10. Devolver ResultadoEmision.
 *
 * Credenciales: el SII no usa apiToken de proveedor. La credencial real es el
 * certificado (via EmisorRepository). De Credenciales se usa rutEmisor,
 * ambiente y rutSender (firmante).
 */
final class SiiDirectoFacturador implements FacturadorInterface
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';
    // RUT del SII como receptor de envios de DTE.
    private const RUT_RECEPTOR_SII = '60803000-K';

    private readonly SiiAutenticador $autenticador;
    private readonly XmlSigner $signer;
    private readonly DteXmlBuilder $dteBuilder;
    private readonly TedBuilder $tedBuilder;
    private readonly EnvioDteBuilder $envioBuilder;
    private readonly SiiUploader $uploader;
    private readonly SiiConsultor $consultor;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly FolioRepositoryInterface $folios,
        private readonly EmisorRepositoryInterface $emisor,
        ?SiiAutenticador $autenticador = null,
        ?XmlSigner $signer = null,
        ?DteXmlBuilder $dteBuilder = null,
        ?EnvioDteBuilder $envioBuilder = null,
        ?SiiUploader $uploader = null,
        ?TedBuilder $tedBuilder = null,
        ?SiiConsultor $consultor = null,
        private readonly ?DteEmitidoRepositoryInterface $dteEmitido = null,
    ) {
        $this->signer       = $signer ?? new XmlSigner();
        $this->autenticador = $autenticador ?? new SiiAutenticador($http, $this->signer);
        $this->dteBuilder   = $dteBuilder ?? new DteXmlBuilder();
        $this->tedBuilder   = $tedBuilder ?? new TedBuilder();
        $this->envioBuilder = $envioBuilder ?? new EnvioDteBuilder();
        $this->uploader     = $uploader ?? new SiiUploader($http);
        $this->consultor    = $consultor ?? new SiiConsultor($http);
    }

    public function nombreProveedor(): string
    {
        return 'sii_directo';
    }

    /**
     * Orquesta la autenticacion: obtiene el certificado del emisor y pide el
     * token al SII. Metodo publico auxiliar (tambien usado por emitir()).
     */
    public function autenticar(Credenciales $cred): string
    {
        $cert = $this->emisor->obtenerCertificado($cred->rutEmisor, $cred->ambiente);
        return $this->autenticador->obtenerToken($cert, $cred->ambiente);
    }

    /**
     * Consulta el estado de un ENVIO por su TrackID. NO consume folios.
     *
     * El SII consulta el resultado del envio (no de un folio individual), asi
     * que el EstadoDte devuelto lleva trackId pero no folio/tipo.
     */
    public function consultarEnvioPorTrackId(string $trackId, Credenciales $cred): EstadoDte
    {
        $token = $this->autenticar($cred);
        $res   = $this->consultor->consultarEnvio($cred->rutEmisor, $trackId, $token, $cred->ambiente);

        return new EstadoDte(
            estado:  $res['estado'] !== '' ? $res['estado'] : 'desconocido',
            glosa:   $res['glosa'] !== '' ? $res['glosa'] : null,
            trackId: $trackId,
            raw:     $res,
        );
    }

    public function emitir(DocumentoTributario $doc, Credenciales $cred): ResultadoEmision
    {
        // 1. Reunir dependencias SIN quemar folio. Si algo falla aqui (cert no
        //    cargado, token rechazado, etc.) la excepcion propaga sin tocar el
        //    contador de folios.
        $cafXml = $this->folios->obtenerCafActivo($cred->rutEmisor, $doc->tipoDte, $cred->ambiente);
        $cert   = $this->emisor->obtenerCertificado($cred->rutEmisor, $cred->ambiente);
        $datos  = $this->emisor->obtenerDatosEmisor($cred->rutEmisor, $cred->ambiente);
        $token  = $this->autenticador->obtenerToken($cert, $cred->ambiente);

        $rutSender = $this->resolverRutSender($cred, $cert);

        // 2. Asignar folio: a partir de aqui queda QUEMADO.
        $folio = $this->folios->asignarSiguienteFolio($cred->rutEmisor, $doc->tipoDte, $cred->ambiente);

        try {
            // 3. DTE individual SIN firmar y SIN TED.
            $dteDoc = $this->dteBuilder->build($doc, $datos, $folio);

            // 4. EnvioDTE: importa el DTE al doc final con namespace SII.
            //    A partir de aqui TODO vive en un solo documento.
            $envioDoc = $this->envioBuilder->build([$dteDoc], $datos, self::RUT_RECEPTOR_SII, $rutSender, $cred->ambiente);

            // 4b. Insertar el TED (timbre) dentro del Documento YA EN EL DOC FINAL.
            //     Debe ser aqui (no en el dteDoc antes de importar): el TED va con
            //     xmlns="" y el importNode de libxml perderia esa declaracion,
            //     cambiando el C14N del DD e invalidando la FRMT. Va ANTES de la
            //     firma del Documento para que esa firma cubra el TED.
            $this->tedBuilder->build($envioDoc, $doc, $datos, $folio, $cafXml);

            // 5. Insertar los ESQUELETOS de firma (Documento(s) y SetDTE) SIN
            //    calcular digests todavia. Toda la mutacion estructural ocurre aqui.
            $idsDocumentos = [];
            foreach ($this->documentos($envioDoc) as $documento) {
                $id = $documento->getAttribute('ID');
                $this->signer->insertarEsqueletoFirma($documento, $id, $cert);
                $idsDocumentos[] = $id;
            }
            $this->signer->insertarEsqueletoFirma($this->primerElemento($envioDoc, 'SetDTE'), 'SetDoc', $cert);

            // 5b. Agregar xmlns:xsi y schemaLocation al root ANTES de congelar. Con
            //     C14N inclusivo el SII hereda el xsi del root al canonicalizar el
            //     Documento; el digest se calcula sobre el doc congelado (ya con xsi),
            //     asi coincide con el del SII. Sin la declaracion: SCH-00001.
            $this->envioBuilder->agregarSchemaLocation($envioDoc);

            // 5c. Congelar: serializar, normalizar el prefijo "default:" y re-parsear.
            //     Desde aqui NO hay mas mutacion estructural; solo se escribe texto,
            //     por lo que los bytes firmados son exactamente los que se envian.
            $this->signer->congelar($envioDoc);

            // 6. Calcular digests y firmar, en orden: Documento(s) PRIMERO (su firma
            //    queda dentro del SetDTE), SetDTE DESPUES (su digest cubre el DTE ya
            //    firmado).
            foreach ($idsDocumentos as $idDoc) {
                $this->signer->calcularDigestYFirmar($envioDoc, $idDoc, $cert);
            }
            $this->signer->calcularDigestYFirmar($envioDoc, 'SetDoc', $cert);

            // DEBUG (opt-in, SII_DUMP_ENVIO=1): volcado del EnvioDTE final, ya
            // firmado, tal cual queda antes de enviarse. Solo para diagnostico
            // de rechazos de caratula; no afecta el flujo normal.
            $this->volcarEnvioDebug($envioDoc, $doc->tipoDte->value, $folio);

            // 7-8. Upload al SII. El SII espera el archivo en ISO-8859-1.
            //    La conversion NO afecta las firmas: estas se calcularon sobre
            //    C14N (siempre UTF-8) y el SII recanonicaliza en UTF-8 para
            //    validar; el encoding del archivo fisico es independiente.
            $envioXml  = $this->serializarParaSii($envioDoc);
            $resultado = $this->uploader->subir($envioXml, $token, $rutSender, $cred->rutEmisor, $cred->ambiente);

            // 9. Folio usado con exito.
            $this->folios->marcarFolioComoUsado($cred->rutEmisor, $doc->tipoDte, $cred->ambiente, $folio, true);

            // 9b. Persistir la emision (best-effort): un fallo de persistencia NO
            //     debe perder un DTE ya aceptado por el SII, por eso se captura.
            $this->persistirEmitido($doc, $cred, $folio, $resultado['trackId'] ?? null, $envioXml);

            // 10. Resultado.
            return new ResultadoEmision(
                tipoDte: $doc->tipoDte,
                folio:   $folio,
                estado:  'enviado',
                trackId: $resultado['trackId'],
                pdfUrl:  null,
                xml:     $envioXml,
                raw:     $resultado,
            );
        } catch (Throwable $e) {
            // Fallo despues de asignar folio -> marcar fallido y relanzar.
            $this->folios->marcarFolioComoUsado($cred->rutEmisor, $doc->tipoDte, $cred->ambiente, $folio, false);
            throw $e;
        }
    }

    /**
     * Persiste la emision en dte_emitido si hay repositorio inyectado. Best-effort:
     * captura cualquier error para no convertir un DTE ya emitido en una excepcion.
     */
    private function persistirEmitido(
        DocumentoTributario $doc,
        Credenciales $cred,
        int $folio,
        ?string $trackId,
        string $envioXml,
    ): void {
        if ($this->dteEmitido === null) {
            return;
        }
        try {
            $m   = $this->extraerMontos($envioXml);
            $ref = $doc->referencias[0] ?? null;
            $this->dteEmitido->registrar(
                rutEmisor:    $cred->rutEmisor,
                ambiente:     $cred->ambiente,
                tipoDte:      $doc->tipoDte->value,
                folio:        $folio,
                trackId:      $trackId,
                estado:       'enviado',
                xml:          $envioXml,
                fechaEmision: $m['fecha'],
                neto:         $m['neto'],
                iva:          $m['iva'],
                total:        $m['total'],
                receptorRut:  $doc->receptor->rut,
                folioRef:     isset($ref['folio']) ? (int) $ref['folio'] : null,
                tipoDteRef:   isset($ref['tipoDocumento']) ? (int) $ref['tipoDocumento'] : null,
                // Migracion 026. Salen del DOCUMENTO y no del XML: son lo que se
                // pidio emitir, y el XML es su consecuencia. Si el documento no
                // los trae quedan NULL, que significa "no se informo" -- y NO 2,
                // aunque el SII interprete asi el silencio: guardar un 2 que
                // nadie eligio borraria la diferencia entre elegir credito y no
                // haber preguntado.
                formaPago:        $doc->formaPago,
                fechaVencimiento: $doc->fechaVencimiento?->format('Y-m-d'),
                // Migracion 028. Estos SI salen del XML y no del documento: son
                // el resultado del calculo del builder (agrupacion por codigo,
                // redondeos), no lo que se pidio. Guardar lo pedido y no lo
                // emitido dejaria la base contando algo distinto del DTE que el
                // SII tiene.
                exento:            $m['exento'],
                impuestoAdicional: $m['impuestoAdicional'],
            );
        } catch (Throwable $e) {
            error_log('dte_emitido registrar fallo (folio ' . $folio . '): ' . $e->getMessage());
        }
    }

    /**
     * Extrae MntNeto/MntExe/IVA/MntTotal, el impuesto adicional y FchEmis del
     * EnvioDTE ya serializado.
     *
     * MntExe E IMPUESTO ADICIONAL SE LEEN DESDE LA MIGRACION 028, y hay una
     * razon aritmetica para que vayan juntos: hasta ahora el exento se podia
     * reconstruir como total - neto - iva, asi que no tener columna era
     * incomodo pero no destructivo. Con un tercer sumando esa resta deja de
     * despejar nada -- una ecuacion, dos incognitas -- y el exento de cualquier
     * documento que ademas lleve ILA quedaria perdido para siempre.
     *
     * @return array{neto:int, exento:int, iva:int, impuestoAdicional:int, total:int, fecha:string}
     */
    private function extraerMontos(string $envioXml): array
    {
        $dom    = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $dom->loadXML($envioXml);
        libxml_use_internal_errors($previo);

        $leer = function (string $tag) use ($dom): ?string {
            $n = $dom->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
            return $n !== null ? trim($n->textContent) : null;
        };

        return [
            'neto'              => (int) ($leer('MntNeto') ?? '0'),
            'exento'            => (int) ($leer('MntExe') ?? '0'),
            'iva'               => (int) ($leer('IVA') ?? '0'),
            'impuestoAdicional' => $this->sumarImpuestoAdicional($dom),
            'total'             => (int) ($leer('MntTotal') ?? '0'),
            'fecha'             => $leer('FchEmis') ?? date('Y-m-d'),
        ];
    }

    /**
     * Suma los MontoImp de TODOS los bloques ImptoReten del ambito dado.
     *
     * Se guarda la SUMA y no cada bloque: la columna responde "cuanto de este
     * total no es ni neto, ni IVA, ni exento", que es lo que necesitan los
     * informes. El desglose por codigo sigue completo en el XML, que es la
     * fuente de verdad del documento.
     *
     * @param DOMDocument|DOMElement $ambito
     */
    private function sumarImpuestoAdicional(object $ambito): int
    {
        $suma = 0;
        foreach ($ambito->getElementsByTagNameNS(self::NS_SII, 'ImptoReten') as $bloque) {
            if (! $bloque instanceof DOMElement) {
                continue;
            }
            $monto = $bloque->getElementsByTagNameNS(self::NS_SII, 'MontoImp')->item(0);
            if ($monto !== null) {
                $suma += (int) trim($monto->textContent);
            }
        }

        return $suma;
    }

    /**
     * Consulta el estado REAL del folio (DOK/DNK) via getEstDte, recuperando el
     * XML emitido desde dte_emitido (fuente de verdad). Sincroniza el estado en
     * la BD. Requiere el repositorio dte_emitido inyectado.
     */
    public function consultarEstado(int $folio, int $tipoDte, Credenciales $cred): EstadoDte
    {
        if ($this->dteEmitido === null) {
            throw new OperacionNoSoportadaException(
                'consultarEstado(folio, tipo) requiere el repositorio dte_emitido inyectado'
                . ' para recuperar el XML del folio (fuente de verdad).'
            );
        }

        $xml = $this->dteEmitido->obtenerXml($cred->rutEmisor, $cred->ambiente, $tipoDte, $folio);
        if ($xml === null) {
            throw new RuntimeException(sprintf(
                'No hay DTE persistido para tipo %d folio %d en ambiente %s.',
                $tipoDte,
                $folio,
                $cred->ambiente->value,
            ));
        }

        $datos = $this->datosConsultaDte($xml, $tipoDte, $folio);

        // Autenticar y resolver el RUT consultante (firmante del certificado).
        $cert           = $this->emisor->obtenerCertificado($cred->rutEmisor, $cred->ambiente);
        $token          = $this->autenticador->obtenerToken($cert, $cred->ambiente);
        $rutConsultante = $this->resolverRutSender($cred, $cert);

        $res = $this->consultor->consultarDte(
            $rutConsultante,
            $cred->rutEmisor,
            $datos['rutRecep'],
            $tipoDte,
            $folio,
            $datos['fchEmis'],
            $datos['mntTotal'],
            $token,
            $cred->ambiente,
        );

        $estado = $res['estado'] !== '' ? $res['estado'] : 'desconocido';

        // Sincronizar la BD con el estado real (best-effort: no romper la lectura).
        //
        // EL NUM_ATENCION NO SE ESCRIBE EN track_id. NUNCA.
        //
        // Esto pasaba antes: $trackId = $res['numAtencion'] y de ahi al UPDATE.
        // Son dos cosas distintas con nombres parecidos. track_id es el TrackID
        // del SOBRE que devolvio DTEUpload, y es la clave con la que
        // RegistroVeredictoSii::persistir() reparte el veredicto a todas las
        // filas del envio y con la que el runner agrupa los pendientes. El
        // NUM_ATENCION es una referencia de atencion de ESTA consulta.
        //
        // Pisarlo dejaba al documento HUERFANO DE SU SOBRE: el fan-out del
        // veredicto ya no lo alcanzaba, porque su track habia dejado de ser el
        // de sus hermanos. Es el mismo mecanismo que se reparo hace poco --
        // veredictos que se escriben en todas las filas del envio -- roto por
        // otro lado.
        //
        // POR QUE SE DESCARTA Y NO SE GUARDA EN OTRA PARTE: guardarlo pide una
        // columna, y una columna se justifica por un consumidor. Hoy no hay
        // ninguno: la funcionalidad de averiguar CUAL documento rechazo el SII
        // se evaluo y se descarto por volumen -- son 2 documentos en produccion,
        // se resuelven a mano. Una columna para una funcion que no se esta
        // construyendo es peso muerto. Y el dato no se pierde: viaja en el
        // EstadoDte que devuelve este metodo, en raw['numAtencion'].
        try {
            $this->dteEmitido->actualizarEstado(
                $cred->rutEmisor,
                $cred->ambiente,
                $tipoDte,
                $folio,
                $estado,
            );
        } catch (Throwable $e) {
            error_log('dte_emitido actualizarEstado fallo (folio ' . $folio . '): ' . $e->getMessage());
        }

        return new EstadoDte(
            estado:  $estado,
            glosa:   $res['glosaErr'] !== '' ? $res['glosaErr'] : ($res['glosa'] !== '' ? $res['glosa'] : null),
            tipoDte: TipoDte::from($tipoDte),
            folio:   $folio,
            raw:     $res,
        );
    }

    /**
     * Extrae del EnvioDTE los datos que getEstDte exige (FchEmis, RUTRecep,
     * MntTotal) DEL DOCUMENTO PEDIDO, no del primero del sobre.
     *
     * QUE ESTABA MAL, Y POR QUE NADIE LO NOTO
     * -------------------------------------------------------------------------
     * Esto leia con getElementsByTagNameNS(...)->item(0) sobre el DOCUMENTO
     * ENTERO. Y el xml que guarda cada fila de dte_emitido es el EnvioDTE
     * COMPLETO, no el documento: lo dice persistirEmitidosLote() unas lineas mas
     * abajo. O sea que en un lote de 20 facturas, 19 recibian el receptor, la
     * fecha y el monto de la PRIMERA, y el SII contestaba DNK -- "datos no
     * coinciden" -- para documentos perfectamente validos.
     *
     * Nadie lo noto porque este camino no se usa: medido en produccion, no hay
     * NI UNA fila en estado DOK, y DOK solo lo escribe aqui. Es un defecto
     * latente, no un incidente.
     *
     * Y ES UN DNK INDISTINGUIBLE DEL REAL. El SII responde lo mismo si el
     * documento fue rechazado que si le mandaste el monto de otro folio: desde
     * su lado es la misma pregunta mal contestada. Por eso la unica defensa es
     * no equivocarse al preguntar.
     *
     * EL CRITERIO ES EL DE DtePdfGenerator::seleccionarDocumento(), NO UNO NUEVO
     * -------------------------------------------------------------------------
     * En el repo habia dos formas de ubicar un documento dentro de un sobre y
     * NO son equivalentes:
     *
     *   seleccionarDocumento()   compara TipoDTE y Folio del propio XML, y
     *                            LANZA si no encuentra.
     *   persistirEmitidosLote()  indexa por el atributo ID "F{folio}T{tipo}",
     *                            que es convencion NUESTRA, y cae a ceros en
     *                            silencio si no lo encuentra.
     *
     * Se toma el primero, por su modo de fallo: caer a ceros es exactamente el
     * defecto que este arreglo viene a quitar. Y compara los datos del SII en
     * vez de un ID que ponemos nosotros. Se implementa sobre DOM y no llamando a
     * esa funcion porque es privada de otra clase y trabaja sobre objetos de
     * LibreDTE, que esta clase no carga; el CRITERIO es el mismo.
     *
     * @throws RuntimeException si el XML no parsea, si el documento no esta en
     *         el sobre, o si le faltan los datos que getEstDte exige.
     * @return array{fchEmis:string, rutRecep:string, mntTotal:int}
     */
    private function datosConsultaDte(string $envioXml, int $tipoDte, int $folio): array
    {
        // LAS TRES GUARDAS -- vacio, ilegible, ausente -- VIVEN EN DocumentoDelSobre.
        // Estaban escritas aqui hasta que el mismo error aparecio en
        // reconstruirOriginal() del motor: dos copias del mismo criterio, escritas
        // por separado, es como se llego a tenerlo mal en los dos sitios.
        $documento = DocumentoDelSobre::ubicar($envioXml, $tipoDte, $folio);

        // AMBITO: el <Documento> encontrado, no el sobre. Es todo el arreglo.
        $leer = static fn (string $tag): string => DocumentoDelSobre::texto($documento, $tag);

        $fchEmis  = $leer('FchEmis');
        $rutRecep = $leer('RUTRecep');

        // MntTotal 0 SI es legitimo -- una NC de correccion de texto lo lleva --
        // asi que no entra en esta guarda. Fecha y receptor vacios no lo son.
        if ($fchEmis === '' || $rutRecep === '') {
            throw new RuntimeException(sprintf(
                'Al documento tipo %d folio %d le faltan datos que getEstDte exige (FchEmis="%s", RUTRecep="%s").',
                $tipoDte,
                $folio,
                $fchEmis,
                $rutRecep,
            ));
        }

        return [
            'fchEmis'  => $fchEmis,
            'rutRecep' => $rutRecep,
            'mntTotal' => (int) ($leer('MntTotal') ?: '0'),
        ];
    }

    /**
     * Anula o corrige-texto un documento, emitiendo una Nota de Credito (61)
     * que lo referencia. Para los casos SIN detalles nuevos:
     *   - AnulaTotal (CodRef=1): la NC replica el receptor y los montos del
     *     original (anula el documento completo).
     *   - CorrigeTexto (CodRef=2): NC con MntTotal=0 (solo corrige datos).
     *
     * Para correccion de monto / devolucion parcial (con Detalle propio) o para
     * emitir una Nota de Debito, usar emitirDocumentoReferenciado().
     *
     * Reusa todo el flujo de emitir() (folio NC, build, TED, firma, envio).
     */
    public function anular(
        DocumentoOriginal $original,
        string $motivo,
        TipoAnulacion $tipo,
        Credenciales $cred,
    ): ResultadoEmision {
        if ($tipo === TipoAnulacion::CorrigeMonto) {
            throw new OperacionNoSoportadaException(
                'CorrigeMonto requiere los Detalle a corregir/devolver: usa'
                . ' emitirDocumentoReferenciado(DocumentoTributario, ...).'
            );
        }

        if ($tipo === TipoAnulacion::CorrigeTexto) {
            // Solo corrige datos: sin montos. MntTotal=0.
            $nc = new DocumentoTributario(
                tipoDte:         TipoDte::NotaCreditoElectronica,
                receptor:        $original->receptor,
                detalles:        [new Detalle('Correccion de texto', 1, 1, exento: true, descuentoPorcentaje: 100.0)],
                montosSonBrutos: $original->montosSonBrutos,
                referencias:     [$this->referenciaAOriginal($original, $tipo, $motivo)],
                observaciones:   $motivo,
                totales:         ['MntTotal' => 0],
            );
        } else {
            // AnulaTotal: replica receptor + montos del original.
            $totales = [
                'MntNeto'  => $original->montoNeto,
                'IVA'      => $original->iva,
                'MntTotal' => $original->montoTotal,
            ];
            $montoExento = $this->montoExentoOriginal($original);
            if ($montoExento > 0) {
                $totales['MntExe'] = $montoExento;
            }

            $nc = new DocumentoTributario(
                tipoDte:         TipoDte::NotaCreditoElectronica,
                receptor:        $original->receptor,
                detalles:        $original->detalles,
                montosSonBrutos: $original->montosSonBrutos,
                referencias:     [$this->referenciaAOriginal($original, $tipo, $motivo)],
                observaciones:   $motivo,
                totales:         $totales,
            );
        }

        return $this->emitir($nc, $cred);
    }

    /**
     * Emite una NC (61) o ND (56) CON Detalle propio, referenciando un documento
     * original. El tipoDte sale del $doc (61 o 56). Sirve para:
     *   - NC por devolucion parcial / correccion de monto (CodRef=3) con sus items.
     *   - Nota de Debito (56) que referencia otro documento (ej. anula una NC, CodRef=1).
     *
     * Si el caller no trae Referencia, se agrega una al original con
     * CodRef=$tipo->value. Si ya trae Referencia, se respeta para no duplicar
     * referencias en NC/ND de certificacion.
     */
    public function emitirDocumentoReferenciado(
        DocumentoTributario $doc,
        DocumentoOriginal $ref,
        TipoAnulacion $tipo,
        Credenciales $cred,
    ): ResultadoEmision {
        // RazonRef: usa las observaciones del documento si vienen.
        $referencia = $this->referenciaAOriginal($ref, $tipo, $doc->observaciones);

        $conRef = new DocumentoTributario(
            tipoDte:            $doc->tipoDte,
            receptor:           $doc->receptor,
            detalles:           $doc->detalles,
            montosSonBrutos:    $doc->montosSonBrutos,
            folio:              $doc->folio,
            fechaEmision:       $doc->fechaEmision,
            referencias:        $doc->referencias !== [] ? $doc->referencias : [$referencia],
            observaciones:      $doc->observaciones,
            totales:            $doc->totales,
            descuentoGlobalPct: $doc->descuentoGlobalPct,
        );

        return $this->emitir($conRef, $cred);
    }

    /**
     * Arma el array de Referencia (formato que consume DteXmlBuilder) apuntando
     * al documento original, con CodRef = valor del TipoAnulacion.
     *
     * @return array<string,mixed>
     */
    private function referenciaAOriginal(DocumentoOriginal $ref, TipoAnulacion $tipo, ?string $motivo): array
    {
        $referencia = [
            'tipoDocumento' => $ref->tipoDte->value,
            'folio'         => $ref->folio,
            'fecha'         => $ref->fechaEmision->format('Y-m-d'),
            'codigo'        => $tipo->value,
        ];
        if ($motivo !== null && $motivo !== '') {
            $referencia['razon'] = $motivo;
        }
        return $referencia;
    }

    private function montoExentoOriginal(DocumentoOriginal $original): int
    {
        $total = 0;
        foreach ($original->detalles as $detalle) {
            if (! $detalle->exento) {
                continue;
            }
            $monto = $detalle->cantidad * $detalle->precioUnitario;
            if ($detalle->descuentoPorcentaje > 0) {
                $monto *= (1 - $detalle->descuentoPorcentaje / 100);
            }
            $total += (int) round($monto);
        }
        return $total;
    }

    // ------------------------------------------------------------------

    private function resolverRutSender(Credenciales $cred, Certificado $cert): string
    {
        if ($cred->rutSender !== null && $cred->rutSender !== '') {
            return $cred->rutSender;
        }
        // Intentar extraer del subject del certificado (campo serialNumber).
        $rut = $this->extraerRutDeCertificado($cert);
        if ($rut !== null) {
            return $rut;
        }
        throw new CredencialesInvalidasException(
            'rutSender no provisto en Credenciales y no se pudo extraer del certificado'
        );
    }

    private function extraerRutDeCertificado(Certificado $cert): ?string
    {
        $parsed = openssl_x509_parse($cert->certData);
        if ($parsed === false) {
            return null;
        }
        // El RUT del firmante suele venir en subject/serialNumber, ej "13520634-2".
        $sn = $parsed['subject']['serialNumber'] ?? null;
        if (is_string($sn) && preg_match('/^\d{7,8}-[\dkK]$/', $sn)) {
            return $sn;
        }
        return null;
    }

    private function primerElemento(DOMDocument $doc, string $tag): DOMElement
    {
        $node = $doc->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
        if (! $node instanceof DOMElement) {
            throw new RuntimeException("SiiDirectoFacturador: no se encontro <$tag> en el documento generado");
        }
        return $node;
    }

    /**
     * Serializa el EnvioDTE en ISO-8859-1, como espera el SII.
     *
     * No afecta las firmas: se calcularon sobre C14N (UTF-8) y el SII
     * recanonicaliza en UTF-8 para validar, asi que el encoding del archivo
     * fisico es independiente. Solo la estructura ASCII y los acentos (ej.
     * razon social) cambian de bytes.
     */
    private function serializarParaSii(DOMDocument $doc): string
    {
        $utf8 = XmlSigner::limpiarPrefijosDsig((string) $doc->saveXML());
        $iso  = (string) mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
        // Ajustar el encoding declarado en la cabecera XML a ISO-8859-1.
        $iso = (string) preg_replace(
            '/(<\?xml[^>]*encoding=")UTF-8("[^>]*\?>)/i',
            '${1}ISO-8859-1${2}',
            $iso,
            1,
        );
        return $iso;
    }

    /**
     * DEBUG opt-in: vuelca a disco el EnvioDTE final (ya construido y firmado,
     * mismo objeto que se serializa para el SOAP), INMEDIATAMENTE antes del
     * envio. Sirve para inspeccionar la caratula real cuando el SII rechaza
     * sin glosa util (ej. "Rechazado por Error en Caratula []").
     *
     * Aditivo y sin riesgo para el flujo de emision:
     *   - Solo actua si la env SII_DUMP_ENVIO vale exactamente '1'. Ausente o
     *     con cualquier otro valor, esta funcion no hace nada (comportamiento
     *     identico al actual).
     *   - Cualquier fallo (permisos, disco lleno, directorio no creable) se
     *     ignora: nunca debe convertir un DTE que sí se puede emitir en un
     *     error de volcado a disco.
     *   - No modifica $envioDoc ni el XML que se envia: solo lee (saveXML) y
     *     escribe una copia aparte.
     */
    private function volcarEnvioDebug(DOMDocument $envioDoc, int $tipoDte, int $folio): void
    {
        if (getenv('SII_DUMP_ENVIO') !== '1') {
            return;
        }
        try {
            $dir = '/app/debug';
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $ruta = sprintf('%s/envio_dte_%d_%d_%s.xml', $dir, $tipoDte, $folio, date('Y-m-d_His'));
            @file_put_contents($ruta, (string) $envioDoc->saveXML());
        } catch (Throwable $e) {
            error_log(sprintf(
                'volcarEnvioDebug fallo (tipo %d folio %d): %s',
                $tipoDte,
                $folio,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Devuelve todos los <Documento> del envio como un array materializado.
     *
     * Se materializa a array (no se itera la DOMNodeList viva) porque firmarNodo
     * inserta nodos hermanos durante el recorrido y mutar el arbol mientras se
     * itera una lista viva es fragil.
     *
     * @return list<DOMElement>
     */
    private function documentos(DOMDocument $doc): array
    {
        $out = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_SII, 'Documento') as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }
        if ($out === []) {
            throw new RuntimeException('SiiDirectoFacturador: no se encontro <Documento> en el envio generado');
        }
        return $out;
    }

    /**
     * Emite varios DTE (incluso de tipos distintos) en UN solo EnvioDTE.
     * No asigna folios: cada doc debe traer doc->folio fijado y sus referencias
     * resueltas. Marca cada folio usado segun el resultado del upload y, si hay
     * repositorio dte_emitido inyectado, persiste cada documento del lote
     * (best-effort, igual que emitir()). Si el envio falla NO se persiste nada.
     *
     * @param list<DocumentoTributario> $docs
     * @return array{trackId:string, raw:mixed, xml:string}
     */
    public function emitirLote(array $docs, Credenciales $cred): array
    {
        if ($docs === []) {
            throw new RuntimeException('emitirLote: lista de documentos vacia');
        }

        $cert      = $this->emisor->obtenerCertificado($cred->rutEmisor, $cred->ambiente);
        $datos     = $this->emisor->obtenerDatosEmisor($cred->rutEmisor, $cred->ambiente);
        $token     = $this->autenticador->obtenerToken($cert, $cred->ambiente);
        $rutSender = $this->resolverRutSender($cred, $cert);

        $cafPorTipo = [];
        $dteDocs    = [];
        $meta       = [];
        foreach ($docs as $doc) {
            $folio = $doc->folio;
            if ($folio === null) {
                throw new RuntimeException('emitirLote: cada documento debe traer su folio fijado (doc->folio)');
            }
            $tipoKey = $doc->tipoDte->value;
            if (! isset($cafPorTipo[$tipoKey])) {
                $cafPorTipo[$tipoKey] = $this->folios->obtenerCafActivo($cred->rutEmisor, $doc->tipoDte, $cred->ambiente);
            }
            $dteDocs[] = $this->dteBuilder->build($doc, $datos, $folio);
            $meta[]    = ['doc' => $doc, 'folio' => $folio, 'caf' => $cafPorTipo[$tipoKey]];
        }

        try {
            $envioDoc = $this->envioBuilder->build($dteDocs, $datos, self::RUT_RECEPTOR_SII, $rutSender, $cred->ambiente);

            $documentos    = $this->documentos($envioDoc);
            $idsDocumentos = [];
            foreach ($documentos as $i => $documento) {
                $this->tedBuilder->build($envioDoc, $meta[$i]['doc'], $datos, $meta[$i]['folio'], $meta[$i]['caf'], $documento);
                $id = $documento->getAttribute('ID');
                $this->signer->insertarEsqueletoFirma($documento, $id, $cert);
                $idsDocumentos[] = $id;
            }
            $this->signer->insertarEsqueletoFirma($this->primerElemento($envioDoc, 'SetDTE'), 'SetDoc', $cert);

            $this->envioBuilder->agregarSchemaLocation($envioDoc);
            $this->signer->congelar($envioDoc);

            foreach ($idsDocumentos as $idDoc) {
                $this->signer->calcularDigestYFirmar($envioDoc, $idDoc, $cert);
            }
            $this->signer->calcularDigestYFirmar($envioDoc, 'SetDoc', $cert);

            $envioXml  = $this->serializarParaSii($envioDoc);
            $resultado = $this->uploader->subir($envioXml, $token, $rutSender, $cred->rutEmisor, $cred->ambiente);

            foreach ($meta as $m) {
                $this->folios->marcarFolioComoUsado($cred->rutEmisor, $m['doc']->tipoDte, $cred->ambiente, $m['folio'], true);
            }

            // Persistir cada documento del lote (best-effort, como en emitir()):
            // solo se llega aqui con el SII habiendo aceptado el envio.
            $this->persistirEmitidosLote($meta, $cred, $resultado['trackId'] ?? null, $envioXml);

            return ['trackId' => $resultado['trackId'], 'raw' => $resultado, 'xml' => $envioXml];
        } catch (Throwable $e) {
            foreach ($meta as $m) {
                $this->folios->marcarFolioComoUsado($cred->rutEmisor, $m['doc']->tipoDte, $cred->ambiente, $m['folio'], false);
            }
            throw $e;
        }
    }

    /**
     * Persiste cada documento de un lote en dte_emitido, si hay repositorio
     * inyectado. Best-effort por fila (igual que persistirEmitido()): un fallo
     * de persistencia no convierte un lote ya aceptado por el SII en excepcion.
     *
     * El xml guardado en cada fila es el EnvioDTE COMPLETO -- MISMO criterio
     * que emitir(): los consumidores (reconstruirOriginal(), consultarEstado())
     * esperan el sobre y ubican el documento adentro por tipo/folio. Los montos
     * y FchEmis si son POR DOCUMENTO: se leen del <Documento> propio de cada
     * folio (ID "F{folio}T{tipo}"), no del primero del sobre como hace
     * extraerMontos().
     *
     * ESA AFIRMACION ERA FALSA PARA DOS DE LOS TRES CONSUMIDORES QUE NOMBRA, y
     * se arreglo en dos entregas distintas. Queda escrito entero porque la
     * leccion no es cual fallaba sino que la frase sola no se distinguia de la
     * realidad: se dio por buena dos veces.
     *
     *   consultarEstado()      su datosConsultaDte() tomaba el PRIMER FchEmis,
     *                          RUTRecep y MntTotal del sobre entero. En un lote
     *                          de 20, diecinueve consultaban a getEstDte con los
     *                          datos de otro documento y recibian DNK -- "datos
     *                          no coinciden" --, indistinguible de un rechazo.
     *
     *   reconstruirOriginal()  (public/index.php) hacia lo mismo con el receptor
     *                          y los montos, Y ADEMAS recorria los <Detalle> del
     *                          SOBRE ENTERO: la nota de credito de anulacion
     *                          salia con las lineas de los treinta documentos.
     *                          Armado sobre 136 documentos de produccion; no se
     *                          disparo porque el tipo 34 estaba bloqueado para
     *                          anular por otro motivo, y no hay ningun 33 en
     *                          lote.
     *
     *   reconstruirOriginal() no era el unico que lo hacia mal, y ninguno de los
     *   dos se enteraba del otro: cada uno tenia su propia copia del criterio.
     *   Hoy los dos llaman a Sii\DocumentoDelSobre::ubicar(), que es donde vive
     *   -- una sola vez -- y por eso la frase de arriba ya es cierta.
     *
     * folio_ref/tipo_dte_ref: a diferencia de persistirEmitido() (que toma
     * referencias[0] tal cual), aqui se toma la PRIMERA referencia con
     * TpoDocRef NUMERICO (la madre). En los documentos de un set la referencia
     * [0] es la del SET (TpoDocRef "SET") y castearla a int guardaria 0.
     *
     * @param list<array{doc: DocumentoTributario, folio: int, caf: mixed}> $meta
     */
    private function persistirEmitidosLote(array $meta, Credenciales $cred, ?string $trackId, string $envioXml): void
    {
        if ($this->dteEmitido === null) {
            return;
        }

        $dom    = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $dom->loadXML($envioXml);
        libxml_use_internal_errors($previo);

        // Montos/fecha por documento, indexados por el ID estandar "F{folio}T{tipo}".
        $montosPorId = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_SII, 'Documento') as $documento) {
            if (! $documento instanceof DOMElement) {
                continue;
            }
            $leer = static function (string $tag) use ($documento): ?string {
                $n = $documento->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
                return $n !== null ? trim($n->textContent) : null;
            };
            $montosPorId[$documento->getAttribute('ID')] = [
                'neto'              => (int) ($leer('MntNeto') ?? '0'),
                'exento'            => (int) ($leer('MntExe') ?? '0'),
                'iva'               => (int) ($leer('IVA') ?? '0'),
                // El ambito es ESTE Documento, no el sobre: un lote lleva N
                // documentos y sumar sobre el sobre entero le daria a cada uno
                // el impuesto de todos.
                'impuestoAdicional' => $this->sumarImpuestoAdicional($documento),
                'total'             => (int) ($leer('MntTotal') ?? '0'),
                'fecha'             => $leer('FchEmis') ?? date('Y-m-d'),
            ];
        }

        foreach ($meta as $m) {
            $doc   = $m['doc'];
            $folio = $m['folio'];
            try {
                $id = sprintf('F%dT%d', $folio, $doc->tipoDte->value);
                $mt = $montosPorId[$id] ?? ['neto' => 0, 'exento' => 0, 'iva' => 0, 'impuestoAdicional' => 0, 'total' => 0, 'fecha' => date('Y-m-d')];

                $folioRef   = null;
                $tipoDteRef = null;
                foreach ($doc->referencias as $ref) {
                    if (is_array($ref) && is_numeric($ref['tipoDocumento'] ?? null) && isset($ref['folio'])) {
                        $tipoDteRef = (int) $ref['tipoDocumento'];
                        $folioRef   = (int) $ref['folio'];
                        break;
                    }
                }

                $this->dteEmitido->registrar(
                    rutEmisor:    $cred->rutEmisor,
                    ambiente:     $cred->ambiente,
                    tipoDte:      $doc->tipoDte->value,
                    folio:        $folio,
                    trackId:      $trackId,
                    estado:       'enviado',
                    xml:          $envioXml,
                    fechaEmision: $mt['fecha'],
                    neto:         $mt['neto'],
                    iva:          $mt['iva'],
                    total:        $mt['total'],
                    receptorRut:  $doc->receptor->rut,
                    folioRef:     $folioRef,
                    tipoDteRef:   $tipoDteRef,
                    // Igual que en el unitario. Hasta la entrega 2 la carga
                    // masiva no los manda, asi que aqui llegan NULL.
                    formaPago:        $doc->formaPago,
                    fechaVencimiento: $doc->fechaVencimiento?->format('Y-m-d'),
                    // Migracion 028, leidos del Documento de ESTE folio dentro
                    // del sobre (ver el comentario de $montosPorId).
                    exento:            $mt['exento'],
                    impuestoAdicional: $mt['impuestoAdicional'],
                );
            } catch (Throwable $e) {
                error_log('dte_emitido registrar fallo (lote, folio ' . $folio . '): ' . $e->getMessage());
            }
        }
    }
}
