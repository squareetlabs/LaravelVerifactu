<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Squareetlabs\VeriFactu\Helpers\HashHelper;

class HashHelperTest extends TestCase
{
    public function testGenerateInvoiceHashReturnsHashAndInputString(): void
    {
        $data = [
            'issuer_tax_id' => 'A12345678',
            'invoice_number' => 'INV-001',
            'issue_date' => '2024-01-01',
            'invoice_type' => 'F1',
            'total_tax' => '21.00',
            'total_amount' => '121.00',
            'previous_hash' => '',
            'generated_at' => '2024-01-01T12:00:00+01:00',
        ];
        $result = HashHelper::generateInvoiceHash($data);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('inputString', $result);
        $this->assertEquals(64, strlen($result['hash']));
        $this->assertStringContainsString('IDEmisorFactura=A12345678', $result['inputString']);
    }

    public function testGenerateInvoiceHashThrowsOnMissingField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $data = [
            'issuer_tax_id' => 'A12345678',
            // Falta invoice_number
            'issue_date' => '2024-01-01',
            'invoice_type' => 'F1',
            'total_tax' => '21.00',
            'total_amount' => '121.00',
            'previous_hash' => '',
            'generated_at' => '2024-01-01T12:00:00+01:00',
        ];
        HashHelper::generateInvoiceHash($data);
    }
} 