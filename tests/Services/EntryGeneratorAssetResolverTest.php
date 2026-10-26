<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\EntryGeneratorAssetResolver;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;

class EntryGeneratorAssetResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        AssetContainer::findByHandle('assets')?->delete();
        parent::tearDown();
    }

    private function fakeContainer(): void
    {
        config(['filesystems.disks.test_assets' => [
            'driver' => 'local',
            'root' => Storage::fake('test_assets')->path(''),
        ]]);

        AssetContainer::make('assets')->disk('test_assets')->title('Assets')->save();
        Storage::disk('test_assets')->put('events/mission.jpg', 'x');
    }

    /**
     * The core "use this image everywhere" guarantee for nested sections: an
     * assets field inside a replicator set that OMITS its `container` (valid in
     * Statamic when a single container exists) must still receive the preferred
     * image, not stay empty.
     */
    public function test_it_fills_a_container_less_assets_field_inside_a_replicator_set(): void
    {
        $this->fakeContainer();

        $blueprint = Blueprint::make('page')->setContents([
            'fields' => [
                ['handle' => 'main', 'field' => [
                    'type' => 'replicator',
                    'sets' => [
                        'main_group' => [
                            'sets' => [
                                'text_with_image' => [
                                    'fields' => [
                                        ['handle' => 'text', 'field' => ['type' => 'textarea']],
                                        // No `container` — relies on the sole container.
                                        ['handle' => 'image', 'field' => ['type' => 'assets', 'max_files' => 1]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
            ],
        ]);

        $data = ['main' => [['type' => 'text_with_image', 'text' => 'Hello']]];
        $displayData = $data;
        $warnings = [];

        $preferred = new PreferredAssetPaths([['container' => 'assets', 'path' => 'events/mission.jpg']]);

        (new EntryGeneratorAssetResolver)
            ->fillAssetFieldsWithRandom($data, $displayData, $blueprint, $warnings, $preferred);

        $this->assertSame('events/mission.jpg', $data['main'][0]['image'] ?? null);
    }

    /**
     * An explicitly configured container must always win over the sole-container
     * fallback, so multi-container sites keep steering each field to its own
     * container.
     */
    public function test_explicit_container_is_respected_even_with_multiple_containers(): void
    {
        $this->fakeContainer();

        config(['filesystems.disks.test_other' => [
            'driver' => 'local',
            'root' => Storage::fake('test_other')->path(''),
        ]]);
        AssetContainer::make('other')->disk('test_other')->title('Other')->save();
        Storage::disk('test_other')->put('x.jpg', 'x');

        $blueprint = Blueprint::make('page')->setContents([
            'fields' => [
                ['handle' => 'hero', 'field' => [
                    'type' => 'assets',
                    'container' => 'assets',
                    'max_files' => 1,
                ]],
            ],
        ]);

        $data = [];
        $displayData = [];
        $warnings = [];

        $preferred = new PreferredAssetPaths([['container' => 'assets', 'path' => 'events/mission.jpg']]);

        (new EntryGeneratorAssetResolver)
            ->fillAssetFieldsWithRandom($data, $displayData, $blueprint, $warnings, $preferred);

        AssetContainer::findByHandle('other')?->delete();

        $this->assertSame('events/mission.jpg', $data['hero'] ?? null);
    }
}
