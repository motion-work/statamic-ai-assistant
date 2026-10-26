<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\InfomaniakService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

class TieredModelTest extends TestCase
{
    private InfomaniakService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InfomaniakService;
        config(['statamic-ai-assistant.infomaniak_model' => 'strong-model']);
    }

    public function test_uses_fast_model_inside_the_closure_then_restores(): void
    {
        config(['statamic-ai-assistant.infomaniak_model_fast' => 'fast-model']);

        $seen = $this->service->usingFastModel(fn () => config('statamic-ai-assistant.infomaniak_model'));

        $this->assertSame('fast-model', $seen, 'fast model should be active inside the closure');
        $this->assertSame('strong-model', config('statamic-ai-assistant.infomaniak_model'), 'strong model must be restored after');
    }

    public function test_no_swap_when_fast_model_unset(): void
    {
        config(['statamic-ai-assistant.infomaniak_model_fast' => '']);

        $seen = $this->service->usingFastModel(fn () => config('statamic-ai-assistant.infomaniak_model'));

        $this->assertSame('strong-model', $seen, 'without a fast model, the strong model is used (no regression)');
        $this->assertSame('strong-model', config('statamic-ai-assistant.infomaniak_model'));
    }

    public function test_model_is_restored_even_if_the_closure_throws(): void
    {
        config(['statamic-ai-assistant.infomaniak_model_fast' => 'fast-model']);

        try {
            $this->service->usingFastModel(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('strong-model', config('statamic-ai-assistant.infomaniak_model'));
    }
}
