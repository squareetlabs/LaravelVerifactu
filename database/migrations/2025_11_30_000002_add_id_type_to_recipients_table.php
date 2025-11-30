<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Añade el campo id_type para destinatarios extranjeros según AEAT.
     * Valores posibles:
     * - 02: NIF-IVA
     * - 03: Pasaporte
     * - 04: Documento oficial de identificación expedido por el país
     * - 05: Certificado de residencia fiscal
     * - 06: Otro documento probatorio
     * - 07: No censado (no registrado en España)
     */
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->string('id_type', 2)->nullable()->after('country')
                ->comment('Tipo ID para extranjeros: 02=NIF-IVA, 03=Pasaporte, 04=Doc oficial, 05=Cert residencia, 06=Otro, 07=No censado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn('id_type');
        });
    }
};

