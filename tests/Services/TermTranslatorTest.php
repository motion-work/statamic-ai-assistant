<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Services\TermTranslator;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

class TermTranslatorTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::default()->handle();

        Blueprint::make('adv_tag')->setNamespace('taxonomies.adv_tags')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'localizable' => true]],
                ['handle' => 'description', 'field' => ['type' => 'textarea', 'localizable' => true]],
                ['handle' => 'icon', 'field' => ['type' => 'text', 'localizable' => false]],
            ]]]]],
        ])->save();

        Taxonomy::make('adv_tags')->title('Adv Tags')->save();
    }

    protected function tearDown(): void
    {
        Term::query()->where('taxonomy', 'adv_tags')->get()->each(fn ($t) => $t->delete());
        foreach (Blueprint::in('taxonomies.adv_tags')->all() as $bp) {
            $bp->delete();
        }
        Taxonomy::find('adv_tags')?->delete();

        parent::tearDown();
    }

    public function test_translates_localizable_term_fields_and_keeps_non_localizable(): void
    {
        $term = Term::make()->taxonomy('adv_tags')->slug('wellness');
        $term->dataForLocale($this->site, [
            'title' => 'Wellness',
            'description' => 'Alles rund um Erholung.',
            'icon' => 'spa',
        ]);
        $term->save();

        $deepl = $this->createStub(DeeplService::class);
        $deepl->method('translateBatch')->willReturnCallback(
            fn (array $texts) => array_map(fn ($t) => 'FR:'.$t, $texts),
        );

        $translator = new TermTranslator($deepl);
        $localized = $translator->translateTerm(Term::find('adv_tags::wellness'), $this->site, $this->site);

        $this->assertSame('FR:Wellness', $localized->value('title'));
        $this->assertSame('FR:Alles rund um Erholung.', $localized->value('description'));
        // Non-localizable fields are inherited, never machine-translated —
        // and reported so the run can warn about text-bearing ones.
        $this->assertNotSame('FR:spa', $localized->value('icon'));
        $this->assertSame(['icon'], $translator->takeSkippedNonLocalizable());
    }
}
