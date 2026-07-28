<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Bootstrap;

final class CaBundleResolver
{
    public static function resolve(): ?string
    {
        foreach (self::candidates() as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /** @return list<string> */
    public static function candidates(): array
    {
        $paths = [
            (string) (getenv('OMIE_CA_BUNDLE') ?: ''),
            (string) (getenv('CURL_CA_BUNDLE') ?: ''),
            (string) (getenv('SSL_CERT_FILE') ?: ''),
            (string) ini_get('curl.cainfo'),
            (string) ini_get('openssl.cafile'),
            Bootstrap::rootPath('storage/certs/cacert.pem'),
            dirname(PHP_BINARY) . '/extras/ssl/cacert.pem',
            'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
            'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/share/certs/ca-root-nss.crt',
            '/etc/ssl/cert.pem',
        ];

        $localAppData = trim((string) (getenv('LOCALAPPDATA') ?: ''));
        if ($localAppData !== '') {
            $githubBundles = glob(
                str_replace('\\', '/', $localAppData)
                . '/GitHubDesktop/app-*/resources/app/git/mingw64/etc/ssl/certs/ca-bundle.crt'
            ) ?: [];
            rsort($githubBundles, SORT_NATURAL);
            array_push($paths, ...$githubBundles);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $path): string => trim(str_replace('\\', '/', $path)),
            $paths
        ))));
    }
}
