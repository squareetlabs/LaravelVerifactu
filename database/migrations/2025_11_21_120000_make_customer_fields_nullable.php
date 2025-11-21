<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Make customer_name and customer_tax_id nullable to support simplified invoices (F2)
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('customer_name', 120)->nullable()->change();
            $table->string('customer_tax_id', 20)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('customer_name', 120)->nullable(false)->change();
            $table->string('customer_tax_id', 20)->nullable(false)->change();
        });
    }
};

