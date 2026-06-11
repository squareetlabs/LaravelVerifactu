# Laravel Verifactu - Sistema de facturación electrónica

**Paquete Laravel 10/11/12 para gestión y registro de facturación electrónica VeriFactu**


<p align="center">
<a href="https://scrutinizer-ci.com/g/squareetlabs/LaravelVerifactu/"><img src="https://scrutinizer-ci.com/g/squareetlabs/LaravelVerifactu/badges/quality-score.png?b=main" alt="Quality Score"></a>
<a href="https://scrutinizer-ci.com/g/squareetlabs/LaravelVerifactu/"><img src="https://scrutinizer-ci.com/g/squareetlabs/LaravelVerifactu/badges/code-intelligence.svg?b=main" alt="Code Intelligence"></a>
<a href="https://packagist.org/packages/squareetlabs/laravel-verifactu"><img class="latest_stable_version_img" src="https://poser.pugx.org/squareetlabs/laravel-verifactu/v/stable" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/squareetlabs/laravel-verifactu"><img class="total_img" src="https://poser.pugx.org/squareetlabs/laravel-verifactu/downloads" alt="Total Downloads"></a> 
<a href="https://packagist.org/packages/squareetlabs/laravel-verifactu"><img class="license_img" src="https://poser.pugx.org/squareetlabs/laravel-verifactu/license" alt="License"></a>
</p>

---

## Características principales

- Modelos Eloquent para invoices, breakdowns y recipients
- Enum types para campos fiscales (invoice type, tax type, regime, etc.)
- Helpers para operaciones de fecha, string y hash
- Servicio AEAT client (configurable e inyectable)
- Form Requests para validación
- API Resources para respuestas RESTful
- Factories y tests unitarios para todos los componentes core
- **Modos duales**: VERIFACTU y NO VERIFACTU (Requerimiento)
- **Helper QR**: Generación automática de URLs para códigos QR según modo y entorno
- **Campos avanzados**: Encadenamiento blockchain, facturas rectificativas, subsanación
- **Multi-tenant**: Soporte para múltiples instalaciones bajo el mismo NIF
- **Clientes extranjeros**: Soporte para identificadores internacionales
- **Estado AEAT**: Campos para tracking de respuestas y estado de registro
- Listo para extensión y uso en producción

---

## Instalación

```bash
composer require squareetlabs/laravel-verifactu
```

Publica la configuración y migraciones:

```bash
php artisan vendor:publish --provider="Squareetlabs\VeriFactu\Providers\VeriFactuServiceProvider"
php artisan migrate
```

---

## Configuración

Edita tu archivo `.env` o `config/verifactu.php` según tus necesidades:

```php
return [
    'enabled' => true,
    'system_id' => env('VERIFACTU_SYSTEM_ID', '01'),
    'default_currency' => 'EUR',
    'issuer' => [
        'name' => env('VERIFACTU_ISSUER_NAME', ''),
        'vat' => env('VERIFACTU_ISSUER_VAT', ''),
    ],
    
    // Modo Verifactu (true) o NO Verifactu/Requerimiento (false)
    'verifactu_mode' => env('VERIFACTU_MODE', true),
    
    // Parámetros del Sistema Informático
    'tipo_uso_posible_solo_verifactu' => env('VERIFACTU_TIPO_USO_SOLO_VF', 'N'),
    'tipo_uso_posible_multi_ot' => env('VERIFACTU_TIPO_USO_MULTI_OT', 'S'),
    'indicador_multiples_ot' => env('VERIFACTU_INDICADOR_MULTI_OT', 'N'),
    
    // Define si se cargan las migraciones del paquete (por defecto false)
    'load_migrations' => env('VERIFACTU_LOAD_MIGRATIONS', false),
];
```

### Modos de facturación

Este paquete soporta dos modos de facturación:

#### 1. Modo VERIFACTU (por defecto)
El modo estándar de facturación electrónica.

```env
VERIFACTU_MODE=true
```

#### 2. Modo NO VERIFACTU (Requerimiento)
Para facturas bajo requerimiento de la Agencia Tributaria. Requiere un `RefRequerimiento` proporcionado por Hacienda.

```env
VERIFACTU_MODE=false
```

El paquete ajustará automáticamente las URLs del servicio SOAP según el modo configurado:
- **Producción VERIFACTU**: `https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP`
- **Producción NO VERIFACTU**: `https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP`
- **Pruebas VERIFACTU**: `https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP`
- **Pruebas NO VERIFACTU**: `https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP`

### Colaborador social (entidad colaboradora AEAT)

Si tu plataforma está registrada como **entidad colaboradora** de la AEAT, puedes
remitir registros en nombre de tus clientes usando **el certificado de la
plataforma**: los obligados de emisión (autónomos, pymes…) no necesitan aportar
su propio certificado digital.

```env
# Identidad del colaborador social (añade el bloque Representante a la Cabecera)
VERIFACTU_REP_NAME="Mi Plataforma SL"
VERIFACTU_REP_VAT=B99999999

# El certificado configurado debe ser el del COLABORADOR, no el del emisor
VERIFACTU_CERT_PATH=/ruta/certificado-plataforma.pem
```

En aplicaciones multi-tenant, usa `AeatClientFactory` (resuelve el certificado
vía el contrato `CertificateProvider`, por defecto desde config):

```php
use Squareetlabs\VeriFactu\Services\AeatClientFactory;
use Squareetlabs\VeriFactu\Services\AeatSubmissionResponse;

$client = app(AeatClientFactory::class)->make(
    issuer: ['name' => $company->legal_name, 'vat' => $company->tax_id],
    representative: config('verifactu.representative'),
);

// Huella y timestamp precalculados de tu propia cadena (ver $record):
$result = $client->sendInvoice($invoice, $previous, [
    'hash' => $record->hash,
    'generated_at' => $record->generated_at,
]);

// Respuesta tipada: EstadoEnvio, CSV y errores por línea ya parseados
$response = AeatSubmissionResponse::fromClientResult($result);

if ($response->accepted()) {
    // EstadoEnvio = Correcto; $response->csv disponible
} else {
    Log::warning('AEAT rechazo', ['error' => $response->firstError()]);
}
```

Si tu aplicación guarda los certificados en otro sitio (por emisor, cifrados,
Vault, S3+KMS...), implementa el contrato y re-vincúlalo:

```php
$this->app->bind(
    \Squareetlabs\VeriFactu\Contracts\CertificateProvider::class,
    \App\Services\MyCertificateStore::class,
);
```

### Parámetros del Sistema Informático

Configura los parámetros según las características de tu sistema:

- **tipo_uso_posible_solo_verifactu**: Indica si el sistema solo puede usarse en modo VERIFACTU (`S`) o también en modo NO VERIFACTU (`N`)
- **tipo_uso_posible_multi_ot**: Indica si el sistema puede usarse con múltiples obligados tributarios (`S` o `N`)
- **indicador_multiples_ot**: Indica si existen múltiples obligados tributarios en el sistema (`S` o `N`)

```env
VERIFACTU_TIPO_USO_SOLO_VF=N
VERIFACTU_TIPO_USO_MULTI_OT=S
VERIFACTU_INDICADOR_MULTI_OT=N
```

### Variables de entorno disponibles

Todas las opciones de configuración pueden establecerse mediante variables de entorno en tu archivo `.env`:

```env
# Configuración básica
VERIFACTU_ISSUER_NAME="Mi Empresa S.L."
VERIFACTU_ISSUER_VAT="B12345678"
VERIFACTU_SYSTEM_ID="01"
VERIFACTU_DEFAULT_CURRENCY="EUR"

# Modo de facturación
VERIFACTU_MODE=true                    # true = VERIFACTU, false = NO VERIFACTU

# Parámetros del Sistema Informático
VERIFACTU_TIPO_USO_SOLO_VF=N          # S = Solo VERIFACTU, N = Ambos modos
VERIFACTU_TIPO_USO_MULTI_OT=S         # S = Múltiples OT, N = Un solo OT
VERIFACTU_INDICADOR_MULTI_OT=N        # S = Existen múltiples OT, N = Un solo OT

# Migraciones
VERIFACTU_LOAD_MIGRATIONS=false        # true = Cargar migraciones del paquete
```

### Estructura de configuración completa

El archivo `config/verifactu.php` incluye la siguiente estructura:

```php
return [
    'enabled' => env('VERIFACTU_ENABLED', true),
    'system_id' => env('VERIFACTU_SYSTEM_ID', '01'),
    'default_currency' => env('VERIFACTU_DEFAULT_CURRENCY', 'EUR'),
    
    'issuer' => [
        'name' => env('VERIFACTU_ISSUER_NAME', ''),
        'vat' => env('VERIFACTU_ISSUER_VAT', ''),
    ],
    
    'verifactu_mode' => env('VERIFACTU_MODE', true),
    
    'sistema_informatico' => [
        'tipo_uso_posible_solo_verifactu' => env('VERIFACTU_TIPO_USO_SOLO_VF', 'N'),
        'tipo_uso_posible_multi_ot' => env('VERIFACTU_TIPO_USO_MULTI_OT', 'S'),
        'indicador_multiples_ot' => env('VERIFACTU_INDICADOR_MULTI_OT', 'N'),
    ],
    
    'load_migrations' => env('VERIFACTU_LOAD_MIGRATIONS', false),
];
```

---

## Integración

Este paquete soporta dos modos de uso:

### 1. Proyectos Nuevos (Uso completo)

Si estás empezando un proyecto desde cero, puedes usar los modelos y migraciones que incluye el paquete.

1. Habilita las migraciones en tu `.env`:
   ```env
   VERIFACTU_LOAD_MIGRATIONS=true
   ```
2. Ejecuta las migraciones:
   ```bash
   php artisan migrate
   ```
3. Usa los modelos `Squareetlabs\VeriFactu\Models\Invoice`, `Breakdown` y `Recipient` directamente.

### 2. Sistemas Existentes (Adaptador)

Si ya tienes tu propio sistema de facturación, no necesitas usar nuestras migraciones. Solo necesitas implementar los contratos en tus modelos.

1. Asegúrate de que `VERIFACTU_LOAD_MIGRATIONS` es `false` (por defecto).
2. Genera un adaptador para tu modelo de factura:
   ```bash
   php artisan verifactu:make-adapter Invoice
   ```
3. Esto generará el código necesario en tu terminal. Cópialo a tu modelo `App\Models\Invoice` e implementa la interfaz `VeriFactuInvoice`.

Ejemplo de implementación:

```php
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model implements VeriFactuInvoice
{
    // Implementa los métodos requeridos por la interfaz
    public function getInvoiceNumber(): string
    {
        return $this->invoice_number; // Tu campo personalizado
    }
    
    // ... resto de métodos
}
```

---

## Uso rápido

### Crear una Invoice (Ejemplo de Controller)

```php
use Squareetlabs\VeriFactu\Http\Requests\StoreInvoiceRequest;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Http\Resources\InvoiceResource;

public function store(StoreInvoiceRequest $request)
{
    $invoice = Invoice::create($request->validated());
    // Opcionalmente puedes asociar breakdowns y recipients
    // $invoice->breakdowns()->createMany([...]);
    // $invoice->recipients()->createMany([...]);
    return new InvoiceResource($invoice->load(['breakdowns', 'recipients']));
}
```

---

## Tipos de Invoice disponibles

El paquete soporta todos los tipos de factura según la normativa AEAT:

| Tipo | Enum | Descripción |
|------|------|-------------|
| F1 | `InvoiceType::STANDARD` | Factura completa |
| F2 | `InvoiceType::SIMPLIFIED` | Factura simplificada |
| F3 | `InvoiceType::SUBSTITUTE` | Factura que sustituye a otra |
| F4 | `InvoiceType::EXPORT` | Factura de exportación |
| R1 | `InvoiceType::RECTIFICATIVE_R1` | Rectificativa por sustitución o diferencia |
| R2 | `InvoiceType::RECTIFICATIVE_R2` | Rectificativa por anulación |
| R3 | `InvoiceType::RECTIFICATIVE_R3` | Rectificativa por descuento |
| R4 | `InvoiceType::RECTIFICATIVE_R4` | Rectificativa por incremento |
| R5 | `InvoiceType::RECTIFICATIVE_R5` | Rectificativa por otros conceptos |

## Ejemplos de tipos de Invoice

A continuación, ejemplos de cómo crear cada tipo de invoice usando el modelo y enums:

### Factura estándar
```php
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Enums\InvoiceType;

$invoice = Invoice::create([
    'number' => 'INV-STD-001',
    'date' => '2024-07-01',
    'customer_name' => 'Standard Customer',
    'customer_tax_id' => 'C12345678',
    'issuer_name' => 'Issuer S.A.',
    'issuer_tax_id' => 'B87654321',
    'amount' => 100.00,
    'tax' => 21.00,
    'total' => 121.00,
    'type' => InvoiceType::STANDARD,
]);
```

### Factura simplificada
```php
$invoice = Invoice::create([
    'number' => 'INV-SIMP-001',
    'date' => '2024-07-01',
    'customer_name' => 'Simplified Customer',
    'customer_tax_id' => 'C87654321',
    'issuer_name' => 'Issuer S.A.',
    'issuer_tax_id' => 'B87654321',
    'amount' => 50.00,
    'tax' => 10.50,
    'total' => 60.50,
    'type' => InvoiceType::SIMPLIFIED,
]);
```

### Factura de sustitución
```php
$invoice = Invoice::create([
    'number' => 'INV-SUB-001',
    'date' => '2024-07-01',
    'customer_name' => 'Substitute Customer',
    'customer_tax_id' => 'C11223344',
    'issuer_name' => 'Issuer S.A.',
    'issuer_tax_id' => 'B87654321',
    'amount' => 80.00,
    'tax' => 16.80,
    'total' => 96.80,
    'type' => InvoiceType::SUBSTITUTE,
    // Puedes añadir aquí la relación con facturas sustituidas si implementas la lógica
]);
```

### Factura rectificativa (R1)
```php
use Squareetlabs\VeriFactu\Enums\RectificativeType;

$invoice = Invoice::create([
    'number' => 'INV-RECT-001',
    'date' => '2024-07-01',
    'customer_name' => 'Rectified Customer',
    'customer_tax_id' => 'C55667788',
    'issuer_name' => 'Issuer S.A.',
    'issuer_tax_id' => 'B87654321',
    'amount' => 120.00,
    'tax' => 25.20,
    'total' => 145.20,
    'type' => InvoiceType::RECTIFICATIVE_R1,
    'rectificative_type' => 'S', // S=Sustitución, I=Diferencia
    'rectified_invoices' => json_encode(['INV-001', 'INV-002']),
    'rectification_amount' => json_encode(['base' => -50.00, 'tax' => -10.50, 'total' => -60.50]),
]);
```

### Factura de exportación (F4)

```php
$invoice = Invoice::create([
    'number' => 'INV-EXP-001',
    'date' => '2024-07-01',
    'customer_name' => 'Foreign Customer Ltd',
    'customer_tax_id' => null,
    'customer_country' => 'GB',
    'issuer_name' => 'Issuer S.A.',
    'issuer_tax_id' => 'B87654321',
    'amount' => 500.00,
    'tax' => 0.00,  // Exportaciones exentas de IVA
    'total' => 500.00,
    'type' => InvoiceType::EXPORT,
]);
```

### Factura con encadenamiento blockchain
```php
// Primera factura de la cadena
$firstInvoice = Invoice::create([
    'number' => 'INV-001',
    'date' => '2024-07-01',
    'is_first_invoice' => true,
    'amount' => 100.00,
    'tax' => 21.00,
    'total' => 121.00,
    // ... otros campos
]);

// Segunda factura enlazada
$secondInvoice = Invoice::create([
    'number' => 'INV-002',
    'date' => '2024-07-02',
    'is_first_invoice' => false,
    'previous_invoice_number' => 'INV-001',
    'previous_invoice_date' => '2024-07-01',
    'previous_invoice_hash' => $firstInvoice->hash,
    'amount' => 150.00,
    'tax' => 31.50,
    'total' => 181.50,
    // ... otros campos
]);
```

### Subsanación (reenvío tras rechazo AEAT)
```php
$invoice = Invoice::create([
    'number' => 'INV-SUB-001',
    'date' => '2024-07-01',
    'is_subsanacion' => true,
    'rejected_invoice_number' => 'INV-REJECTED-001',
    'rejection_date' => '2024-06-30',
    'amount' => 100.00,
    'tax' => 21.00,
    'total' => 121.00,
    // ... otros campos
]);
```

> **Nota:** Para facturas rectificativas y sustitutivas, si implementas los campos y relaciones adicionales (como facturas rectificadas/sustituidas, tipo de rectificación, importe de rectificación), deberás añadirlos en el array de creación.

---

## Campos avanzados del modelo Invoice

El modelo `Invoice` incluye campos avanzados para cumplir con todas las especificaciones AEAT:

### Campos de encadenamiento blockchain

```php
$invoice = Invoice::create([
    'number' => 'INV-002',
    'is_first_invoice' => false,                    // false si no es la primera
    'previous_invoice_number' => 'INV-001',         // Número de factura anterior
    'previous_invoice_date' => '2024-07-01',        // Fecha de factura anterior
    'previous_invoice_hash' => 'ABC123...',          // Hash de factura anterior
    // ... otros campos
]);
```

### Campos de facturas rectificativas

```php
$invoice = Invoice::create([
    'number' => 'INV-RECT-001',
    'type' => InvoiceType::RECTIFICATIVE_R1,
    'rectificative_type' => 'I',                    // 'S' = Sustitución, 'I' = Diferencia
    'rectified_invoices' => [                       // Array de facturas rectificadas
        'INV-001',
        'INV-002'
    ],
    'rectification_amount' => [                     // Importes de rectificación
        'base' => -50.00,
        'tax' => -10.50,
        'total' => -60.50
    ],
    // ... otros campos
]);
```

### Campos de subsanación

```php
$invoice = Invoice::create([
    'number' => 'INV-SUBS-001',
    'is_subsanacion' => true,                       // Indica que es subsanación
    'rejected_invoice_number' => 'INV-REJECTED-001', // Número de factura rechazada
    'rejection_date' => '2024-06-30',              // Fecha de rechazo
    // ... otros campos
]);
```

### Campos multi-tenant (múltiples instalaciones)

Si tu sistema gestiona múltiples instalaciones bajo el mismo NIF emisor:

```php
$invoice = Invoice::create([
    'number' => 'INV-001',
    'numero_instalacion' => 'INST-001',            // Identificador de instalación
    // ... otros campos
]);
```

### Campos de estado de respuesta AEAT

Después de enviar una factura a AEAT, puedes actualizar estos campos:

```php
$invoice->update([
    'aeat_status' => 'ACEPTADA',                   // Estado de respuesta AEAT
    'aeat_response_code' => '0',                   // Código de respuesta
    'aeat_response_message' => 'Registro aceptado', // Mensaje de respuesta
    'aeat_registration_date' => now(),            // Fecha de registro en AEAT
    'aeat_csv' => 'CSV-123456789',                // CSV de registro
    'has_aeat_warnings' => false,                 // Si tiene advertencias
]);
```

### Campos adicionales

```php
$invoice = Invoice::create([
    'number' => 'INV-001',
    'operation_date' => '2024-07-01',              // Fecha de operación (si difiere de date)
    'tax_period' => '01',                         // Período fiscal (01, 02, 0A, etc.)
    'external_reference' => 'REF-EXT-123',        // Referencia externa opcional
    'description' => 'Descripción de la operación',
    'status' => 'draft',                          // Estado interno: draft, sent, accepted, rejected
    // ... otros campos
]);
```

### Tabla de referencia rápida de campos

#### Campos básicos
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `number` | string | Número/serie de factura |
| `date` | date | Fecha de expedición |
| `type` | InvoiceType enum | Tipo de factura (F1, F2, R1, etc.) |
| `amount` | decimal | Base imponible |
| `tax` | decimal | Importe de impuestos |
| `total` | decimal | Importe total |

#### Campos de cliente/emisor
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `customer_name` | string\|null | Nombre del cliente |
| `customer_tax_id` | string\|null | NIF/CIF del cliente |
| `customer_country` | string\|null | País del cliente (ISO) |
| `issuer_name` | string | Nombre del emisor |
| `issuer_tax_id` | string | NIF/CIF del emisor |
| `issuer_country` | string | País del emisor (ISO) |

#### Campos de encadenamiento blockchain
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `is_first_invoice` | boolean | Si es la primera factura de la cadena |
| `previous_invoice_number` | string\|null | Número de factura anterior |
| `previous_invoice_date` | date\|null | Fecha de factura anterior |
| `previous_invoice_hash` | string\|null | Hash de factura anterior |
| `hash` | string\|null | Hash de esta factura |

#### Campos de facturas rectificativas
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `rectificative_type` | string\|null | 'S' = Sustitución, 'I' = Diferencia |
| `rectified_invoices` | array | Array de números de facturas rectificadas |
| `rectification_amount` | array | Importes de rectificación (base, tax, total) |

#### Campos de subsanación
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `is_subsanacion` | boolean | Si es una subsanación |
| `rejected_invoice_number` | string\|null | Número de factura rechazada |
| `rejection_date` | date\|null | Fecha de rechazo |

#### Campos multi-tenant
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `numero_instalacion` | string\|null | Identificador de instalación |

#### Campos de estado AEAT
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `aeat_status` | string\|null | Estado: ACEPTADA, RECHAZADA, ENVIADA |
| `aeat_response_code` | string\|null | Código de respuesta AEAT |
| `aeat_response_message` | string\|null | Mensaje de respuesta |
| `aeat_registration_date` | datetime\|null | Fecha de registro en AEAT |
| `aeat_csv` | string\|null | CSV de registro |
| `has_aeat_warnings` | boolean | Si tiene advertencias |

#### Campos adicionales
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `operation_date` | date\|null | Fecha de operación (si difiere de date) |
| `tax_period` | string\|null | Período fiscal (01, 02, 0A, etc.) |
| `correction_type` | string\|null | Tipo de corrección |
| `external_reference` | string\|null | Referencia externa |
| `description` | string\|null | Descripción de la operación |
| `status` | string | Estado interno (draft, sent, accepted, rejected) |
| `csv` | string\|null | CSV interno |

---

## Ejemplos avanzados

### Factura con cliente extranjero

Para clientes extranjeros, usa el campo `id_type` en el modelo `Recipient`:

```php
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Recipient;
use Squareetlabs\VeriFactu\Enums\ForeignIdType;

$invoice = Invoice::create([
    'number' => 'INV-INT-001',
    'date' => '2024-07-01',
    'customer_name' => 'Foreign Customer Ltd',
    'customer_tax_id' => null,                     // Sin NIF para extranjeros
    'customer_country' => 'GB',                    // Código ISO del país
    // ... otros campos
]);

// Crear recipient con identificador extranjero
$recipient = Recipient::create([
    'invoice_id' => $invoice->id,
    'name' => 'Foreign Customer Ltd',
    'tax_id' => 'GB123456789',                     // VAT number del país
    'country' => 'GB',
    'id_type' => ForeignIdType::VAT_NUMBER,       // Tipo de identificador
]);
```

Tipos de identificadores extranjeros disponibles:
- `ForeignIdType::VAT_NUMBER` - Número de IVA
- `ForeignIdType::PASSPORT` - Pasaporte
- `ForeignIdType::OFFICIAL_ID` - Documento oficial de identidad
- `ForeignIdType::RESIDENCE_CERTIFICATE` - Certificado de residencia
- `ForeignIdType::OTHER` - Otro tipo

### Factura rectificativa completa (por diferencia)

```php
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Enums\InvoiceType;

// Factura original
$originalInvoice = Invoice::create([
    'number' => 'INV-001',
    'date' => '2024-07-01',
    'amount' => 100.00,
    'tax' => 21.00,
    'total' => 121.00,
    'type' => InvoiceType::STANDARD,
    // ... otros campos
]);

// Factura rectificativa por diferencia
$rectificativeInvoice = Invoice::create([
    'number' => 'INV-RECT-001',
    'date' => '2024-07-15',
    'type' => InvoiceType::RECTIFICATIVE_R1,
    'rectificative_type' => 'I',                   // I = Diferencia
    'rectified_invoices' => ['INV-001'],          // Facturas rectificadas
    'rectification_amount' => [
        'base' => -20.00,                          // Diferencia en base
        'tax' => -4.20,                            // Diferencia en impuesto
        'total' => -24.20                          // Diferencia total
    ],
    'amount' => -20.00,                            // Importe negativo
    'tax' => -4.20,
    'total' => -24.20,
    // ... otros campos
]);
```

### Factura con múltiples instalaciones (multi-tenant)

```php
$invoice = Invoice::create([
    'number' => 'INV-001',
    'date' => '2024-07-01',
    'numero_instalacion' => 'INST-001',            // Identificador de instalación
    'issuer_name' => 'Empresa Principal S.A.',
    'issuer_tax_id' => 'B12345678',
    // ... otros campos
]);

// Otra factura de diferente instalación pero mismo emisor
$invoice2 = Invoice::create([
    'number' => 'INV-002',
    'date' => '2024-07-01',
    'numero_instalacion' => 'INST-002',           // Diferente instalación
    'issuer_name' => 'Empresa Principal S.A.',
    'issuer_tax_id' => 'B12345678',                // Mismo NIF emisor
    // ... otros campos
]);
```

### Cadena completa de facturas (blockchain)

```php
// Primera factura de la cadena
$first = Invoice::create([
    'number' => 'INV-001',
    'date' => '2024-07-01',
    'is_first_invoice' => true,
    'amount' => 100.00,
    'tax' => 21.00,
    'total' => 121.00,
    // ... otros campos
]);

// Calcular hash (se hace automáticamente si está configurado)
$first->hash = HashHelper::generateInvoiceHash([...])['hash'];
$first->save();

// Segunda factura enlazada
$second = Invoice::create([
    'number' => 'INV-002',
    'date' => '2024-07-02',
    'is_first_invoice' => false,
    'previous_invoice_number' => $first->number,
    'previous_invoice_date' => $first->date,
    'previous_invoice_hash' => $first->hash,
    'amount' => 150.00,
    'tax' => 31.50,
    'total' => 181.50,
    // ... otros campos
]);

// Tercera factura enlazada
$third = Invoice::create([
    'number' => 'INV-003',
    'date' => '2024-07-03',
    'is_first_invoice' => false,
    'previous_invoice_number' => $second->number,
    'previous_invoice_date' => $second->date,
    'previous_invoice_hash' => $second->hash,
    'amount' => 200.00,
    'tax' => 42.00,
    'total' => 242.00,
    // ... otros campos
]);
```

### Subsanación de factura rechazada

```php
// Factura original que fue rechazada
$rejectedInvoice = Invoice::create([
    'number' => 'INV-REJECTED-001',
    'date' => '2024-06-30',
    'aeat_status' => 'RECHAZADA',
    'aeat_response_code' => '1',
    'aeat_response_message' => 'Error en validación',
    // ... otros campos
]);

// Factura de subsanación
$subsanacionInvoice = Invoice::create([
    'number' => 'INV-SUBS-001',
    'date' => '2024-07-01',
    'is_subsanacion' => true,
    'rejected_invoice_number' => 'INV-REJECTED-001',
    'rejection_date' => '2024-06-30',
    // Corregir los datos que causaron el rechazo
    'amount' => 100.00,                             // Corregido
    'tax' => 21.00,
    'total' => 121.00,
    // ... otros campos corregidos
]);
```

---

## Envío de Invoice a AEAT (Ejemplo de Controller)

```php
use Illuminate\Http\Request;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Squareetlabs\VeriFactu\Models\Invoice;

class InvoiceAeatController extends Controller
{
    public function send(Request $request, AeatClient $aeatClient, $invoiceId)
    {
        $invoice = Invoice::with(['breakdowns', 'recipients'])->findOrFail($invoiceId);
        $result = $aeatClient->sendInvoice($invoice);
        
        // Actualizar campos de estado si el envío fue exitoso
        if ($result['status'] === 'success') {
            $invoice->update([
                'aeat_status' => $result['aeat_status'] ?? 'ENVIADA',
                'aeat_response_code' => $result['code'] ?? null,
                'aeat_response_message' => $result['message'] ?? null,
                'aeat_registration_date' => $result['registration_date'] ?? null,
                'aeat_csv' => $result['csv'] ?? null,
                'has_aeat_warnings' => $result['has_warnings'] ?? false,
            ]);
        }
        
        return response()->json($result, $result['status'] === 'success' ? 200 : 422);
    }
}
```

> **Nota:** Protege este endpoint con autenticación/autorización adecuada.
> 
> El resultado incluirá el XML enviado y recibido, útil para depuración.
> 
> Si el certificado no es válido o hay error de validación, el array tendrá 'status' => 'error' y 'message'.

### Consultar estado de facturas enviadas

Puedes consultar el estado de las facturas después de enviarlas:

```php
use Squareetlabs\VeriFactu\Models\Invoice;

// Buscar facturas por estado
$acceptedInvoices = Invoice::where('aeat_status', 'ACEPTADA')->get();
$rejectedInvoices = Invoice::where('aeat_status', 'RECHAZADA')->get();
$pendingInvoices = Invoice::whereNull('aeat_status')->get();

// Buscar facturas con advertencias
$invoicesWithWarnings = Invoice::where('has_aeat_warnings', true)->get();

// Buscar facturas por CSV
$invoice = Invoice::where('aeat_csv', 'CSV-123456789')->first();

// Obtener información completa de una factura
$invoice = Invoice::find(1);
echo "Estado: " . $invoice->aeat_status;
echo "Código: " . $invoice->aeat_response_code;
echo "Mensaje: " . $invoice->aeat_response_message;
echo "CSV: " . $invoice->aeat_csv;
echo "Fecha registro: " . $invoice->aeat_registration_date;
```

---

## Validación y creación de Breakdown (Ejemplo de Controller)

```php
use Squareetlabs\VeriFactu\Http\Requests\StoreBreakdownRequest;
use Squareetlabs\VeriFactu\Models\Breakdown;

public function storeBreakdown(StoreBreakdownRequest $request)
{
    $breakdown = Breakdown::create($request->validated());
    return response()->json($breakdown);
}
```

---

## Uso de Helpers

### Helpers de fecha, string y hash

```php
use Squareetlabs\VeriFactu\Helpers\DateTimeHelper;
use Squareetlabs\VeriFactu\Helpers\StringHelper;
use Squareetlabs\VeriFactu\Helpers\HashHelper;

$dateIso = DateTimeHelper::formatIso8601('2024-01-01 12:00:00');
$sanitized = StringHelper::sanitize('  &Hello <World>  ');
$hash = HashHelper::generateInvoiceHash([
    'issuer_tax_id' => 'A12345678',
    'invoice_number' => 'INV-001',
    'issue_date' => '2024-01-01',
    'invoice_type' => 'F1',
    'total_tax' => '21.00',
    'total_amount' => '121.00',
    'previous_hash' => '',
    'generated_at' => '2024-01-01T12:00:00+01:00',
]);
```

### Generación de URL para códigos QR

El paquete incluye un helper para generar las URLs necesarias para los códigos QR que deben incluirse en las facturas según la normativa VERIFACTU.

```php
use Squareetlabs\VeriFactu\Helpers\QrUrlHelper;
use Squareetlabs\VeriFactu\Models\Invoice;

// Obtener la factura
$invoice = Invoice::find(1);

// Generar URL para QR en modo VERIFACTU (producción)
$qrUrl = QrUrlHelper::build(
    $invoice, 
    'B12345678',  // NIF del emisor
    true,         // true = producción, false = pruebas
    true          // true = VERIFACTU, false = NO VERIFACTU
);

// Resultado: https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345678&numserie=FAC-001&fecha=15-01-2024&importe=121.00
```

El helper generará automáticamente la URL correcta según el entorno y modo:

- **Producción VERIFACTU**: `https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR`
- **Producción NO VERIFACTU**: `https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu`
- **Pruebas VERIFACTU**: `https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR`
- **Pruebas NO VERIFACTU**: `https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu`

Si no proporcionas el parámetro `verifactuMode`, el helper usará el valor configurado en `config/verifactu.php`:

```php
// Usa la configuración por defecto del modo
$qrUrl = QrUrlHelper::build($invoice, 'B12345678', true);
```

**Ejemplo en un controlador:**

```php
use Squareetlabs\VeriFactu\Helpers\QrUrlHelper;
use Squareetlabs\VeriFactu\Models\Invoice;

class InvoiceQrController extends Controller
{
    public function generateQr($invoiceId)
    {
        $invoice = Invoice::findOrFail($invoiceId);
        $issuerVat = config('verifactu.issuer.vat');
        $isProduction = app()->environment('production');
        
        $qrUrl = QrUrlHelper::build($invoice, $issuerVat, $isProduction);
        
        // Generar el código QR usando alguna librería (ej: SimpleSoftwareIO/simple-qrcode)
        // $qrCode = QrCode::size(300)->generate($qrUrl);
        
        return response()->json([
            'url' => $qrUrl,
            // 'qr_image' => $qrCode
        ]);
    }
}
```

---

## Uso avanzado

### Integración de eventos y listeners

Puedes disparar eventos cuando se crean, actualizan o envían invoices a AEAT. Ejemplo:

```php
// app/Events/InvoiceSentToAeat.php
namespace App\Events;

use Squareetlabs\VeriFactu\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceSentToAeat
{
    use Dispatchable, SerializesModels;
    public function __construct(public Invoice $invoice, public array $aeatResponse) {}
}
```

Despacha el evento tras el envío:

```php
use App\Events\InvoiceSentToAeat;

// ... después de enviar a AEAT
InvoiceSentToAeat::dispatch($invoice, $result);
```

Crea un listener para notificaciones o logging:

```php
// app/Listeners/LogAeatResponse.php
namespace App\Listeners;

use App\Events\InvoiceSentToAeat;
use Illuminate\Support\Facades\Log;

class LogAeatResponse
{
    public function handle(InvoiceSentToAeat $event)
    {
        Log::info('AEAT response', [
            'invoice_id' => $event->invoice->id,
            'response' => $event->aeatResponse,
        ]);
    }
}
```

Registra tu evento y listener en `EventServiceProvider`:

```php
protected $listen = [
    \App\Events\InvoiceSentToAeat::class => [
        \App\Listeners\LogAeatResponse::class,
    ],
];
```

---

### Políticas de autorización

Puedes restringir el acceso a invoices usando policies de Laravel:

```php
// app/Policies/InvoicePolicy.php
namespace App\Policies;

use App\Models\User;
use Squareetlabs\VeriFactu\Models\Invoice;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->id === $invoice->user_id;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->id === $invoice->user_id && $invoice->status === 'draft';
    }
}
```

Registra la policy en `AuthServiceProvider`:

```php
protected $policies = [
    \Squareetlabs\VeriFactu\Models\Invoice::class => \App\Policies\InvoicePolicy::class,
];
```

Úsala en tu controller:

```php
public function update(Request $request, Invoice $invoice)
{
    $this->authorize('update', $invoice);
    // ...
}
```

---

### Integración de notificaciones

Puedes notificar a usuarios o admins cuando una invoice se envía o falla:

```php
// app/Notifications/InvoiceSentNotification.php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Squareetlabs\VeriFactu\Models\Invoice;

class InvoiceSentNotification extends Notification
{
    use Queueable;
    public function __construct(public Invoice $invoice) {}

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Invoice Sent to AEAT')
            ->line('Invoice #' . $this->invoice->number . ' was sent to AEAT successfully.');
    }
}
```

Despacha la notificación en tu job o listener:

```php
$user->notify(new \App\Notifications\InvoiceSentNotification($invoice));
```

---

### Integración con colas (queues)

Puedes enviar invoices a AEAT de forma asíncrona usando colas:

```php
use Squareetlabs\VeriFactu\Models\Invoice;
use App\Jobs\SendInvoiceToAeatJob;

// Despacha el job a la cola
SendInvoiceToAeatJob::dispatch($invoice->id);
```

En tu job, implementa `ShouldQueue`:

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class SendInvoiceToAeatJob implements ShouldQueue
{
    // ...
}
```

Configura tu conexión de cola en `.env` y ejecuta el worker:

```bash
php artisan queue:work
```

---

### Auditoría

Puedes usar paquetes como [owen-it/laravel-auditing](https://github.com/owen-it/laravel-auditing) para auditar cambios en invoices:

1. Instala el paquete:
   ```bash
   composer require owen-it/laravel-auditing
   ```
2. Añade el contrato `\OwenIt\Auditing\Contracts\Auditable` a tu modelo:
   ```php
   use OwenIt\Auditing\Contracts\Auditable;

   class Invoice extends Model implements Auditable
   {
       use \OwenIt\Auditing\Auditable;
       // ...
   }
   ```
3. Ahora todos los cambios en invoices serán auditados automáticamente. Puedes ver los logs:
   ```php
   $audits = $invoice->audits;
   ```

---

## Testing

Ejecuta todos los tests unitarios:

```bash
php artisan test
# o
vendor/bin/phpunit
```

---

## Contribuir

Las contribuciones son bienvenidas. Por favor:

1. Fork el proyecto
2. Crea una rama para tu feature
3. Commit tus cambios
4. Push a la rama
5. Abre un Pull Request

## Licencia

Este paquete es open-source bajo la [Licencia MIT](LICENSE.md).

## Soporte

- **Documentación técnica**: https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/informacion-tecnica.html
- **Issues**: https://github.com/squareetlabs/LaravelVerifactu/issues

## Autores

- **Alberto Rial Barreiro** - [SquareetLabs](https://www.squareet.com)
- **Jacobo Cantorna Cigarrán** - [SquareetLabs](https://www.squareet.com)

---

Si este paquete te ha sido útil, ¡no olvides darle una estrella en GitHub!
