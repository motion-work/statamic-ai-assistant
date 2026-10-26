<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class EntriesFieldResolutionTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::default()->handle();
        $this->wipeEntries();

        Blueprint::make('plane')->setNamespace('collections.planes')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'related', 'field' => ['type' => 'entries', 'collections' => ['planes']]],
                ['handle' => 'related_one', 'field' => ['type' => 'entries', 'collections' => ['planes'], 'max_items' => 1]],
            ]]]]],
        ])->save();

        Collection::make('planes')->title('Planes')->sites([$this->site])->save();

        foreach (['Boeing' => 'boeing', 'Salt & Stone' => 'salt-stone'] as $title => $slug) {
            Entry::make()->collection('planes')->locale($this->site)->slug($slug)->data(['title' => $title])->save();
        }
    }

    private function map(string $fieldHandle, mixed $value, array &$warnings): mixed
    {
        $service = app(EntryGeneratorService::class);
        $field = Blueprint::find('collections.planes.plane')->field($fieldHandle);
        $m = new \ReflectionMethod($service, 'mapEntriesFieldValue');
        $m->setAccessible(true);

        $args = [$value, $field, &$warnings];

        return $m->invokeArgs($service, $args);
    }

    private function boeingId(): string
    {
        return (string) Entry::query()->where('collection', 'planes')->get()
            ->first(fn ($e) => $e->value('title') === 'Boeing')->id();
    }

    public function test_resolves_an_existing_entry_title_to_its_id(): void
    {
        $warnings = [];
        $result = $this->map('related', ['Boeing'], $warnings);

        $this->assertSame([$this->boeingId()], $result);
        $this->assertSame([], $warnings);
    }

    public function test_matches_ignoring_case_and_punctuation(): void
    {
        $warnings = [];
        // "salt and stone" must resolve to the "Salt & Stone" entry.
        $result = $this->map('related', ['salt and stone'], $warnings);

        $this->assertCount(1, $result);
        $this->assertSame([], $warnings);
    }

    public function test_single_max_items_returns_a_string_not_an_array(): void
    {
        $warnings = [];
        $result = $this->map('related_one', 'Boeing', $warnings);

        $this->assertSame($this->boeingId(), $result);
    }

    public function test_unresolved_reference_warns_and_is_not_silently_dropped(): void
    {
        $warnings = [];
        $result = $this->map('related', ['Concorde'], $warnings);

        $this->assertNull($result);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Concorde', $warnings[0]);
    }
}
