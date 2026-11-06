<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services\Free;

use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\Ai\Free\FreeModelCatalog;
use MotionWork\StatamicAiAssistant\Services\Ai\Free\FreeModelHealth;
use MotionWork\StatamicAiAssistant\Services\Ai\Free\FreeModelPicker;
use MotionWork\StatamicAiAssistant\Support\FreeModels;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * The free model catalog (OpenRouter's capability order, exclusions, caching)
 * and the picker that chooses the best fitting free models per task.
 */
class FreeModelCatalogTest extends TestCase
{
    use FakesFreeOpenRouter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFreeOpenRouter();
    }

    public function test_the_catalog_keeps_free_text_models_in_capability_order(): void
    {
        $catalog = (new FreeModelCatalog)->current();

        $this->assertSame(
            ['nvidia/super-vl:free', 'nvidia/ultra:free', 'google/gemma:free', 'acme/small:free', 'cohere/mini:free'],
            array_column($catalog['text'], 'id'),
            'stealth, content-safety, paid, non-text and the router itself are left out',
        );

        $first = $catalog['text'][0];
        $this->assertTrue($first['tools']);
        $this->assertTrue($first['reliable_tools']);
        $this->assertTrue($first['structured']);
        $this->assertTrue($first['image_input']);
        $this->assertSame(42.0, $first['intelligence']);
        $this->assertFalse($catalog['text'][2]['reliable_tools'], 'gemma is not in the reliable-tools list');
    }

    public function test_embedding_models_are_free_large_enough_and_largest_first(): void
    {
        $this->assertSame(['nvidia/embed-8b:free', 'nvidia/embed-1b:free'], (new FreeModelCatalog)->current()['embedding']);
    }

    public function test_the_catalog_is_cached_and_survives_an_outage(): void
    {
        $catalog = new FreeModelCatalog;
        $catalog->current();
        $requests = count($this->openRouterRequests);

        $catalog->current();
        $this->assertCount($requests, $this->openRouterRequests, 'served from the cache');

        Cache::forget('statamic-ai-assistant.free-models.catalog');
        $this->catalogDown = true;
        $this->assertSame('nvidia/super-vl:free', $catalog->current()['text'][0]['id'], 'the last good catalog is used');
    }

    public function test_without_any_catalog_the_configured_fallback_is_used(): void
    {
        $this->catalogDown = true;
        config(['statamic-ai-assistant.openrouter_free_models.fallback_models' => 'fallback/a:free,fallback/b:free']);

        $this->assertSame(['fallback/a:free', 'fallback/b:free', FreeModelPicker::ROUTER], app(FreeModelPicker::class)->models(AiTask::Agent, FreeModels::DENY));
    }

    public function test_the_agent_gets_reliable_tool_models_with_structured_output_first(): void
    {
        $picker = app(FreeModelPicker::class);

        $this->assertSame(
            ['nvidia/super-vl:free', 'cohere/mini:free', FreeModelPicker::ROUTER],
            $picker->models(AiTask::Agent, FreeModels::DENY),
            'gemma: tools unreliable; acme: context too small; ultra: no structured output, so it ranks after cohere',
        );
    }

    public function test_quick_tasks_prefer_models_that_can_answer_without_reasoning(): void
    {
        $picker = app(FreeModelPicker::class);

        $this->assertSame(['nvidia/super-vl:free', 'google/gemma:free', FreeModelPicker::ROUTER], $picker->models(AiTask::Field, FreeModels::DENY));
        $this->assertTrue($picker->canSkipReasoning('google/gemma:free'));
        $this->assertFalse($picker->canSkipReasoning('nvidia/ultra:free'));
        $this->assertFalse($picker->canSkipReasoning(FreeModelPicker::ROUTER));
    }

    public function test_image_analysis_needs_image_input_and_images_have_no_free_model(): void
    {
        $picker = app(FreeModelPicker::class);

        $this->assertSame(['nvidia/super-vl:free', 'google/gemma:free', FreeModelPicker::ROUTER], $picker->models(AiTask::Vision, FreeModels::DENY));
        $this->assertSame([], $picker->models(AiTask::Image, FreeModels::ALLOW));
    }

    public function test_embeddings_need_training_to_be_allowed(): void
    {
        $picker = app(FreeModelPicker::class);

        $this->assertSame([], $picker->models(AiTask::Embedding, FreeModels::DENY));
        $this->assertSame(['nvidia/embed-8b:free'], $picker->models(AiTask::Embedding, FreeModels::ALLOW));
    }

    public function test_a_busy_model_steps_aside_until_it_recovers(): void
    {
        $health = app(FreeModelHealth::class);
        $picker = app(FreeModelPicker::class);

        $health->busy('nvidia/super-vl:free');
        $this->assertSame(['cohere/mini:free', 'nvidia/ultra:free', FreeModelPicker::ROUTER], $picker->models(AiTask::Agent, FreeModels::DENY));

        $health->ok('nvidia/super-vl:free');
        $this->assertSame('nvidia/super-vl:free', $picker->models(AiTask::Agent, FreeModels::DENY)[0]);
    }

    public function test_a_privacy_block_only_applies_under_that_policy(): void
    {
        app(FreeModelHealth::class)->blocked('nvidia/super-vl:free', FreeModels::DENY);
        $picker = app(FreeModelPicker::class);

        $this->assertNotContains('nvidia/super-vl:free', $picker->models(AiTask::Agent, FreeModels::DENY));
        $this->assertSame('nvidia/super-vl:free', $picker->models(AiTask::Agent, FreeModels::ALLOW)[0]);
    }

    public function test_the_first_choice_per_task_is_reported(): void
    {
        $choices = app(FreeModelPicker::class)->firstChoices(FreeModels::DENY);

        $this->assertSame('nvidia/super-vl:free', $choices['agent']);
        $this->assertSame('nvidia/super-vl:free', $choices['vision']);
        $this->assertNull($choices['embedding']);
    }
}
