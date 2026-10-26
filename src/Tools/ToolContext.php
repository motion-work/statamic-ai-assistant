<?php

namespace BoldWeb\StatamicAiAssistant\Tools;

use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;

/**
 * Per-run side-channels a ChatTool may need. Bundling them here means tools with
 * very different needs (a URL fetcher, an image saver, an entry reader) all share
 * one uniform `handle(argsJson, ToolContext)` signature — the runner never has to
 * special-case a tool's collaborators.
 *
 * Callables are optional; a tool simply ignores what it does not use.
 */
class ToolContext
{
    /**
     * @param  (callable(string): void)|null  $warningSink  Receives user-facing warnings
     * @param  (callable(string): void)|null  $onStreamToken  CP drawer streaming notifier
     * @param  (callable(): void)|null  $heartbeat  Keeps long streams alive between tool calls
     * @param  PreferredAssetPaths|null  $imageSink  Queue the image tool appends saved assets to
     * @param  string|null  $imageContainerHint  Asset container the entry's image fields point at
     * @param  (callable(string): void)|null  $activitySink  Receives short human-readable progress lines ("Reading …")
     */
    public function __construct(
        private $warningSink = null,
        private $onStreamToken = null,
        private $heartbeat = null,
        public readonly ?PreferredAssetPaths $imageSink = null,
        public readonly ?string $imageContainerHint = null,
        private $activitySink = null,
    ) {}

    public function addWarning(string $message): void
    {
        if ($this->warningSink !== null) {
            ($this->warningSink)($message);
        }
    }

    /**
     * Report a short, human-readable progress step (e.g. "Reading example.com/events")
     * for the CP activity feed. No-op when no sink is wired.
     */
    public function reportActivity(string $line): void
    {
        if ($this->activitySink !== null && trim($line) !== '') {
            ($this->activitySink)($line);
        }
    }

    public function onStreamToken(): ?callable
    {
        return $this->onStreamToken;
    }

    public function heartbeat(): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }
}
