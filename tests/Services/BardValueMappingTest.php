<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Fields\Field;

/**
 * In UPDATE mode the current-values snapshot shows Bard fields as raw ProseMirror
 * JSON, so mid-tier models often return the new Bard value as an array of nodes
 * rather than an HTML string. The mapper must accept that instead of silently
 * dropping the field (which surfaced as "Update applied" with no visible change).
 */
class BardValueMappingTest extends TestCase
{
    private EntryGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EntryGeneratorService::class);
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function invoke(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(EntryGeneratorService::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->service, $args);
    }

    private function bardField(): Field
    {
        return new Field('text', ['type' => 'bard', 'display' => 'Text']);
    }

    public function test_bard_html_string_is_still_mapped(): void
    {
        $field = $this->bardField();
        $warnings = [];
        $args = ['<p>Hallo Welt</p>', $field, &$warnings, 'default'];

        $mapped = $this->invoke('mapFieldValue', $args);

        $this->assertIsArray($mapped);
        $this->assertSame('paragraph', $mapped[0]['type']);
        $this->assertSame([], $warnings);
    }

    public function test_bard_prosemirror_array_is_accepted_not_dropped(): void
    {
        $field = $this->bardField();
        $warnings = [];

        // What the model returns when mirroring the update snapshot's raw JSON.
        $nodes = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Programm']]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '18 Uhr: Beginn']]],
                ]],
            ]],
        ];

        $args = [$nodes, $field, &$warnings, 'default'];
        $mapped = $this->invoke('mapFieldValue', $args);

        $this->assertIsArray($mapped);
        $this->assertNotEmpty($mapped);
        $this->assertSame('paragraph', $mapped[0]['type']);
        $this->assertSame('bulletList', $mapped[1]['type']);
    }

    public function test_bard_doc_wrapper_is_unwrapped(): void
    {
        $field = $this->bardField();
        $warnings = [];

        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Im Dokument']]],
            ],
        ];

        $args = [$doc, $field, &$warnings, 'default'];
        $mapped = $this->invoke('mapFieldValue', $args);

        $this->assertIsArray($mapped);
        $this->assertSame('paragraph', $mapped[0]['type']);
    }

    public function test_empty_bard_array_returns_null_with_warning(): void
    {
        $field = $this->bardField();
        $warnings = [];
        $args = [[], $field, &$warnings, 'default'];

        $mapped = $this->invoke('mapFieldValue', $args);

        $this->assertNull($mapped);
        $this->assertNotEmpty($warnings);
    }
}
