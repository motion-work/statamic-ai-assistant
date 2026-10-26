<?php

namespace Tests\Support;

use BoldWeb\StatamicAiAssistant\Support\JsonObjectExtractor;
use PHPUnit\Framework\TestCase;

class JsonObjectExtractorTest extends TestCase
{
    public function test_extracts_first_object_ignoring_suffix(): void
    {
        $raw = "prefix {\"title\":\"A\",\"body\":\"x}\"}\ntrailing";

        $json = JsonObjectExtractor::firstObject($raw);

        $this->assertSame('{"title":"A","body":"x}"}', $json);
        $this->assertIsArray(json_decode($json, true));
    }

    public function test_extracts_first_object_after_text_without_braces(): void
    {
        $raw = "Thinking: output minimal JSON only.\n{\"title\":\"One\"}";

        $json = JsonObjectExtractor::firstObject($raw);

        $this->assertSame('{"title":"One"}', $json);
    }

    public function test_respects_escaped_quotes_in_strings(): void
    {
        $raw = '{"a":"say \"hi\"","b":1}';

        $json = JsonObjectExtractor::firstObject($raw);

        $this->assertSame($raw, $json);
    }

    public function test_returns_null_when_unclosed(): void
    {
        $this->assertNull(JsonObjectExtractor::firstObject('{"a":1'));
    }
}
