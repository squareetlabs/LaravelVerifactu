<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\Recipient;

/**
 * Test para operaciones de exportación
 * 
 * Caso de uso: Art. 21 LIVA - Exportaciones
 * 
 * Características:
 * - Operaciones exentas (destino fuera de la UE)
 * - ClaveRegimen = '02' (Exportación)
 * - CalificacionOperacion = 'S3' (Exenta)
 */
class ExportOperationsTest extends TestCase
{
    /** @test */
    public function it_creates_export_invoice_outside_eu()
    {
        // Arrange: Exportación a países terceros
        $invoice = Invoice::factory()->create([
            'number' => 'EXP-2025-001',
            'date' => now(),
            'issuer_name' => 'Exportadora España SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F1',
            'description' => 'Export to USA - Art. 21 LIVA',
            'amount' => 50000.00,
            'tax' => 0.00, // Exenta
            'total' => 50000.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'American Company Inc',
            'tax_id' => 'US123456789',
            'country' => 'US',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '02', // Exportación
            'operation_type' => 'S3', // Exenta
            'tax_rate' => 0.00,
            'base_amount' => 50000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertEquals('02', $invoice->breakdowns->first()->regime_type);
        $this->assertEquals('S3', $invoice->breakdowns->first()->operation_type);
        $this->assertEquals(0.00, $invoice->tax);
        $this->assertEquals('US', $invoice->recipients->first()->country);
    }

    /** @test */
    public function it_creates_export_invoice_to_multiple_destinations()
    {
        // Arrange: Exportación con múltiples líneas
        $invoice = Invoice::factory()->create([
            'number' => 'EXP-2025-002',
            'date' => now(),
            'issuer_name' => 'Global Exporter SL',
            'issuer_tax_id' => 'B87654321',
            'type' => 'F1',
            'description' => 'Multiple products export',
            'amount' => 100000.00,
            'tax' => 0.00,
            'total' => 100000.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Asian Distributor Ltd',
            'tax_id' => 'CN987654321',
            'country' => 'CN',
        ]);

        // Producto 1
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '02',
            'operation_type' => 'S3',
            'tax_rate' => 0.00,
            'base_amount' => 60000.00,
            'tax_amount' => 0.00,
        ]);

        // Producto 2
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '02',
            'operation_type' => 'S3',
            'tax_rate' => 0.00,
            'base_amount' => 40000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertCount(2, $invoice->breakdowns);
        $this->assertTrue($invoice->breakdowns->every(fn($b) => $b->regime_type === '02'));
        $this->assertTrue($invoice->breakdowns->every(fn($b) => $b->operation_type === 'S3'));
    }

    /** @test */
    public function export_invoice_can_be_chained()
    {
        // Arrange
        $firstInvoice = Invoice::factory()->create([
            'number' => 'EXP-001',
            'date' => now()->subDay(),
            'issuer_name' => 'Exporter SL',
            'issuer_tax_id' => 'B11223344',
            'type' => 'F1',
            'is_first_invoice' => true,
            'amount' => 30000.00,
            'tax' => 0.00,
            'total' => 30000.00,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $firstInvoice->id,
            'tax_type' => '01',
            'regime_type' => '02',
            'operation_type' => 'S3',
            'tax_rate' => 0.00,
            'base_amount' => 30000.00,
            'tax_amount' => 0.00,
        ]);

        $secondInvoice = Invoice::factory()->create([
            'number' => 'EXP-002',
            'date' => now(),
            'issuer_name' => 'Exporter SL',
            'issuer_tax_id' => 'B11223344',
            'type' => 'F1',
            'is_first_invoice' => false,
            'previous_invoice_number' => $firstInvoice->number,
            'previous_invoice_date' => $firstInvoice->date,
            'previous_invoice_hash' => $firstInvoice->hash,
            'amount' => 45000.00,
            'tax' => 0.00,
            'total' => 45000.00,
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $secondInvoice->id,
            'tax_type' => '01',
            'regime_type' => '02',
            'operation_type' => 'S3',
            'tax_rate' => 0.00,
            'base_amount' => 45000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertEquals($firstInvoice->hash, $secondInvoice->previous_invoice_hash);
        $this->assertEquals('02', $secondInvoice->breakdowns->first()->regime_type);
    }
}

