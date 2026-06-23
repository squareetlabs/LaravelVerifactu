<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

/**
 * Reference to a client certificate usable by ext-soap (local file path).
 */
final class CertificateRef
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $password = null,
    ) {
    }
}
