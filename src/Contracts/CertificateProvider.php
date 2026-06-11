<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Contracts;

use Squareetlabs\VeriFactu\Services\CertificateRef;

/**
 * Resolves the client certificate used to authenticate against AEAT.
 *
 * The default implementation (ConfigCertificateProvider) reads a single
 * platform certificate from config — the right fit for "entidad colaboradora"
 * platforms that submit on behalf of all their clients.
 *
 * Hosts with other storage models (per-issuer certificates, encrypted stores,
 * Vault, S3+KMS...) bind their own implementation in the container:
 *
 *     $this->app->bind(CertificateProvider::class, MyCertificateStore::class);
 */
interface CertificateProvider
{
    /**
     * @param string|null $issuerVat NIF of the obligado emisión, for providers
     *        that resolve a different certificate per issuer. The default
     *        config-based provider ignores it.
     */
    public function resolve(?string $issuerVat = null): CertificateRef;
}
