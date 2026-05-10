<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

use BIomie\Support;
use DateTimeImmutable;
use Throwable;

final class VendorSupport
{
    public static function formatPeriodLabel(string $start, string $end): string
    {
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $end);

        if (!$startDate instanceof DateTimeImmutable || !$endDate instanceof DateTimeImmutable) {
            return $start . ' ate ' . $end;
        }

        return $startDate->format('d/m/Y') . ' ate ' . $endDate->format('d/m/Y');
    }

    public static function normalizeKey(string $value): string
    {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return trim($normalized);
    }

    public static function summarizeThrowable(Throwable $throwable, int $maxLength = 220): string
    {
        $message = trim($throwable->getMessage());
        if ($message === '') {
            $message = $throwable::class;
        }

        if (strlen($message) <= $maxLength) {
            return $message;
        }

        return substr($message, 0, max(0, $maxLength - 3)) . '...';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function canonicalPayloadHasSignal(array $payload): bool
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $summaryStatus = is_array($payload['summary_status'] ?? null) ? $payload['summary_status'] : [];
        $dailyStatus = is_array($payload['daily_status'] ?? null) ? $payload['daily_status'] : [];

        $billed = Support::toFloat($summaryStatus['billed_value'] ?? $summary['gross_revenue'] ?? $summary['revenue'] ?? 0);
        $authorized = Support::toFloat($summaryStatus['authorized_value'] ?? $summary['pending_value'] ?? 0);
        $cancelled = Support::toFloat($summaryStatus['cancelled_value'] ?? $summary['cancelled_value'] ?? 0);
        $orders = (int) ($summary['orders'] ?? 0);
        $hasDailySignal = false;

        foreach ($dailyStatus as $row) {
            if (
                Support::toFloat($row['billed_value'] ?? 0) > 0
                || Support::toFloat($row['authorized_value'] ?? 0) > 0
                || Support::toFloat($row['cancelled_value'] ?? 0) > 0
                || (int) ($row['billed_orders'] ?? 0) > 0
                || (int) ($row['authorized_orders'] ?? 0) > 0
                || (int) ($row['cancelled_orders'] ?? 0) > 0
            ) {
                $hasDailySignal = true;
                break;
            }
        }

        return $billed > 0
            || $authorized > 0
            || $cancelled > 0
            || $orders > 0
            || self::matrixContainsSignal($payload['vendor_status_matrix'] ?? [])
            || $hasDailySignal;
    }

    /**
     * @param mixed $rows
     */
    public static function matrixContainsSignal(mixed $rows): bool
    {
        if (!is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (
                Support::toFloat($row['billed_value'] ?? 0) > 0
                || Support::toFloat($row['authorized_value'] ?? 0) > 0
                || Support::toFloat($row['cancelled_value'] ?? 0) > 0
                || (int) ($row['billed_orders'] ?? 0) > 0
                || (int) ($row['authorized_orders'] ?? 0) > 0
                || (int) ($row['cancelled_orders'] ?? 0) > 0
            ) {
                return true;
            }
        }

        return false;
    }
}
