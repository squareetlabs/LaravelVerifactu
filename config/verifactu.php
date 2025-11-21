<?php

return [
    'enabled' => true,
    'default_currency' => 'EUR',
    
    'issuer' => [
        'name' => env('VERIFACTU_ISSUER_NAME', ''),
        'vat' => env('VERIFACTU_ISSUER_VAT', ''),
    ],
    
    // Configuración AEAT
    'aeat' => [
        'cert_path' => env('VERIFACTU_CERT_PATH', storage_path('certificates/aeat.pfx')),
        'cert_password' => env('VERIFACTU_CERT_PASSWORD'),
        'production' => env('VERIFACTU_PRODUCTION', false),
    ],
    
    // Sistema Informático (datos requeridos por AEAT)
    'sistema_informatico' => [
        // Nombre del sistema informático
        'nombre' => env('VERIFACTU_SISTEMA_NOMBRE', 'LaravelVerifactu'),
        
        // ID del sistema informático (único por software)
        // Debe ser asignado por AEAT o generado de forma única
        'id' => env('VERIFACTU_SISTEMA_ID', '01'),
        
        // Versión del sistema informático
        'version' => env('VERIFACTU_SISTEMA_VERSION', '1.0'),
    
        // Número de instalación (único por cada instalación del cliente)
        // IMPORTANTE: Cada cliente debe tener su propio número
        'numero_instalacion' => env('VERIFACTU_NUMERO_INSTALACION', '001'),
        
        // Tipo de uso
        'solo_verifactu' => env('VERIFACTU_SOLO_VERIFACTU', true),
        'multi_ot' => env('VERIFACTU_MULTI_OT', false),
        'indicador_multiples_ot' => env('VERIFACTU_INDICADOR_MULTIPLES_OT', false),
    ],
]; 