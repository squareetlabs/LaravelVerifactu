<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Añade campos para almacenar el estado de la respuesta AEAT,
     * incluyendo el estado "AceptadoConErrores" y warnings.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('aeat_estado_registro', 30)
                  ->nullable()
                  ->after('csv')
                  ->index();
            
            $table->string('aeat_codigo_error', 20)
                  ->nullable()
                  ->after('aeat_estado_registro');
            
            $table->text('aeat_descripcion_error')
                  ->nullable()
                  ->after('aeat_codigo_error');
            
            $table->boolean('has_aeat_warnings')
                  ->default(false)
                  ->after('aeat_descripcion_error')
                  ->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['aeat_estado_registro']);
            $table->dropIndex(['has_aeat_warnings']);
            
            $table->dropColumn([
                'aeat_estado_registro',
                'aeat_codigo_error',
                'aeat_descripcion_error',
                'has_aeat_warnings',
            ]);
        });
    }
};

