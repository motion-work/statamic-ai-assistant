<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class FindEntriesShortlistTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::default()->handle();

        // The Stache persists across tests in a single process run, so start clean.
        $this->wipeEntries();

        Blueprint::make('package')->setNamespace('collections.packages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ]]]]],
        ])->save();

        Collection::make('packages')->title('Packages')->sites([$this->site])->save();
    }

    private function makeEntry(string $title, string $slug): void
    {
        Entry::make()
            ->collection('packages')
            ->locale($this->site)
            ->slug($slug)
            ->data(['title' => $title])
            ->save();
    }

    private function service(): EntryGeneratorService
    {
        return app(EntryGeneratorService::class);
    }

    /** @return array<int, string> */
    private function titles(?string $collection, string $query, int $limit = 10): array
    {
        return array_column($this->service()->findEntriesShortlist($collection, $query, $limit), 'title');
    }

    /**
     * The bug that started this: an ampersand title could not be found once the
     * user/LLM normalised the "&" away. All of these must resolve to the entry.
     */
    #[DataProvider('ampersandQueries')]
    public function test_ampersand_titles_are_found_regardless_of_how_the_connector_is_written(string $query): void
    {
        $this->makeEntry('Salt & Stone', 'salt-stone');
        $this->makeEntry('After Sunrise', 'after-sunrise'); // decoy

        $this->assertContains('Salt & Stone', $this->titles('packages', $query), "query [{$query}] did not find the entry");
    }

    /** @return array<int, array<int, string>> */
    public static function ampersandQueries(): array
    {
        return [
            'literal ampersand' => ['Salt & Stone'],
            'spelled out and' => ['Salt and Stone'],
            'connector dropped' => ['Salt Stone'],
            'plus sign' => ['Salt + Stone'],
            'lower case' => ['salt stone'],
            'reversed order' => ['Stone Salt'],
            'single token' => ['Stone'],
        ];
    }

    public function test_diacritics_are_folded_for_the_query_side(): void
    {
        $this->makeEntry('Café Menü', 'cafe-menu');

        // Accented stored title, plain-ASCII query.
        $this->assertContains('Café Menü', $this->titles('packages', 'cafe menu'));
    }

    public function test_best_match_is_ranked_first(): void
    {
        $this->makeEntry('Salt & Stone', 'salt-stone');
        $this->makeEntry('Salt & Stone Deluxe Weekend', 'salt-stone-deluxe');

        $titles = $this->titles('packages', 'Salt & Stone');

        // Exact title beats the longer superset title.
        $this->assertSame('Salt & Stone', $titles[0]);
    }

    public function test_requires_all_tokens_so_unrelated_entries_are_excluded(): void
    {
        $this->makeEntry('Salt & Stone', 'salt-stone');
        $this->makeEntry('Day Spa', 'day-spa');

        $titles = $this->titles('packages', 'Salt Stone');

        $this->assertContains('Salt & Stone', $titles);
        $this->assertNotContains('Day Spa', $titles);
    }

    public function test_relaxes_to_any_token_when_no_entry_matches_all(): void
    {
        $this->makeEntry('Salt & Stone', 'salt-stone');

        // "Wellness" matches nothing; without the relax fallback the strict AND
        // would return zero rows and the agent would give up.
        $titles = $this->titles('packages', 'Salt Wellness');

        $this->assertContains('Salt & Stone', $titles);
    }

    public function test_empty_query_returns_recent_entries(): void
    {
        $this->makeEntry('Salt & Stone', 'salt-stone');
        $this->makeEntry('Day Spa', 'day-spa');

        $this->assertCount(2, $this->titles('packages', '   '));
    }

    public function test_find_entries_reports_pagination_and_pages_through_matches(): void
    {
        $this->makeEntry('Spa Day One', 'spa-day-one');
        $this->makeEntry('Spa Day Two', 'spa-day-two');
        $this->makeEntry('Spa Day Three', 'spa-day-three');

        $page1 = $this->service()->findEntries('packages', 'Spa Day', 2, 0);

        $this->assertCount(2, $page1['results']);
        $this->assertSame(3, $page1['pagination']['total']);
        $this->assertTrue($page1['pagination']['has_more']);

        $page2 = $this->service()->findEntries('packages', 'Spa Day', 2, 2);

        $this->assertCount(1, $page2['results']);
        $this->assertFalse($page2['pagination']['has_more']);

        // Paging never returns the same entry twice.
        $ids = array_column(array_merge($page1['results'], $page2['results']), 'id');
        $this->assertCount(3, array_unique($ids));
    }

    public function test_entry_mention_resolves_the_open_entry_by_id(): void
    {
        $this->makeEntry('Geschichte', 'geschichte');
        $id = (string) Entry::query()->where('collection', 'packages')->where('slug', 'geschichte')->first()->id();

        $mention = $this->service()->entryMention($id);

        $this->assertSame($id, $mention['id']);
        $this->assertSame('Geschichte', $mention['title']);
        $this->assertSame('packages', $mention['collection']);
        $this->assertSame('Packages', $mention['collection_title']);
        $this->assertNull($this->service()->entryMention('create'));
        $this->assertNull($this->service()->entryMention('does-not-exist'));
    }

    public function test_result_rows_carry_the_published_state(): void
    {
        $this->makeEntry('Salt & Stone', 'salt-stone');

        $rows = $this->service()->findEntriesShortlist('packages', 'Salt Stone', 5);

        $this->assertArrayHasKey('published', $rows[0]);
        $this->assertIsBool($rows[0]['published']);
    }
}
