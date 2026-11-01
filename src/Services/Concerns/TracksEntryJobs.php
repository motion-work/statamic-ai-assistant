<?php

namespace MotionWork\StatamicAiAssistant\Services\Concerns;

use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;

/**
 * The entry jobs' side of an AI agent session: each entry's status, streamed
 * output and result, the counts, and the preferred imagery they consume.
 * Mutates the session only through update().
 */
trait TracksEntryJobs
{
    /**
     * @return array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts: bool}
     */
    public function buildPrefetchedUrlAugmentation(string $sessionId): array
    {
        $session = $this->getSession($sessionId);
        if (! is_array($session)) {
            return [
                'appendix' => '',
                'warnings' => [],
                'preferred' => new PreferredAssetPaths,
                'appended_to_prompts' => false,
            ];
        }

        $ua = $session['url_augmentation'] ?? [];
        $paths = is_array($ua['preferred_paths'] ?? null) ? $ua['preferred_paths'] : [];

        return [
            'appendix' => (string) ($ua['appendix'] ?? ''),
            'warnings' => array_values(array_filter($ua['warnings'] ?? [], 'is_string')),
            'preferred' => new PreferredAssetPaths($paths),
            'appended_to_prompts' => (bool) ($ua['appended_to_prompts'] ?? false),
        ];
    }

    /**
     * Persist remaining preferred paths after a job mutates the shared queue.
     *
     * @param  array<int, array{container: string, path: string}>  $preferredPaths
     */
    public function setPreferredPaths(string $sessionId, array $preferredPaths): void
    {
        $this->update($sessionId, function (array $session) use ($preferredPaths): array {
            if (! isset($session['url_augmentation']) || ! is_array($session['url_augmentation'])) {
                $session['url_augmentation'] = [
                    'appendix' => '',
                    'warnings' => [],
                    'preferred_paths' => [],
                    'appended_to_prompts' => false,
                ];
            }
            $session['url_augmentation']['preferred_paths'] = array_values($preferredPaths);

            return $session;
        });
    }

    public function markEntryGenerating(string $sessionId, string $entryId): void
    {
        $this->update($sessionId, function (array $session) use ($entryId): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $session['entries'][$entryId]['status'] = 'generating';
            $session['entries'][$entryId]['stream_buffer'] = '';
            $session['entries'][$entryId]['started_at'] = $session['entries'][$entryId]['started_at']
                ?? now()->toIso8601String();
            $session['counts'] = $this->recount($session);

            return $session;
        });
    }

    /**
     * Accumulate stream deltas for CP polling (token + field-key UX). Buffer is
     * flushed into stream_delta on each progress snapshot.
     */
    public function recordStreamDelta(string $sessionId, string $entryId, string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->update($sessionId, function (array $session) use ($entryId, $delta): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $row = &$session['entries'][$entryId];
            $row['token_length'] = (int) ($row['token_length'] ?? 0) + strlen($delta);
            $buf = (string) ($row['stream_buffer'] ?? '');
            $buf .= $delta;
            if (strlen($buf) > 12000) {
                $buf = substr($buf, -12000);
            }
            $row['stream_buffer'] = $buf;

            return $session;
        });
    }

    /**
     * The localization was saved on the target site. The card must not offer
     * "Save as draft", which would create a new entry on the current site.
     *
     * @param  array{entry_id: string, edit_url: ?string, title: string, warnings?: array<int, string>}  $saved
     */
    public function markEntrySaved(string $sessionId, string $entryId, array $saved): void
    {
        $this->update($sessionId, function (array $session) use ($entryId, $saved): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $session['entries'][$entryId]['status'] = 'saved';
            $session['entries'][$entryId]['saved_entry'] = [
                'entry_id' => (string) ($saved['entry_id'] ?? ''),
                'edit_url' => isset($saved['edit_url']) && is_string($saved['edit_url']) ? $saved['edit_url'] : null,
                'title' => (string) ($saved['title'] ?? ''),
            ];
            $session['entries'][$entryId]['warnings'] = is_array($saved['warnings'] ?? null) ? array_values($saved['warnings']) : [];
            $session['entries'][$entryId]['error'] = null;
            $session['entries'][$entryId]['completed_at'] = now()->toIso8601String();
            $session['entries'][$entryId]['stream_buffer'] = '';
            $session['counts'] = $this->recount($session);

            return $session;
        });

        $this->markCompletedIfDone($sessionId);
    }

    /**
     * @param  array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: array<int, string>}  $result
     */
    public function markEntrySuccess(string $sessionId, string $entryId, array $result): void
    {
        $this->update($sessionId, function (array $session) use ($entryId, $result): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $session['entries'][$entryId]['status'] = 'ready';
            $session['entries'][$entryId]['data'] = $result['data'];
            $session['entries'][$entryId]['displayData'] = $result['displayData'];
            $session['entries'][$entryId]['warnings'] = $result['warnings'];
            $session['entries'][$entryId]['error'] = null;
            $session['entries'][$entryId]['completed_at'] = now()->toIso8601String();
            $session['entries'][$entryId]['stream_buffer'] = '';
            $session['counts'] = $this->recount($session);

            return $session;
        });

        $this->markCompletedIfDone($sessionId);
    }

    public function markEntryFailed(string $sessionId, string $entryId, string $message): void
    {
        $this->update($sessionId, function (array $session) use ($entryId, $message): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $session['entries'][$entryId]['status'] = 'failed';
            $session['entries'][$entryId]['error'] = $message;
            $session['entries'][$entryId]['completed_at'] = now()->toIso8601String();
            $session['entries'][$entryId]['stream_buffer'] = '';
            $session['counts'] = $this->recount($session);

            return $session;
        });

        $this->markCompletedIfDone($sessionId);
    }

    public function markCompletedIfDone(string $sessionId): void
    {
        $this->update($sessionId, function (array $session): array {
            if (($session['status'] ?? '') !== 'running') {
                return $session;
            }
            $planningStatus = (string) ($session['planning_status'] ?? 'planning');
            if ($planningStatus === 'planning') {
                // Planner still running — never mark complete even if no entries are pending yet.
                return $session;
            }
            $counts = $this->recount($session);
            $session['counts'] = $counts;
            if ($counts['pending'] === 0 && $counts['running'] === 0) {
                $session['status'] = 'completed';
            }

            return $session;
        });
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{pending: int, running: int, completed: int, failed: int}
     */
    private function recount(array $session): array
    {
        $counts = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($session['entries'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $st = (string) ($row['status'] ?? 'pending');
            match ($st) {
                'pending' => $counts['pending']++,
                'generating' => $counts['running']++,
                'ready', 'saved' => $counts['completed']++,
                'failed' => $counts['failed']++,
                default => $counts['pending']++,
            };
        }

        return $counts;
    }
}
