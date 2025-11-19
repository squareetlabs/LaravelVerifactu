<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Squareetlabs\VeriFactu\Helpers\HashHelper;

/**
 * Test compliance with AEAT official specifications for hash generation.
 * 
 * Reference: "Detalle de las especificaciones técnicas para generación de la 
 * huella o hash de los registros de facturación" v0.1.2 (27/08/2024)
 * 
 * @see https://sede.agenciatributaria.gob.es/
 */
class HashHelperAeatComplianceTest extends TestCase
{
    /**
     * Test Case 1 from AEAT spec (page 10):
     * First invoice registration in a SIF (no previous hash).
     * 
     * Expected hash: 3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60
     */
    public function test_first_invoice_hash_matches_aeat_specification(): void
    {
        $data = [
            'issuer_tax_id' => '89890001K',
            'invoice_number' => '12345678/G33',
            'issue_date' => '01-01-2024',
            'invoice_type' => 'F1',
            'total_tax' => '12.35',
            'total_amount' => '123.45',
            'previous_hash' => '', // Empty for first invoice
            'generated_at' => '2024-01-01T19:20:30+01:00',
        ];

        $result = HashHelper::generateInvoiceHash($data);

        // Verify hash format (64 chars, uppercase hex)
        $this->assertMatchesRegularExpression('/^[A-F0-9]{64}$/', $result['hash']);
        
        // Verify hash matches AEAT expected value
        $this->assertEquals(
            '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
            $result['hash'],
            'Hash does not match AEAT official example for first invoice'
        );

        // Verify input string uses official AEAT field names
        $expectedInputString = 'IDEmisorFactura=89890001K&NumSerieFactura=12345678/G33&'
            . 'FechaExpedicionFactura=01-01-2024&TipoFactura=F1&CuotaTotal=12.35&'
            . 'ImporteTotal=123.45&Huella=&FechaHoraHusoGenRegistro=2024-01-01T19:20:30+01:00';

        $this->assertEquals($expectedInputString, $result['inputString']);
    }

    /**
     * Test Case 2 from AEAT spec (page 11):
     * Second invoice with previous hash.
     * 
     * Expected hash: F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97
     */
    public function test_second_invoice_hash_matches_aeat_specification(): void
    {
        $data = [
            'issuer_tax_id' => '89890001K',
            'invoice_number' => '12345679/G34',
            'issue_date' => '01-01-2024',
            'invoice_type' => 'F1',
            'total_tax' => '12.35',
            'total_amount' => '123.45',
            'previous_hash' => '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
            'generated_at' => '2024-01-01T19:20:35+01:00',
        ];

        $result = HashHelper::generateInvoiceHash($data);

        $this->assertEquals(
            'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97',
            $result['hash'],
            'Hash does not match AEAT official example for second invoice'
        );
    }

    /**
     * Test that hash output is always uppercase (page 9 of spec).
     */
    public function test_hash_output_is_uppercase(): void
    {
        $data = [
            'issuer_tax_id' => '89890001K',
            'invoice_number' => 'TEST-001',
            'issue_date' => '01-01-2024',
            'invoice_type' => 'F1',
            'total_tax' => '10.00',
            'total_amount' => '100.00',
            'previous_hash' => '',
            'generated_at' => '2024-01-01T12:00:00+01:00',
        ];

        $result = HashHelper::generateInvoiceHash($data);

        $this->assertMatchesRegularExpression(
            '/^[A-F0-9]{64}$/',
            $result['hash'],
            'Hash must be 64 uppercase hexadecimal characters'
        );
        
        $this->assertEquals(
            strtoupper($result['hash']),
            $result['hash'],
            'Hash must be in uppercase'
        );
    }

    /**
     * Test whitespace trimming (page 6 of spec):
     * Values with leading/trailing spaces should be trimmed.
     */
    public function test_whitespace_trimming(): void
    {
        $dataWithSpaces = [
            'issuer_tax_id' => '89890001K',
            'invoice_number' => ' 12345678 / G33 ', // Spaces inside preserved
            'issue_date' => '01-01-2024',
            'invoice_type' => 'F1',
            'total_tax' => '12.35',
            'total_amount' => '123.45',
            'previous_hash' => '',
            'generated_at' => '2024-01-01T19:20:30+01:00',
        ];

        $dataWithoutSpaces = [
            'issuer_tax_id' => '89890001K',
            'invoice_number' => '12345678 / G33', // Trimmed
            'issue_date' => '01-01-2024',
            'invoice_type' => 'F1',
            'total_tax' => '12.35',
            'total_amount' => '123.45',
            'previous_hash' => '',
            'generated_at' => '2024-01-01T19:20:30+01:00',
        ];

        $hashWithSpaces = HashHelper::generateInvoiceHash($dataWithSpaces)['hash'];
        $hashWithoutSpaces = HashHelper::generateInvoiceHash($dataWithoutSpaces)['hash'];

        $this->assertEquals(
            $hashWithSpaces,
            $hashWithoutSpaces,
            'Leading/trailing spaces should be trimmed but internal spaces preserved'
        );
    }
}

