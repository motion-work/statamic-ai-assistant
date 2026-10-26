<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * Guards the "step 1" schema compaction: it must shrink what we SEND without
 * ever dropping a key the model actually uses. The denylist approach means any
 * key not explicitly named as noise survives — including future additions.
 */
class SchemaPromptEncodingTest extends TestCase
{
    /** A representative deeply-nested schema: group + grid + replicator sets + bard. */
    private function sampleSchema(): array
    {
        return [
            'title' => ['label' => 'Title', 'generatable' => true, 'type' => 'text', 'required' => true],
            'hero' => [
                'label' => 'Hero', 'generatable' => true, 'type' => 'group',
                'fields' => [
                    'hero_title' => ['label' => 'Hero Title', 'generatable' => true, 'type' => 'text', 'ai_description' => 'The headline'],
                    'body' => ['label' => 'Body', 'generatable' => true, 'type' => 'html'],
                ],
            ],
            'rows' => [
                'label' => 'Rows', 'generatable' => true, 'type' => 'grid',
                'row_fields' => [
                    'name' => ['label' => 'Name', 'generatable' => true, 'type' => 'text'],
                ],
            ],
            'blocks' => [
                'label' => 'Blocks', 'generatable' => true, 'type' => 'structured',
                'sets' => [
                    'text' => ['content' => ['label' => 'Content', 'generatable' => true, 'type' => 'html']],
                ],
            ],
        ];
    }

    private function encode(array $schema): string
    {
        $service = app(EntryGeneratorService::class);
        $m = new \ReflectionMethod($service, 'encodeSchemaForPrompt');
        $m->setAccessible(true);

        return $m->invoke($service, $schema);
    }

    public function test_noise_key_is_stripped_at_every_depth(): void
    {
        $out = $this->encode($this->sampleSchema());

        $this->assertStringNotContainsString('generatable', $out, 'the internal marker must not reach the model, at any nesting depth');
    }

    public function test_all_model_facing_keys_survive(): void
    {
        $decoded = json_decode($this->encode($this->sampleSchema()), true);

        // Structure + every useful key is intact.
        $this->assertSame('text', $decoded['title']['type']);
        $this->assertTrue($decoded['title']['required']);
        $this->assertSame('The headline', $decoded['hero']['fields']['hero_title']['ai_description']);
        $this->assertSame('html', $decoded['hero']['fields']['body']['type']);
        $this->assertArrayHasKey('name', $decoded['rows']['row_fields']);
        $this->assertSame('html', $decoded['blocks']['sets']['text']['content']['type']);
        $this->assertSame('Hero', $decoded['hero']['label']);
    }

    public function test_output_is_minified(): void
    {
        $out = $this->encode($this->sampleSchema());

        // No pretty-print artefacts: no newlines, no 4-space indents.
        $this->assertStringNotContainsString("\n", $out);
        $this->assertStringNotContainsString('    ', $out);
        // Still valid JSON.
        $this->assertIsArray(json_decode($out, true));
    }

    public function test_measured_savings_are_substantial(): void
    {
        $schema = $this->sampleSchema();
        $before = strlen((string) json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $after = strlen($this->encode($schema));

        $pct = round(100 * ($before - $after) / $before);
        fwrite(STDERR, "\n[schema encoding] before={$before} chars, after={$after} chars, saved {$pct}%\n");

        $this->assertLessThan($before, $after);
        // Pretty-print + the repeated marker are a large fraction of a nested schema.
        $this->assertGreaterThanOrEqual(30, $pct);
    }
}
