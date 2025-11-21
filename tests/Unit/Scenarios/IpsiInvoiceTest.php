<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\Recipient;

/**
 * Test para facturas con IPSI (Ceuta y Melilla)
 * 
 * Caso de uso: Impuesto sobre la Producción, los Servicios y la Importación
 * 
 * Características:
 * - Aplica en Ceuta y Melilla (territorios fuera del ámbito territorial del IVA)
 * - Tipos: 0.5%, 1%, 2%, 4%, 10%
 * - TaxType = '02' (IPSI)
 * - RegimeType = '08' (IPSI/IGIC)
 */
class IpsiInvoiceTest extends TestCase
{
    /** @test */
    public function it_creates_valid_invoice_with_ipsi()
    {
        // Arrange: Factura con IPSI (Ceuta/Melilla)
        $invoice = Invoice::factory()->create([
            'number' => 'IPSI-2025-001',
            'date' => now(),
            'issuer_name' => 'Comercio Ceuta SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F1',
            'description' => 'Venta de productos en Ceuta',
            'amount' => 100.00,
            'tax' => 0.50, // IPSI 0.5%
            'total' => 100.50,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Cliente Ceuta',
            'tax_id' => '12345678A',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '02', // IPSI
            'regime_type' => '08', // IPSI/IGIC
            'operation_type' => 'S1', // Sujeta no exenta
            'tax_rate' => 0.5,
            'base_amount' => 100.00,
            'tax_amount' => 0.50,
        ]);

        // Assert
        $this->assertDatabaseHas('invoices', [
            'number' => 'IPSI-2025-001',
        ]);
        
        $this->assertEquals('02', $invoice->breakdowns->first()->tax_type);
        $this->assertEquals('08', $invoice->breakdowns->first()->regime_type);
        $this->assertEquals(0.5, $invoice->breakdowns->first()->tax_rate);
        $this->assertEquals(100.50, $invoice->total);
    }

    /** @test */
    public function it_supports_multiple_ipsi_rates()
    {
        // Arrange: Factura con múltiples tipos de IPSI
        $invoice = Invoice::factory()->create([
            'number' => 'IPSI-2025-002',
            'date' => now(),
            'issuer_name' => 'Multi Tax Ceuta SL',
            'issuer_tax_id' => 'B87654321',
            'type' => 'F1',
            'description' => 'Productos con diferentes tipos IPSI',
            'amount' => 1000.00,
            'tax' => 45.00, // 10 + 10 + 20 + 5
            'total' => 1045.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Cliente Melilla',
            'tax_id' => 'B11223344',
            'country' => 'ES',
        ]);

        // IPSI 1% (productos básicos)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '02',
            'regime_type' => '08',
            'operation_type' => 'S1',
            'tax_rate' => 1.0,
            'base_amount' => 200.00,
            'tax_amount' => 2.00,
        ]);

        // IPSI 2%
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '02',
            'regime_type' => '08',
            'operation_type' => 'S1',
            'tax_rate' => 2.0,
            'base_amount' => 500.00,
            'tax_amount' => 10.00,
        ]);

        // IPSI 4%
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '02',
            'regime_type' => '08',
            'operation_type' => 'S1',
            'tax_rate' => 4.0,
            'base_amount' => 200.00,
            'tax_amount' => 8.00,
        ]);

        // IPSI 10% (productos de lujo)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '02',
            'regime_type' => '08',
            'operation_type' => 'S1',
            'tax_rate' => 10.0,
            'base_amount' => 100.00,
            'tax_amount' => 10.00,
        ]);

        // Assert
        $this->assertCount(4, $invoice->breakdowns);
        
        $taxRates = $invoice->breakdowns->pluck('tax_rate')->toArray();
        $this->assertContains(1.0, $taxRates);
        $this->assertContains(2.0, $taxRates);
        $this->assertContains(4.0, $taxRates);
        $this->assertContains(10.0, $taxRates);
        
        $this->assertEquals(30.00, $invoice->breakdowns->sum('tax_amount'));
    }

    /** @test */
    public function ipsi_invoice_can_be_chained()
    {
        // Arrange: Encadenamiento de facturas IPSI
        $firstInvoice = Invoice::factory()->create([
            'number' => 'IPSI-CHAIN-001',
            'date' => now()->subDay(),
            'issuer_name' => 'Chain Ceuta SL',
            'issuer_tax_id' => 'B55667788',
            'type' => 'F1',
            'is_first_invoice' => true,
            'amount' => 500.00,
            'tax' => 5.00, // IPSI 1%
            'total' => 505.00,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $firstInvoice->id,
            'tax_type' => '02',
            'regime_type' => '08',
            'operation_type' => 'S1',
            'tax_rate' => 1.0,
            'base_amount' => 500.00,
            'tax_amount' => 5.00,
        ]);

        $secondInvoice = Invoice::factory()->create([
            'number' => 'IPSI-CHAIN-002',
            'date' => now(),
            'issuer_name' => 'Chain Ceuta SL',
            'issuer_tax_id' => 'B55667788',
            'type' => 'F1',
            'is_first_invoice' => false,
            'previous_invoice_number' => $firstInvoice->number,
            'previous_invoice_date' => $firstInvoice->date,
            'previous_invoice_hash' => $firstInvoice->hash,
            'amount' => 800.00,
            'tax' => 8.00,
            'total' => 808.00,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $secondInvoice->id,
            'tax_type' => '02',
            'regime_type' => '08',
            'operation_type' => 'S1',
            'tax_rate' => 1.0,
            'base_amount' => 800.00,
            'tax_amount' => 8.00,
        ]);

        // Assert
        $this->assertEquals($firstInvoice->hash, $secondInvoice->previous_invoice_hash);
        $this->assertEquals('02', $secondInvoice->breakdowns->first()->tax_type);
    }

    /** @test */
    public function it_creates_simplified_invoice_with_ipsi()
    {
        // Arrange: Factura simplificada con IPSI
        $invoice = Invoice::factory()->create([
            'number' => 'TICKET-IPSI-001',
            'date' => now(),
            'issuer_name' => 'Retail Melilla SL',
            'issuer_tax_id' => 'B99887766',
            'type' => 'F2', // Simplificada
            'description' => 'Venta retail Melilla',
            'amount' => 50.00,
            'tax' => 1.00, // IPSI 2%
            'total' => 51.00,
            'is_first_invoice' => false,
            'customer_name' => null,
            'customer_tax_id' => null,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '02', // IPSI
            'regime_type' => '08',
            'operation_type' => 'S1',
            'tax_rate' => 2.0,
            'base_amount' => 50.00,
            'tax_amount' => 1.00,
        ]);

        // Assert
        $this->assertEquals('F2', $invoice->type);
        $this->assertEquals('02', $invoice->breakdowns->first()->tax_type);
        $this->assertNull($invoice->customer_tax_id);
    }

    /** @test */
    public function it_supports_ipsi_exempt_operations()
    {
        // Arrange: Operación exenta de IPSI
        $invoice = Invoice::factory()->create([
            'number' => 'IPSI-EXEMPT-001',
            'date' => now(),
            'issuer_name' => 'Exportador Ceuta SL',
            'issuer_tax_id' => 'B44556677',
            'type' => 'F1',
            'description' => 'Exportación desde Ceuta - Exenta IPSI',
            'amount' => 2000.00,
            'tax' => 0.00, // Exenta
            'total' => 2000.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Cliente Extranjero',
            'tax_id' => 'FR123456789',
            'country' => 'FR',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '02', // IPSI
            'regime_type' => '08',
            'operation_type' => 'S3', // Exenta
            'tax_rate' => 0.0,
            'base_amount' => 2000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertEquals('S3', $invoice->breakdowns->first()->operation_type);
        $this->assertEquals(0.00, $invoice->tax);
        $this->assertEquals('02', $invoice->breakdowns->first()->tax_type);
    }
}

