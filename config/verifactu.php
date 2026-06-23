<?php

return [
    'enabled' => true,
    'default_currency' => 'EUR',
    
    'issuer' => [
        'name' => env('VERIFACTU_ISSUER_NAME', ''),
        'vat' => env('VERIFACTU_ISSUER_VAT', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Representative (Colaborador Social)
    |--------------------------------------------------------------------------
    |
    | For platforms registered as "entidad colaboradora" (colaborador social)
    | with AEAT: invoices are submitted on behalf of the issuer using the
    | PLATFORM's certificate, so issuers never have to provide their own.
    | When 'vat' is set, a Representante block is added to the Cabecera of
    | every submission. The TLS certificate configured under 'aeat' must then
    | be the collaborator's certificate.
    |
    */
    'representative' => [
        'name' => env('VERIFACTU_REP_NAME', ''),
        'vat' => env('VERIFACTU_REP_VAT', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verifactu Mode
    |--------------------------------------------------------------------------
    |
    | Set to true for VERIFACTU mode or false for NO VERIFACTU (Requerimiento) mode.
    | 
    | VERIFACTU mode (true):
    |   - Online invoice submission
    |   - HTTPS certificate authentication
    |   - No XAdES signature required
    | 
    | NO VERIFACTU mode (false):
    |   - Submission upon requirement (RefRequerimiento needed)
    |   - XAdES-EPES signature required
    |   - Offline capable
    |
    */
    'verifactu_mode' => env('VERIFACTU_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | Computer System Information (Sistema Informático)
    |--------------------------------------------------------------------------
    |
    | Information about the system that generates the invoices.
    | Required by AEAT for VeriFactu compliance.
    |
    */
    'sistema_informatico' => [
        'name' => env('VERIFACTU_SYSTEM_NAME', 'LaravelVerifactu'),
        'id' => env('VERIFACTU_SYSTEM_ID', 'LV'),
        'version' => env('VERIFACTU_SYSTEM_VERSION', '1.0'),
        'installation_number' => env('VERIFACTU_INSTALLATION_NUMBER', '001'),
        
        /*
        |----------------------------------------------------------------------
        | System Capability Parameters
        |----------------------------------------------------------------------
        |
        | Define the system's capabilities according to AEAT requirements.
        | Values must be 'S' (Yes) or 'N' (No) as per AEAT specification.
        |
        | - only_verifactu_capable: System can ONLY operate in VERIFACTU mode
        | - multi_obligated_entities_capable: System supports multiple obligated entities
        | - has_multiple_obligated_entities: Indicates if multiple entities currently exist
        |
        */
        'only_verifactu_capable' => env('VERIFACTU_ONLY_VERIFACTU_CAPABLE', 'S'),
        'multi_obligated_entities_capable' => env('VERIFACTU_MULTI_OT_CAPABLE', 'N'),
        'has_multiple_obligated_entities' => env('VERIFACTU_HAS_MULTI_OT', 'N'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AEAT Connection Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to AEAT web services.
    |
    */
    'aeat' => [
        'cert_path' => env('VERIFACTU_CERT_PATH', storage_path('certificates/aeat.pfx')),
        'cert_password' => env('VERIFACTU_CERT_PASSWORD'),
        'production' => env('VERIFACTU_PRODUCTION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Load Package Migrations
    |--------------------------------------------------------------------------
    |
    | Set to true if you want to use the package's Invoice, Breakdown, and
    | Recipient models. Set to false if you have your own invoice system
    | and will implement the VeriFactu contracts on your existing models.
    |
    */
    'load_migrations' => env('VERIFACTU_LOAD_MIGRATIONS', false),
];