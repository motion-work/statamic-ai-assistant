<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use MotionWork\StatamicAiAssistant\Mcp\AssetAltLocaleResolver;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Site;

class AssetAltLocaleResolverTest extends TestCase
{
    public function test_maps_default_locale_to_alt_and_others_to_suffixed_handles(): void
    {
        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => '/'],
            'fr' => ['name' => 'Français', 'locale' => 'fr_FR', 'url' => '/fr/'],
        ]);

        $resolver = new AssetAltLocaleResolver;
        $result = $resolver->map(['de', 'fr'], ['alt', 'alt_fr', 'caption']);

        $this->assertSame('alt', $result['locale_handles']['de']);
        $this->assertSame('alt_fr', $result['locale_handles']['fr']);
        $this->assertSame(['alt', 'alt_fr'], $result['alt_fields']);
    }
}
