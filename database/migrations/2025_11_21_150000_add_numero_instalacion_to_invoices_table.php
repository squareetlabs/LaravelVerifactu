<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('numero_instalacion', 100)
                  ->nullable()
                  ->after('issuer_tax_id')
                  ->comment('Número de instalación único para VERIFACTU (max 100 caracteres según XSD AEAT). Formato: CIF-001');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('numero_instalacion');
        });
    }
};

