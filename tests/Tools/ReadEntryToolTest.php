<?php

namespace MotionWork\StatamicAiAssistant\Tests\Tools;

use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\ReadEntryTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class ReadEntryToolTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::default()->handle();

        // The Stache persists across tests in a single process run, so start clean.
        $this->wipeEntries();

        // Reuse the fixture 'packages' collection (like FindEntriesShortlistTest)
        // so test runs never leave new collection files in tests/__fixtures__.
        Blueprint::make('package')->setNamespace('collections.packages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'intro', 'field' => ['type' => 'textarea']],
            ]]]]],
        ])->save();

        Collection::make('packages')->title('Packages')->sites([$this->site])->save();
    }

    private function tool(?callable $finder = null): ReadEntryTool
    {
        return new ReadEntryTool($finder ?? fn () => []);
    }

    private function makeEntry(array $data): string
    {
        $entry = Entry::make()
            ->collection('packages')
            ->locale($this->site)
            ->slug('about')
            ->data($data);
        $entry->save();

        return (string) $entry->id();
    }

    public function test_requires_entry_id_or_query(): void
    {
        $result = $this->tool()->handle('{}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('entry_id_or_query_required', $result['error']);
    }

    public function test_query_with_multiple_matches_asks_to_disambiguate(): void
    {
        $finder = fn () => [
            ['id' => '1', 'title' => 'A', 'slug' => 'a', 'collection' => 'pages'],
            ['id' => '2', 'title' => 'B', 'slug' => 'b', 'collection' => 'pages'],
        ];

        $result = $this->tool($finder)->handle('{"query":"a"}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('multiple_matches', $result['error']);
    }

    public function test_unknown_entry_id_returns_not_found(): void
    {
        $result = $this->tool()->handle('{"entry_id":"does-not-exist"}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('entry_not_found', $result['error']);
    }

    public function test_returns_full_field_values_and_meta(): void
    {
        $id = $this->makeEntry(['title' => 'About us', 'intro' => 'We build things.']);

        $result = $this->tool()->handle(json_encode(['entry_id' => $id]), new ToolContext);

        $this->assertTrue($result['ok']);
        $this->assertSame('About us', $result['title']);
        $this->assertSame('packages', $result['collection']);
        $this->assertSame('about', $result['slug']);
        $this->assertIsBool($result['published']);
        $this->assertSame('We build things.', $result['data']['intro']);
    }

    public function test_fields_argument_filters_data_and_reports_missing_handles(): void
    {
        $id = $this->makeEntry(['title' => 'About us', 'intro' => 'We build things.']);

        $result = $this->tool()->handle(json_encode(['entry_id' => $id, 'fields' => ['intro', 'nope']]), new ToolContext);

        $this->assertTrue($result['ok']);
        $this->assertSame(['intro' => 'We build things.'], $result['data']);
        $this->assertSame(['nope'], $result['missing_fields']);
    }

    public function test_oversized_entry_returns_field_index_instead_of_data(): void
    {
        $id = $this->makeEntry(['title' => 'Big', 'intro' => str_repeat('x', 70_000)]);

        $result = $this->tool()->handle(json_encode(['entry_id' => $id]), new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('entry_too_large', $result['error']);
        $this->assertArrayHasKey('intro', $result['available_fields']);
        $this->assertGreaterThan(60_000, $result['available_fields']['intro']);
        $this->assertArrayNotHasKey('data', $result);

        // The escape hatch it advertises must actually work.
        $narrow = $this->tool()->handle(json_encode(['entry_id' => $id, 'fields' => ['title']]), new ToolContext);
        $this->assertTrue($narrow['ok']);
        $this->assertSame(['title' => 'Big'], $narrow['data']);
    }

    public function test_definition_exposes_entry_id_query_and_fields(): void
    {
        $def = $this->tool()->definition();

        $this->assertSame('read_entry', $def['function']['name']);
        $props = $def['function']['parameters']['properties'];
        $this->assertArrayHasKey('entry_id', $props);
        $this->assertArrayHasKey('query', $props);
        $this->assertArrayHasKey('fields', $props);
    }
}
