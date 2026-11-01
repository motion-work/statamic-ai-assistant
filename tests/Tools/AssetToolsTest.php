<?php

namespace MotionWork\StatamicAiAssistant\Tests\Tools;

use Illuminate\Support\Facades\Storage;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\ListAssetsTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use MotionWork\StatamicAiAssistant\Tools\UpdateAssetTool;
use MotionWork\StatamicAiAssistant\Tools\UseAssetsTool;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\YAML;

class AssetToolsTest extends TestCase
{
    public function test_list_assets_without_args_lists_containers(): void
    {
        $result = (new ListAssetsTool)->handle('{}', new ToolContext);

        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['containers']);
    }

    public function test_list_assets_unknown_container_lists_available(): void
    {
        $result = (new ListAssetsTool)->handle('{"container":"ghost"}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function test_use_assets_requires_refs_and_a_sink(): void
    {
        $tool = new UseAssetsTool;

        $noRefs = $tool->handle('{"refs":[]}', new ToolContext(imageSink: new PreferredAssetPaths));
        $this->assertFalse($noRefs['ok']);

        $noSink = $tool->handle('{"refs":["images::a.jpg"]}', new ToolContext);
        $this->assertFalse($noSink['ok']);
    }

    public function test_use_assets_reports_unknown_refs_without_polluting_the_sink(): void
    {
        $sink = new PreferredAssetPaths;
        $result = (new UseAssetsTool)->handle('{"refs":["ghost::nope.jpg"]}', new ToolContext(imageSink: $sink));

        $this->assertFalse($result['ok']);
        $this->assertSame(['ghost::nope.jpg'], $result['unknown']);
        $this->assertTrue($sink->isEmpty());
    }

    public function test_update_asset_unknown_ref_is_actionable(): void
    {
        $result = (new UpdateAssetTool)->handle('{"ref":"ghost::x.jpg","values":{"alt":"A"}}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('list_assets', $result['error']);
    }

    public function test_definitions_are_wellformed(): void
    {
        foreach ([new ListAssetsTool, new UseAssetsTool, new UpdateAssetTool] as $tool) {
            $def = $tool->definition();
            $this->assertSame($tool->name(), $def['function']['name']);
            $this->assertIsArray($def['function']['parameters']['properties']);
            $this->assertIsInt($tool->maxCalls());
        }
    }

    private function makeContainerWithMeta(): void
    {
        config(['filesystems.disks.tool_assets' => [
            'driver' => 'local',
            'root' => Storage::fake('tool_assets')->path(''),
        ]]);
        Blueprint::make('media')->setNamespace('assets')->setContents([
            'fields' => [
                ['handle' => 'alt', 'field' => ['type' => 'text']],
                ['handle' => 'alt_fr', 'field' => ['type' => 'text']],
            ],
        ])->save();
        AssetContainer::make('media')->disk('tool_assets')->title('Media')->save();
        Storage::disk('tool_assets')->put('team/anna.jpg', 'x');
        Storage::disk('tool_assets')->put('team/ben.jpg', 'x');
        // Written before anything lists the container, which caches its file index per request.
        Storage::disk('tool_assets')->put('team/.meta/anna.jpg.yaml', YAML::dump(['data' => ['alt' => 'Anna at the bench']]));
    }

    public function test_list_assets_shows_stored_meta_and_subfolders(): void
    {
        $this->makeContainerWithMeta();

        $root = (new ListAssetsTool)->handle('{"container":"media"}', new ToolContext);
        $this->assertSame(['team'], $root['subfolders']);

        $result = (new ListAssetsTool)->handle('{"container":"media","folder":"team"}', new ToolContext);
        $rows = collect($result['assets'])->keyBy('filename');

        $this->assertSame(['alt', 'alt_fr'], $result['meta_fields']);
        $this->assertSame(['alt' => 'Anna at the bench'], $rows['anna.jpg']['meta']);
        $this->assertArrayNotHasKey('meta', $rows['ben.jpg']);
        $this->assertSame('media::team/ben.jpg', $rows['ben.jpg']['ref']);
    }

    public function test_update_asset_applies_known_handles_and_reports_the_rest(): void
    {
        $this->makeContainerWithMeta();
        $tool = new UpdateAssetTool;

        $result = $tool->handle(json_encode([
            'ref' => 'media::team/anna.jpg',
            'values' => ['alt' => 'Anna at the bench', 'alt_fr' => 'Anna à l’établi', 'caption' => 'x'],
        ]), new ToolContext);

        $this->assertTrue($result['updated']);
        $this->assertSame(['alt_fr'], $result['applied']);
        $this->assertSame(['alt'], $result['unchanged']);
        $this->assertSame(['caption'], $result['rejected']);
        $this->assertSame('Anna à l’établi', Asset::find('media::team/anna.jpg')->get('alt_fr'));

        $again = $tool->handle('{"ref":"media::team/anna.jpg","values":{"alt_fr":"Anna à l’établi"}}', new ToolContext);
        $this->assertFalse($again['updated']);
        $this->assertSame(['alt_fr'], $again['unchanged']);
    }
}
