<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;

/**
 * Test para facturas simplificadas (F2)
 * 
 * Caso de uso: Facturas sin identificación del destinatario (tickets, recibos)
 * Art. 6.1.d) RD 1619/2012
 * 
 * Características:
 * - No requiere identificación completa del destinatario
 * - Común en retail, hostelería, transporte
 * - Límite máximo según normativa
 */
class SimplifiedInvoiceTest extends TestCase
{
    /** @test */
    public function it_creates_valid_simplified_invoice_without_recipient()
    {
        // Arrange: Crear factura simplificada sin destinatario
        $invoice = Invoice::factory()->create([
            'number' => 'TICKET-2025-001',
            'date' => now(),
            'issuer_name' => 'Retail Store SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F2', // Factura simplificada
            'description' => 'Venta al por menor',
            'amount' => 50.00,
            'tax' => 10.50,
            'total' => 60.50,
            'is_first_invoice' => false,
            'customer_name' => null, // Sin cliente identificado
            'customer_tax_id' => null,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01', // IVA
            'regime_type' => '01', // General
            'operation_type' => 'S1', // Sujeta no exenta
            'tax_rate' => 21.00,
            'base_amount' => 50.00,
            'tax_amount' => 10.50,
        ]);

        // Assert: Verificar estructura
        $this->assertDatabaseHas('invoices', [
            'number' => 'TICKET-2025-001',
            'type' => 'F2',
        ]);
        
        $this->assertNull($invoice->customer_name);
        $this->assertNull($invoice->customer_tax_id);
        $this->assertEquals('F2', $invoice->type);
        $this->assertCount(1, $invoice->breakdowns);
    }

    /** @test */
    public function it_creates_simplified_invoice_with_partial_recipient_data()
    {
        // Arrange: Factura simplificada con datos parciales del cliente
        $invoice = Invoice::factory()->create([
            'number' => 'TICKET-2025-002',
            'date' => now(),
            'issuer_name' => 'Retail Store SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F2',
            'description' => 'Venta con nombre cliente',
            'amount' => 80.00,
            'tax' => 16.80,
            'total' => 96.80,
            'is_first_invoice' => false,
            'customer_name' => 'Cliente Final', // Solo nombre, sin NIF
            'customer_tax_id' => null,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 80.00,
            'tax_amount' => 16.80,
        ]);

        // Assert
        $this->assertEquals('Cliente Final', $invoice->customer_name);
        $this->assertNull($invoice->customer_tax_id);
        $this->assertEquals('F2', $invoice->type);
    }

    /** @test */
    public function it_supports_multiple_tax_rates_in_simplified_invoice()
    {
        // Arrange: Factura simplificada con múltiples tipos de IVA
        $invoice = Invoice::factory()->create([
            'number' => 'TICKET-2025-003',
            'date' => now(),
            'issuer_name' => 'Restaurant SL',
            'issuer_tax_id' => 'B87654321',
            'type' => 'F2',
            'description' => 'Consumo en restaurante',
            'amount' => 100.00,
            'tax' => 16.00, // 21% + 10% + 4%
            'total' => 116.00,
            'is_first_invoice' => false,
        ]);

        // IVA 21% (comida)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 50.00,
            'tax_amount' => 10.50,
        ]);

        // IVA 10% (bebidas)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 10.00,
            'base_amount' => 40.00,
            'tax_amount' => 4.00,
        ]);

        // IVA 4% (pan)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 4.00,
            'base_amount' => 10.00,
            'tax_amount' => 0.40,
        ]);

        // Assert
        $this->assertCount(3, $invoice->breakdowns);
        $this->assertEquals(116.00, $invoice->total);
        
        $taxRates = $invoice->breakdowns->pluck('tax_rate')->toArray();
        $this->assertContains(21.00, $taxRates);
        $this->assertContains(10.00, $taxRates);
        $this->assertContains(4.00, $taxRates);
    }

    /** @test */
    public function simplified_invoice_can_be_chained()
    {
        // Arrange: Primera factura simplificada
        $firstInvoice = Invoice::factory()->create([
            'number' => 'TICKET-2025-100',
            'date' => now()->subDay(),
            'issuer_name' => 'Shop SL',
            'issuer_tax_id' => 'B11111111',
            'type' => 'F2',
            'is_first_invoice' => true,
            'amount' => 30.00,
            'tax' => 6.30,
            'total' => 36.30,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $firstInvoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 30.00,
            'tax_amount' => 6.30,
        ]);

        // Segunda factura simplificada encadenada
        $secondInvoice = Invoice::factory()->create([
            'number' => 'TICKET-2025-101',
            'date' => now(),
            'issuer_name' => 'Shop SL',
            'issuer_tax_id' => 'B11111111',
            'type' => 'F2',
            'is_first_invoice' => false,
            'previous_invoice_number' => $firstInvoice->number,
            'previous_invoice_date' => $firstInvoice->date,
            'previous_invoice_hash' => $firstInvoice->hash,
            'amount' => 45.00,
            'tax' => 9.45,
            'total' => 54.45,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $secondInvoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 45.00,
            'tax_amount' => 9.45,
        ]);

        // Assert: Verificar encadenamiento
        $this->assertEquals('TICKET-2025-100', $secondInvoice->previous_invoice_number);
        $this->assertNotNull($secondInvoice->previous_invoice_hash);
        $this->assertEquals($firstInvoice->hash, $secondInvoice->previous_invoice_hash);
    }
}

