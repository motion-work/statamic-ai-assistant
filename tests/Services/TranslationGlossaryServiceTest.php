<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\DeeplService;
use BoldWeb\StatamicAiAssistant\Services\TranslationGlossaryService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

class FakeGlossaryDeeplService extends DeeplService
{
    public array $created = [];

    public array $deleted = [];

    public string $nextId = 'gl-123';

    public function createGlossaryOnDeepL(string $name, array $dictionaries, ?string $previousId = null): string
    {
        if ($previousId !== null) {
            $this->deleted[] = $previousId;
        }

        $this->created[] = ['name' => $name, 'dictionaries' => $dictionaries];

        return $this->nextId;
    }

    public function deleteGlossaryOnDeepL(string $glossaryId): void
    {
        $this->deleted[] = $glossaryId;
    }
}

class TranslationGlossaryServiceTest extends TestCase
{
    private string $path;

    private FakeGlossaryDeeplService $deepl;

    private TranslationGlossaryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/glossary-test-'.uniqid().'.yaml';
        config()->set('statamic-ai-assistant.translation_glossary_path', $this->path);

        $this->deepl = new FakeGlossaryDeeplService;
        $this->service = new TranslationGlossaryService($this->deepl);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_save_normalizes_terms_and_drops_empty_rows(): void
    {
        $saved = $this->service->save([
            ['terms' => ['de' => "  Zimmer\t", 'en' => 'Room']],
            ['terms' => ['de' => '', 'en' => '   ']],
            ['id' => 'keep-me', 'terms' => ['DE' => 'Suite']],
        ]);

        $this->assertCount(2, $saved);
        $this->assertSame(['de' => 'Zimmer', 'en' => 'Room'], $saved[0]['terms']);
        $this->assertSame('keep-me', $saved[1]['id']);
        $this->assertSame(['de' => 'Suite'], $saved[1]['terms']);
    }

    public function test_dictionaries_are_built_per_ordered_pair_with_complete_rows_only(): void
    {
        $dictionaries = $this->service->buildDictionaries([
            ['id' => '1', 'terms' => ['de' => 'Zimmer', 'en' => 'Room', 'fr' => 'Chambre']],
            ['id' => '2', 'terms' => ['de' => 'Frühstück', 'en' => 'Breakfast']],
        ]);

        $pairs = array_map(fn ($d) => $d['source_lang'].'>'.$d['target_lang'], $dictionaries);

        // de/en both rows; fr pairs only row 1.
        $this->assertContains('de>en', $pairs);
        $this->assertContains('en>de', $pairs);
        $this->assertContains('de>fr', $pairs);
        $this->assertContains('fr>en', $pairs);
        $this->assertCount(6, $dictionaries);

        $deEn = collect($dictionaries)->first(fn ($d) => $d['source_lang'] === 'de' && $d['target_lang'] === 'en');
        $this->assertSame(['Zimmer' => 'Room', 'Frühstück' => 'Breakfast'], $deEn['entries']);

        $frEn = collect($dictionaries)->first(fn ($d) => $d['source_lang'] === 'fr' && $d['target_lang'] === 'en');
        $this->assertSame(['Chambre' => 'Room'], $frEn['entries']);
    }

    public function test_sync_creates_glossary_and_persists_id(): void
    {
        $this->service->save([
            ['terms' => ['de' => 'Zimmer', 'en' => 'Room']],
        ]);

        $warnings = $this->service->sync();

        $this->assertSame([], $warnings);
        $this->assertCount(1, $this->deepl->created);
        $this->assertSame('gl-123', $this->service->glossaryId());

        // Persisted: a fresh service instance reads the same id.
        $fresh = new TranslationGlossaryService($this->deepl);
        $this->assertSame('gl-123', $fresh->glossaryId());
    }

    public function test_sync_with_no_entries_deletes_existing_glossary(): void
    {
        $this->service->save([['terms' => ['de' => 'Zimmer', 'en' => 'Room']]]);
        $this->service->sync();

        $this->service->save([]);
        $this->service->sync();

        $this->assertContains('gl-123', $this->deepl->deleted);
        $this->assertNull($this->service->glossaryId());
    }

    public function test_glossary_id_for_returns_id_only_for_covered_pairs(): void
    {
        $this->service->save([
            ['terms' => ['de' => 'Zimmer', 'en' => 'Room']],
        ]);
        $this->service->sync();

        $this->assertSame('gl-123', $this->service->glossaryIdFor('de', 'en'));
        $this->assertSame('gl-123', $this->service->glossaryIdFor('en', 'de'));
        $this->assertNull($this->service->glossaryIdFor('de', 'fr'));
        $this->assertNull($this->service->glossaryIdFor('de', 'de'));
    }
}
