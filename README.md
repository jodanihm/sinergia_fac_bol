# plantiflex/facturacion-cl

Modulo agnostico de facturacion electronica para Chile (DTE/SII).

- Standalone (Composer, sin framework).
- Disenado para multi-tenant: las credenciales viajan en cada llamada, no en
  el constructor.
- Proveedor inicial: **LibreDTE** via API REST con Guzzle.
- El contrato `FacturadorInterface` permite agregar otros proveedores
  (OpenFactura, etc.) sin tocar el codigo consumidor.

## Requisitos

- PHP >= 8.2
- ext-json
- `guzzlehttp/guzzle ^7.8`

## Instalacion

```bash
composer require plantiflex/facturacion-cl
```

## Uso minimo: emitir una boleta

```php
<?php

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Providers\LibreDteFacturador;

require __DIR__ . '/vendor/autoload.php';

// 1. Una sola instancia del facturador (puede atender muchos tenants).
$facturador = new LibreDteFacturador(new Client(['timeout' => 30]));

// 2. Credenciales del tenant que esta emitiendo en este momento.
$credenciales = new Credenciales(
    rutEmisor: '76543210-K',
    apiToken: 'TU_TOKEN_LIBREDTE',
    ambiente: Ambiente::Certificacion,
);

// 3. Documento a emitir. Decide explicitamente si los precios son netos o brutos.
$boleta = new DocumentoTributario(
    tipoDte: TipoDte::BoletaElectronica,
    receptor: new Receptor(rut: '11111111-1', razonSocial: 'Cliente Final'),
    detalles: [
        new Detalle(nombre: 'Producto A', cantidad: 2, precioUnitario: 1990),
        new Detalle(nombre: 'Producto B', cantidad: 1, precioUnitario: 4990),
    ],
    montosSonBrutos: true, // precioUnitario incluye IVA
);

// 4. Emitir.
$resultado = $facturador->emitir($boleta, $credenciales);

echo "Folio: {$resultado->folio}, estado: {$resultado->estado}\n";
echo "PDF: {$resultado->pdfUrl}\n";
```

## Multi-tenant

La misma instancia del facturador atiende cualquier RUT. Lo unico que cambia
entre tenants es el objeto `Credenciales` que pasas en cada llamada:

```php
$resultadoA = $facturador->emitir($docA, $credencialesTenantA);
$resultadoB = $facturador->emitir($docB, $credencialesTenantB);
```

## Montos netos vs brutos

Esta es una fuente clasica de bugs en facturacion CL, asi que `DocumentoTributario`
**obliga** a declararlo:

- `montosSonBrutos = false` (default): los `precioUnitario` de los detalles son
  netos (sin IVA). El IVA se calcula y agrega.
- `montosSonBrutos = true`: los `precioUnitario` ya incluyen IVA.

Tipico para boletas a consumidor final: `true`.
Tipico para facturas B2B: `false`.

## Anular un DTE

En el SII, anular se traduce en emitir una Nota de Credito (tipo 61) que
replica el receptor, los detalles y los totales del documento original. La
NC tiene su propio folio (de la serie 61) y se emite por el flujo normal.

El caller PASA los datos del documento original via `DocumentoOriginal`
(receptor, detalles, montos, fecha). Esta capa no consulta el DTE original al
proveedor, para no depender de endpoints de consulta que pueden no estar
confirmados.

```php
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;

$original = new DocumentoOriginal(
    tipoDte:         TipoDte::FacturaElectronica,
    folio:           4242,
    fechaEmision:    new DateTimeImmutable('2025-03-15'),
    receptor:        $receptorReal,
    detalles:        $detallesReales,
    montoNeto:       50000,
    iva:             9500,
    montoTotal:      59500,
    montosSonBrutos: false,
);

$resultado = $facturador->anular(
    original: $original,
    motivo:   'Error en datos del cliente',
    tipo:     TipoAnulacion::AnulaTotal,
    cred:     $credenciales,
);
// $resultado->tipoDte === TipoDte::NotaCreditoElectronica
```

**Estado actual por proveedor:**

| Proveedor | Facturas (33/34) | Boletas (39/41) | CorrigeMonto/Texto |
| --- | --- | --- | --- |
| `ApiGatewayFacturador` | Implementado | `AnulacionNoSoportadaException` (flujo distinto, no validado) | `OperacionNoSoportadaException` |
| `LibreDteFacturador` | `AnulacionNoSoportadaException` (pendiente) | `AnulacionNoSoportadaException` | `AnulacionNoSoportadaException` |

## Consultar estado

```php
$estado = $facturador->consultarEstado(
    folio: 555,
    tipoDte: TipoDte::FacturaElectronica->value,
    cred: $credenciales,
);

echo $estado->estado;   // ej: "aceptado"
echo $estado->glosa;
```

## Manejo de errores

Todas las excepciones extienden `Plantiflex\FacturacionCl\Exceptions\FacturacionException`:

- `CredencialesInvalidasException` — HTTP 401/403, token o RUT incorrecto.
- `EmisionRechazadaException` — el proveedor rechazo el documento. Trae
  `detalle` con la respuesta cruda para diagnostico.
- `ConexionException` — fallo de red o HTTP 5xx.
- `AnulacionNoSoportadaException` — `anular()` esta pendiente de validacion
  en ambiente real; lanza esta excepcion explicitamente.
- `DocumentoInvalidoException` (extiende `InvalidArgumentException`) — el
  DTO se construyo con datos invalidos (RUT vacio, sin detalles, etc.).

## Agregar otro proveedor

Implementa `Plantiflex\FacturacionCl\Contracts\FacturadorInterface` y listo.
El codigo consumidor no cambia.

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Notas sobre LibreDTE

Las rutas exactas de la API de LibreDTE y el formato fino del payload pueden
variar entre Edicion Comunidad y SaaS. Los puntos sensibles estan aislados en
metodos privados de `LibreDteFacturador` (`endpointEmision`, `endpointEstado`,
`buildEmisionPayload`) marcados con `// TODO: verificar contra doc LibreDTE`,
para que se puedan ajustar sin tocar el resto del codigo.
