<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\Recipient;

/**
 * Test para régimen de recargo de equivalencia
 * 
 * Caso de uso: Art. 148-155 LIVA
 * 
 * Características:
 * - Comercio minorista
 * - Se añade recargo al IVA normal
 * - ClaveRegimen = '18'
 * - Recargos: 5.2% (para IVA 21%), 1.4% (para IVA 10%), 0.5% (para IVA 4%)
 */
class EquivalenceSurchargeTest extends TestCase
{
    /** @test */
    public function it_creates_invoice_with_equivalence_surcharge()
    {
        // Arrange: Factura a minorista con recargo
        $invoice = Invoice::factory()->create([
            'number' => 'REC-2025-001',
            'date' => now(),
            'issuer_name' => 'Mayorista Productos SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F1',
            'description' => 'Venta con recargo de equivalencia',
            'amount' => 1000.00,
            'tax' => 262.00, // IVA 21% (210) + Recargo 5.2% (52)
            'total' => 1262.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Minorista Retail SL',
            'tax_id' => 'B87654321',
            'country' => 'ES',
        ]);

        // IVA + Recargo
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '18', // Recargo de equivalencia
            'operation_type' => 'S1',
            'tax_rate' => 21.00, // IVA base
            'base_amount' => 1000.00,
            'tax_amount' => 210.00, // Solo IVA (el recargo se añade aparte en la práctica)
        ]);

        // Assert
        $this->assertEquals('18', $invoice->breakdowns->first()->regime_type);
        $this->assertEquals(21.00, $invoice->breakdowns->first()->tax_rate);
    }

    /** @test */
    public function it_supports_multiple_surcharge_rates()
    {
        // Arrange: Productos con diferentes tipos IVA + recargo
        $invoice = Invoice::factory()->create([
            'number' => 'REC-MULTI-001',
            'date' => now(),
            'issuer_name' => 'Distribuidor SL',
            'issuer_tax_id' => 'B11223344',
            'type' => 'F1',
            'description' => 'Productos variados con recargo',
            'amount' => 3000.00,
            'tax' => 500.00, // Suma de IVAs y recargos
            'total' => 3500.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Tienda Minorista SL',
            'tax_id' => 'B55667788',
            'country' => 'ES',
        ]);

        // IVA 21% (+ recargo 5.2%)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '18',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 1000.00,
            'tax_amount' => 210.00,
        ]);

        // IVA 10% (+ recargo 1.4%)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '18',
            'operation_type' => 'S1',
            'tax_rate' => 10.00,
            'base_amount' => 1000.00,
            'tax_amount' => 100.00,
        ]);

        // IVA 4% (+ recargo 0.5%)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '18',
            'operation_type' => 'S1',
            'tax_rate' => 4.00,
            'base_amount' => 1000.00,
            'tax_amount' => 40.00,
        ]);

        // Assert
        $this->assertCount(3, $invoice->breakdowns);
        $this->assertTrue($invoice->breakdowns->every(fn($b) => $b->regime_type === '18'));
    }
}

