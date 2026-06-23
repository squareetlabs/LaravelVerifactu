<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\Recipient;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Contracts\VeriFactuBreakdown;
use Squareetlabs\VeriFactu\Contracts\VeriFactuRecipient;

class ContractComplianceTest extends TestCase
{
    public function testInvoiceModelImplementsContract(): void
    {
        $invoice = new Invoice();
        $this->assertInstanceOf(VeriFactuInvoice::class, $invoice);
    }

    public function testBreakdownModelImplementsContract(): void
    {
        $breakdown = new Breakdown();
        $this->assertInstanceOf(VeriFactuBreakdown::class, $breakdown);
    }

    public function testRecipientModelImplementsContract(): void
    {
        $recipient = new Recipient();
        $this->assertInstanceOf(VeriFactuRecipient::class, $recipient);
    }

    public function testCustomImplementationCanBeInstantiated(): void
    {
        $customInvoice = new class implements VeriFactuInvoice {
            public function getInvoiceNumber(): string
            {
                return 'TEST-001';
            }
            public function getIssueDate(): \Carbon\Carbon
            {
                return now();
            }
            public function getInvoiceType(): string
            {
                return 'F1';
            }
            public function getTotalAmount(): float
            {
                return 100.0;
            }
            public function getTaxAmount(): float
            {
                return 21.0;
            }
            public function getCustomerName(): string
            {
                return 'Test';
            }
            public function getCustomerTaxId(): ?string
            {
                return '12345678Z';
            }
            public function getBreakdowns(): \Illuminate\Support\Collection
            {
                return collect();
            }
            public function getRecipients(): \Illuminate\Support\Collection
            {
                return collect();
            }
            public function getPreviousHash(): ?string
            {
                return null;
            }
            public function getOperationDescription(): string
            {
                return 'Test';
            }
            public function getOperationDate(): ?\Carbon\Carbon
            {
                return null;
            }
            public function getTaxPeriod(): ?string
            {
                return null;
            }
            public function getCorrectionType(): ?string
            {
                return null;
            }
            public function getExternalReference(): ?string
            {
                return null;
            }
            public function getCorrectedBaseAmount(): ?float
            {
                return null;
            }
            public function getCorrectedTaxAmount(): ?float
            {
                return null;
            }
            public function getCorrectedSurchargeAmount(): ?float
            {
                return null;
            }
        };

        $this->assertInstanceOf(VeriFactuInvoice::class, $customInvoice);
    }
}
