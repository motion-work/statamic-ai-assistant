<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Statamic\Fields\Field;

/**
 * Grids must be presented to the LLM as a repeating list (one row per item),
 * not as a replicator-style "structured" field with a synthetic set. Regression
 * cover for grids whose single sub-field is rich text, where the model used to
 * dump a whole bullet list into one row instead of one row per bullet.
 */
class EntryGeneratorGridSchemaTest extends TestCase
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

    private function gridField(): Field
    {
        return new Field('lead_informations', [
            'type' => 'grid',
            'display' => 'Informationen',
            'fields' => [
                ['handle' => 'information', 'field' => ['type' => 'bard']],
            ],
        ]);
    }

    public function test_grid_schema_uses_row_fields_and_repeating_description(): void
    {
        $entry = $this->invoke('buildFieldSchemaEntry', [$this->gridField(), false, 'default']);

        $this->assertSame('grid', $entry['type']);
        $this->assertArrayHasKey('row_fields', $entry);
        $this->assertArrayHasKey('information', $entry['row_fields']);
        // Must NOT reuse the replicator-style "sets"/"structured" framing.
        $this->assertArrayNotHasKey('sets', $entry);
        $this->assertStringContainsString('ONE row per item', $entry['description']);
    }

    public function test_grid_response_maps_to_one_row_per_item(): void
    {
        $field = $this->gridField();
        $warnings = [];

        // What the LLM should now return: an array of rows, one item each.
        $rows = [
            ['information' => '<p>Ein Glas Prosecco</p>'],
            ['information' => '<p>Hausgebackene Scones</p>'],
            ['information' => '<p>Suesse Mini-Patisserie</p>'],
        ];

        $args = [$rows, $field, &$warnings, 'default'];
        $result = $this->invoke('mapReplicatorData', $args);

        $this->assertCount(3, $result);
        foreach ($result as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('information', $row);
        }
    }

    public function test_system_message_includes_grid_rule_when_grid_present(): void
    {
        $schema = [
            'lead_informations' => $this->invoke('buildFieldSchemaEntry', [$this->gridField(), false, 'default']),
        ];

        $message = strtolower($this->invoke('buildSystemMessage', [$schema, 'en']));

        $this->assertStringContainsString('one row per item', $message);
    }

    public function test_grid_type_is_detected_even_when_nested_in_a_group(): void
    {
        // A grid nested inside a group must still trigger the grid rule.
        $schema = [
            'sidebar' => [
                'type' => 'group',
                'fields' => [
                    'lead_informations' => $this->invoke('buildFieldSchemaEntry', [$this->gridField(), false, 'default']),
                ],
            ],
        ];

        $this->assertTrue($this->invoke('fieldSchemaContainsType', [$schema, 'grid']));
    }
}
