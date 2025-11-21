<?php

return [
    'enabled' => true,
    'default_currency' => 'EUR',
    
    'issuer' => [
        'name' => env('VERIFACTU_ISSUER_NAME', ''),
        'vat' => env('VERIFACTU_ISSUER_VAT', ''),
    ],
    
    'aeat' => [
        'cert_path' => env('VERIFACTU_CERT_PATH', storage_path('certificates/aeat.pfx')),
        'cert_password' => env('VERIFACTU_CERT_PASSWORD'),
        'production' => env('VERIFACTU_PRODUCTION', false),
    ],
    
    'sistema_informatico' => [
        'nombre' => env('VERIFACTU_SISTEMA_NOMBRE', 'OrbilaiVerifactu'),
        'id' => env('VERIFACTU_SISTEMA_ID', 'OV'),
        'solo_verifactu' => env('VERIFACTU_SOLO_VERIFACTU', true),
        'multi_ot' => env('VERIFACTU_MULTI_OT', true),
    ],
]; 