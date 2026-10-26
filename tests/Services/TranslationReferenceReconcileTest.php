<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\CpTranslationBatchRunner;
use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Services\EntryReferenceResolver;
use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Bulk-translation reference handling: dependency-aware ordering, the
 * remap-only reconcile pass, and unresolved-reference bookkeeping.
 */
class TranslationReferenceReconcileTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::default()->handle();

        $this->wipeEntries();

        Blueprint::make('package')->setNamespace('collections.packages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'related', 'field' => ['type' => 'entries', 'localizable' => true]],
            ]]]]],
        ])->save();

        Collection::make('packages')->title('Packages')->sites([$this->site])->save();
    }

    private function makeEntry(string $title, array $data = []): string
    {
        $entry = Entry::make()
            ->collection('packages')
            ->locale($this->site)
            ->slug(\Illuminate\Support\Str::slug($title))
            ->data(array_merge(['title' => $title], $data));
        $entry->save();

        return (string) $entry->id();
    }

    private function translator(): EntryTranslator
    {
        return new EntryTranslator($this->createStub(DeeplService::class), new EntryReferenceResolver);
    }

    // ------------------------------------------------------------------
    //  Dependency-aware ordering
    // ------------------------------------------------------------------

    public function test_referenced_entries_are_ordered_before_referencing_ones(): void
    {
        $b = $this->makeEntry('Linked B');
        $c = $this->makeEntry('Linked C');
        $a = $this->makeEntry('Page A', ['related' => [$b, $c]]);

        $runner = new class($this->createStub(TranslationService::class)) extends CpTranslationBatchRunner
        {
            public function order($entries)
            {
                return $this->orderByReferences($entries);
            }
        };

        // Selection order puts the referencing page first — ordering must flip it.
        $ordered = $runner->order(collect([Entry::find($a), Entry::find($b), Entry::find($c)]));
        $ids = $ordered->map(fn ($e) => (string) $e->id())->all();

        $this->assertSame($a, end($ids), 'referencing entry must come last');
        $this->assertEqualsCanonicalizing([$a, $b, $c], $ids);
    }

    public function test_reference_cycles_fall_back_to_selection_order(): void
    {
        $a = $this->makeEntry('Cycle A');
        $b = $this->makeEntry('Cycle B');
        Entry::find($a)->set('related', [$b])->save();
        Entry::find($b)->set('related', [$a])->save();

        $runner = new class($this->createStub(TranslationService::class)) extends CpTranslationBatchRunner
        {
            public function order($entries)
            {
                return $this->orderByReferences($entries);
            }
        };

        $ordered = $runner->order(collect([Entry::find($a), Entry::find($b)]));

        $this->assertSame([$a, $b], $ordered->map(fn ($e) => (string) $e->id())->all());
    }

    // ------------------------------------------------------------------
    //  Remap-only reconcile pass
    // ------------------------------------------------------------------

    public function test_remap_swaps_reference_when_localization_exists(): void
    {
        $linked = $this->makeEntry('Linked');
        $page = $this->makeEntry('Page', ['related' => [$linked]]);

        // Simulate the linked entry's localization: an entry whose origin points
        // at the linked entry (that is how the resolver finds localizations).
        $localized = Entry::make()
            ->collection('packages')
            ->locale($this->site)
            ->slug('linked-localized')
            ->origin($linked)
            ->data(['title' => 'Linked (localized)']);
        $localized->save();

        $changed = $this->translator()->remapEntryReferences(Entry::find($page));

        $this->assertTrue($changed);
        $this->assertSame([(string) $localized->id()], Entry::find($page)->get('related'));
    }

    public function test_remap_leaves_untranslated_references_and_records_them(): void
    {
        $linked = $this->makeEntry('Linked');
        $page = $this->makeEntry('Page', ['related' => [$linked]]);

        $translator = $this->translator();
        $changed = $translator->remapEntryReferences(Entry::find($page));

        $this->assertFalse($changed);
        $this->assertSame([$linked], Entry::find($page)->get('related'));
        // The unresolved id is reported so editors can be warned.
        $this->assertSame([$linked], $translator->takeUnresolvedReferences());
        // Drained after taking.
        $this->assertSame([], $translator->takeUnresolvedReferences());
    }

    public function test_remap_only_mode_never_creates_entries(): void
    {
        $linked = $this->makeEntry('Linked');
        $page = $this->makeEntry('Page', ['related' => [$linked]]);
        $before = Entry::all()->count();

        $this->translator()->remapEntryReferences(Entry::find($page));

        $this->assertSame($before, Entry::all()->count());
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    public function test_navigation_sync_falls_back_to_inline_mode_without_a_queue(): void
    {
        // Test env runs the sync queue driver → the runner must take the inline
        // path and pass the sync result through with mode: sync.
        config(['queue.default' => 'sync']);

        $syncService = $this->createStub(\MotionWork\StatamicAiAssistant\Services\NavigationTreeSyncService::class);
        $syncService->method('sync')->willReturn(['results' => [['locale' => 'fr', 'success' => true, 'pages' => []]]]);

        $runner = new CpTranslationBatchRunner($this->createStub(TranslationService::class));
        $result = $runner->runNavigationSync($syncService, 'main', ['fr'], false, 1);

        $this->assertSame('sync', $result['mode']);
        $this->assertSame('fr', $result['results'][0]['locale']);
    }

    public function test_linked_entry_ids_are_collected_from_batch_rows(): void
    {
        $rows = [
            ['linked_entries' => [['entry_id' => 'id-1'], ['entry_id' => 'id-2']]],
            ['linked_entries' => [['entry_id' => 'id-2']]],
            ['linked_entries' => []],
            [],
        ];

        $runner = new class($this->createStub(TranslationService::class)) extends CpTranslationBatchRunner
        {
            public static function ids(array $rows): array
            {
                return self::linkedEntryIdsFromResults($rows);
            }
        };

        $this->assertSame(['id-1', 'id-2'], $runner::ids($rows));
    }
}
