<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

/**
 * A tool call's arguments, read the way every connector tool reads them:
 * missing strings become '' (required) or null (optional), anything that is
 * not an array becomes [], and flags default to false.
 */
final class McpArguments
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(private array $arguments) {}

    public function string(string $key): string
    {
        return (string) ($this->arguments[$key] ?? '');
    }

    public function optional(string $key): ?string
    {
        return isset($this->arguments[$key]) ? (string) $this->arguments[$key] : null;
    }

    /**
     * @return array<mixed>
     */
    public function list(string $key): array
    {
        return is_array($this->arguments[$key] ?? null) ? $this->arguments[$key] : [];
    }

    public function flag(string $key): bool
    {
        return (bool) ($this->arguments[$key] ?? false);
    }

    /**
     * Reads "kind:key", kind being string, optional, list or flag.
     */
    public function read(string $spec): mixed
    {
        [$kind, $key] = explode(':', $spec, 2);

        return match ($kind) {
            'string' => $this->string($key),
            'optional' => $this->optional($key),
            'list' => $this->list($key),
            'flag' => $this->flag($key),
        };
    }
}
