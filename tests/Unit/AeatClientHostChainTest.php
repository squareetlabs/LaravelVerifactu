<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\InteractsWithAeatClient;
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
    use InteractsWithAeatClient;

    private const PRECOMPUTED_HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789';
    private const GENERATED_AT = '2026-06-10T10:00:00+00:00';

    public function testPrecomputedRecordHashAndTimestampAreSubmittedVerbatim(): void
    {
        Config::set('verifactu.issuer', ['name' => 'Config Issuer', 'vat' => 'B00000000']);

        $soapClientMock = $this->mockSoapExpecting(function ($args) {
            $registroAlta = $args[0]['RegistroFactura'][0]['RegistroAlta'];

            return $registroAlta['Huella'] === self::PRECOMPUTED_HASH
                && $registroAlta['FechaHoraHusoGenRegistro'] === self::GENERATED_AT;
        });

        $client = $this->makeAeatClient($soapClientMock);

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

        $soapClientMock = $this->mockSoapExpecting(function ($args) {
            $body = $args[0];
            $registroAlta = $body['RegistroFactura'][0]['RegistroAlta'];

            return $body['Cabecera']['ObligadoEmision']['NIF'] === 'B11111111'
                && $body['Cabecera']['ObligadoEmision']['NombreRazon'] === 'Tenant Company SL'
                && $registroAlta['IDFactura']['IDEmisorFactura'] === 'B11111111';
        });

        $client = $this->makeAeatClient($soapClientMock, issuer: [
            'name' => 'Tenant Company SL',
            'vat' => 'B11111111',
        ]);

        $result = $client->sendInvoice($this->makeInvoiceMock());

        $this->assertEquals('success', $result['status']);
    }
}
