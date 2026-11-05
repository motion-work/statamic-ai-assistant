<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviderException;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\Ai\GeneratedImage;
use MotionWork\StatamicAiAssistant\Services\AssetImageDownloader;
use MotionWork\StatamicAiAssistant\Services\GeneratedImageStore;
use MotionWork\StatamicAiAssistant\Services\ImageAltTextGenerator;
use MotionWork\StatamicAiAssistant\Services\OpenRouterService;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Support\ImageGeneration;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\GenerateImageTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

/**
 * Image generation: the images endpoint request and its model fallback, saving
 * the image as a labelled asset, and the generate_image tool inside (and
 * outside) an ImageGeneration scope.
 */
class ImageGenerationTest extends TestCase
{
    /** @var array<int, Request> */
    private array $requests = [];

    protected function tearDown(): void
    {
        // Saved containers outlive the test in the fixtures; other tests count containers.
        AssetContainer::find('media')?->delete();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic-ai-assistant.provider_name' => 'auto',
            'statamic-ai-assistant.openrouter_api_key' => 'sk-or-test',
            'statamic-ai-assistant.ai_http_retry_times' => 1,
            'statamic-ai-assistant.openrouter_profiles.image' => ['models' => 'flux/klein, google/flash-image'],
            'statamic-ai-assistant.openrouter_provider' => ['data_collection' => 'deny', 'only' => 'black-forest-labs', 'sort' => 'price'],
            'statamic-ai-assistant.image_generation' => [
                'enabled' => true,
                'folder' => 'ai-generated',
                'max_per_entry' => 2,
                'aspect_ratio' => '16:9',
                'resolution' => '1K',
                'output_format' => 'jpeg',
            ],
        ]);
    }

    /**
     * @param  array<int, array{0: int, 1: array<string, mixed>}>  $replies  One [status, body] per request, in order.
     */
    private function fakeImages(array $replies): void
    {
        Http::fake(function (Request $request) use (&$replies) {
            $this->requests[] = $request;
            [$status, $body] = array_shift($replies) ?? [500, []];

            return Http::response($body, $status, $status === 200 ? ['X-Generation-Id' => 'gen-img-1'] : []);
        });
    }

    /** @return array<string, mixed> */
    private function imageReply(string $bytes = 'jpeg-bytes'): array
    {
        return [
            'created' => 1,
            'data' => [['b64_json' => base64_encode($bytes), 'media_type' => 'image/jpeg']],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 4000, 'total_tokens' => 4030, 'cost' => 0.009],
        ];
    }

    public function test_it_posts_one_image_request_and_reports_its_cost(): void
    {
        Event::fake([AiCompletionFinished::class]);
        $this->fakeImages([[200, $this->imageReply()]]);

        $image = (new OpenRouterService)->generateImage('An oak table', ['aspect_ratio' => '1:1']);

        $this->assertSame('jpeg-bytes', $image->bytes);
        $this->assertSame('jpg', $image->extension());
        $this->assertSame('flux/klein', $image->model);

        $this->assertCount(1, $this->requests);
        $this->assertSame('https://openrouter.ai/api/v1/images', $this->requests[0]->url());
        $payload = $this->requests[0]->data();
        $this->assertSame('flux/klein', $payload['model']);
        $this->assertSame('An oak table', $payload['prompt']);
        $this->assertSame(1, $payload['n']);
        $this->assertSame('1:1', $payload['aspect_ratio']);
        $this->assertSame('1K', $payload['resolution']);
        $this->assertSame('jpeg', $payload['output_format']);
        // The images endpoint's provider object knows only / ignore / sort, not the privacy fields.
        $this->assertSame(['only' => ['black-forest-labs'], 'sort' => 'price'], $payload['provider']);
        $this->assertArrayNotHasKey('user', $payload, 'no CP user signed in');

        Event::assertDispatched(AiCompletionFinished::class, fn (AiCompletionFinished $e) => $e->task === AiTask::Image
            && $e->provider === 'openrouter'
            && $e->requestedModels === ['flux/klein', 'google/flash-image']
            && $e->model === 'flux/klein'
            && $e->generationId === 'gen-img-1'
            && $e->cost() === 0.009);
    }

    public function test_a_model_error_falls_back_to_the_next_model(): void
    {
        $this->fakeImages([
            [400, ['error' => ['code' => 400, 'message' => 'output_format: not supported']]],
            [200, $this->imageReply('png-bytes')],
        ]);

        $image = (new OpenRouterService)->generateImage('An oak table');

        $this->assertSame('google/flash-image', $image->model);
        $this->assertSame(['flux/klein', 'google/flash-image'], array_map(fn (Request $r) => $r->data()['model'], $this->requests));
    }

    public function test_a_credit_refusal_is_not_retried_with_another_model(): void
    {
        $this->fakeImages([[402, ['error' => ['code' => 402, 'message' => 'Insufficient credits']]]]);

        try {
            (new OpenRouterService)->generateImage('An oak table');
            $this->fail('Expected an AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertSame(402, $e->status);
            $this->assertStringContainsString('out of credits', $e->getMessage());
        }

        $this->assertCount(1, $this->requests);
    }

    public function test_the_image_is_saved_as_a_labelled_asset_in_the_generated_folder(): void
    {
        $this->makeContainer();
        $saved = $this->store('Oak table in a sunlit workshop')->save(
            new GeneratedImage($this->jpeg(), 'image/jpeg', 'flux/klein'),
            'media',
            'Oak table in a sunlit workshop, warm light',
        );

        $this->assertSame('media', $saved['container']);
        $this->assertStringStartsWith('ai-generated/oak-table-in-a-sunlit-workshop-warm-light-', $saved['path']);
        $this->assertStringEndsWith('.jpg', $saved['path']);
        Storage::disk('gen_assets')->assertExists($saved['path']);

        $asset = Asset::find('media::'.$saved['path']);
        $this->assertTrue($asset->get('ai_generated'));
        $this->assertSame('Oak table in a sunlit workshop, warm light', $asset->get('ai_prompt'));
        $this->assertSame('Oak table in a sunlit workshop', $asset->get('alt'));
    }

    public function test_the_tool_saves_the_image_into_the_scope_queue(): void
    {
        $this->makeContainer();
        $ai = $this->createStub(ImageCapableAiService::class);
        $ai->method('supportsImageGeneration')->willReturn(true);
        $ai->method('generateImage')->willReturn(new GeneratedImage($this->jpeg(), 'image/jpeg', 'flux/klein'));
        $this->app->instance(AbstractAiService::class, $ai);

        $sink = new PreferredAssetPaths;
        $activity = [];
        $context = new ToolContext(activitySink: function (string $line) use (&$activity) {
            $activity[] = $line;
        });
        $tool = new GenerateImageTool($ai, $this->store('A hero image'));

        $result = ImageGeneration::during($sink, 'media', fn () => $tool->handle('{"prompt":"A hero image","aspect_ratio":"16:9","field":"hero"}', $context));

        $this->assertTrue($result['ok']);
        $this->assertSame('hero', $result['field']);
        $this->assertSame([['container' => 'media', 'path' => $result['path']]], $sink->remainingEntries());
        $this->assertSame('Generating image…', $activity[0]);
        $this->assertSame(2, $tool->maxCalls());
    }

    public function test_the_tool_does_nothing_outside_a_scope_or_when_switched_off(): void
    {
        $ai = $this->createStub(ImageCapableAiService::class);
        $ai->method('supportsImageGeneration')->willReturn(true);
        $this->app->instance(AbstractAiService::class, $ai);
        $tool = new GenerateImageTool($ai, $this->store(''));

        $outside = $tool->handle('{"prompt":"A hero image"}', new ToolContext);
        $this->assertFalse($outside['ok']);

        config(['statamic-ai-assistant.image_generation.enabled' => false]);
        $switchedOff = ImageGeneration::during(new PreferredAssetPaths, 'media', fn () => ImageGeneration::scope());
        $this->assertNull($switchedOff);

        config(['statamic-ai-assistant.image_generation.enabled' => true]);
        $noImageField = ImageGeneration::during(new PreferredAssetPaths, null, fn () => ImageGeneration::scope());
        $this->assertNull($noImageField, 'an entry without an image field gets no scope');
    }

    public function test_a_budget_refusal_reaches_the_editor_as_a_warning(): void
    {
        $ai = $this->createStub(ImageCapableAiService::class);
        $ai->method('supportsImageGeneration')->willReturn(true);
        $ai->method('generateImage')->willThrowException(new AiProviderException('The OpenRouter budget for this site is used up.', 403, 'openrouter_key_limit'));
        $this->app->instance(AbstractAiService::class, $ai);

        $warnings = [];
        $context = new ToolContext(warningSink: function (string $w) use (&$warnings) {
            $warnings[] = $w;
        });
        $tool = new GenerateImageTool($ai, $this->store(''));

        $result = ImageGeneration::during(new PreferredAssetPaths, 'media', fn () => $tool->handle('{"prompt":"A hero image"}', $context));

        $this->assertFalse($result['ok']);
        $this->assertSame(['The OpenRouter budget for this site is used up.'], $warnings);
    }

    public function test_image_generation_needs_the_openrouter_provider(): void
    {
        $this->assertTrue(ImageGeneration::enabled());

        config(['statamic-ai-assistant.provider_name' => 'infomaniak', 'statamic-ai-assistant.infomaniak_api_token' => 'token']);
        $this->app->forgetInstance(AbstractAiService::class);

        $this->assertFalse(ImageGeneration::enabled());
        $this->assertStringContainsString('OpenRouter', (string) ImageGeneration::unavailableReason());
    }

    private function store(string $alt): GeneratedImageStore
    {
        $altText = $this->createStub(ImageAltTextGenerator::class);
        $altText->method('forImageBytes')->willReturn($alt !== '' ? $alt : null);

        return new GeneratedImageStore(new AssetImageDownloader($altText), $altText);
    }

    /** A real 4×4 JPEG: Statamic decodes uploads to read their size. */
    private function jpeg(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function makeContainer(): void
    {
        config(['filesystems.disks.gen_assets' => [
            'driver' => 'local',
            'root' => Storage::fake('gen_assets')->path(''),
        ]]);
        AssetContainer::make('media')->disk('gen_assets')->title('Media')->save();
    }
}
