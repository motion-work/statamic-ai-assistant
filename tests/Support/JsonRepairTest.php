<?php

namespace MotionWork\StatamicAiAssistant\Tests\Support;

use MotionWork\StatamicAiAssistant\Support\JsonRepair;
use PHPUnit\Framework\TestCase;

class JsonRepairTest extends TestCase
{
    /** @return mixed */
    private function repairAndDecode(string $json)
    {
        return json_decode(JsonRepair::repair($json), true);
    }

    public function test_valid_json_is_left_intact(): void
    {
        $json = '{"title":"Boeing","tags":["a","b"],"n":3,"ok":true,"empty":""}';

        $this->assertSame(json_decode($json, true), $this->repairAndDecode($json));
    }

    public function test_stray_unescaped_closing_quote_inside_a_string_is_escaped(): void
    {
        // The exact defect from the log: opening quote of «Jumbo» escaped, closing
        // quote left bare, so it terminated the "text" string early.
        $broken = '{"text":"Die 747, auch \"Jumbo" genannt, war gross."}';

        $this->assertNull(json_decode($broken, true), 'precondition: the input is invalid JSON');

        $fixed = $this->repairAndDecode($broken);
        $this->assertIsArray($fixed);
        $this->assertSame('Die 747, auch "Jumbo" genannt, war gross.', $fixed['text']);
    }

    public function test_html_heavy_value_with_a_stray_quote_survives(): void
    {
        $broken = '{"body":"<p>Ein <strong>"echtes" Grossraumflugzeug</strong>.</p>","seo":false}';

        $fixed = $this->repairAndDecode($broken);
        $this->assertIsArray($fixed);
        $this->assertStringContainsString('"echtes"', $fixed['body']);
        $this->assertFalse($fixed['seo']);
    }

    public function test_trailing_commas_are_removed(): void
    {
        $this->assertSame(
            ['a' => 1, 'b' => [1, 2]],
            $this->repairAndDecode('{"a":1,"b":[1,2,],}'),
        );
    }

    public function test_truncated_output_is_closed(): void
    {
        // Response cut off mid-string, several levels deep.
        $broken = '{"title":"X","main":[{"type":"text","text":"unfinished';

        $fixed = $this->repairAndDecode($broken);
        $this->assertIsArray($fixed);
        $this->assertSame('X', $fixed['title']);
        $this->assertSame('unfinished', $fixed['main'][0]['text']);
    }

    public function test_commas_inside_strings_are_not_touched(): void
    {
        $json = '{"t":"one, two, three","list":["x, y","z"]}';

        $this->assertSame(json_decode($json, true), $this->repairAndDecode($json));
    }

    public function test_structural_errors_are_left_for_the_caller_to_retry(): void
    {
        // A bare object where an object member is expected (the second defect in
        // the logged response) is ambiguous — repair must NOT invent a fix; it
        // stays invalid so the caller falls back to the LLM correction round.
        $broken = '{"type":"facts","numbers":{"value":"1916"},{"type":"facts"}}';

        $this->assertNull($this->repairAndDecode($broken));
    }
}
