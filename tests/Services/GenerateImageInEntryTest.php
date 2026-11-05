<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Support\Facades\Storage;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\GeneratedImage;
use MotionWork\StatamicAiAssistant\Services\AssetImageDownloader;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\GeneratedImageStore;
use MotionWork\StatamicAiAssistant\Services\ImageAltTextGenerator;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * generate_image inside an entry generation: offered only while the site
 * switch is on, and the image lands on the entry's image field on the create
 * and the update path.
 */
class GenerateImageInEntryTest extends TestCase
{
    /** @var array<int, array<int, string>> tool names offered per model call */
    private array $offered = [];

    private bool $callTool = true;

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
            'statamic-ai-assistant.image_generation.enabled' => true,
            'filesystems.disks.gen_entry_assets' => ['driver' => 'local', 'root' => Storage::fake('gen_entry_assets')->path('')],
        ]);
        AssetContainer::make('media')->disk('gen_entry_assets')->title('Media')->save();

        // Reuse the fixture 'packages' collection so the run leaves no new collection files.
        Blueprint::make('package')->setNamespace('collections.packages')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'hero', 'field' => ['type' => 'assets', 'container' => 'media', 'max_files' => 1]],
        ]]]]]])->save();
        Collection::make('packages')->title('Packages')->save();

        $this->app->instance(AbstractAiService::class, $this->fakeAi());
        $this->app->instance(GeneratedImageStore::class, $this->store());
        $this->app->forgetInstance(EntryGeneratorService::class);
    }

    public function test_a_new_entry_gets_the_generated_image(): void
    {
        $result = app(EntryGeneratorService::class)->generateContent('packages', 'package', 'Erstelle ein Hero-Bild einer Eichenholz-Werkstatt', 'default');

        $this->assertContains('generate_image', $this->offered[0]);
        $this->assertStringStartsWith('ai-generated/', (string) $result['data']['hero']);
    }

    public function test_an_update_sets_the_field_to_the_generated_image(): void
    {
        $entry = Entry::make()->collection('packages')->locale('default')->slug('tea')->data(['title' => 'Tea']);
        $entry->save();

        $result = app(EntryGeneratorService::class)->generateUpdateForEntry((string) $entry->id(), 'Erstelle ein neues Hero-Bild', 'default');

        $this->assertContains('generate_image', $this->offered[0]);
        $this->assertStringStartsWith('ai-generated/', (string) $result['data']['hero']);
    }

    public function test_the_tool_is_not_offered_while_the_switch_is_off(): void
    {
        config(['statamic-ai-assistant.image_generation.enabled' => false]);
        $this->callTool = false;

        app(EntryGeneratorService::class)->generateContent('packages', 'package', 'Eine Seite über Tee', 'default');

        $this->assertNotContains('generate_image', $this->offered[0]);
    }

    /**
     * A model that calls generate_image once, then answers with the entry's
     * JSON, setting `hero` to the path the tool returned.
     */
    private function fakeAi(): AbstractAiService
    {
        $ai = $this->createStub(ImageCapableAiService::class);
        $ai->method('supportsChatTools')->willReturn(true);
        $ai->method('supportsImageGeneration')->willReturn(true);
        $ai->method('cleanResult')->willReturnArgument(0);
        $ai->method('generateImage')->willReturnCallback(fn () => new GeneratedImage($this->jpeg(), 'image/jpeg', 'flux/klein'));
        $ai->method('createChatCompletion')->willReturnCallback(function (array $messages, $max, array $tools) {
            $this->offered[] = array_map(fn ($tool) => $tool['function']['name'] ?? $tool['type'], $tools);
            $last = end($messages);

            if ($this->callTool && ($last['role'] ?? '') !== 'tool') {
                return ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'call-1',
                    'type' => 'function',
                    'function' => ['name' => 'generate_image', 'arguments' => '{"prompt":"An oak workshop","field":"hero"}'],
                ]]]]]];
            }

            $path = ($last['role'] ?? '') === 'tool' ? (json_decode((string) $last['content'], true)['path'] ?? '') : '';

            return ['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(array_filter(['title' => 'Werkstatt', 'hero' => $path]))]]]];
        });

        return $ai;
    }

    private function store(): GeneratedImageStore
    {
        $altText = $this->createStub(ImageAltTextGenerator::class);
        $altText->method('forImageBytes')->willReturn('An oak workshop');

        return new GeneratedImageStore(new AssetImageDownloader($altText), $altText);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
