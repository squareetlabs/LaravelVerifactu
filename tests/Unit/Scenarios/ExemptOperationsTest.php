<?php

declare(strict_types=1);

namespace Tests\Unit\Scenarios;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\Recipient;

/**
 * Test para operaciones exentas (S3)
 * 
 * Casos de uso:
 * - Exportaciones (Art. 21 LIVA)
 * - Entregas intracomunitarias (Art. 25 LIVA)
 * - Servicios educativos, sanitarios (Art. 20 LIVA)
 * - Servicios financieros, seguros (Art. 20 LIVA)
 * 
 * Características:
 * - Operación sujeta al impuesto
 * - Pero exenta de pago
 * - Tipo impositivo = 0%
 * - CalificacionOperacion = 'S3'
 */
class ExemptOperationsTest extends TestCase
{
    /** @test */
    public function it_creates_export_invoice_with_exempt_operation()
    {
        // Arrange: Factura de exportación (exenta Art. 21 LIVA)
        $invoice = Invoice::factory()->create([
            'number' => 'EXP-2025-001',
            'date' => now(),
            'issuer_name' => 'Export Company SL',
            'issuer_tax_id' => 'B12345678',
            'type' => 'F1', // Factura completa
            'description' => 'Export of goods - Art. 21 LIVA',
            'amount' => 10000.00,
            'tax' => 0.00, // Exenta
            'total' => 10000.00,
            'is_first_invoice' => false,
        ]);

        // Destinatario fuera de la UE
        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'International Client Inc',
            'tax_id' => 'US123456789', // NIF extranjero
            'country' => 'US',
        ]);

        // Breakdown con operación exenta
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01', // IVA
            'regime_type' => '02', // Exportación
            'operation_type' => 'S3', // Sujeta y exenta
            'tax_rate' => 0.00,
            'base_amount' => 10000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertEquals(0.00, $invoice->tax);
        $this->assertEquals('02', $invoice->breakdowns->first()->regime_type);
        $this->assertEquals('S3', $invoice->breakdowns->first()->operation_type);
        $this->assertEquals(0.00, $invoice->breakdowns->first()->tax_rate);
    }

    /** @test */
    public function it_creates_intra_community_delivery_invoice()
    {
        // Arrange: Entrega intracomunitaria (Art. 25 LIVA)
        $invoice = Invoice::factory()->create([
            'number' => 'EU-2025-001',
            'date' => now(),
            'issuer_name' => 'EU Trader SL',
            'issuer_tax_id' => 'ESB12345678',
            'type' => 'F1',
            'description' => 'Intra-community delivery - Art. 25 LIVA',
            'amount' => 5000.00,
            'tax' => 0.00, // Exenta
            'total' => 5000.00,
            'is_first_invoice' => false,
        ]);

        // Destinatario en UE
        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'EU Company GmbH',
            'tax_id' => 'DE123456789', // NIF alemán
            'country' => 'DE',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01', // IVA
            'regime_type' => '01', // General (entregas intracomunitarias)
            'operation_type' => 'S3', // Sujeta y exenta
            'tax_rate' => 0.00,
            'base_amount' => 5000.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertEquals('DE', $invoice->recipients->first()->country);
        $this->assertEquals('S3', $invoice->breakdowns->first()->operation_type);
        $this->assertEquals(0.00, $invoice->total - $invoice->amount);
    }

    /** @test */
    public function it_creates_education_services_exempt_invoice()
    {
        // Arrange: Servicios educativos exentos (Art. 20.1.9º LIVA)
        $invoice = Invoice::factory()->create([
            'number' => 'EDU-2025-001',
            'date' => now(),
            'issuer_name' => 'Academia de Formación SL',
            'issuer_tax_id' => 'B87654321',
            'type' => 'F1',
            'description' => 'Educational services - Art. 20.1.9º LIVA',
            'amount' => 1200.00,
            'tax' => 0.00, // Exenta
            'total' => 1200.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Alumno Particular',
            'tax_id' => '12345678A',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01', // IVA
            'regime_type' => '01', // General
            'operation_type' => 'S3', // Sujeta y exenta
            'tax_rate' => 0.00,
            'base_amount' => 1200.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertStringContainsString('Educational', $invoice->description);
        $this->assertEquals('S3', $invoice->breakdowns->first()->operation_type);
    }

    /** @test */
    public function it_creates_medical_services_exempt_invoice()
    {
        // Arrange: Servicios médicos exentos (Art. 20.1.2º LIVA)
        $invoice = Invoice::factory()->create([
            'number' => 'MED-2025-001',
            'date' => now(),
            'issuer_name' => 'Clínica Médica SL',
            'issuer_tax_id' => 'B11223344',
            'type' => 'F1',
            'description' => 'Medical services - Art. 20.1.2º LIVA',
            'amount' => 150.00,
            'tax' => 0.00, // Exenta
            'total' => 150.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Paciente',
            'tax_id' => '87654321B',
            'country' => 'ES',
        ]);

        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01', // IVA
            'regime_type' => '01', // General
            'operation_type' => 'S3', // Sujeta y exenta
            'tax_rate' => 0.00,
            'base_amount' => 150.00,
            'tax_amount' => 0.00,
        ]);

        // Assert
        $this->assertEquals(0.00, $invoice->tax);
        $this->assertEquals(150.00, $invoice->total);
    }

    /** @test */
    public function it_supports_mixed_exempt_and_taxed_operations()
    {
        // Arrange: Factura con operaciones mixtas (exentas + sujetas)
        $invoice = Invoice::factory()->create([
            'number' => 'MIX-2025-001',
            'date' => now(),
            'issuer_name' => 'Mixed Services SL',
            'issuer_tax_id' => 'B55667788',
            'type' => 'F1',
            'description' => 'Mixed operations',
            'amount' => 2000.00,
            'tax' => 105.00, // Solo la parte sujeta
            'total' => 2105.00,
            'is_first_invoice' => false,
        ]);

        Recipient::factory()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Cliente Mixto SL',
            'tax_id' => 'B99887766',
            'country' => 'ES',
        ]);

        // Operación exenta (servicios educativos)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S3', // Exenta
            'tax_rate' => 0.00,
            'base_amount' => 1500.00,
            'tax_amount' => 0.00,
        ]);

        // Operación sujeta (material)
        Breakdown::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_type' => '01',
            'regime_type' => '01',
            'operation_type' => 'S1', // Sujeta no exenta
            'tax_rate' => 21.00,
            'base_amount' => 500.00,
            'tax_amount' => 105.00,
        ]);

        // Assert
        $this->assertCount(2, $invoice->breakdowns);
        $exemptBreakdown = $invoice->breakdowns->where('operation_type', 'S3')->first();
        $taxedBreakdown = $invoice->breakdowns->where('operation_type', 'S1')->first();
        
        $this->assertEquals(0.00, $exemptBreakdown->tax_amount);
        $this->assertEquals(105.00, $taxedBreakdown->tax_amount);
        $this->assertEquals(105.00, $invoice->tax);
    }
}

