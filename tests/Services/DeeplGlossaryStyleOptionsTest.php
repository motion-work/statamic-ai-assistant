<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\DeeplService;
use BoldWeb\StatamicAiAssistant\Services\TranslationGlossaryService;
use BoldWeb\StatamicAiAssistant\Services\TranslationStyleRulesService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

/**
 * Verifies how CP-managed glossary + style rules are planned into DeepL request
 * options across every translation path (page, bulk, field, Bard, navigation)
 * — they all funnel through translateBatch → planTranslationSegments.
 *
 * Key rule (DeepL): a glossary is only enforced ("hard") on the classic model
 * (latency_optimized); any style_id forces the next-gen model, which downgrades
 * glossaries to soft hints. So when a term AND a style rule both apply, the
 * batch is split: term-bearing text → hard glossary, the rest → style rule.
 */
class DeeplGlossaryStyleOptionsTest extends TestCase
{
    private DeeplService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('statamic-ai-assistant.translation_glossary_path', sys_get_temp_dir().'/gl-opt-'.uniqid().'.yaml');
        config()->set('statamic-ai-assistant.translation_style_rules_path', sys_get_temp_dir().'/sr-opt-'.uniqid().'.yaml');

        $this->service = new DeeplService;
    }

    /**
     * @return array<int, array{options: array<string, mixed>, positions: array<int, int>}>
     */
    private function plan(array $texts, array $options, ?string $source, string $target): array
    {
        $method = new \ReflectionMethod($this->service, 'planTranslationSegments');
        $method->setAccessible(true);

        return $method->invoke($this->service, $texts, $options, $source, $target);
    }

    private function bindGlossary(?string $idForDeFr, array $termsForDeFr = []): void
    {
        $fake = new class($idForDeFr, $termsForDeFr) extends TranslationGlossaryService
        {
            public function __construct(private ?string $id, private array $terms)
            {
                // Skip parent constructor — lookups are stubbed.
            }

            public function glossaryIdFor(string $sourceBase, string $targetBase): ?string
            {
                return ($sourceBase === 'de' && $targetBase === 'fr') ? $this->id : null;
            }

            public function sourceTermsFor(string $sourceBase, string $targetBase): array
            {
                return ($sourceBase === 'de' && $targetBase === 'fr') ? $this->terms : [];
            }
        };

        $this->app->instance(TranslationGlossaryService::class, $fake);
    }

    private function bindStyleRules(?string $idForFr): void
    {
        $fake = new class($idForFr) extends TranslationStyleRulesService
        {
            public function __construct(private ?string $id)
            {
                // Skip parent constructor — lookups are stubbed.
            }

            public function styleIdFor(string $targetBase): ?string
            {
                return $targetBase === 'fr' ? $this->id : null;
            }
        };

        $this->app->instance(TranslationStyleRulesService::class, $fake);
    }

    public function test_glossary_only_uses_hard_classic_model_single_segment(): void
    {
        $this->bindGlossary('gl-1', ['Apartment']);
        $this->bindStyleRules(null);

        $segments = $this->plan(['Das Apartment ist schön.'], ['tag_handling' => 'html'], 'de', 'fr-CH');

        $this->assertCount(1, $segments);
        $this->assertSame('gl-1', $segments[0]['options']['glossary']);
        $this->assertSame('latency_optimized', $segments[0]['options']['model_type']);
        $this->assertArrayNotHasKey('style_id', $segments[0]['options']);
        $this->assertSame([0], $segments[0]['positions']);
    }

    public function test_style_only_uses_next_gen_model_single_segment(): void
    {
        $this->bindGlossary(null);
        $this->bindStyleRules('st-1');

        $segments = $this->plan(['Bonjour'], [], 'de', 'fr-CH');

        $this->assertCount(1, $segments);
        $this->assertSame('st-1', $segments[0]['options']['style_id']);
        $this->assertArrayNotHasKey('glossary', $segments[0]['options']);
        $this->assertArrayNotHasKey('model_type', $segments[0]['options']);
    }

    public function test_glossary_and_style_split_by_term_presence(): void
    {
        $this->bindGlossary('gl-1', ['Apartment']);
        $this->bindStyleRules('st-1');

        $texts = [
            'Das Apartment am See.',   // 0 — has term
            'Willkommen bei uns.',      // 1 — no term
            'Unser Apartment ist toll.', // 2 — has term
        ];

        $segments = $this->plan($texts, ['tag_handling' => 'html'], 'de', 'fr-CH');

        $this->assertCount(2, $segments);

        // Hard-glossary segment: the two term-bearing texts, classic model, no style.
        $hard = $segments[0];
        $this->assertSame([0, 2], $hard['positions']);
        $this->assertSame('gl-1', $hard['options']['glossary']);
        $this->assertSame('latency_optimized', $hard['options']['model_type']);
        $this->assertArrayNotHasKey('style_id', $hard['options']);

        // Style segment: the remaining text keeps the style rule.
        $style = $segments[1];
        $this->assertSame([1], $style['positions']);
        $this->assertSame('st-1', $style['options']['style_id']);
        $this->assertArrayNotHasKey('model_type', $style['options']);
    }

    public function test_glossary_and_style_with_no_term_present_keeps_style_single_segment(): void
    {
        $this->bindGlossary('gl-1', ['Apartment']);
        $this->bindStyleRules('st-1');

        $segments = $this->plan(['Willkommen bei uns.', 'Schöne Ferien!'], [], 'de', 'fr-CH');

        $this->assertCount(1, $segments);
        $this->assertSame('st-1', $segments[0]['options']['style_id']);
        $this->assertSame([0, 1], $segments[0]['positions']);
        $this->assertArrayNotHasKey('model_type', $segments[0]['options']);
    }

    public function test_prefer_style_config_keeps_single_request(): void
    {
        config()->set('statamic-ai-assistant.prefer_glossary_over_style', false);

        $this->bindGlossary('gl-1', ['Apartment']);
        $this->bindStyleRules('st-1');

        $segments = $this->plan(['Das Apartment am See.'], [], 'de', 'fr-CH');

        $this->assertCount(1, $segments);
        // Glossary rides along but the style rule is applied (soft glossary).
        $this->assertSame('st-1', $segments[0]['options']['style_id']);
        $this->assertSame('gl-1', $segments[0]['options']['glossary']);
    }

    public function test_glossary_requires_known_source_language(): void
    {
        $this->bindGlossary('gl-1', ['Apartment']);
        $this->bindStyleRules('st-1');

        // No source language → glossary cannot be attached; style still applies.
        $segments = $this->plan(['Das Apartment am See.'], [], null, 'fr-CH');

        $this->assertCount(1, $segments);
        $this->assertArrayNotHasKey('glossary', $segments[0]['options']);
        $this->assertSame('st-1', $segments[0]['options']['style_id']);
    }

    public function test_explicit_caller_options_are_never_overridden(): void
    {
        $this->bindGlossary('gl-1', ['Apartment']);
        $this->bindStyleRules('st-1');

        $segments = $this->plan(
            ['Das Apartment am See.'],
            ['glossary' => 'custom', 'style_id' => 'custom-style'],
            'de',
            'fr-CH',
        );

        $this->assertCount(1, $segments);
        $this->assertSame('custom', $segments[0]['options']['glossary']);
        $this->assertSame('custom-style', $segments[0]['options']['style_id']);
    }

    public function test_nothing_configured_is_a_plain_single_segment(): void
    {
        $this->bindGlossary(null);
        $this->bindStyleRules(null);

        $segments = $this->plan(['Hallo Welt'], ['tag_handling' => 'html'], 'de', 'fr-CH');

        $this->assertCount(1, $segments);
        $this->assertArrayNotHasKey('glossary', $segments[0]['options']);
        $this->assertArrayNotHasKey('style_id', $segments[0]['options']);
        $this->assertSame(['tag_handling' => 'html'], $segments[0]['options']);
    }
}
