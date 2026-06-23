<?php

declare(strict_types=1);

namespace Tests\Support;

use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Enums\InvoiceType;
use Squareetlabs\VeriFactu\Services\AeatClient;

/**
 * Helpers compartidos para tests de AeatClient: cliente con SoapClient
 * mockeado (sin llamadas de red) y mock de factura mínima válida.
 */
trait InteractsWithAeatClient
{
    /**
     * SoapClient mockeado que valida el body enviado a RegFactuSistemaFacturacion.
     *
     * @param callable(array $args): bool $assertion
     */
    protected function mockSoapExpecting(callable $assertion): \SoapClient
    {
        $soapClientMock = $this->getMockBuilder(\SoapClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__setLocation', '__soapCall', '__getLastRequest', '__getLastResponse'])
            ->getMock();

        $soapClientMock->expects($this->once())
            ->method('__soapCall')
            ->with('RegFactuSistemaFacturacion', $this->callback($assertion))
            ->willReturn(new \stdClass());

        return $soapClientMock;
    }

    /**
     * AeatClient real con el transporte SOAP sustituido por el mock.
     */
    protected function makeAeatClient(
        \SoapClient $soapClientMock,
        ?array $issuer = null,
        ?array $representative = null
    ): AeatClient {
        return new class ('/path/cert.pem', 'pwd', false, true, $issuer, $representative, $soapClientMock) extends AeatClient {
            private $soapClientMock;

            public function __construct($certPath, $certPassword, $production, $verifactuMode, $issuer, $representative, $soapClientMock)
            {
                parent::__construct($certPath, $certPassword, $production, $verifactuMode, $issuer, $representative);
                $this->soapClientMock = $soapClientMock;
            }

            protected function getSoapClient(): \SoapClient
            {
                return $this->soapClientMock;
            }
        };
    }

    /**
     * Factura mínima válida (F1, 100€ + 21% IVA) con overrides puntuales.
     *
     * @param array<string, mixed> $overrides método del contrato => valor
     */
    protected function makeInvoiceMock(array $overrides = []): VeriFactuInvoice
    {
        $defaults = [
            'getInvoiceNumber' => 'FAC-2026-000001',
            'getIssueDate' => now(),
            'getInvoiceType' => InvoiceType::STANDARD->value,
            'getTotalAmount' => 121.0,
            'getTaxAmount' => 21.0,
            'getBreakdowns' => collect(),
            'getRecipients' => collect(),
            'getPreviousHash' => null,
            'getOperationDescription' => 'Venta',
            'getOperationDate' => null,
            'getTaxPeriod' => null,
            'getCorrectionType' => null,
            'getExternalReference' => null,
        ];

        $invoiceMock = $this->createMock(VeriFactuInvoice::class);

        foreach ([...$defaults, ...$overrides] as $method => $value) {
            $invoiceMock->method($method)->willReturn($value);
        }

        return $invoiceMock;
    }
}
