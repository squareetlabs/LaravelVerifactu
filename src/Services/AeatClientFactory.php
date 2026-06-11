<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

use Squareetlabs\VeriFactu\Contracts\CertificateProvider;

/**
 * Builds ready-to-use AeatClient instances from configuration plus the bound
 * CertificateProvider, so host applications don't repeat the wiring.
 *
 * Multi-tenant / colaborador social usage:
 *
 *     $client = app(AeatClientFactory::class)->make(
 *         issuer: ['name' => $company->legal_name, 'vat' => $company->tax_id],
 *         representative: config('verifactu.representative'),
 *     );
 *
 * Single-issuer usage (everything from config):
 *
 *     $client = app(AeatClientFactory::class)->make();
 */
class AeatClientFactory
{
    public function __construct(private readonly CertificateProvider $certificates)
    {
    }

    /**
     * @param array{name: string, vat: string}|null $issuer Obligado emisión.
     *        Defaults to config('verifactu.issuer').
     * @param array{name: string, vat: string}|null $representative Colaborador
     *        social. Defaults to config('verifactu.representative') when set.
     */
    public function make(
        ?array $issuer = null,
        ?array $representative = null,
        ?bool $verifactuMode = null
    ): AeatClient {
        $certificate = $this->certificates->resolve($issuer['vat'] ?? null);

        return new AeatClient(
            certPath: $certificate->path,
            certPassword: $certificate->password,
            production: (bool) config('verifactu.aeat.production', false),
            verifactuMode: $verifactuMode,
            issuer: $issuer,
            representative: $representative,
        );
    }
}
