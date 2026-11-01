<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Support\JsonObjectExtractor;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;

/**
 * Where generated entries can go: the collections and blueprints the agent
 * may use, and the one a prompt asks for when the editor did not choose.
 */
final class GenerationTargets
{
    public function __construct(
        private AbstractAiService $aiService,
        private PromptUrlFetcher $promptUrlFetcher,
        private ?FigmaContentFetcher $figma,
        private EntryJsonParser $parser,
    ) {}

    /**
     * Collections visible for entry generation (non-hidden blueprints only).
     *
     * When $entriesPerCollection > 0, each row also carries `count` (total entries
     * in the active site) and `entries` (recent shortlist: id/title/slug). This
     * extra payload is only used by the agentic planner so the LLM can reason
     * about updating existing entries without an extra tool round-trip.
     *
     * @return array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>, count?: int, entries?: array<int, array{id: string, title: string, slug: string}>}>
     */
    public function getCollectionsCatalog(int $entriesPerCollection = 0): array
    {
        $siteHandle = Site::selected()?->handle() ?? Site::default()->handle();

        return Collection::all()
            ->map(function ($collection) use ($entriesPerCollection, $siteHandle) {
                $blueprints = $collection->entryBlueprints()
                    ->reject->hidden()
                    ->values()
                    ->map(function ($bp) {
                        return [
                            'handle' => $bp->handle(),
                            'title' => $bp->title(),
                        ];
                    });

                $row = [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'blueprints' => $blueprints->values()->all(),
                ];

                if ($entriesPerCollection > 0) {
                    $row['count'] = (int) Entry::query()
                        ->where('collection', $collection->handle())
                        ->where('site', $siteHandle)
                        ->count();

                    $row['entries'] = Entry::query()
                        ->where('collection', $collection->handle())
                        ->where('site', $siteHandle)
                        ->orderBy('updated_at', 'desc')
                        ->limit($entriesPerCollection)
                        ->get()
                        ->map(fn ($e) => [
                            'id' => (string) $e->id(),
                            'title' => (string) ($e->value('title') ?? ''),
                            'slug' => (string) ($e->slug() ?? ''),
                        ])
                        ->values()
                        ->all();
                }

                return $row;
            })
            ->filter(fn ($row) => $row['blueprints'] !== [])
            ->values()
            ->all();
    }

    /**
     * Ask the LLM which collection and blueprint best match the user request.
     * Falls back to the "pages" collection when unsure; if missing, the first catalog entry.
     *
     * @param  array{appendix: string, warnings: array<int, string>}|null  $prefetchedUrlAug  When null, URLs are fetched once from $prompt.
     * @param  array{appendix: string, warnings: array<int, string>}|null  $prefetchedFigmaAug  When null, Figma links are resolved when a fetcher is available.
     * @return array{collection: string, blueprint: string}
     */
    public function resolveTargetFromPrompt(string $prompt, ?string $attachmentContent = null, ?array $prefetchedUrlAug = null, ?array $prefetchedFigmaAug = null): array
    {
        $catalog = $this->getCollectionsCatalog();

        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $urlAug = $prefetchedUrlAug ?? $this->promptUrlFetcher->buildAugmentation($prompt);
        $figmaAug = $prefetchedFigmaAug ?? ($this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []]);

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $attachmentPart = $attachmentContent
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachmentContent, 6000)
            : '';

        $system = 'You are a Statamic CMS assistant. Given the user\'s request and the list of collections with their entry blueprints, choose the single best collection and blueprint for a new entry.'
            .' Return ONLY a JSON object with exactly two string keys: "collection" (the collection handle) and "blueprint" (the blueprint handle). The handles must match the catalog exactly (case-sensitive).'
            .' Prefer the most specific collection that fits the topic. If you are unsure, several fit equally, or none clearly apply, use the collection with handle "pages" if it exists in the catalog; otherwise use the first collection in the catalog.'
            .' The blueprint must be one of the blueprints listed for that collection.'
            .' Do not include markdown fences or any text outside the JSON.';

        $user = "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n{$prompt}{$urlAug['appendix']}{$figmaAug['appendix']}{$attachmentPart}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        // Lightweight classification (pick collection + blueprint) — use the fast model tier.
        $raw = $this->aiService->usingFastModel(fn () => $this->aiService->generateFromMessages($messages, 256));

        if ($raw === null || trim($raw) === '') {
            return $this->fallbackTargetSelection($catalog);
        }

        try {
            $parsed = $this->parseTargetSelectionResponse($raw);
            $collectionHandle = isset($parsed['collection']) && is_string($parsed['collection']) ? trim($parsed['collection']) : '';
            $blueprintHandle = isset($parsed['blueprint']) && is_string($parsed['blueprint']) ? trim($parsed['blueprint']) : '';
        } catch (\RuntimeException) {
            return $this->fallbackTargetSelection($catalog);
        }

        if ($this->validateTargetSelection($catalog, $collectionHandle, $blueprintHandle)) {
            return ['collection' => $collectionHandle, 'blueprint' => $blueprintHandle];
        }

        return $this->fallbackTargetSelection($catalog);
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{collection: string, blueprint: string}
     */
    private function fallbackTargetSelection(array $catalog): array
    {
        foreach ($catalog as $row) {
            if (($row['handle'] ?? '') === 'pages' && ($row['blueprints'][0]['handle'] ?? '') !== '') {
                return [
                    'collection' => 'pages',
                    'blueprint' => $row['blueprints'][0]['handle'],
                ];
            }
        }

        $first = $catalog[0];

        return [
            'collection' => $first['handle'],
            'blueprint' => $first['blueprints'][0]['handle'],
        ];
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     */
    private function validateTargetSelection(array $catalog, string $collectionHandle, string $blueprintHandle): bool
    {
        if ($collectionHandle === '' || $blueprintHandle === '') {
            return false;
        }

        foreach ($catalog as $row) {
            if ($row['handle'] !== $collectionHandle) {
                continue;
            }

            foreach ($row['blueprints'] as $bp) {
                if (($bp['handle'] ?? '') === $blueprintHandle) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTargetSelectionResponse(string $rawResponse): array
    {
        $response = trim($rawResponse);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $jsonStr = JsonObjectExtractor::firstObject($response);

        if ($jsonStr === null) {
            $firstBrace = strpos($response, '{');
            $lastBrace = strrpos($response, '}');

            if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
                $this->parser->logUnparseableResponse('target_selection', $rawResponse);
                throw new \RuntimeException(__('Could not parse AI response as JSON. Please try again.'));
            }

            $jsonStr = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        try {
            $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->parser->logUnparseableResponse('target_selection', $rawResponse, $e->getMessage());
            throw new \RuntimeException(__('Invalid JSON in AI response: :message', ['message' => $e->getMessage()]), $e->getCode(), $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->parser->logUnparseableResponse('target_selection', $rawResponse, 'response was not a JSON object');
            throw new \RuntimeException(__('AI response must be a JSON object, not an array.'));
        }

        return $decoded;
    }
}
