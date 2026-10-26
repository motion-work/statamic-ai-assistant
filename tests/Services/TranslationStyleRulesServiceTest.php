<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\DeeplService;
use BoldWeb\StatamicAiAssistant\Services\TranslationStyleRulesService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

class FakeStyleRulesDeeplService extends DeeplService
{
    /** @var array<int, array{name: string, language: string, instructions: array<int, string>}> */
    public array $created = [];

    /** @var array<int, string> */
    public array $deleted = [];

    public int $counter = 0;

    /** @var array<int, array{style_id: string, name: string, language: string}> */
    public array $remote = [];

    public function createStyleRuleOnDeepL(string $name, string $language, array $instructions): string
    {
        $id = 'style-'.$language.'-'.(++$this->counter);
        $this->created[] = ['name' => $name, 'language' => $language, 'instructions' => $instructions];
        $this->remote[] = ['style_id' => $id, 'name' => $name, 'language' => $language];

        return $id;
    }

    public function deleteStyleRuleOnDeepL(string $styleRuleId): void
    {
        $this->deleted[] = $styleRuleId;
        $this->remote = array_values(array_filter($this->remote, fn ($r) => $r['style_id'] !== $styleRuleId));
    }

    public function listStyleRulesOnDeepL(): array
    {
        return $this->remote;
    }
}

class TranslationStyleRulesServiceTest extends TestCase
{
    private string $path;

    private FakeStyleRulesDeeplService $deepl;

    private TranslationStyleRulesService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/style-rules-test-'.uniqid().'.yaml';
        config()->set('statamic-ai-assistant.translation_style_rules_path', $this->path);
        config()->set('app.name', 'Test Site');

        $this->deepl = new FakeStyleRulesDeeplService;
        $this->service = new TranslationStyleRulesService($this->deepl);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_save_and_sync_create_style_rules_per_language(): void
    {
        $this->service->save([
            'de' => ['Formelle Anrede (Sie).'],
            'en' => ['Use British spelling.'],
            'fr' => [''],
        ]);

        $warnings = $this->service->sync();

        $this->assertSame([], $warnings);
        $this->assertCount(2, $this->deepl->created);
        $this->assertSame('style-de-1', $this->service->styleIdFor('de'));
        $this->assertSame('style-en-2', $this->service->styleIdFor('en'));
        $this->assertNull($this->service->styleIdFor('fr'));

        // Persisted across instances.
        $fresh = new TranslationStyleRulesService($this->deepl);
        $this->assertSame('style-de-1', $fresh->styleIdFor('de'));
    }

    public function test_multiple_instructions_per_language_are_stored_and_sent(): void
    {
        $this->service->save([
            'de' => ['Formelle Anrede (Sie).', "Immer 'ss' statt 'ß'.", '  '],
        ]);

        $rules = $this->service->rules();
        $this->assertSame(
            ['Formelle Anrede (Sie).', "Immer 'ss' statt 'ß'."],
            $rules['de']['instructions'],
        );

        $this->service->sync();

        $this->assertCount(1, $this->deepl->created);
        $this->assertSame(
            ['Formelle Anrede (Sie).', "Immer 'ss' statt 'ß'."],
            $this->deepl->created[0]['instructions'],
        );
    }

    public function test_clearing_instructions_deletes_the_deepl_rule(): void
    {
        $this->service->save(['de' => ['Formelle Anrede (Sie).']]);
        $this->service->sync();

        $this->service->save(['de' => []]);
        $warnings = $this->service->sync();

        $this->assertSame([], $warnings);
        $this->assertContains('style-de-1', $this->deepl->deleted);
        $this->assertNull($this->service->styleIdFor('de'));
        $this->assertSame([], $this->deepl->listStyleRulesOnDeepL());
    }

    public function test_resync_replaces_the_previous_rule(): void
    {
        $this->service->save(['de' => ['Version 1']]);
        $this->service->sync();
        $this->service->save(['de' => ['Version 2']]);
        $this->service->sync();

        $this->assertContains('style-de-1', $this->deepl->deleted);
        $this->assertSame('style-de-2', $this->service->styleIdFor('de'));
        // Exactly one de rule remains on DeepL (no duplicates).
        $this->assertCount(1, array_filter($this->deepl->remote, fn ($r) => $r['language'] === 'de'));
    }

    public function test_sync_reconciles_orphan_duplicates_by_name(): void
    {
        // Simulate two managed FR rules already on DeepL (orphans from earlier
        // recreate-on-save runs) while local state only tracks one of them.
        $name = 'Statamic CMS — Test Site (fr)';
        $this->deepl->remote = [
            ['style_id' => 'orphan-1', 'name' => $name, 'language' => 'fr'],
            ['style_id' => 'orphan-2', 'name' => $name, 'language' => 'fr'],
            ['style_id' => 'unrelated', 'name' => 'Someone else', 'language' => 'fr'],
        ];

        // Local state knows only orphan-1 with fresh content.
        file_put_contents($this->path, "rules:\n  fr:\n    instructions:\n      - 'Neu'\n    style_id: orphan-1\n");
        $service = new TranslationStyleRulesService($this->deepl);

        $warnings = $service->sync();

        $this->assertSame([], $warnings);
        // Both managed orphans deleted.
        $this->assertContains('orphan-1', $this->deepl->deleted);
        $this->assertContains('orphan-2', $this->deepl->deleted);
        // Unmanaged rule left untouched.
        $this->assertNotContains('unrelated', $this->deepl->deleted);
        // Exactly one managed FR rule remains.
        $managedFr = array_filter($this->deepl->remote, fn ($r) => $r['name'] === $name);
        $this->assertCount(1, $managedFr);
    }

    public function test_empty_managed_orphan_is_cleaned_even_without_local_state(): void
    {
        // A managed EN rule lingers on DeepL but local state has nothing for it.
        $name = 'Statamic CMS — Test Site (en)';
        $this->deepl->remote = [
            ['style_id' => 'ghost', 'name' => $name, 'language' => 'en'],
        ];

        $warnings = $this->service->sync();

        $this->assertSame([], $warnings);
        $this->assertContains('ghost', $this->deepl->deleted);
        $this->assertSame([], $this->deepl->listStyleRulesOnDeepL());
    }

    public function test_legacy_string_instructions_are_normalized_to_array(): void
    {
        file_put_contents($this->path, "rules:\n  de:\n    instructions: 'Ein einzelner Satz.'\n    style_id: legacy-1\n");
        $service = new TranslationStyleRulesService($this->deepl);

        $rules = $service->rules();

        $this->assertSame(['Ein einzelner Satz.'], $rules['de']['instructions']);
        $this->assertSame('legacy-1', $service->styleIdFor('de'));
    }
}
