<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\FieldValueMapper;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;

/**
 * A new entry must pass blueprint validation, so required fields the model
 * left empty get a safe default where one exists, and a warning either way.
 */
class RequiredFieldFallbackTest extends TestCase
{
    public function test_empty_required_fields_get_a_safe_default_or_a_warning(): void
    {
        $required = fn (array $field) => ['validate' => ['required']] + $field;

        $blueprint = Blueprint::make('fallbacks')->setContents(['fields' => [
            ['handle' => 'title', 'field' => $required(['type' => 'text', 'display' => 'Title'])],
            ['handle' => 'intro', 'field' => $required(['type' => 'bard', 'display' => 'Intro'])],
            ['handle' => 'color', 'field' => $required(['type' => 'select', 'display' => 'Color', 'options' => ['red' => 'Rot', 'blue' => 'Blau']])],
            ['handle' => 'tags', 'field' => $required(['type' => 'checkboxes', 'display' => 'Tags', 'options' => ['a' => 'A', 'b' => 'B']])],
            ['handle' => 'flag', 'field' => $required(['type' => 'toggle', 'display' => 'Flag'])],
            ['handle' => 'when', 'field' => $required(['type' => 'date', 'display' => 'When'])],
            ['handle' => 'clip', 'field' => $required(['type' => 'video', 'display' => 'Clip'])],
            ['handle' => 'blocks', 'field' => $required(['type' => 'replicator', 'display' => 'Blocks', 'sets' => []])],
            ['handle' => 'optional', 'field' => ['type' => 'text', 'display' => 'Optional']],
            ['handle' => 'subtitle', 'field' => $required(['type' => 'text', 'display' => 'Subtitle'])],
            ['handle' => 'meta', 'field' => $required(['type' => 'group', 'display' => 'Meta', 'fields' => [
                ['handle' => 'kicker', 'field' => $required(['type' => 'text', 'display' => 'Kicker'])],
            ]])],
        ]]);

        $result = app(FieldValueMapper::class)->mapToFieldData(['subtitle' => 'Given by the model'], $blueprint, 'default', 'Write about tea');
        $data = $result['data'];

        $this->assertSame('Write about tea', $data['title']);
        $this->assertSame('paragraph', $data['intro'][0]['type']);
        $this->assertSame('<p>Write about tea</p>', $result['displayData']['intro']);
        $this->assertSame('red', $data['color']);
        $this->assertSame(['a'], $data['tags']);
        $this->assertFalse($data['flag']);
        $this->assertSame(now()->format('Y-m-d'), $data['when']);
        $this->assertSame(['kicker' => 'Write about tea'], $data['meta']);
        $this->assertSame('Given by the model', $data['subtitle']);
        $this->assertArrayNotHasKey('optional', $data);
        $this->assertArrayNotHasKey('clip', $data);
        $this->assertArrayNotHasKey('blocks', $data);

        $warnings = implode("\n", $result['warnings']);
        $this->assertSame(7, substr_count($warnings, 'a safe default was applied'));
        $this->assertStringContainsString('Clip is required but no video URL was provided', $warnings);
        $this->assertStringContainsString('Blocks is required but has no blocks', $warnings);
    }
}
