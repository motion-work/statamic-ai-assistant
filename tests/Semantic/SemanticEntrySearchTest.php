<?php

namespace MotionWork\StatamicAiAssistant\Tests\Semantic;

use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndex;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndexer;
use MotionWork\StatamicAiAssistant\Semantic\SemanticEntrySearch;
use MotionWork\StatamicAiAssistant\Semantic\SemanticSearch;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\EntrySearch;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class SemanticEntrySearchTest extends TestCase
{
    use FakesEmbeddings;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->wipeEntries();
        $this->useSemanticSearch();

        foreach (['pages', 'faq'] as $handle) {
            Blueprint::make('entry')->setNamespace('collections.'.$handle)->setContents([
                'tabs' => ['main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                    ['handle' => 'body', 'field' => ['type' => 'textarea']],
                ]]]]],
            ])->save();
            Collection::make($handle)->title(ucfirst($handle))->sites([Site::default()->handle()])->save();
        }

        $this->ids['warranty'] = $this->entry('pages', 'Garantie und Reparaturen', 'Wir reparieren defekte Möbelstücke.');
        $this->ids['care'] = $this->entry('pages', 'Pflegehinweis', 'So ölen Sie Ihr Holz richtig.');
        $this->ids['payment'] = $this->entry('faq', 'Zahlung & Lieferung', 'Rechnung oder TWINT.');
    }

    protected function tearDown(): void
    {
        $this->wipeEntries();
        $this->tearDownSemanticSearch();
        parent::tearDown();
    }

    public function test_entries_are_found_by_meaning_when_no_word_matches(): void
    {
        $this->buildIndex();

        $this->assertSame([], (new EntrySearch)->findEntriesShortlist(null, 'Rückgabe', 5), 'Keyword search finds nothing.');
        $this->assertSame($this->ids['warranty'], $this->search()->findEntriesShortlist(null, 'Rückgabe', 5)[0]['id']);

        $content = $this->search()->searchEntryContent(null, 'Bezahlung', 5);
        $this->assertSame($this->ids['payment'], $content[0]['id']);
        $this->assertNotSame('', $content[0]['snippet'], 'A semantic-only content match gets a snippet.');
    }

    public function test_an_exact_title_match_stays_first_and_the_shape_is_unchanged(): void
    {
        $this->buildIndex();

        $result = $this->search()->findEntries(null, 'Pflegehinweis', 10);

        $this->assertSame($this->ids['care'], $result['results'][0]['id']);
        $this->assertSame(['id', 'title', 'slug', 'collection', 'published'], array_keys($result['results'][0]));
        $this->assertSame(['total', 'limit', 'offset', 'has_more'], array_keys($result['pagination']));
    }

    public function test_partial_word_matches_do_not_outrank_a_match_in_meaning(): void
    {
        $this->entry('faq', 'Wie pflege ich den Tisch?', 'Mit Öl.');
        $this->buildIndex();

        $keyword = array_column((new EntrySearch)->findEntriesShortlist(null, 'Wie bezahle ich?', 5), 'title');
        $this->assertSame(['Wie pflege ich den Tisch?'], $keyword, 'Keyword search only matches "wie" and "ich".');

        $this->assertSame($this->ids['payment'], $this->search()->findEntriesShortlist(null, 'Wie bezahle ich?', 5)[0]['id']);
    }

    public function test_the_collection_filter_applies_to_semantic_matches(): void
    {
        $this->buildIndex();

        $this->assertSame([], $this->search()->findEntriesShortlist('faq', 'Rückgabe', 5));
        $this->assertSame($this->ids['payment'], $this->search()->findEntriesShortlist('faq', 'Bezahlung', 5)[0]['id']);
    }

    /**
     * Each reason semantic search cannot run returns the keyword result as is.
     */
    #[DataProvider('fallbacks')]
    public function test_it_falls_back_to_the_keyword_search(string $case): void
    {
        $this->buildIndex();
        $this->{'arrange'.ucfirst($case)}();

        foreach (['Rückgabe', 'Pflegehinweis', 'Garantie'] as $query) {
            $this->assertSame((new EntrySearch)->findEntries(null, $query, 10), $this->search()->findEntries(null, $query, 10), $case.': '.$query);
            $this->assertSame((new EntrySearch)->searchEntryContent(null, $query, 10), $this->search()->searchEntryContent(null, $query, 10), $case.': '.$query);
        }
    }

    /** @return array<string, array{string}> */
    public static function fallbacks(): array
    {
        return array_combine(
            ['otherProvider', 'noKey', 'disabled', 'notBuilt', 'otherModel', 'tooMany', 'requestFails', 'budgetUsedUp', 'unreadableIndex'],
            array_map(fn ($c) => [$c], ['otherProvider', 'noKey', 'disabled', 'notBuilt', 'otherModel', 'tooMany', 'requestFails', 'budgetUsedUp', 'unreadableIndex']),
        );
    }

    public function test_a_failed_embedding_request_is_logged_once_and_never_thrown(): void
    {
        $this->buildIndex();
        $this->fakeEmbeddings(500);
        Log::spy();

        $this->search()->findEntries(null, 'Rückgabe', 10);
        $this->search()->findEntries(null, 'Holzpflege', 10);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Semantic search fell back'))->once();
    }

    private function arrangeOtherProvider(): void
    {
        config(['statamic-ai-assistant.provider_name' => 'infomaniak', 'statamic-ai-assistant.infomaniak_api_token' => 'token']);
        $this->freshServices();
    }

    private function arrangeNoKey(): void
    {
        config(['statamic-ai-assistant.openrouter_api_key' => '', 'statamic-ai-assistant.provider_name' => 'openrouter']);
        $this->freshServices();
    }

    private function arrangeDisabled(): void
    {
        config(['statamic-ai-assistant.semantic_search.enabled' => false]);
    }

    private function arrangeNotBuilt(): void
    {
        (new EmbeddingIndex(Site::default()->handle()))->clear();
    }

    private function arrangeOtherModel(): void
    {
        config(['statamic-ai-assistant.openrouter_profiles.embedding.models' => 'other/embedding-model']);
    }

    private function arrangeTooMany(): void
    {
        config(['statamic-ai-assistant.semantic_search.max_entries' => 2]);
    }

    private function arrangeRequestFails(): void
    {
        $this->fakeEmbeddings(500);
    }

    private function arrangeBudgetUsedUp(): void
    {
        $this->fakeEmbeddings(402);
    }

    private function arrangeUnreadableIndex(): void
    {
        file_put_contents($this->indexPath.'/'.Site::default()->handle().'/vectors.bin', 'broken');
    }

    private function freshServices(): void
    {
        $this->app->forgetInstance(AbstractAiService::class);
        $this->app->forgetInstance(SemanticSearch::class);
    }

    private function search(): SemanticEntrySearch
    {
        return new SemanticEntrySearch(app(SemanticSearch::class));
    }

    private function buildIndex(): void
    {
        app(EmbeddingIndexer::class)->buildSite(Site::default()->handle());
    }

    private function entry(string $collection, string $title, string $body): string
    {
        $entry = Entry::make()->collection($collection)->locale(Site::default()->handle())->slug(str($title)->slug())->data(['title' => $title, 'body' => $body]);
        $entry->save();

        return (string) $entry->id();
    }
}
