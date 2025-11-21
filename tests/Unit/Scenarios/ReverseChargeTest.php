<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\Recipient;

/**
 * Test para inversión del sujeto pasivo (S2)
 * 
 * Casos de uso:
 * - Construcción y obras (Art. 84.Uno.2º LIVA)
 * - Oro de inversión (Art. 84.Uno.3º LIVA)
 * - Residuos y materiales de recuperación (Art. 84.Uno.4º LIVA)
 * - Teléfonos móviles, consolas, ordenadores (Art. 84.Uno.5º LIVA)
 * 
 * Características:
 * - El destinatario es quien liquida el IVA
 * - Factura sin cuota de IVA repercutida
 * - CalificacionOperacion = 'S2'
 */
class ReverseChargeTest extends TestCase
{
    /** @test */
    public function it_creates_construction_invoice_with_reverse_charge()
    {
        // Arrange: Factura de construcción con inversión (Art. 84.Uno.2º)
        $invoice = Invoice::factory()->create([
            'number' => 'CONST-2025-001',
            'date' => now(),
            'issuer_name' => 'Construction Services SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F1',
            'description' => 'Construction works - Reverse charge Art. 84.Uno.2º',
            'amount' => 50000.00,
            'tax' => 0.00, // No se repercute (inversión)
            'total' => 50000.00,
            'is_first_invoice' => false,
        ]);

        // Destinatario empresario (quien liquida el IVA)
        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Main Contractor SL',
            'tax_id' => 'B87654321',
            'country' => 'ES',
        ]);

        // Breakdown con inversión del sujeto pasivo
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01', // IVA
            'regime_type' => '01', // General
            'operation_type' => 'S2', // Sujeta con inversión
            'tax_rate' => 21.00, // Tipo aplicable (aunque no se repercute)
            'base_amount' => 50000.00,
            'tax_amount' => 0.00, // No se repercute
        ]);

        // Assert
        $this->assertEquals('S2', $invoice->breakdowns->first()->operation_type);
        $this->assertEquals(0.00, $invoice->tax);
        $this->assertEquals(50000.00, $invoice->total);
        $this->assertStringContainsString('Reverse charge', $invoice->description);
    }

    /** @test */
    public function it_creates_gold_investment_invoice_with_reverse_charge()
    {
        // Arrange: Oro de inversión (Art. 84.Uno.3º)
        $invoice = Invoice::factory()->create([
            'number' => 'GOLD-2025-001',
            'date' => now(),
            'issuer_name' => 'Gold Dealer SL',
            'issuer_tax_id' => 'B11223344',
            'type' => 'F1',
            'description' => 'Investment gold - Reverse charge Art. 84.Uno.3º',
            'amount' => 100000.00,
            'tax' => 0.00,
            'total' => 100000.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Investment Bank SA',
            'tax_id' => 'A99887766',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '04', // Régimen especial oro de inversión
            'operation_type' => 'S2', // Inversión del sujeto pasivo
            'tax_rate' => 21.00,
            'base_amount' => 100000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertEquals('04', $invoice->breakdowns->first()->regime_type);
        $this->assertEquals('S2', $invoice->breakdowns->first()->operation_type);
    }

    /** @test */
    public function it_creates_scrap_materials_invoice_with_reverse_charge()
    {
        // Arrange: Residuos y chatarra (Art. 84.Uno.4º)
        $invoice = Invoice::factory()->create([
            'number' => 'SCRAP-2025-001',
            'date' => now(),
            'issuer_name' => 'Recycling Materials SL',
            'issuer_tax_id' => 'B55443322',
            'type' => 'F1',
            'description' => 'Scrap metal - Reverse charge Art. 84.Uno.4º',
            'amount' => 8000.00,
            'tax' => 0.00,
            'total' => 8000.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Recycling Plant SL',
            'tax_id' => 'B22334455',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S2',
            'tax_rate' => 21.00,
            'base_amount' => 8000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertEquals('S2', $invoice->breakdowns->first()->operation_type);
        $this->assertStringContainsString('Scrap', $invoice->description);
    }

    /** @test */
    public function it_creates_electronics_invoice_with_reverse_charge()
    {
        // Arrange: Móviles, tablets, consolas (Art. 84.Uno.5º)
        $invoice = Invoice::factory()->create([
            'number' => 'ELEC-2025-001',
            'date' => now(),
            'issuer_name' => 'Electronics Wholesale SL',
            'issuer_tax_id' => 'B66778899',
            'type' => 'F1',
            'description' => 'Mobile phones - Reverse charge Art. 84.Uno.5º',
            'amount' => 15000.00,
            'tax' => 0.00,
            'total' => 15000.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Phone Retailer SL',
            'tax_id' => 'B99887744',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S2',
            'tax_rate' => 21.00,
            'base_amount' => 15000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertEquals(0.00, $invoice->tax);
        $this->assertEquals('S2', $invoice->breakdowns->first()->operation_type);
    }

    /** @test */
    public function it_supports_mixed_normal_and_reverse_charge_in_same_invoice()
    {
        // Arrange: Factura con líneas normales e inversión mixta
        $invoice = Invoice::factory()->create([
            'number' => 'MIX-RC-2025-001',
            'date' => now(),
            'issuer_name' => 'Mixed Operations SL',
            'issuer_tax_id' => 'B33445566',
            'type' => 'F1',
            'description' => 'Mixed invoice with reverse charge',
            'amount' => 30000.00,
            'tax' => 2100.00, // Solo línea normal
            'total' => 32100.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Cliente Mixto SL',
            'tax_id' => 'B77889900',
            'country' => 'ES',
        ]);

        // Línea normal (S1)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1', // Normal
            'tax_rate' => 21.00,
            'base_amount' => 10000.00,
            'tax_amount' => 2100.00,
        ]);

        // Línea con inversión (S2)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S2', // Inversión
            'tax_rate' => 21.00,
            'base_amount' => 20000.00,
            'tax_amount' => 0.00, // No se repercute
        ]);

        // Assert
        $this->assertCount(2, $invoice->breakdowns);
        
        $normalBreakdown = $invoice->breakdowns->where('operation_type', 'S1')->first();
        $reverseBreakdown = $invoice->breakdowns->where('operation_type', 'S2')->first();
        
        $this->assertEquals(2100.00, $normalBreakdown->tax_amount);
        $this->assertEquals(0.00, $reverseBreakdown->tax_amount);
        $this->assertEquals(2100.00, $invoice->tax);
    }

    /** @test */
    public function reverse_charge_invoice_can_be_chained()
    {
        // Arrange: Encadenamiento de facturas con inversión
        $firstInvoice = Invoice::factory()->create([
            'number' => 'RC-001',
            'date' => now()->subDay(),
            'issuer_name' => 'Construction SL',
            'issuer_tax_id' => 'B12121212',
            'type' => 'F1',
            'is_first_invoice' => true,
            'amount' => 20000.00,
            'tax' => 0.00,
            'total' => 20000.00,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $firstInvoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S2',
            'tax_rate' => 21.00,
            'base_amount' => 20000.00,
            'tax_amount' => 0.00,
        ]);

        $secondInvoice = Invoice::factory()->create([
            'number' => 'RC-002',
            'date' => now(),
            'issuer_name' => 'Construction SL',
            'issuer_tax_id' => 'B12121212',
            'type' => 'F1',
            'is_first_invoice' => false,
            'previous_invoice_number' => $firstInvoice->number,
            'previous_invoice_date' => $firstInvoice->date,
            'previous_invoice_hash' => $firstInvoice->hash,
            'amount' => 25000.00,
            'tax' => 0.00,
            'total' => 25000.00,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $secondInvoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S2',
            'tax_rate' => 21.00,
            'base_amount' => 25000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertNotNull($secondInvoice->previous_invoice_hash);
        $this->assertEquals($firstInvoice->hash, $secondInvoice->previous_invoice_hash);
        $this->assertEquals('S2', $secondInvoice->breakdowns->first()->operation_type);
    }
}

