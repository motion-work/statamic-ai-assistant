<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;

/**
 * Regression: the update flow walks the blueprint's fields via
 * $blueprint->fields()->all(), which is a Statamic Collection (not a plain
 * array). walkAndInjectAssets used to be typed `array` and threw a TypeError
 * on the update path ("must be of type array, Collection given").
 */
class UpdateAssetSchemaTest extends TestCase
{
    public function test_asset_schema_walk_accepts_collection_fields(): void
    {
        config(['statamic-ai-assistant.bold_agent_asset_listing_cap' => 0]);

        $blueprint = Blueprint::make('t')->setContents([
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'fields' => [
                                ['handle' => 'title', 'field' => ['type' => 'text']],
                                ['handle' => 'image', 'field' => ['type' => 'assets', 'max_files' => 1]],
                                ['handle' => 'meta', 'field' => ['type' => 'group', 'fields' => [
                                    ['handle' => 'og_image', 'field' => ['type' => 'assets', 'max_files' => 1]],
                                ]]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = app(EntryGeneratorService::class);
        $method = new \ReflectionMethod($service, 'augmentSchemaWithAssetFields');
        $method->setAccessible(true);

        $schema = [];
        $args = [&$schema, $blueprint];
        $assetFields = $method->invokeArgs($service, $args); // must not throw

        $handles = array_map(fn ($f) => $f['field']->handle(), $assetFields);
        $this->assertContains('image', $handles);
        $this->assertContains('og_image', $handles, 'nested (group) asset fields are walked too');
    }
}
