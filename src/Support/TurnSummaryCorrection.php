<?php

namespace MotionWork\StatamicAiAssistant\Support;

/**
 * The planner writes a turn's summary when it dispatches the entry jobs,
 * before they finish ("Fertig — «…» aktualisiert."). Once every entry of that
 * turn has finished and one of them failed, the summary is rewritten so it
 * does not claim success. Runs in the CP language of the job (CpLocale).
 */
final class TurnSummaryCorrection
{
    private const FINISHED = ['ready', 'saved', 'failed'];

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public static function apply(array $session): array
    {
        $entries = is_array($session['entries'] ?? null) ? $session['entries'] : [];
        $transcript = is_array($session['transcript'] ?? null) ? $session['transcript'] : [];

        foreach ($transcript as $i => $turn) {
            $text = self::correctedText($turn, $entries);
            if ($text !== null) {
                $transcript[$i]['text'] = $text;
                $transcript[$i]['corrected'] = true;
            }
        }
        $session['transcript'] = $transcript;

        return $session;
    }

    /**
     * The truthful summary for a finished turn with failures, else null.
     *
     * @param  array<string, mixed>  $turn
     * @param  array<string, array<string, mixed>>  $entries
     */
    private static function correctedText(array $turn, array $entries): ?string
    {
        $ids = is_array($turn['entry_ids'] ?? null) ? $turn['entry_ids'] : [];
        if (($turn['role'] ?? '') !== 'assistant' || ($turn['kind'] ?? '') !== 'summary' || ! empty($turn['corrected']) || $ids === []) {
            return null;
        }

        $rows = array_values(array_filter(array_map(fn ($id) => $entries[$id] ?? null, $ids)));
        $failed = array_values(array_filter($rows, fn (array $row) => ($row['status'] ?? '') === 'failed'));
        $finished = array_filter($rows, fn (array $row) => in_array($row['status'] ?? '', self::FINISHED, true));

        if ($failed === [] || count($finished) < count($rows)) {
            return null;
        }

        $labels = implode(', ', array_map(
            fn (array $row) => (string) __('“:label”', ['label' => (string) ($row['label'] ?? '')]),
            $failed,
        ));

        return count($failed) === count($rows)
            ? (string) __('That did not work: :labels.', ['labels' => $labels])
            : (string) __(':done of :total done. Did not work: :labels.', [
                'done' => count($rows) - count($failed),
                'total' => count($rows),
                'labels' => $labels,
            ]);
    }
}
