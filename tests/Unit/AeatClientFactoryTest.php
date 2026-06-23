<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Contracts\CertificateProvider;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Squareetlabs\VeriFactu\Services\AeatClientFactory;
use Squareetlabs\VeriFactu\Services\CertificateRef;
use Illuminate\Support\Facades\Config;

class AeatClientFactoryTest extends TestCase
{
    public function testBuildsClientWithIssuerRepresentativeAndProviderCertificate(): void
    {
        Config::set('verifactu.aeat.production', false);

        $provider = $this->createMock(CertificateProvider::class);
        $provider->expects($this->once())
            ->method('resolve')
            ->with('B11111111') // recibe el NIF del emisor para providers por-emisor
            ->willReturn(new CertificateRef('/certs/platform.pem', 'pwd'));

        $factory = new AeatClientFactory($provider);

        $client = $factory->make(
            issuer: ['name' => 'Tenant SL', 'vat' => 'B11111111'],
            representative: ['name' => 'Plataforma SL', 'vat' => 'B99999999'],
        );

        $this->assertInstanceOf(AeatClient::class, $client);
        $this->assertEquals('/certs/platform.pem', $this->prop($client, 'certPath'));
        $this->assertEquals('pwd', $this->prop($client, 'certPassword'));
        $this->assertEquals(['name' => 'Tenant SL', 'vat' => 'B11111111'], $this->prop($client, 'issuer'));
        $this->assertEquals(['name' => 'Plataforma SL', 'vat' => 'B99999999'], $this->prop($client, 'representative'));
        $this->assertFalse($this->prop($client, 'production'));
    }

    public function testDefaultsToConfigIssuerWhenNoneGiven(): void
    {
        Config::set('verifactu.issuer', ['name' => 'Config Issuer', 'vat' => 'A00000000']);
        Config::set('verifactu.representative', ['name' => '', 'vat' => '']);

        $provider = $this->createMock(CertificateProvider::class);
        $provider->method('resolve')->willReturn(new CertificateRef('/certs/platform.pem'));

        $client = (new AeatClientFactory($provider))->make();

        $this->assertEquals(['name' => 'Config Issuer', 'vat' => 'A00000000'], $this->prop($client, 'issuer'));
        $this->assertNull($this->prop($client, 'representative'));
    }

    public function testIsResolvableFromContainer(): void
    {
        $this->assertInstanceOf(
            AeatClientFactory::class,
            $this->app->make(AeatClientFactory::class),
        );
    }

    private function prop(AeatClient $client, string $property): mixed
    {
        $ref = new \ReflectionProperty(AeatClient::class, $property);

        return $ref->getValue($client);
    }
}
