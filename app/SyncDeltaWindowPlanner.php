<?php

declare(strict_types=1);

namespace BIomie;

use DateTimeImmutable;

final class SyncDeltaWindowPlanner
{
    /**
     * @param array<string,mixed> $profile
     * @param list<array<string,mixed>> $cursors
     * @return array{start_at:DateTimeImmutable,end_at:DateTimeImmutable}|null
     */
    public static function resolve(array $profile, array $cursors, DateTimeImmutable $now): ?array
    {
        $hotWindowMinutes = max(1, (int) ($profile['hot_window_minutes'] ?? 120));
        $safetyWindowMinutes = max(0, (int) ($profile['safety_window_minutes'] ?? 15));

        if ($cursors === []) {
            return [
                'start_at' => $now->modify(sprintf('-%d minutes', $hotWindowMinutes + $safetyWindowMinutes)),
                'end_at' => $now,
            ];
        }

        $startCandidates = [];
        $endCandidates = [];
        $hasDueCursor = false;
        foreach ($cursors as $cursor) {
            $repairFrom = trim((string) ($cursor['repair_from'] ?? ''));
            $repairTo = trim((string) ($cursor['repair_to'] ?? ''));
            $nextDeltaAt = trim((string) ($cursor['next_delta_at'] ?? ''));

            $due = $repairFrom !== '' || $nextDeltaAt === '' || $nextDeltaAt <= $now->format('Y-m-d H:i:s');
            if (!$due) {
                continue;
            }
            $hasDueCursor = true;

            if ($repairFrom !== '') {
                $startCandidates[] = new DateTimeImmutable($repairFrom);
                $endCandidates[] = new DateTimeImmutable($repairTo !== '' ? $repairTo : $now->format('Y-m-d H:i:s'));
                continue;
            }

            $cursorSafety = max($safetyWindowMinutes, (int) ($cursor['safety_window_minutes'] ?? 0));
            $startCandidates[] = $now->modify(sprintf('-%d minutes', $hotWindowMinutes + $cursorSafety));
            $endCandidates[] = $now;
        }

        if (!$hasDueCursor) {
            return null;
        }

        $startAt = $startCandidates[0] ?? $now->modify(sprintf('-%d minutes', $hotWindowMinutes + $safetyWindowMinutes));
        foreach ($startCandidates as $candidate) {
            if ($candidate < $startAt) {
                $startAt = $candidate;
            }
        }

        $endAt = $endCandidates[0] ?? $now;
        foreach ($endCandidates as $candidate) {
            if ($candidate > $endAt) {
                $endAt = $candidate;
            }
        }

        if ($endAt < $startAt) {
            $endAt = $startAt;
        }

        return [
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }
}
