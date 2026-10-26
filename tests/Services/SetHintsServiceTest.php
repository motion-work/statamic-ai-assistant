<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\SetHintsService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

class SetHintsServiceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/set-hints-test-'.uniqid().'.yaml';
        config()->set('statamic-ai-assistant.set_hints_path', $this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_field_hints_round_trip(): void
    {
        $service = new SetHintsService;

        $service->saveFieldHints([
            'hero_title' => [
                'ai_description' => 'Main page headline over the hero image.',
                'when_to_use' => ['Keep under 60 characters', ''],
            ],
            'empty_one' => ['ai_description' => '', 'when_to_use' => []],
        ]);

        $fresh = new SetHintsService;

        $this->assertSame(
            [
                'hero_title' => [
                    'ai_description' => 'Main page headline over the hero image.',
                    'when_to_use' => ['Keep under 60 characters'],
                ],
            ],
            $fresh->allFieldHints()
        );
        $this->assertNull($fresh->forField('empty_one'));
        $this->assertNotNull($fresh->forField('hero_title'));
    }

    public function test_saving_set_hints_preserves_field_hints_and_vice_versa(): void
    {
        $service = new SetHintsService;

        $service->save(['hero' => ['ai_description' => 'Block hint.', 'when_to_use' => []]]);
        $service->saveFieldHints(['lead' => ['ai_description' => 'Field hint.', 'when_to_use' => []]]);
        $service->save(['hero' => ['ai_description' => 'Updated block hint.', 'when_to_use' => []]]);

        $fresh = new SetHintsService;

        $this->assertSame('Updated block hint.', $fresh->forSet('hero')['ai_description']);
        $this->assertSame('Field hint.', $fresh->forField('lead')['ai_description']);
    }

    public function test_legacy_string_field_hints_are_normalized(): void
    {
        file_put_contents($this->path, "field_hints:\n  lead: 'Short intro paragraph.'\n");

        $service = new SetHintsService;

        $this->assertSame(
            ['ai_description' => 'Short intro paragraph.', 'when_to_use' => []],
            $service->forField('lead')
        );
    }
}
