<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Services\EntryReferenceResolver;
use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * localizable: false fields and translation: the force-list (with wildcards),
 * the translate_non_localizable_text switch, and the skip bookkeeping. This is
 * the "Eckdaten stayed German" case — a prefixed fieldset import whose grid
 * and sub-fields are all marked localizable: false.
 */
class NonLocalizableTranslationTest extends TestCase
{
    private EntryTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new EntryTranslator($this->createStub(DeeplService::class), new EntryReferenceResolver);
    }

    private function gate(string $handle, ?array $fieldDef): bool
    {
        $ref = new \ReflectionMethod(EntryTranslator::class, 'shouldForceTranslateField');
        $ref->setAccessible(true);

        return $ref->invoke($this->translator, $handle, $fieldDef);
    }

    public function test_exact_force_handles_still_match(): void
    {
        config(['deepl.force_translate_handles' => ['hero_title']]);

        $this->assertTrue($this->gate('hero_title', ['type' => 'text']));
        $this->assertFalse($this->gate('other_title', ['type' => 'text']));
    }

    public function test_wildcard_force_handles_match_prefixed_imports(): void
    {
        config(['deepl.force_translate_handles' => ['key_facts_*']]);

        $this->assertTrue($this->gate('key_facts_numbers', ['type' => 'grid']));
        $this->assertTrue($this->gate('key_facts_title', ['type' => 'text']));
        $this->assertFalse($this->gate('other_numbers', ['type' => 'grid']));
    }

    public function test_switch_forces_text_bearing_types_only(): void
    {
        config(['deepl.force_translate_handles' => []]);
        config(['deepl.translate_non_localizable_text' => true]);

        $this->assertTrue($this->gate('key_facts_numbers', ['type' => 'grid']));
        $this->assertTrue($this->gate('lead', ['type' => 'bard']));
        $this->assertTrue($this->gate('subtitle', ['type' => 'text']));

        // Genuinely shared, non-text fields keep inheriting from the origin.
        $this->assertFalse($this->gate('images', ['type' => 'assets']));
        $this->assertFalse($this->gate('published_at', ['type' => 'date']));
        $this->assertFalse($this->gate('related', ['type' => 'entries']));
    }

    public function test_skipped_text_fields_are_recorded_for_warnings(): void
    {
        config(['deepl.force_translate_handles' => []]);
        config(['deepl.translate_non_localizable_text' => false]);

        $this->assertFalse($this->gate('key_facts_numbers', ['type' => 'grid']));
        $this->assertFalse($this->gate('images', ['type' => 'assets']));

        // Only the text-bearing skip is reported; drained after taking.
        $this->assertSame(['key_facts_numbers'], $this->translator->takeSkippedNonLocalizable());
        $this->assertSame([], $this->translator->takeSkippedNonLocalizable());
    }
}
