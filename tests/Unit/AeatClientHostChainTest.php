<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Enums\InvoiceType;
use Illuminate\Support\Facades\Config;

/**
 * Covers the host-chain integration features:
 *  - sendInvoice() must submit a precomputed hash and generated_at timestamp
 *    verbatim (apps that persist their own chain at issuance time would break
 *    chain integrity if the client recomputed them at submission time).
 *  - Per-instance issuer for multi-tenant hosts where each company is a
 *    different obligado emisión.
 */
class AeatClientHostChainTest extends TestCase
{
    private const PRECOMPUTED_HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789';
    private const GENERATED_AT = '2026-06-10T10:00:00+00:00';

    public function testPrecomputedRecordHashAndTimestampAreSubmittedVerbatim(): void
    {
        Config::set('verifactu.issuer', ['name' => 'Config Issuer', 'vat' => 'B00000000']);

        $soapClientMock = $this->getMockBuilder(\SoapClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__setLocation', '__soapCall', '__getLastRequest', '__getLastResponse'])
            ->getMock();

        $soapClientMock->expects($this->once())
            ->method('__soapCall')
            ->with(
                'RegFactuSistemaFacturacion',
                $this->callback(function ($args) {
                    $registroAlta = $args[0]['RegistroFactura'][0]['RegistroAlta'];

                    return $registroAlta['Huella'] === self::PRECOMPUTED_HASH
                        && $registroAlta['FechaHoraHusoGenRegistro'] === self::GENERATED_AT;
                })
            )
            ->willReturn(new \stdClass());

        $client = $this->makeClient($soapClientMock);

        $result = $client->sendInvoice($this->makeInvoiceMock(), null, [
            'hash' => self::PRECOMPUTED_HASH,
            'generated_at' => self::GENERATED_AT,
        ]);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(self::PRECOMPUTED_HASH, $result['hash']);
        $this->assertEquals(self::GENERATED_AT, $result['timestamp']);
    }

    public function testPerInstanceIssuerOverridesConfig(): void
    {
        Config::set('verifactu.issuer', ['name' => 'Config Issuer', 'vat' => 'B00000000']);

        $soapClientMock = $this->getMockBuilder(\SoapClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__setLocation', '__soapCall', '__getLastRequest', '__getLastResponse'])
            ->getMock();

        $soapClientMock->expects($this->once())
            ->method('__soapCall')
            ->with(
                'RegFactuSistemaFacturacion',
                $this->callback(function ($args) {
                    $body = $args[0];
                    $registroAlta = $body['RegistroFactura'][0]['RegistroAlta'];

                    return $body['Cabecera']['ObligadoEmision']['NIF'] === 'B11111111'
                        && $body['Cabecera']['ObligadoEmision']['NombreRazon'] === 'Tenant Company SL'
                        && $registroAlta['IDFactura']['IDEmisorFactura'] === 'B11111111';
                })
            )
            ->willReturn(new \stdClass());

        $client = $this->makeClient($soapClientMock, [
            'name' => 'Tenant Company SL',
            'vat' => 'B11111111',
        ]);

        $result = $client->sendInvoice($this->makeInvoiceMock());

        $this->assertEquals('success', $result['status']);
    }

    private function makeClient(\SoapClient $soapClientMock, ?array $issuer = null): AeatClient
    {
        return new class ('/path/to/cert.pem', 'password', false, true, $issuer, $soapClientMock) extends AeatClient {
            private $soapClientMock;

            public function __construct($certPath, $certPassword, $production, $verifactuMode, $issuer, $soapClientMock)
            {
                parent::__construct($certPath, $certPassword, $production, $verifactuMode, $issuer);
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
        $invoiceMock->method('getInvoiceNumber')->willReturn('FAC-2026-000001');
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
