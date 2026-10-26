<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Services\EditorialGuidanceService;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

class EditorialGuidanceServiceTest extends TestCase
{
    private string $glossaryPath;

    private string $stylePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->glossaryPath = sys_get_temp_dir().'/eg-glossary-'.uniqid().'.yaml';
        $this->stylePath = sys_get_temp_dir().'/eg-style-'.uniqid().'.yaml';

        config([
            'statamic-ai-assistant.translation_glossary_path' => $this->glossaryPath,
            'statamic-ai-assistant.translation_style_rules_path' => $this->stylePath,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->glossaryPath);
        @unlink($this->stylePath);
        parent::tearDown();
    }

    private function guidance(): EditorialGuidanceService
    {
        $deepl = new DeeplService;
        $glossary = new TranslationGlossaryService($deepl);
        $style = new TranslationStyleRulesService($deepl);

        $glossary->save([
            ['terms' => ['de' => 'Zimmer', 'en' => 'Room', 'fr' => 'Chambre']],
            ['terms' => ['en' => 'Suite']], // no German wording — must be skipped for 'de'
        ]);
        $style->save([
            'de' => ['Formelle Anrede (Sie).', 'Aktiv statt passiv formulieren.'],
            'en' => ['Use British spelling.'],
        ]);

        return new EditorialGuidanceService($glossary, $style, $deepl);
    }

    public function test_it_builds_a_language_scoped_terminology_and_style_block(): void
    {
        $block = $this->guidance()->promptBlock('de');

        $this->assertStringContainsString('BRAND TERMINOLOGY', $block);
        $this->assertStringContainsString('"Zimmer"', $block);
        // Cross-language equivalents included as concept hints.
        $this->assertStringContainsString('en: Room', $block);
        $this->assertStringContainsString('fr: Chambre', $block);
        // Style rules for the target language only.
        $this->assertStringContainsString('Formelle Anrede (Sie).', $block);
        $this->assertStringContainsString('Aktiv statt passiv formulieren.', $block);

        // A row without a German term, and another language's style rules, stay out.
        $this->assertStringNotContainsString('Suite', $block);
        $this->assertStringNotContainsString('British', $block);
    }

    public function test_the_block_switches_with_the_target_language(): void
    {
        $block = $this->guidance()->promptBlock('en');

        $this->assertStringContainsString('"Room"', $block);
        $this->assertStringContainsString('"Suite"', $block);
        $this->assertStringContainsString('Use British spelling.', $block);
        $this->assertStringNotContainsString('Formelle Anrede', $block);
    }

    public function test_it_returns_empty_when_no_guidance_exists_for_the_language(): void
    {
        $this->assertSame('', $this->guidance()->promptBlock('it'));
    }

    public function test_it_returns_empty_for_a_blank_locale(): void
    {
        $this->assertSame('', $this->guidance()->promptBlock(''));
    }
}
