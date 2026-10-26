<?php

namespace MotionWork\StatamicAiAssistant\Tests\Tools;

use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\ListAssetsTool;
use MotionWork\StatamicAiAssistant\Tools\UpdateAssetTool;
use MotionWork\StatamicAiAssistant\Tools\UseAssetsTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;

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
}
