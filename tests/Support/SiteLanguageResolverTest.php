<?php

namespace MotionWork\StatamicAiAssistant\Tests\Support;

use MotionWork\StatamicAiAssistant\Support\SiteLanguageResolver;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Site;

class SiteLanguageResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => 'http://localhost/'],
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => 'http://localhost/en/'],
            'fr' => ['name' => 'Français', 'locale' => 'fr_CH', 'url' => 'http://localhost/fr/'],
        ]);
    }

    public function test_language_names_and_locales_resolve_to_one_site(): void
    {
        $resolver = new SiteLanguageResolver;

        $this->assertSame('en', $resolver->resolve('English')['site']->handle());
        $this->assertSame('en', $resolver->resolve('en-US')['site']->handle());
        $this->assertSame('en', $resolver->resolve('englisch')['site']->handle());
        $this->assertSame('de', $resolver->resolve('Deutsch')['site']->handle());
        $this->assertSame('fr', $resolver->resolve('français')['site']->handle());
    }

    public function test_unknown_language_names_the_available_sites(): void
    {
        $resolved = (new SiteLanguageResolver)->resolve('Klingon');

        $this->assertSame('unknown_site', $resolved['error']);
        $this->assertStringContainsString('en (English, en_US)', $resolved['message']);
    }

    public function test_a_language_with_two_sites_is_ambiguous(): void
    {
        Site::setSites([
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => 'http://localhost/'],
            'uk' => ['name' => 'British', 'locale' => 'en_GB', 'url' => 'http://localhost/uk/'],
        ]);

        $resolved = (new SiteLanguageResolver)->resolve('english');

        $this->assertSame('en', $resolved['site']->handle());

        $ambiguous = (new SiteLanguageResolver)->resolve('englisch');
        $this->assertSame('ambiguous_site', $ambiguous['error']);
    }
}
