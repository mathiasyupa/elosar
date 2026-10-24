<?php

namespace App\Services\Sri;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Loads a PKCS#12 (.p12 / .pfx) signing certificate from the private disk.
 */
class SriCertificateLoader
{
    /**
     * @return array{certificate: string, private_key: string, extra_certs: array<int,string>}
     *
     * @throws RuntimeException when the file is missing or the password is wrong.
     */
    public function load(string $path, string $password): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw new RuntimeException("SRI certificate not found at [{$path}].");
        }

        $pkcs12 = $disk->get($path);
        $certs = [];

        if (! openssl_pkcs12_read($pkcs12, $certs, $password)) {
            throw new RuntimeException('Could not open the SRI certificate. Check the password.');
        }

        return [
            'certificate' => $certs['cert'],
            'private_key' => $certs['pkey'],
            'extra_certs' => $certs['extracerts'] ?? [],
        ];
    }
}
