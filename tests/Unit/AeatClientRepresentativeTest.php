<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Enums\InvoiceType;
use Illuminate\Support\Facades\Config;

/**
 * Colaborador social (entidad colaboradora AEAT): la plataforma remite con su
 * propio certificado en nombre del obligado de emisión. La Cabecera debe
 * incluir el bloque Representante, y el ObligadoEmision sigue siendo el emisor.
 */
class AeatClientRepresentativeTest extends TestCase
{
    public function testRepresentativeBlockIsAddedToCabecera(): void
    {
        Config::set('verifactu.issuer', ['name' => 'Cliente Emisor SL', 'vat' => 'B22222222']);
        Config::set('verifactu.representative', ['name' => '', 'vat' => '']);

        $soapClientMock = $this->mockSoapExpecting(function ($args) {
            $cabecera = $args[0]['Cabecera'];

            return $cabecera['ObligadoEmision']['NIF'] === 'B22222222'
                && $cabecera['Representante']['NIF'] === 'B99999999'
                && $cabecera['Representante']['NombreRazon'] === 'Quandium Platform SL';
        });

        $client = $this->makeClient($soapClientMock, representative: [
            'name' => 'Quandium Platform SL',
            'vat' => 'B99999999',
        ]);

        $result = $client->sendInvoice($this->makeInvoiceMock());
        $this->assertEquals('success', $result['status']);
    }

    public function testRepresentativeFallsBackToConfig(): void
    {
        Config::set('verifactu.issuer', ['name' => 'Cliente Emisor SL', 'vat' => 'B22222222']);
        Config::set('verifactu.representative', ['name' => 'Config Rep SL', 'vat' => 'B88888888']);

        $soapClientMock = $this->mockSoapExpecting(
            fn ($args) => $args[0]['Cabecera']['Representante']['NIF'] === 'B88888888',
        );

        $client = $this->makeClient($soapClientMock);

        $result = $client->sendInvoice($this->makeInvoiceMock());
        $this->assertEquals('success', $result['status']);
    }

    public function testNoRepresentativeBlockWhenNotConfigured(): void
    {
        Config::set('verifactu.issuer', ['name' => 'Emisor Directo SL', 'vat' => 'B22222222']);
        Config::set('verifactu.representative', ['name' => '', 'vat' => '']);

        $soapClientMock = $this->mockSoapExpecting(
            fn ($args) => !isset($args[0]['Cabecera']['Representante']),
        );

        $client = $this->makeClient($soapClientMock);

        $result = $client->sendInvoice($this->makeInvoiceMock());
        $this->assertEquals('success', $result['status']);
    }

    private function mockSoapExpecting(callable $assertion): \SoapClient
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

    private function makeClient(\SoapClient $soapClientMock, ?array $representative = null): AeatClient
    {
        return new class ('/path/cert.pem', 'pwd', false, true, null, $representative, $soapClientMock) extends AeatClient {
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

    private function makeInvoiceMock(): VeriFactuInvoice
    {
        $invoiceMock = $this->createMock(VeriFactuInvoice::class);
        $invoiceMock->method('getInvoiceNumber')->willReturn('FAC-2026-000010');
        $invoiceMock->method('getIssueDate')->willReturn(now());
        $invoiceMock->method('getInvoiceType')->willReturn(InvoiceType::STANDARD->value);
        $invoiceMock->method('getTotalAmount')->willReturn(121.0);
        $invoiceMock->method('getTaxAmount')->willReturn(21.0);
        $invoiceMock->method('getBreakdowns')->willReturn(collect());
        $invoiceMock->method('getRecipients')->willReturn(collect());
        $invoiceMock->method('getPreviousHash')->willReturn(null);
        $invoiceMock->method('getOperationDescription')->willReturn('Venta');
        $invoiceMock->method('getOperationDate')->willReturn(null);
        $invoiceMock->method('getTaxPeriod')->willReturn(null);
        $invoiceMock->method('getCorrectionType')->willReturn(null);
        $invoiceMock->method('getExternalReference')->willReturn(null);

        return $invoiceMock;
    }
}
