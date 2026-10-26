<?php

namespace MotionWork\StatamicAiAssistant\Tests\Support;

use MotionWork\StatamicAiAssistant\Support\BlueprintFieldValidator;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Fieldset;

/**
 * The validator's job is to turn typical LLM mistakes into targeted,
 * self-correcting error messages instead of persisting broken blueprints
 * (behaviour adapted from cboxdk/statamic-mcp).
 */
class BlueprintFieldValidatorTest extends TestCase
{
    private BlueprintFieldValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new BlueprintFieldValidator;
    }

    public function test_valid_fields_pass_through(): void
    {
        $result = $this->validator->validate([
            ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
            ['handle' => 'body', 'field' => ['type' => 'markdown']],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['fields']);
        $this->assertSame('title', $result['fields'][0]['handle']);
    }

    public function test_empty_fields_are_rejected(): void
    {
        $result = $this->validator->validate([]);

        $this->assertFalse($result['ok']);
    }

    public function test_name_instead_of_handle_gets_a_correction_hint(): void
    {
        $result = $this->validator->validate([
            ['name' => 'title', 'field' => ['type' => 'text']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Did you mean "handle"', $result['error']);
    }

    public function test_type_at_top_level_gets_a_correction_hint(): void
    {
        $result = $this->validator->validate([
            ['handle' => 'title', 'type' => 'text'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('"type" at the top level', $result['error']);
    }

    public function test_fieldtype_instead_of_type_gets_a_correction_hint(): void
    {
        $result = $this->validator->validate([
            ['handle' => 'title', 'field' => ['fieldtype' => 'text']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Did you mean "type"', $result['error']);
    }

    public function test_unknown_fieldtype_lists_available_types(): void
    {
        $result = $this->validator->validate([
            ['handle' => 'title', 'field' => ['type' => 'not_a_real_type']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Available types:', $result['error']);
        $this->assertStringContainsString('text', $result['error']);
    }

    public function test_duplicate_handles_are_rejected(): void
    {
        $result = $this->validator->validate([
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'title', 'field' => ['type' => 'textarea']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Duplicate field handle', $result['error']);
    }

    public function test_invalid_handle_shape_is_rejected(): void
    {
        $result = $this->validator->validate([
            ['handle' => 'Hero Title', 'field' => ['type' => 'text']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('snake_case', $result['error']);
    }

    public function test_unknown_taxonomy_reference_is_rejected(): void
    {
        $result = $this->validator->validate([
            ['handle' => 'topics', 'field' => ['type' => 'terms', 'taxonomies' => ['does_not_exist_tax']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does_not_exist_tax', $result['error']);
    }

    public function test_template_expressions_are_stripped_from_config_strings(): void
    {
        $result = $this->validator->validate([
            ['handle' => 'title', 'field' => ['type' => 'text', 'instructions' => 'Hello {{ user:password }} world']],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('Hello  world', $result['fields'][0]['field']['instructions']);
    }

    // ------------------------------------------------------------------
    //  Fieldset imports and references
    // ------------------------------------------------------------------

    private function makeHeroFieldset(): void
    {
        Fieldset::make('adv_hero')->setContents([
            'title' => 'Adv Hero',
            'fields' => [
                ['handle' => 'hero_title', 'field' => ['type' => 'text', 'display' => 'Hero title']],
                ['handle' => 'hero_image', 'field' => ['type' => 'assets', 'max_files' => 1]],
            ],
        ])->save();
    }

    protected function tearDown(): void
    {
        Fieldset::find('adv_hero')?->delete();

        parent::tearDown();
    }

    public function test_import_row_passes_when_the_fieldset_exists(): void
    {
        $this->makeHeroFieldset();

        $result = $this->validator->validate([
            ['import' => 'adv_hero'],
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['import' => 'adv_hero'], $result['fields'][0]);
    }

    public function test_import_row_with_prefix_is_kept(): void
    {
        $this->makeHeroFieldset();

        $result = $this->validator->validate([
            ['import' => 'adv_hero', 'prefix' => 'top_'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['import' => 'adv_hero', 'prefix' => 'top_'], $result['fields'][0]);
    }

    public function test_import_of_unknown_fieldset_lists_available_ones(): void
    {
        $this->makeHeroFieldset();

        $result = $this->validator->validate([
            ['import' => 'ghost_fieldset'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Available fieldsets:', $result['error']);
        $this->assertStringContainsString('adv_hero', $result['error']);
    }

    public function test_string_field_reference_is_validated_against_the_fieldset(): void
    {
        $this->makeHeroFieldset();

        $ok = $this->validator->validate([
            ['handle' => 'headline', 'field' => 'adv_hero.hero_title'],
        ]);
        $this->assertTrue($ok['ok']);
        $this->assertSame('adv_hero.hero_title', $ok['fields'][0]['field']);

        $badField = $this->validator->validate([
            ['handle' => 'headline', 'field' => 'adv_hero.nope'],
        ]);
        $this->assertFalse($badField['ok']);
        $this->assertStringContainsString('hero_title', $badField['error']);

        $badFieldset = $this->validator->validate([
            ['handle' => 'headline', 'field' => 'ghost.title'],
        ]);
        $this->assertFalse($badFieldset['ok']);
        $this->assertStringContainsString('Available fieldsets:', $badFieldset['error']);
    }
}
