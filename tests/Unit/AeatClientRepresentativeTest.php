<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\InteractsWithAeatClient;
use Illuminate\Support\Facades\Config;

/**
 * Colaborador social (entidad colaboradora AEAT): la plataforma remite con su
 * propio certificado en nombre del obligado de emisión. La Cabecera debe
 * incluir el bloque Representante, y el ObligadoEmision sigue siendo el emisor.
 */
class AeatClientRepresentativeTest extends TestCase
{
    use InteractsWithAeatClient;

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

        $client = $this->makeAeatClient($soapClientMock, representative: [
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

        $result = $this->makeAeatClient($soapClientMock)->sendInvoice($this->makeInvoiceMock());

        $this->assertEquals('success', $result['status']);
    }

    public function testNoRepresentativeBlockWhenNotConfigured(): void
    {
        Config::set('verifactu.issuer', ['name' => 'Emisor Directo SL', 'vat' => 'B22222222']);
        Config::set('verifactu.representative', ['name' => '', 'vat' => '']);

        $soapClientMock = $this->mockSoapExpecting(
            fn ($args) => !isset($args[0]['Cabecera']['Representante']),
        );

        $result = $this->makeAeatClient($soapClientMock)->sendInvoice($this->makeInvoiceMock());

        $this->assertEquals('success', $result['status']);
    }
}
