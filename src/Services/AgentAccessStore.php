<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\YAML;

/**
 * Persists the AI agent access configuration — which roles / individual users
 * may use each gated capability, plus the per-role / per-user entry-generation
 * limits for the agent. Super admins are never stored here; they always pass.
 *
 * Storage mirrors SetHintsService / TranslationGlossaryService: a versionable
 * YAML file under content/, path overridable via config `access_path`.
 *
 * Shape:
 *   agent:
 *     roles: [editor]
 *     users: [user-id]
 *     limits: { default: 1, roles: { editor: 3 }, users: { user-id: 10 } }
 *   bulk_translations: { roles: [...], users: [...] }
 *   agent_settings:    { roles: [...], users: [...] }
 *   claude_restricted: true       # off (default): the connector follows the CP role alone
 *   claude_read:    { roles: [...], users: [...] }
 *   claude_edit:    { roles: [...], users: [...] }
 *   claude_publish: { roles: [...], users: [...] }
 */
class AgentAccessStore
{
    /** Gated capabilities. `agent` additionally carries entry-generation limits. */
    public const FEATURES = ['agent', 'bulk_translations', 'agent_settings', 'advanced_tools', 'claude_read', 'claude_edit', 'claude_publish'];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * Absolute path to the YAML file storing the access config.
     */
    public function storagePath(): string
    {
        $path = config('statamic-ai-assistant.access_path');

        if (! is_string($path) || $path === '') {
            return base_path('content/statamic-ai-assistant/access.yaml');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * The full, normalised access document.
     *
     * @return array<string, array{roles: array<int, string>, users: array<int, string>, limits?: array<string, mixed>}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = $this->normalize($this->readFile());
    }

    /**
     * Allow-list for one feature. Unknown/empty features return empty lists
     * (default-deny — only super admins pass).
     *
     * @return array{roles: array<int, string>, users: array<int, string>}
     */
    public function feature(string $feature): array
    {
        $row = $this->all()[$feature] ?? [];

        return [
            'roles' => $row['roles'] ?? [],
            'users' => $row['users'] ?? [],
        ];
    }

    /**
     * Entry-generation limits for the agent (per-role / per-user / default).
     *
     * @return array{default: int, roles: array<string, int>, users: array<string, int>}
     */
    public function agentLimits(): array
    {
        $limits = $this->all()['agent']['limits'] ?? [];

        return [
            'default' => max(1, (int) ($limits['default'] ?? 1)),
            'roles' => $this->intMap($limits['roles'] ?? []),
            'users' => $this->intMap($limits['users'] ?? []),
        ];
    }

    /**
     * Whether the Claude connector grants apply. Off until a super admin
     * turns it on, so existing connector users keep their access.
     */
    public function claudeRestricted(): bool
    {
        return (bool) ($this->all()['claude_restricted'] ?? false);
    }

    /**
     * Persist the full access document (replaces the file).
     *
     * @param  array<string, mixed>  $grants
     */
    public function save(array $grants): void
    {
        $document = $this->normalize($grants);

        $path = $this->storagePath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents($path, YAML::dump($document));

        $this->cache = $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(): array
    {
        $path = $this->storagePath();

        if (! is_file($path)) {
            return [];
        }

        try {
            $raw = (string) file_get_contents($path);
            $parsed = $raw !== '' ? YAML::parse($raw) : [];
        } catch (\Throwable $e) {
            Log::warning('Failed to parse access.yaml', ['error' => $e->getMessage()]);

            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Coerce arbitrary input into the canonical shape: every feature present,
     * roles/users as clean string lists, agent limits as positive ints.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $out = [];

        foreach (self::FEATURES as $feature) {
            $row = is_array($input[$feature] ?? null) ? $input[$feature] : [];

            $normalized = [
                'roles' => $this->stringList($row['roles'] ?? []),
                'users' => $this->stringList($row['users'] ?? []),
            ];

            if ($feature === 'agent') {
                $limits = is_array($row['limits'] ?? null) ? $row['limits'] : [];
                $normalized['limits'] = [
                    'default' => max(1, (int) ($limits['default'] ?? 1)),
                    'roles' => $this->intMap($limits['roles'] ?? []),
                    'users' => $this->intMap($limits['users'] ?? []),
                ];
            }

            $out[$feature] = $normalized;
        }

        $out['claude_restricted'] = filter_var($input['claude_restricted'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[trim($item)] = trim($item);
            }
        }

        return array_values($clean);
    }

    /**
     * @param  mixed  $value
     * @return array<string, int>
     */
    private function intMap($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $limit) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }
            $n = (int) $limit;
            if ($n > 0) {
                $out[trim($key)] = $n;
            }
        }

        return $out;
    }
}
