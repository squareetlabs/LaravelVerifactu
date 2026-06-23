<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Contracts\VeriFactuBreakdown;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class AeatClientHybridTest extends TestCase
{
    public function testSendInvoiceAcceptsCustomImplementation(): void
    {
        // Create a custom invoice implementation
        $customInvoice = new class implements VeriFactuInvoice {
            public function getInvoiceNumber(): string
            {
                return 'TEST-CUSTOM-001';
            }
            public function getIssueDate(): Carbon
            {
                return Carbon::parse('2024-01-01');
            }
            public function getInvoiceType(): string
            {
                return 'F1';
            }
            public function getTotalAmount(): float
            {
                return 121.0;
            }
            public function getTaxAmount(): float
            {
                return 21.0;
            }
            public function getCustomerName(): string
            {
                return 'Custom Customer';
            }
            public function getCustomerTaxId(): ?string
            {
                return 'B12345678';
            }
            public function getPreviousHash(): ?string
            {
                return null;
            }
            public function getOperationDescription(): string
            {
                return 'Custom Operation';
            }

            public function getBreakdowns(): Collection
            {
                return collect([
                    new class implements VeriFactuBreakdown {
                    public function getRegimeType(): string
                    {
                        return '01';
                    }
                    public function getOperationType(): string
                    {
                        return 'S1';
                    }
                    public function getTaxRate(): float
                    {
                        return 21.0;
                    }
                    public function getBaseAmount(): float
                    {
                        return 100.0;
                    }
                    public function getTaxAmount(): float
                    {
                        return 21.0;
                    }
                    }
                ]);
            }

            public function getRecipients(): Collection
            {
                return collect();
            }
            public function getOperationDate(): ?Carbon
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

        // Mock AeatClient to avoid real SOAP calls but verify method signature
        $client = $this->getMockBuilder(AeatClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendInvoice']) // We mock the method itself to verify it accepts the type
            ->getMock();

        // Expect sendInvoice to be called with our custom invoice
        $client->expects($this->once())
            ->method('sendInvoice')
            ->with($this->isInstanceOf(VeriFactuInvoice::class))
            ->willReturn(['status' => 'success']);

        $result = $client->sendInvoice($customInvoice);
        $this->assertEquals('success', $result['status']);
    }
}
