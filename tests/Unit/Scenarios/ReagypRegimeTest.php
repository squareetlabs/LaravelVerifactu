<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\Recipient;

/**
 * Test para régimen especial de agricultura, ganadería y pesca (REAGYP)
 * 
 * Caso de uso: Art. 124-136 LIVA
 * 
 * Características:
 * - Compensación en lugar de repercusión de IVA
 * - ClaveRegimen = '19'
 * - Porcentajes de compensación: 12%, 10.5%, 9%
 */
class ReagypRegimeTest extends TestCase
{
    /** @test */
    public function it_creates_invoice_with_reagyp_regime()
    {
        // Arrange: Factura de agricultura
        $invoice = Invoice::factory()->create([
            'number' => 'AGRI-2025-001',
            'date' => now(),
            'issuer_name' => 'Granja Agrícola SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F1',
            'description' => 'Venta de productos agrícolas - REAGYP',
            'amount' => 10000.00,
            'tax' => 1200.00, // Compensación 12%
            'total' => 11200.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Distribuidor Agrícola SA',
            'tax_id' => 'A87654321',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '19', // REAGYP
            'operation_type' => 'S1',
            'tax_rate' => 12.00, // Compensación
            'base_amount' => 10000.00,
            'tax_amount' => 1200.00,
        ]);

        // Assert
        $this->assertEquals('19', $invoice->breakdowns->first()->regime_type);
        $this->assertEquals(12.00, $invoice->breakdowns->first()->tax_rate);
    }

    /** @test */
    public function it_supports_different_reagyp_compensation_rates()
    {
        // Arrange: Productos ganaderos (10.5%)
        $invoice = Invoice::factory()->create([
            'number' => 'GANA-2025-001',
            'date' => now(),
            'issuer_name' => 'Granja Ganadera SL',
            'issuer_tax_id' => 'B11223344',
            'type' => 'F1',
            'description' => 'Productos ganaderos',
            'amount' => 5000.00,
            'tax' => 525.00, // 10.5%
            'total' => 5525.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Matadero Industrial SA',
            'tax_id' => 'A55667788',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '19',
            'operation_type' => 'S1',
            'tax_rate' => 10.5,
            'base_amount' => 5000.00,
            'tax_amount' => 525.00,
        ]);

        // Assert
        $this->assertEquals(10.5, $invoice->breakdowns->first()->tax_rate);
    }
}

