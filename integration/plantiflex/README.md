# integration/plantiflex/

Implementacion concreta del contrato `FolioRepositoryInterface` para el sistema
Plantiflex (PHP puro + MySQL + PDO).

**NO es parte del paquete.** El paquete `plantiflex/facturacion-cl` define
solamente la interfaz (`src/Contracts/FolioRepositoryInterface.php`). Este
directorio es codigo de ejemplo / referencia para el sistema anfitrion.

## Contenido

- `schema.sql` — esquema MySQL (3 tablas: `dte_caf`, `dte_folio`, `dte_folio_log`)
  multi-tenant y multi-ambiente.
- `MySqlFolioRepository.php` — implementacion PDO. Atomica via `SELECT ... FOR UPDATE`.

## Uso

```php
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$repo = new MySqlFolioRepository(
    pdo: $pdo,
    descifrar: fn (string $c): string => openssl_decrypt($c, 'aes-256-gcm', $llave, /* ... */),
    cifrar:    fn (string $p): string => openssl_encrypt($p, 'aes-256-gcm', $llave, /* ... */),
);

$folio = $repo->asignarSiguienteFolio('77724622-4', TipoDte::BoletaElectronica, Ambiente::Produccion);
$xmlCaf = $repo->obtenerCafActivo('77724622-4', TipoDte::BoletaElectronica, Ambiente::Produccion);
// ... emitir ...
$repo->marcarFolioComoUsado('77724622-4', TipoDte::BoletaElectronica, $folio, emisionExitosa: true);
```

## Atomicidad

`asignarSiguienteFolio()` corre dentro de una transaccion y bloquea las filas
candidatas con `SELECT ... FOR UPDATE`. Dos llamadas concurrentes se serializan
sobre la misma fila de `dte_folio` y nunca devuelven el mismo folio.

El `UNIQUE KEY (rut_emisor, tipo_dte, ambiente, folio)` en `dte_folio_log` es
un segundo cinturon: aunque la logica tuviera un bug, la base rechaza la
duplicacion y la transaccion hace rollback.

## Cifrado del CAF

`caf_xml_cifrado` se guarda cifrado. El cifrado/descifrado lo provee el
sistema anfitrion como closures inyectadas. Esta capa NO maneja llaves
maestras ni elige algoritmo.

## Lo que NO hace

- No firma DTE ni maneja certificados.
- No habla con el SII.
- No carga CAFs (eso lo hace una UI/comando del anfitrion).
