<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\ImageAltTextGenerator;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

class ImageAltTextGeneratorTest extends TestCase
{
    /**
     * @param  bool  $vision  Whether the fake provider claims vision support
     * @param  string|\Throwable  $result  What the model call yields
     */
    private function fakeAi(bool $vision, string|\Throwable $result, ?array &$calls = null): AbstractAiService
    {
        $calls = ['describe' => [], 'prompt' => []];

        return new class($vision, $result, $calls) extends AbstractAiService
        {
            public function __construct(private bool $vision, private string|\Throwable $result, private array &$calls) {}

            protected function callApi(array $messages): string
            {
                return '';
            }

            public function supportsVision(): bool
            {
                return $this->vision;
            }

            public function describeImage(string $imageDataUrl, string $prompt): string
            {
                $this->calls['describe'][] = compact('imageDataUrl', 'prompt');
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }

            public function generateContentFromPrompt(string $prompt, array $context = []): string
            {
                $this->calls['prompt'][] = $prompt;
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    public function test_vision_provider_gets_the_image_bytes_and_context(): void
    {
        $gen = new ImageAltTextGenerator($this->fakeAi(true, '  "Chef plating a dessert in the hotel kitchen."  ', $calls));

        $alt = $gen->forImageBytes('raw-bytes', 'image/jpeg', 'Hero image of the cooking workshop');

        $this->assertSame('Chef plating a dessert in the hotel kitchen.', $alt);
        $this->assertCount(1, $calls['describe']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $calls['describe'][0]['imageDataUrl']);
        $this->assertStringContainsString('Hero image of the cooking workshop', $calls['describe'][0]['prompt']);
        $this->assertSame([], $calls['prompt']);
    }

    public function test_without_vision_the_reason_is_turned_into_alt_via_text_completion(): void
    {
        $gen = new ImageAltTextGenerator($this->fakeAi(false, 'Blick auf die Hotelterrasse am See', $calls));

        $alt = $gen->forImageBytes('raw-bytes', 'image/png', 'Terrassenbild für den Seite-Header');

        $this->assertSame('Blick auf die Hotelterrasse am See', $alt);
        $this->assertSame([], $calls['describe']);
        $this->assertCount(1, $calls['prompt']);
        $this->assertStringContainsString('Terrassenbild für den Seite-Header', $calls['prompt'][0]);
    }

    public function test_without_vision_and_without_context_no_call_is_made(): void
    {
        $gen = new ImageAltTextGenerator($this->fakeAi(false, 'unused', $calls));

        $this->assertNull($gen->forImageBytes('raw-bytes', 'image/png', '   '));
        $this->assertSame([], $calls['prompt']);
    }

    public function test_failures_and_junk_yield_null_instead_of_blocking_the_save(): void
    {
        $failing = new ImageAltTextGenerator($this->fakeAi(true, new \RuntimeException('api down')));
        $this->assertNull($failing->forImageBytes('raw-bytes', 'image/jpeg', 'ctx'));

        $empty = new ImageAltTextGenerator($this->fakeAi(true, '  "  " '));
        $this->assertNull($empty->forImageBytes('raw-bytes', 'image/jpeg', 'ctx'));

        $rambling = new ImageAltTextGenerator($this->fakeAi(true, str_repeat('word ', 200)));
        $this->assertNull($rambling->forImageBytes('raw-bytes', 'image/jpeg', 'ctx'));
    }

    public function test_long_but_reasonable_output_is_clamped_to_alt_length(): void
    {
        $gen = new ImageAltTextGenerator($this->fakeAi(true, str_repeat('a', 200)));

        $alt = $gen->forImageBytes('raw-bytes', 'image/jpeg', 'ctx');

        $this->assertSame(160, mb_strlen($alt));
    }

    public function test_config_toggle_disables_generation_entirely(): void
    {
        config()->set('statamic-ai-assistant.image_fetch.generate_alt', false);

        $gen = new ImageAltTextGenerator($this->fakeAi(true, 'never used', $calls));

        $this->assertNull($gen->forImageBytes('raw-bytes', 'image/jpeg', 'ctx'));
        $this->assertSame([], $calls['describe']);
    }
}
