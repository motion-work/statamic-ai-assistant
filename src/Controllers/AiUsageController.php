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
    public const DEFAULT_PER_PAGE = 25;

    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function data(Request $request, UsageLog $log, UsageReport $report, OpenRouterKeyBudget $budget): JsonResponse
    {
        $current = now()->format('Y-m');
        $month = (string) $request->query('month', $current);
        if (! UsageLog::isMonth($month)) {
            $month = $current;
        }

        $summary = $report->forMonth($month);
        $calls = $this->page($summary['recent'] ?? [], $request);
        unset($summary['recent']);
        $summary['recent'] = $calls['rows'];

        return response()->json([
            'month' => $month,
            'months' => $this->months($log, $current),
            'provider' => AiProviders::active(),
            'tracking' => (bool) config('statamic-ai-assistant.usage_tracking', true),
            'retention_months' => (int) config('statamic-ai-assistant.usage_retention_months', 12),
            'summary' => $summary,
            'pagination' => $calls['pagination'],
            'users' => $this->userLabels($summary),
            'budget' => $this->budget($budget),
        ]);
    }

    /**
     * One page of the call list, newest first.
     *
     * @param  list<array<string, mixed>>  $calls
     * @return array{rows: list<array<string, mixed>>, pagination: array<string, int>}
     */
    private function page(array $calls, Request $request): array
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $total = count($calls);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        return [
            'rows' => array_slice($calls, ($page - 1) * $perPage, $perPage),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        ];
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
