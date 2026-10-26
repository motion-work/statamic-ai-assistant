<?php

namespace MotionWork\StatamicAiAssistant\Tests\Tools;

use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\ReadDocumentTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;

class ReadDocumentToolTest extends TestCase
{
    public function test_unknown_ref_is_actionable(): void
    {
        $result = (new ReadDocumentTool)->handle('{"ref":"ghost::nope.pdf"}', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('list_assets', $result['error']);
    }

    public function test_invalid_arguments_do_not_throw(): void
    {
        $result = (new ReadDocumentTool)->handle('not json', new ToolContext);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_arguments_json', $result['error']);
    }

    public function test_definition_is_wellformed(): void
    {
        $tool = new ReadDocumentTool;
        $def = $tool->definition();

        $this->assertSame('read_document', $tool->name());
        $this->assertSame('read_document', $def['function']['name']);
        $this->assertSame(['ref'], $def['function']['parameters']['required']);
        $this->assertIsInt($tool->maxCalls());
    }
}
