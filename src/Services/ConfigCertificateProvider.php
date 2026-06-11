<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

use Squareetlabs\VeriFactu\Contracts\CertificateProvider;
use Squareetlabs\VeriFactu\Exceptions\VeriFactuException;

/**
 * Default certificate provider: a single certificate configured under
 * verifactu.aeat.cert_path / cert_password.
 *
 * This is the natural fit for platforms operating as AEAT "entidad
 * colaboradora": one platform certificate submits on behalf of every issuer,
 * so issuers never provide their own.
 */
class ConfigCertificateProvider implements CertificateProvider
{
    public function resolve(?string $issuerVat = null): CertificateRef
    {
        $path = (string) config('verifactu.aeat.cert_path', '');

        if ($path === '') {
            throw new VeriFactuException(
                'No certificate configured: set verifactu.aeat.cert_path (VERIFACTU_CERT_PATH).'
            );
        }

        if (!is_file($path)) {
            throw new VeriFactuException(
                "Certificate file not found or not readable: {$path}"
            );
        }

        $password = config('verifactu.aeat.cert_password');

        return new CertificateRef($path, $password === null ? null : (string) $password);
    }
}
