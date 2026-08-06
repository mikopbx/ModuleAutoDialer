<?php

namespace Modules\ModuleAutoDialer\Lib;

final class DialingCandidateSelector
{
    /**
     * Returns the first candidate allowed by absolute time, client lock, and local window.
     * Candidates must be supplied in the desired dialing order.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, bool> $busyClientIds
     * @return array<string, mixed>|null
     */
    public static function select(
        array $candidates,
        array $busyClientIds,
        int $now,
        int $timeStart,
        int $timeEnd
    ): ?array {
        foreach ($candidates as $candidate) {
            if ((int)($candidate['timeCallAllow'] ?? 0) > $now) {
                continue;
            }

            $clientId = (string)($candidate['clientId'] ?? '');
            if ($clientId !== '' && isset($busyClientIds[$clientId])) {
                continue;
            }

            $offset = array_key_exists('timeOffsetMinutes', $candidate)
                && $candidate['timeOffsetMinutes'] !== null
                ? (int)$candidate['timeOffsetMinutes']
                : null;
            if (!DialingWindow::isAllowed($now, $offset, $timeStart, $timeEnd)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
