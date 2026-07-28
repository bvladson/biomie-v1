<?php

declare(strict_types=1);

namespace BIomie;

final class OperationalPayloadFingerprint
{
    /**
     * @param array<string,mixed> $requestParams
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function bronzeRecord(
        string $domain,
        string $entityName,
        string $endpoint,
        string $omieMethod,
        array $requestParams,
        array $payload,
        ?string $naturalKey = null,
        ?string $providerKey = null,
        array $metadata = [],
        string $source = 'runtime',
    ): array {
        $normalizedParams = self::normalizeValue($requestParams);
        $normalizedPayload = self::normalizeValue($payload);
        $normalizedMetadata = self::normalizeValue($metadata);

        $paramsJson = self::encodeJson($normalizedParams);
        $payloadJson = self::encodeJson($normalizedPayload);
        $metadataJson = $normalizedMetadata !== [] ? self::encodeJson($normalizedMetadata) : null;

        $requestHash = hash('sha256', implode('|', [
            $endpoint,
            $omieMethod,
            $paramsJson,
        ]));
        $payloadHash = hash('sha256', $payloadJson);
        $idempotencyKey = hash('sha256', implode('|', [
            $domain,
            $entityName,
            $endpoint,
            $omieMethod,
            $naturalKey ?? '',
            $providerKey ?? '',
            $requestHash,
            $payloadHash,
            $source,
        ]));

        return [
            'request_params_json' => $paramsJson,
            'payload_json' => $payloadJson,
            'metadata_json' => $metadataJson,
            'request_hash' => $requestHash,
            'payload_hash' => $payloadHash,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $headers
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function webhookRecord(
        string $domain,
        string $endpointGroup,
        array $payload,
        array $headers = [],
        ?string $candidateKey = null,
        array $metadata = [],
    ): array {
        $normalizedPayload = self::normalizeValue($payload);
        $normalizedHeaders = self::normalizeValue($headers);
        $normalizedMetadata = self::normalizeValue($metadata);

        $payloadJson = self::encodeJson($normalizedPayload);
        $headersJson = $normalizedHeaders !== [] ? self::encodeJson($normalizedHeaders) : null;
        $metadataJson = $normalizedMetadata !== [] ? self::encodeJson($normalizedMetadata) : null;
        $payloadHash = hash('sha256', $payloadJson);
        $dedupeKey = hash('sha256', implode('|', [
            $domain,
            $endpointGroup,
            $candidateKey ?? '',
            $payloadHash,
        ]));

        return [
            'payload_json' => $payloadJson,
            'headers_json' => $headersJson,
            'metadata_json' => $metadataJson,
            'payload_hash' => $payloadHash,
            'dedupe_key' => $dedupeKey,
        ];
    }

    public static function encodeJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Nao foi possivel serializar payload operacional em JSON.');
        }

        return $json;
    }

    public static function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (self::isList($value)) {
            $normalized = [];
            foreach ($value as $item) {
                $normalized[] = self::normalizeValue($item);
            }

            return $normalized;
        }

        $normalized = [];
        $keys = array_keys($value);
        sort($keys);
        foreach ($keys as $key) {
            $normalized[(string) $key] = self::normalizeValue($value[$key]);
        }

        return $normalized;
    }

    private static function isList(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
    }
}
