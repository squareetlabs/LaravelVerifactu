<?php

return [
    'enabled' => true,
    'system_id' => env('VERIFACTU_SYSTEM_ID', '01'),
    'default_currency' => 'EUR',
    'issuer' => [
        'name' => env('VERIFACTU_ISSUER_NAME', ''),
        'vat' => env('VERIFACTU_ISSUER_VAT', ''),
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