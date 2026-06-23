<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Contracts\CertificateProvider;
use Squareetlabs\VeriFactu\Exceptions\VeriFactuException;
use Squareetlabs\VeriFactu\Services\ConfigCertificateProvider;
use Illuminate\Support\Facades\Config;

class ConfigCertificateProviderTest extends TestCase
{
    private string $tmpCert;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpCert = tempnam(sys_get_temp_dir(), 'vf-cert-');
        file_put_contents($this->tmpCert, 'dummy-pem');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpCert);
        parent::tearDown();
    }

    public function testResolvesCertificateFromConfig(): void
    {
        Config::set('verifactu.aeat.cert_path', $this->tmpCert);
        Config::set('verifactu.aeat.cert_password', 'secret');

        $ref = (new ConfigCertificateProvider())->resolve();

        $this->assertEquals($this->tmpCert, $ref->path);
        $this->assertEquals('secret', $ref->password);
    }

    public function testNullPasswordIsPreserved(): void
    {
        Config::set('verifactu.aeat.cert_path', $this->tmpCert);
        Config::set('verifactu.aeat.cert_password', null);

        $ref = (new ConfigCertificateProvider())->resolve();

        $this->assertNull($ref->password);
    }

    public function testThrowsWhenNoCertificateConfigured(): void
    {
        Config::set('verifactu.aeat.cert_path', '');

        $this->expectException(VeriFactuException::class);
        $this->expectExceptionMessage('No certificate configured');

        (new ConfigCertificateProvider())->resolve();
    }

    public function testThrowsWhenCertificateFileMissing(): void
    {
        Config::set('verifactu.aeat.cert_path', '/nonexistent/cert.pem');

        $this->expectException(VeriFactuException::class);
        $this->expectExceptionMessage('not found');

        (new ConfigCertificateProvider())->resolve();
    }

    public function testIsBoundAsDefaultImplementationInContainer(): void
    {
        $this->assertInstanceOf(
            ConfigCertificateProvider::class,
            $this->app->make(CertificateProvider::class),
        );
    }
}
