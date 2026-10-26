<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Tools;

use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use BoldWeb\StatamicAiAssistant\Tools\ToolContext;

class ToolContextTest extends TestCase
{
    public function test_report_activity_invokes_sink(): void
    {
        $lines = [];
        $ctx = new ToolContext(activitySink: function (string $l) use (&$lines) {
            $lines[] = $l;
        });

        $ctx->reportActivity('Reading example.com');
        $ctx->reportActivity('   ');  // blank is ignored

        $this->assertSame(['Reading example.com'], $lines);
    }

    public function test_report_activity_is_noop_without_sink(): void
    {
        $ctx = new ToolContext;

        // Must not throw when no sink is wired.
        $ctx->reportActivity('anything');

        $this->assertTrue(true);
    }
}
