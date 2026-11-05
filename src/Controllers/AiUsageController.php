<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;
use MotionWork\StatamicAiAssistant\Usage\OpenRouterKeyBudget;
use MotionWork\StatamicAiAssistant\Usage\UsageLog;
use MotionWork\StatamicAiAssistant\Usage\UsageReport;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Data for the "AI usage" CP page (super admins only, see routes/cp.php).
 */
class AiUsageController extends CpController
{
    public function data(Request $request, UsageLog $log, UsageReport $report, OpenRouterKeyBudget $budget): JsonResponse
    {
        $current = now()->format('Y-m');
        $month = (string) $request->query('month', $current);
        if (! UsageLog::isMonth($month)) {
            $month = $current;
        }

        $summary = $report->forMonth($month);

        return response()->json([
            'month' => $month,
            'months' => $this->months($log, $current),
            'provider' => AiProviders::active(),
            'tracking' => (bool) config('statamic-ai-assistant.usage_tracking', true),
            'retention_months' => (int) config('statamic-ai-assistant.usage_retention_months', 12),
            'summary' => $summary,
            'users' => $this->userLabels($summary),
            'budget' => $this->budget($budget),
        ]);
    }

    /**
     * The OpenRouter key's limit, or null when the site does not use OpenRouter.
     *
     * @return array{available: bool, data: array<string, mixed>|null}|null
     */
    private function budget(OpenRouterKeyBudget $budget): ?array
    {
        if (! $budget->applies()) {
            return null;
        }

        $data = $budget->current();

        return ['available' => $data !== null, 'data' => $data];
    }

    /** @return list<string> Months with a log, plus the current one, newest first. */
    private function months(UsageLog $log, string $current): array
    {
        $months = array_values(array_unique([$current, ...$log->months()]));
        rsort($months);

        return $months;
    }

    /**
     * Display names for the user ids in the report. A deleted user keeps its id.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, string>
     */
    private function userLabels(array $summary): array
    {
        $ids = array_column($summary['by_user'] ?? [], 'key');
        foreach ($summary['recent'] ?? [] as $record) {
            $ids[] = (string) ($record['user_id'] ?? '');
        }

        $labels = [];
        foreach (array_unique(array_filter($ids)) as $id) {
            $user = User::find($id);
            $labels[$id] = $user ? (string) ($user->name() ?: $user->email()) : $id;
        }

        return $labels;
    }
}
