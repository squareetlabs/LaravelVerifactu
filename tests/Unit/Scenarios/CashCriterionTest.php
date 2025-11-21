<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\Recipient;

/**
 * Test para régimen especial del criterio de caja
 * 
 * Caso de uso: Art. 163 undecies LIVA
 * 
 * Características:
 * - IVA se devenga en el momento del cobro
 * - ClaveRegimen = '07'
 * - Requiere optar por este régimen
 * - Límite: volumen de operaciones ≤ 2.000.000€
 */
class CashCriterionTest extends TestCase
{
    /** @test */
    public function it_creates_invoice_with_cash_criterion_regime()
    {
        // Arrange: Factura con criterio de caja
        $invoice = Invoice::factory()->create([
            'number' => 'CASH-2025-001',
            'date' => now(),
            'issuer_name' => 'Small Business SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F1',
            'description' => 'Venta con criterio de caja - Art. 163 undecies LIVA',
            'amount' => 10000.00,
            'tax' => 2100.00,
            'total' => 12100.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Cliente Empresa SL',
            'tax_id' => 'B87654321',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01', // IVA
            'regime_type' => '07', // Criterio de caja
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 10000.00,
            'tax_amount' => 2100.00,
        ]);

        // Assert
        $this->assertEquals('07', $invoice->breakdowns->first()->regime_type);
        $this->assertStringContainsString('criterio de caja', strtolower($invoice->description));
    }

    /** @test */
    public function cash_criterion_invoice_can_be_chained()
    {
        // Arrange
        $firstInvoice = Invoice::factory()->create([
            'number' => 'CASH-001',
            'date' => now()->subDay(),
            'issuer_name' => 'Business SL',
            'issuer_tax_id' => 'B11223344',
            'type' => 'F1',
            'is_first_invoice' => true,
            'amount' => 5000.00,
            'tax' => 1050.00,
            'total' => 6050.00,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $firstInvoice->id,
            'tax_type' => '01',
            'regime_type' => '07',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 5000.00,
            'tax_amount' => 1050.00,
        ]);

        $secondInvoice = Invoice::factory()->create([
            'number' => 'CASH-002',
            'date' => now(),
            'issuer_name' => 'Business SL',
            'issuer_tax_id' => 'B11223344',
            'type' => 'F1',
            'is_first_invoice' => false,
            'previous_invoice_number' => $firstInvoice->number,
            'previous_invoice_date' => $firstInvoice->date,
            'previous_invoice_hash' => $firstInvoice->hash,
            'amount' => 8000.00,
            'tax' => 1680.00,
            'total' => 9680.00,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $secondInvoice->id,
            'tax_type' => '01',
            'regime_type' => '07',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 8000.00,
            'tax_amount' => 1680.00,
        ]);

        // Assert
        $this->assertEquals($firstInvoice->hash, $secondInvoice->previous_invoice_hash);
        $this->assertEquals('07', $secondInvoice->breakdowns->first()->regime_type);
    }
}

