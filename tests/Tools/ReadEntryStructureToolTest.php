<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Tools;

use BoldWeb\StatamicAiAssistant\Services\EntryStructureSerializer;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use BoldWeb\StatamicAiAssistant\Tools\ReadEntryStructureTool;
use BoldWeb\StatamicAiAssistant\Tools\ToolContext;

class ReadEntryStructureToolTest extends TestCase
{
    private function tool(callable $finder): ReadEntryStructureTool
    {
        return new ReadEntryStructureTool(new EntryStructureSerializer, $finder);
    }

    public function test_requires_entry_id_or_query(): void
    {
        $result = $this->tool(fn () => [])->handle('{}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('entry_id_or_query_required', $result['error']);
    }

    public function test_query_with_no_match_returns_not_found(): void
    {
        $result = $this->tool(fn () => [])->handle('{"query":"nope"}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('entry_not_found', $result['error']);
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
        $this->assertCount(2, $result['matches']);
    }

    public function test_unknown_entry_id_returns_not_found(): void
    {
        $result = $this->tool(fn () => [])->handle('{"entry_id":"does-not-exist"}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('entry_not_found', $result['error']);
    }

    public function test_invalid_json_arguments(): void
    {
        $result = $this->tool(fn () => [])->handle('{not json', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_arguments_json', $result['error']);
    }

    public function test_reports_activity_for_the_feed(): void
    {
        $lines = [];
        $ctx = new ToolContext(activitySink: function (string $l) use (&$lines) {
            $lines[] = $l;
        });

        $this->tool(fn () => [])->handle('{"query":"tea time"}', $ctx);

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('tea time', $lines[0]);
    }

    public function test_definition_exposes_entry_id_and_query(): void
    {
        $def = $this->tool(fn () => [])->definition();

        $this->assertSame('read_entry_structure', $def['function']['name']);
        $props = $def['function']['parameters']['properties'];
        $this->assertArrayHasKey('entry_id', $props);
        $this->assertArrayHasKey('query', $props);
    }
}
