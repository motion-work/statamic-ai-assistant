<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Fields\Field;

/**
 * Coverage for the field types and coercions adapted from cboxdk/statamic-mcp:
 * option values that match what Statamic actually stores (keys, not labels),
 * multi-choice fields, markdown/number/time/table support, and tolerant date
 * parsing. These are the shapes mid-tier models most often get slightly wrong —
 * the mapper must repair or reject them loudly instead of persisting garbage.
 */
class FieldValueMappingTest extends TestCase
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

    private function map(mixed $value, Field $field, array &$warnings): mixed
    {
        $args = [$value, $field, &$warnings, 'default'];

        return $this->invoke('mapFieldValue', $args);
    }

    private function schemaEntry(Field $field): ?array
    {
        return $this->invoke('buildFieldSchemaEntry', [$field, false, 'default']);
    }

    // ------------------------------------------------------------------
    //  Choice fields: shown options === accepted options === stored values
    // ------------------------------------------------------------------

    public function test_select_with_assoc_options_shows_and_accepts_stored_keys(): void
    {
        $field = new Field('color', ['type' => 'select', 'display' => 'Color', 'options' => ['red' => 'Rot', 'blue' => 'Blau']]);

        // The schema must advertise the stored keys, not the display labels.
        $this->assertSame(['red', 'blue'], $this->schemaEntry($field)['options']);

        $warnings = [];
        $this->assertSame('red', $this->map('red', $field, $warnings));
        $this->assertSame([], $warnings);

        // A label is not a stored value — rejected with a warning.
        $this->assertNull($this->map('Rot', $field, $warnings));
        $this->assertNotEmpty($warnings);
    }

    public function test_select_with_key_value_row_options_uses_the_key(): void
    {
        $field = new Field('size', ['type' => 'select', 'display' => 'Size', 'options' => [
            ['key' => 'sm', 'value' => 'Small'],
            ['key' => 'lg', 'value' => 'Large'],
        ]]);

        $this->assertSame(['sm', 'lg'], $this->schemaEntry($field)['options']);

        $warnings = [];
        $this->assertSame('lg', $this->map('lg', $field, $warnings));
        $this->assertSame([], $warnings);
    }

    public function test_multiple_select_wraps_bare_string_and_skips_invalid_values(): void
    {
        $field = new Field('tags', ['type' => 'select', 'display' => 'Tags', 'multiple' => true, 'options' => ['a' => 'A', 'b' => 'B']]);

        $this->assertSame('multi_select', $this->schemaEntry($field)['type']);

        $warnings = [];
        $this->assertSame(['a'], $this->map('a', $field, $warnings));
        $this->assertSame(['a', 'b'], $this->map(['a', 'b'], $field, $warnings));
        $this->assertSame([], $warnings);

        $this->assertSame(['a'], $this->map(['a', 'nope'], $field, $warnings));
        $this->assertNotEmpty($warnings);
    }

    public function test_checkboxes_are_supported_as_multi_choice(): void
    {
        $field = new Field('features', ['type' => 'checkboxes', 'display' => 'Features', 'options' => ['wifi' => 'WiFi', 'pool' => 'Pool']]);

        $this->assertSame('multi_select', $this->schemaEntry($field)['type']);

        $warnings = [];
        $this->assertSame(['wifi', 'pool'], $this->map(['wifi', 'pool'], $field, $warnings));
        $this->assertSame([], $warnings);
    }

    public function test_radio_is_supported_as_single_choice(): void
    {
        $field = new Field('level', ['type' => 'radio', 'display' => 'Level', 'options' => ['low' => 'Low', 'high' => 'High']]);

        $this->assertSame('select', $this->schemaEntry($field)['type']);

        $warnings = [];
        $this->assertSame('high', $this->map('high', $field, $warnings));
        $this->assertSame([], $warnings);
    }

    // ------------------------------------------------------------------
    //  Previously skipped field types
    // ------------------------------------------------------------------

    public function test_markdown_passes_string_through_and_rejects_arrays(): void
    {
        $field = new Field('body', ['type' => 'markdown', 'display' => 'Body']);

        $this->assertSame('markdown', $this->schemaEntry($field)['type']);

        $warnings = [];
        $this->assertSame('**Hallo** Welt', $this->map('**Hallo** Welt', $field, $warnings));
        $this->assertSame([], $warnings);

        $this->assertNull($this->map([['type' => 'paragraph']], $field, $warnings));
        $this->assertNotEmpty($warnings);
    }

    public function test_integer_and_float_are_coerced_from_numeric_values(): void
    {
        $int = new Field('count', ['type' => 'integer', 'display' => 'Count']);
        $float = new Field('price', ['type' => 'float', 'display' => 'Price']);

        $warnings = [];
        $this->assertSame(42, $this->map('42', $int, $warnings));
        $this->assertSame(19.9, $this->map('19.9', $float, $warnings));
        $this->assertSame([], $warnings);

        $this->assertNull($this->map('zweiundvierzig', $int, $warnings));
        $this->assertNotEmpty($warnings);
    }

    public function test_time_is_normalized_and_validated(): void
    {
        $field = new Field('starts', ['type' => 'time', 'display' => 'Starts']);

        $warnings = [];
        $this->assertSame('09:30', $this->map('9:30', $field, $warnings));
        $this->assertSame('18:00', $this->map('18:00:00', $field, $warnings));
        $this->assertSame([], $warnings);

        $this->assertNull($this->map('25:00', $field, $warnings));
        $this->assertNotEmpty($warnings);
    }

    // ------------------------------------------------------------------
    //  Table fields
    // ------------------------------------------------------------------

    public function test_table_unwraps_value_cells_and_bare_array_rows(): void
    {
        $field = new Field('prices', ['type' => 'table', 'display' => 'Prices']);

        $warnings = [];
        $mapped = $this->map([
            ['cells' => ['Room', ['value' => '120'], null]],
            ['Suite', '190'], // bare array row — coerced to {cells: ...}
        ], $field, $warnings);

        $this->assertSame([
            ['cells' => ['Room', '120', null]],
            ['cells' => ['Suite', '190']],
        ], $mapped);
        $this->assertSame([], $warnings);
    }

    public function test_table_rows_without_cells_are_skipped_with_warning(): void
    {
        $field = new Field('prices', ['type' => 'table', 'display' => 'Prices']);

        $warnings = [];
        $mapped = $this->map([
            ['cells' => ['ok']],
            ['columns' => ['broken']],
        ], $field, $warnings);

        $this->assertSame([['cells' => ['ok']]], $mapped);
        $this->assertNotEmpty($warnings);
    }

    // ------------------------------------------------------------------
    //  Tolerant date parsing
    // ------------------------------------------------------------------

    public function test_date_accepts_iso_datetime_and_split_object(): void
    {
        $field = new Field('date', ['type' => 'date', 'display' => 'Date']);

        $warnings = [];
        $this->assertSame('2024-01-15', $this->map('2024-01-15', $field, $warnings));
        $this->assertSame('2024-01-15', $this->map('2024-01-15T12:00:00.000Z', $field, $warnings));
        $this->assertSame('2024-01-15', $this->map(['date' => '2024-01-15', 'time' => '12:00'], $field, $warnings));
        $this->assertSame([], $warnings);
    }

    public function test_time_enabled_date_keeps_the_time_portion(): void
    {
        $field = new Field('date', ['type' => 'date', 'display' => 'Date', 'time_enabled' => true]);

        $warnings = [];
        $this->assertSame('2024-01-15 12:30', $this->map('2024-01-15 12:30', $field, $warnings));
        $this->assertSame('2024-01-15 12:30', $this->map(['date' => '2024-01-15', 'time' => '12:30'], $field, $warnings));
        $this->assertSame([], $warnings);
    }

    public function test_unparseable_date_is_rejected_with_warning(): void
    {
        $field = new Field('date', ['type' => 'date', 'display' => 'Date']);

        $warnings = [];
        $this->assertNull($this->map('not a date', $field, $warnings));
        $this->assertNotEmpty($warnings);
    }
}
