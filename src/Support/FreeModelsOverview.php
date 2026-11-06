<?php

namespace MotionWork\StatamicAiAssistant\Support;

use MotionWork\StatamicAiAssistant\Services\Ai\Free\FreeModelPicker;
use MotionWork\StatamicAiAssistant\Usage\OpenRouterKeyBudget;

/**
 * The "Free models" card of the access page: both switches as they apply,
 * whether config locks them, why free mode can't run, the free model each
 * task would use now, and today's free requests.
 */
final class FreeModelsOverview
{
    public function __construct(
        private readonly FreeModelPicker $picker,
        private readonly OpenRouterKeyBudget $budget,
    ) {}

    /**
     * @return array{on: bool, training: bool, locked: bool, trainingLocked: bool, unavailable: ?string, picks: array<string, ?string>, daily: ?array{used: int, limit: int}}
     */
    public function toArray(): array
    {
        $unavailable = FreeModels::unavailableReason();

        return [
            'on' => FreeModels::switchedOn(),
            'training' => FreeModels::trainingAllowed(),
            'locked' => FreeModels::configOverride('enabled') !== null,
            'trainingLocked' => FreeModels::configOverride('allow_training') !== null,
            'unavailable' => $unavailable,
            'picks' => $unavailable === null ? $this->picks() : [],
            'daily' => $unavailable === null ? $this->daily() : null,
        ];
    }

    /** @return array<string, ?string> */
    private function picks(): array
    {
        return rescue(fn () => $this->picker->firstChoices(FreeModels::dataCollection()), [], false);
    }

    /** @return array{used: int, limit: int}|null */
    private function daily(): ?array
    {
        $requests = $this->budget->current()['free_model_daily_requests'] ?? null;

        if (! is_array($requests) || ! isset($requests['limit'])) {
            return null;
        }

        return ['used' => (int) ($requests['used'] ?? 0), 'limit' => (int) $requests['limit']];
    }
}
