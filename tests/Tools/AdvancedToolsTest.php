<?php

namespace MotionWork\StatamicAiAssistant\Tests\Tools;

use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\Advanced\AdvancedToolset;
use MotionWork\StatamicAiAssistant\Tools\Advanced\ConfigureCollectionTool;
use MotionWork\StatamicAiAssistant\Tools\Advanced\CreateBlueprintTool;
use MotionWork\StatamicAiAssistant\Tools\Advanced\CreateCollectionTool;
use MotionWork\StatamicAiAssistant\Tools\Advanced\CreateTaxonomyTool;
use MotionWork\StatamicAiAssistant\Tools\Advanced\CreateTermsTool;
use MotionWork\StatamicAiAssistant\Tools\Advanced\ReadBlueprintTool;
use MotionWork\StatamicAiAssistant\Tools\Advanced\UpdateBlueprintTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

class AdvancedToolsTest extends TestCase
{
    /** Handles created during a test; removed again in tearDown so no fixture files linger. */
    private const COLLECTION = 'adv_events';

    private const TAXONOMY = 'adv_topics';

    protected function tearDown(): void
    {
        foreach (Blueprint::in('collections.'.self::COLLECTION)->all() as $bp) {
            $bp->delete();
        }
        Collection::find(self::COLLECTION)?->delete();
        if ($taxonomy = Taxonomy::find(self::TAXONOMY)) {
            foreach ($taxonomy->queryTerms()->get() as $term) {
                $term->delete();
            }
            $taxonomy->delete();
        }
        foreach (['adv_hero', 'component_adv_slider', 'adv_main_components', 'adv_flat_components'] as $fs) {
            \Statamic\Facades\Fieldset::find($fs)?->delete();
        }

        parent::tearDown();
    }

    private function invokeTool(object $tool, array $args): array
    {
        return $tool->handle(json_encode($args), new ToolContext);
    }

    /**
     * All field rows of a blueprint `structure`, whether stored flat or
     * normalized into tabs → sections by Statamic.
     */
    private function structureFieldRows(array $structure): array
    {
        if (isset($structure['fields']) && is_array($structure['fields'])) {
            return $structure['fields'];
        }

        return collect($structure['tabs'] ?? [])
            ->flatMap(fn ($tab) => collect($tab['sections'] ?? [])->flatMap(fn ($s) => $s['fields'] ?? []))
            ->values()
            ->all();
    }

    public function test_full_structural_workflow(): void
    {
        // 1. Taxonomy.
        $result = $this->invokeTool(new CreateTaxonomyTool, ['handle' => self::TAXONOMY, 'title' => 'Adv Topics']);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertNotNull(Taxonomy::find(self::TAXONOMY));

        // 2. Collection referencing the taxonomy.
        $result = $this->invokeTool(new CreateCollectionTool, [
            'handle' => self::COLLECTION,
            'title' => 'Adv Events',
            'dated' => true,
            'taxonomies' => [self::TAXONOMY],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertStringContainsString('create_blueprint', $result['next_step']);

        $collection = Collection::find(self::COLLECTION);
        $this->assertNotNull($collection);
        $this->assertTrue($collection->dated());
        $this->assertSame([self::TAXONOMY], $collection->taxonomies()->map->handle()->values()->all());

        // 3. Blueprint for it.
        $result = $this->invokeTool(new CreateBlueprintTool, [
            'handle' => 'event',
            'collection' => self::COLLECTION,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                ['handle' => 'date', 'field' => ['type' => 'date']],
            ],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(['title', 'date'], $result['fields']);

        // 4. Read it back.
        $result = $this->invokeTool(new ReadBlueprintTool, ['handle' => 'event', 'collection' => self::COLLECTION]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(['title', 'date'], array_column($result['fields'], 'handle'));

        // 5. Merge-update: change one field, add one — existing fields survive.
        $result = $this->invokeTool(new UpdateBlueprintTool, [
            'handle' => 'event',
            'collection' => self::COLLECTION,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Event name']],
                ['handle' => 'location', 'field' => ['type' => 'text']],
            ],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertEqualsCanonicalizing(['title', 'date', 'location'], $result['fields']);

        $read = $this->invokeTool(new ReadBlueprintTool, ['handle' => 'event', 'collection' => self::COLLECTION]);
        $byHandle = array_column($read['fields'], null, 'handle');
        $this->assertSame('Event name', $byHandle['title']['display']);
        $this->assertArrayHasKey('date', $byHandle);

        // 6. Configure: valid keys applied, unknown keys reported as ignored.
        $result = $this->invokeTool(new ConfigureCollectionTool, [
            'handle' => self::COLLECTION,
            'settings' => ['title' => 'Adv Events 2', 'nonsense_key' => 'x'],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(['title'], $result['applied']);
        $this->assertSame(['nonsense_key'], $result['ignored']);
        $this->assertSame('Adv Events 2', Collection::find(self::COLLECTION)->title());
    }

    public function test_create_collection_refuses_existing_handle_and_unknown_taxonomy(): void
    {
        $this->invokeTool(new CreateCollectionTool, ['handle' => self::COLLECTION, 'title' => 'Adv Events']);

        $dupe = $this->invokeTool(new CreateCollectionTool, ['handle' => self::COLLECTION, 'title' => 'Again']);
        $this->assertFalse($dupe['ok']);
        $this->assertStringContainsString('already exists', $dupe['error']);

        $badTax = $this->invokeTool(new CreateCollectionTool, ['handle' => 'adv_other', 'title' => 'X', 'taxonomies' => ['ghost_tax']]);
        $this->assertFalse($badTax['ok']);
        $this->assertStringContainsString('ghost_tax', $badTax['error']);
        $this->assertNull(Collection::find('adv_other'));
    }

    public function test_create_terms_adds_terms_and_is_idempotent(): void
    {
        $this->invokeTool(new CreateTaxonomyTool, ['handle' => self::TAXONOMY, 'title' => 'Adv Topics']);

        // Mixed input: bare string title, {title}, and {title, explicit slug}.
        $result = $this->invokeTool(new CreateTermsTool, [
            'taxonomy' => self::TAXONOMY,
            'terms' => ['Kulinarik', ['title' => 'Wellness'], ['title' => 'Live Musik', 'slug' => 'musik']],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertEqualsCanonicalizing(['kulinarik', 'wellness', 'musik'], $result['created']);
        $this->assertSame([], $result['skipped']);

        $this->assertNotNull(Term::find(self::TAXONOMY.'::kulinarik'));
        $this->assertSame('Live Musik', Term::find(self::TAXONOMY.'::musik')->in(Site::default()->handle())->get('title'));

        // Re-run: existing terms are skipped, only genuinely new ones created.
        $again = $this->invokeTool(new CreateTermsTool, [
            'taxonomy' => self::TAXONOMY,
            'terms' => ['Kulinarik', 'Familie'],
        ]);
        $this->assertTrue($again['ok'], $again['error'] ?? '');
        $this->assertSame(['familie'], $again['created']);
        $this->assertEqualsCanonicalizing(['kulinarik'], $again['skipped']);
    }

    public function test_create_terms_requires_existing_taxonomy(): void
    {
        $res = $this->invokeTool(new CreateTermsTool, ['taxonomy' => 'ghost_tax', 'terms' => ['X']]);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('ghost_tax', $res['error']);
        $this->assertStringContainsString('create_taxonomy', $res['error']);
    }

    public function test_create_terms_validates_input(): void
    {
        $this->invokeTool(new CreateTaxonomyTool, ['handle' => self::TAXONOMY, 'title' => 'Adv Topics']);

        $empty = $this->invokeTool(new CreateTermsTool, ['taxonomy' => self::TAXONOMY, 'terms' => []]);
        $this->assertFalse($empty['ok']);

        $noTitle = $this->invokeTool(new CreateTermsTool, ['taxonomy' => self::TAXONOMY, 'terms' => [['slug' => 'x']]]);
        $this->assertFalse($noTitle['ok']);
        $this->assertStringContainsString('title', $noTitle['error']);
    }

    public function test_create_blueprint_requires_existing_collection_and_valid_fields(): void
    {
        $missing = $this->invokeTool(new CreateBlueprintTool, [
            'handle' => 'event',
            'collection' => 'ghost_collection',
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ]);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('create_collection', $missing['error']);

        $this->invokeTool(new CreateCollectionTool, ['handle' => self::COLLECTION, 'title' => 'Adv Events']);

        $badFields = $this->invokeTool(new CreateBlueprintTool, [
            'handle' => 'event',
            'collection' => self::COLLECTION,
            'fields' => [['handle' => 'title', 'type' => 'text']],
        ]);
        $this->assertFalse($badFields['ok']);
        $this->assertStringContainsString('"type" at the top level', $badFields['error']);
    }

    public function test_blueprint_can_import_a_fieldset_and_updates_do_not_duplicate_the_import(): void
    {
        \Statamic\Facades\Fieldset::make('adv_hero')->setContents([
            'title' => 'Adv Hero',
            'fields' => [
                ['handle' => 'hero_title', 'field' => ['type' => 'text']],
                ['handle' => 'hero_image', 'field' => ['type' => 'assets', 'max_files' => 1]],
            ],
        ])->save();

        $this->invokeTool(new CreateCollectionTool, ['handle' => self::COLLECTION, 'title' => 'Adv Events']);

        $created = $this->invokeTool(new CreateBlueprintTool, [
            'handle' => 'event',
            'collection' => self::COLLECTION,
            'fields' => [
                ['import' => 'adv_hero'],
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ],
        ]);
        $this->assertTrue($created['ok'], $created['error'] ?? '');
        $this->assertContains('import:adv_hero', $created['fields']);

        // Statamic resolves the import: the fieldset's fields exist on the blueprint.
        $read = $this->invokeTool(new ReadBlueprintTool, ['handle' => 'event', 'collection' => self::COLLECTION]);
        $this->assertEqualsCanonicalizing(
            ['hero_title', 'hero_image', 'title'],
            array_column($read['fields'], 'handle'),
        );

        // The raw structure keeps the reference (not inlined copies) …
        $this->assertContains(['import' => 'adv_hero'], $this->structureFieldRows($read['structure']));

        // … and re-sending the same import on update does not duplicate it.
        $updated = $this->invokeTool(new UpdateBlueprintTool, [
            'handle' => 'event',
            'collection' => self::COLLECTION,
            'fields' => [
                ['import' => 'adv_hero'],
                ['handle' => 'location', 'field' => ['type' => 'text']],
            ],
        ]);
        $this->assertTrue($updated['ok'], $updated['error'] ?? '');

        $reread = $this->invokeTool(new ReadBlueprintTool, ['handle' => 'event', 'collection' => self::COLLECTION]);
        $importRows = array_filter($this->structureFieldRows($reread['structure']), fn ($row) => ($row['import'] ?? null) === 'adv_hero');
        $this->assertCount(1, $importRows);
        $this->assertContains('location', array_column($reread['fields'], 'handle'));
    }

    public function test_create_blueprint_with_tabs_mirrors_site_layout(): void
    {
        \Statamic\Facades\Fieldset::make('adv_hero')->setContents([
            'title' => 'Adv Hero',
            'fields' => [['handle' => 'hero_title', 'field' => ['type' => 'text']]],
        ])->save();
        \Statamic\Facades\Fieldset::make('adv_main_components')->setContents([
            'title' => 'Adv Main Components',
            'fields' => [['handle' => 'components', 'field' => ['type' => 'replicator', 'sets' => []]]],
        ])->save();

        $this->invokeTool(new CreateCollectionTool, ['handle' => self::COLLECTION, 'title' => 'Adv Events']);

        $created = $this->invokeTool(new CreateBlueprintTool, [
            'handle' => 'event',
            'collection' => self::COLLECTION,
            'tabs' => [
                [
                    'handle' => 'main',
                    'display' => 'Main',
                    'sections' => [
                        ['fields' => [
                            ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
                            ['import' => 'adv_hero'],
                        ]],
                        ['display' => 'Inhalt', 'fields' => [['import' => 'adv_main_components']]],
                    ],
                ],
                [
                    'handle' => 'seo',
                    'display' => 'SEO',
                    'sections' => [
                        ['display' => 'Basic', 'instructions' => 'Basic SEO settings.', 'fields' => [
                            ['handle' => 'meta_title', 'field' => ['type' => 'text']],
                        ]],
                    ],
                ],
                // Convenience shape: "fields" directly on the tab = one section.
                [
                    'handle' => 'sidebar',
                    'fields' => [['handle' => 'slug', 'field' => ['type' => 'slug']]],
                ],
            ],
        ]);

        $this->assertTrue($created['ok'], $created['error'] ?? '');
        $this->assertSame(['main', 'seo', 'sidebar'], $created['tabs']);
        $this->assertSame(['title', 'import:adv_hero', 'import:adv_main_components', 'meta_title', 'slug'], $created['fields']);

        // Raw structure keeps the tab/section layout exactly as the site's
        // hand-written blueprints do — nothing collapses into one tab.
        $read = $this->invokeTool(new ReadBlueprintTool, ['handle' => 'event', 'collection' => self::COLLECTION]);
        $tabs = $read['structure']['tabs'];
        $this->assertSame(['main', 'seo', 'sidebar'], array_keys($tabs));
        $this->assertCount(2, $tabs['main']['sections']);
        $this->assertSame('Inhalt', $tabs['main']['sections'][1]['display']);
        $this->assertContains(['import' => 'adv_main_components'], $tabs['main']['sections'][1]['fields']);
        $this->assertSame('Basic SEO settings.', $tabs['seo']['sections'][0]['instructions']);
        $this->assertSame('slug', $tabs['sidebar']['sections'][0]['fields'][0]['handle']);

        // Imports resolve across tabs.
        $this->assertEqualsCanonicalizing(
            ['title', 'hero_title', 'components', 'meta_title', 'slug'],
            array_column($read['fields'], 'handle'),
        );
    }

    public function test_create_blueprint_with_tabs_rejects_bad_shapes(): void
    {
        $this->invokeTool(new CreateCollectionTool, ['handle' => self::COLLECTION, 'title' => 'Adv Events']);

        // tabs and fields together are ambiguous.
        $both = $this->invokeTool(new CreateBlueprintTool, [
            'handle' => 'event',
            'collection' => self::COLLECTION,
            'tabs' => [['handle' => 'main', 'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]],
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ]);
        $this->assertFalse($both['ok']);
        $this->assertStringContainsString('not both', $both['error']);

        // Handle uniqueness is blueprint-wide, across tabs.
        $dupe = $this->invokeTool(new CreateBlueprintTool, [
            'handle' => 'event',
            'collection' => self::COLLECTION,
            'tabs' => [
                ['handle' => 'main', 'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]],
                ['handle' => 'seo', 'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]],
            ],
        ]);
        $this->assertFalse($dupe['ok']);
        $this->assertStringContainsString('Duplicate field handle', $dupe['error']);

        // A tab without sections/fields is a targeted error, not a crash.
        $empty = $this->invokeTool(new CreateBlueprintTool, [
            'handle' => 'event',
            'collection' => self::COLLECTION,
            'tabs' => [['handle' => 'main']],
        ]);
        $this->assertFalse($empty['ok']);
        $this->assertStringContainsString('sections', $empty['error']);

        $this->assertNull($this->invokeTool(new ReadBlueprintTool, ['handle' => 'event', 'collection' => self::COLLECTION])['structure'] ?? null);
    }

    public function test_create_fieldset_and_register_it_as_component_in_grouped_container(): void
    {
        // Container in the grouped format real sites use (sets → group → sets).
        \Statamic\Facades\Fieldset::make('adv_main_components')->setContents([
            'title' => 'Main Components',
            'fields' => [[
                'handle' => 'main_components',
                'field' => [
                    'type' => 'replicator',
                    'display' => 'Komponenten',
                    'sets' => [
                        'default_group' => [
                            'display' => 'Blocks',
                            'sets' => [
                                'text' => ['display' => 'Text', 'fields' => [['import' => 'adv_hero']]],
                            ],
                        ],
                    ],
                ],
            ]],
        ])->save();
        \Statamic\Facades\Fieldset::make('adv_hero')->setContents([
            'title' => 'Adv Hero',
            'fields' => [['handle' => 'hero_title', 'field' => ['type' => 'text']]],
        ])->save();

        // 1. Create the component fieldset.
        $created = $this->invokeTool(new \MotionWork\StatamicAiAssistant\Tools\Advanced\CreateFieldsetTool, [
            'handle' => 'component_adv_slider',
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Titel']],
                ['handle' => 'images', 'field' => ['type' => 'assets']],
            ],
        ]);
        $this->assertTrue($created['ok'], $created['error'] ?? '');
        $this->assertStringContainsString('add_component_set', $created['next_step']);

        // Duplicate handle refused.
        $dupe = $this->invokeTool(new \MotionWork\StatamicAiAssistant\Tools\Advanced\CreateFieldsetTool, [
            'handle' => 'component_adv_slider',
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ]);
        $this->assertFalse($dupe['ok']);

        // 2. The container is discoverable via list_fieldsets.
        $list = $this->invokeTool(new \MotionWork\StatamicAiAssistant\Tools\Advanced\ListFieldsetsTool, ['handle' => 'adv_main_components']);
        $this->assertTrue($list['fieldsets'][0]['component_container']);
        $this->assertSame(['text'], $list['fieldsets'][0]['set_handles']);

        // 3. Register the component: set lands in the group, importing the fieldset.
        $registered = $this->invokeTool(new \MotionWork\StatamicAiAssistant\Tools\Advanced\AddComponentSetTool, [
            'component' => 'component_adv_slider',
            'container' => 'adv_main_components',
            'display' => 'Slider',
        ]);
        $this->assertTrue($registered['ok'], $registered['error'] ?? '');
        $this->assertSame('adv_slider', $registered['set_handle']);

        $sets = \Statamic\Facades\Fieldset::find('adv_main_components')->contents()['fields'][0]['field']['sets'];
        $this->assertSame(
            ['display' => 'Slider', 'fields' => [['import' => 'component_adv_slider']]],
            $sets['default_group']['sets']['adv_slider'],
        );
        // Existing sets untouched.
        $this->assertArrayHasKey('text', $sets['default_group']['sets']);

        // 4. Re-registering the same component is refused.
        $again = $this->invokeTool(new \MotionWork\StatamicAiAssistant\Tools\Advanced\AddComponentSetTool, [
            'component' => 'component_adv_slider',
            'container' => 'adv_main_components',
        ]);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already registered', $again['error']);
    }

    public function test_add_component_set_supports_flat_containers_and_reports_missing_ones(): void
    {
        \Statamic\Facades\Fieldset::make('component_adv_slider')->setContents([
            'title' => 'Adv Slider',
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ])->save();

        // Flat sets format (no groups).
        \Statamic\Facades\Fieldset::make('adv_flat_components')->setContents([
            'title' => 'Flat Components',
            'fields' => [[
                'handle' => 'blocks',
                'field' => ['type' => 'replicator', 'sets' => ['text' => ['display' => 'Text', 'fields' => []]]],
            ]],
        ])->save();

        $registered = $this->invokeTool(new \MotionWork\StatamicAiAssistant\Tools\Advanced\AddComponentSetTool, [
            'component' => 'component_adv_slider',
            'container' => 'adv_flat_components',
        ]);
        $this->assertTrue($registered['ok'], $registered['error'] ?? '');

        $sets = \Statamic\Facades\Fieldset::find('adv_flat_components')->contents()['fields'][0]['field']['sets'];
        $this->assertSame([['import' => 'component_adv_slider']], $sets['adv_slider']['fields']);

        // A fieldset without sets is called out as not-a-container.
        \Statamic\Facades\Fieldset::make('adv_hero')->setContents([
            'title' => 'Adv Hero',
            'fields' => [['handle' => 'hero_title', 'field' => ['type' => 'text']]],
        ])->save();

        $notContainer = $this->invokeTool(new \MotionWork\StatamicAiAssistant\Tools\Advanced\AddComponentSetTool, [
            'component' => 'component_adv_slider',
            'container' => 'adv_hero',
        ]);
        $this->assertFalse($notContainer['ok']);
        $this->assertStringContainsString('not a components container', $notContainer['error']);
    }

    public function test_form_blueprints_are_listable_readable_and_updatable(): void
    {
        \Statamic\Facades\Form::make('adv_kontakt')->title('Adv Kontakt')->save();
        Blueprint::make('adv_kontakt')->setNamespace('forms')->setContents([
            'title' => 'Adv Kontakt',
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text', 'display' => 'Name']],
                ['handle' => 'email', 'field' => ['type' => 'text', 'display' => 'E-Mail']],
            ],
        ])->save();

        try {
            // Discoverable.
            $list = $this->invokeTool(new \MotionWork\StatamicAiAssistant\Tools\Advanced\ListBlueprintsTool, []);
            $this->assertContains('adv_kontakt', array_column($list['forms'], 'form'));

            // Readable via the form parameter.
            $read = $this->invokeTool(new ReadBlueprintTool, ['handle' => 'adv_kontakt', 'form' => 'adv_kontakt']);
            $this->assertTrue($read['ok'], $read['error'] ?? '');
            $this->assertContains('email', array_column($read['fields'], 'handle'));

            // Updatable: the checkbox lands on the FORM blueprint.
            $updated = $this->invokeTool(new UpdateBlueprintTool, [
                'handle' => 'adv_kontakt',
                'form' => 'adv_kontakt',
                'fields' => [
                    ['handle' => 'food_type', 'field' => ['type' => 'checkboxes', 'options' => ['vegan' => 'Vegan']]],
                ],
            ]);
            $this->assertTrue($updated['ok'], $updated['error'] ?? '');
            $this->assertContains('food_type', $updated['fields']);
            $this->assertContains('name', $updated['fields']);

            // Unknown form → actionable error listing forms.
            $bad = $this->invokeTool(new ReadBlueprintTool, ['handle' => 'x', 'form' => 'ghost_form']);
            $this->assertFalse($bad['ok']);
            $this->assertStringContainsString('adv_kontakt', $bad['error']);
        } finally {
            foreach (Blueprint::in('forms')->all() as $bp) {
                if ($bp->handle() === 'adv_kontakt') {
                    $bp->delete();
                }
            }
            \Statamic\Facades\Form::find('adv_kontakt')?->delete();
        }
    }

    public function test_invalid_handles_are_rejected(): void
    {
        $result = $this->invokeTool(new CreateCollectionTool, ['handle' => 'Adv Events!', 'title' => 'X']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('snake_case', $result['error']);
    }

    public function test_advanced_tools_require_grant_and_explicit_user_opt_in(): void
    {
        $user = \Statamic\Facades\User::make()->email('adv-super@test.dev')->makeSuper();
        $user->save();

        try {
            // Granted (super) but NOT opted in → inactive. The opt-in toggle is
            // the safety catch for production sites.
            $this->assertTrue(\MotionWork\StatamicAiAssistant\Support\AgentAccess::allows('advanced_tools', $user));
            $this->assertFalse(\MotionWork\StatamicAiAssistant\Support\AgentAccess::advancedToolsActive($user));

            // Opted in → active.
            $user->setPreference(\MotionWork\StatamicAiAssistant\Support\AgentAccess::ADVANCED_TOOLS_PREFERENCE, true)->save();
            $this->assertTrue(\MotionWork\StatamicAiAssistant\Support\AgentAccess::advancedToolsActive($user));

            // Opted out again → inactive.
            $user->setPreference(\MotionWork\StatamicAiAssistant\Support\AgentAccess::ADVANCED_TOOLS_PREFERENCE, false)->save();
            $this->assertFalse(\MotionWork\StatamicAiAssistant\Support\AgentAccess::advancedToolsActive($user));
        } finally {
            $user->delete();
        }
    }

    public function test_toolset_gating_follows_session_flag_and_config(): void
    {
        $this->assertTrue(AdvancedToolset::enabledForSession(['advanced_tools' => true]));
        $this->assertFalse(AdvancedToolset::enabledForSession(['advanced_tools' => false]));
        $this->assertFalse(AdvancedToolset::enabledForSession([]));

        config(['statamic-ai-assistant.advanced_tools' => false]);
        $this->assertFalse(AdvancedToolset::enabledForSession(['advanced_tools' => true]));
        config(['statamic-ai-assistant.advanced_tools' => true]);
    }

    public function test_every_advanced_tool_has_a_unique_name_and_definition(): void
    {
        $names = array_map(fn ($tool) => $tool->name(), AdvancedToolset::tools());

        $this->assertSame($names, array_unique($names));

        foreach (AdvancedToolset::tools() as $tool) {
            $def = $tool->definition();
            $this->assertSame($tool->name(), $def['function']['name']);
            $this->assertIsArray($def['function']['parameters']['properties']);
        }
    }
}
