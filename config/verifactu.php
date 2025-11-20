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
    
    // Otros parámetros de configuración...
]; 