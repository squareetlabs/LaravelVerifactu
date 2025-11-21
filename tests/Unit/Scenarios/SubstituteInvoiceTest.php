<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\Recipient;

/**
 * Test para facturas sustitutivas (F3)
 * 
 * Caso de uso: Sustituir facturas simplificadas previamente declaradas
 * 
 * Características:
 * - Emitida como sustitución de simplificadas previas
 * - Típico cuando el cliente solicita factura completa
 * - TipoFactura = 'F3'
 */
class SubstituteInvoiceTest extends TestCase
{
    /** @test */
    public function it_creates_substitute_invoice_replacing_simplified()
    {
        // Arrange: Factura simplificada original
        $simplifiedInvoice = Invoice::factory()->create([
            'number' => 'TICKET-2025-001',
            'date' => now()->subDay(),
            'issuer_name' => 'Retail Store SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F2', // Simplificada
            'description' => 'Venta inicial',
            'amount' => 100.00,
            'tax' => 21.00,
            'total' => 121.00,
            'is_first_invoice' => false,
            'customer_name' => null,
            'customer_tax_id' => null,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $simplifiedInvoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 100.00,
            'tax_amount' => 21.00,
        ]);

        // Factura sustitutiva (F3) con datos completos del cliente
        $substituteInvoice = Invoice::factory()->create([
            'number' => 'F-2025-001',
            'date' => now(),
            'issuer_name' => 'Retail Store SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F3', // Sustitutiva
            'description' => 'Sustituye TICKET-2025-001',
            'amount' => 100.00,
            'tax' => 21.00,
            'total' => 121.00,
            'is_first_invoice' => false,
            'customer_name' => 'Cliente Completo SL',
            'customer_tax_id' => 'B87654321',
        ]);

        Recipient::factory()->create([
            'invoice_id' => $substituteInvoice->id,
            'name' => 'Cliente Completo SL',
            'tax_id' => 'B87654321',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $substituteInvoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 100.00,
            'tax_amount' => 21.00,
        ]);

        // Assert
        $this->assertEquals('F2', $simplifiedInvoice->type);
        $this->assertEquals('F3', $substituteInvoice->type);
        $this->assertNull($simplifiedInvoice->customer_tax_id);
        $this->assertNotNull($substituteInvoice->customer_tax_id);
        $this->assertEquals($simplifiedInvoice->total, $substituteInvoice->total);
    }

    /** @test */
    public function substitute_invoice_includes_recipient_data()
    {
        // Arrange: Factura sustitutiva debe tener destinatario completo
        $invoice = Invoice::factory()->create([
            'number' => 'SUST-2025-001',
            'date' => now(),
            'issuer_name' => 'Company SL',
            'issuer_tax_id' => 'B11223344',
            'type' => 'F3',
            'description' => 'Sustitutiva de tickets anteriores',
            'amount' => 500.00,
            'tax' => 105.00,
            'total' => 605.00,
            'is_first_invoice' => false,
            'customer_name' => 'Empresa Cliente SA',
            'customer_tax_id' => 'A99887766',
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Empresa Cliente SA',
            'tax_id' => 'A99887766',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 500.00,
            'tax_amount' => 105.00,
        ]);

        // Assert
        $this->assertEquals('F3', $invoice->type);
        $this->assertNotNull($invoice->customer_tax_id);
        $this->assertCount(1, $invoice->recipients);
        $this->assertEquals('A99887766', $invoice->recipients->first()->tax_id);
    }

    /** @test */
    public function substitute_invoice_can_be_chained()
    {
        // Arrange: Encadenamiento de facturas sustitutivas
        $firstInvoice = Invoice::factory()->create([
            'number' => 'SUST-001',
            'date' => now()->subDay(),
            'issuer_name' => 'Store SL',
            'issuer_tax_id' => 'B55667788',
            'type' => 'F3',
            'is_first_invoice' => true,
            'amount' => 200.00,
            'tax' => 42.00,
            'total' => 242.00,
            'customer_tax_id' => 'B11111111',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $firstInvoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 200.00,
            'tax_amount' => 42.00,
        ]);

        $secondInvoice = Invoice::factory()->create([
            'number' => 'SUST-002',
            'date' => now(),
            'issuer_name' => 'Store SL',
            'issuer_tax_id' => 'B55667788',
            'type' => 'F3',
            'is_first_invoice' => false,
            'previous_invoice_number' => $firstInvoice->number,
            'previous_invoice_date' => $firstInvoice->date,
            'previous_invoice_hash' => $firstInvoice->hash,
            'amount' => 300.00,
            'tax' => 63.00,
            'total' => 363.00,
            'customer_tax_id' => 'B22222222',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $secondInvoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 300.00,
            'tax_amount' => 63.00,
        ]);

        // Assert
        $this->assertEquals($firstInvoice->hash, $secondInvoice->previous_invoice_hash);
        $this->assertEquals('F3', $secondInvoice->type);
    }

    /** @test */
    public function substitute_invoice_supports_multiple_tax_rates()
    {
        // Arrange: Sustitutiva con múltiples tipos
        $invoice = Invoice::factory()->create([
            'number' => 'SUST-MULTI-001',
            'date' => now(),
            'issuer_name' => 'Multi Store SL',
            'issuer_tax_id' => 'B33445566',
            'type' => 'F3',
            'description' => 'Sustitu facturas de productos variados',
            'amount' => 1000.00,
            'tax' => 175.00,
            'total' => 1175.00,
            'is_first_invoice' => false,
            'customer_tax_id' => 'B77889900',
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Cliente Final SL',
            'tax_id' => 'B77889900',
            'country' => 'ES',
        ]);

        // IVA 21%
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 21.00,
            'base_amount' => 500.00,
            'tax_amount' => 105.00,
        ]);

        // IVA 10%
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 10.00,
            'base_amount' => 400.00,
            'tax_amount' => 40.00,
        ]);

        // IVA 4%
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1',
            'tax_rate' => 4.00,
            'base_amount' => 100.00,
            'tax_amount' => 4.00,
        ]);

        // Assert
        $this->assertCount(3, $invoice->breakdowns);
        $this->assertEquals(149.00, $invoice->breakdowns->sum('tax_amount'));
    }
}

