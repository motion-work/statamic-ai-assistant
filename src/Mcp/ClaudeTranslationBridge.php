<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use MotionWork\StatamicAiAssistant\Support\SiteLanguageResolver;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;

/**
 * Translation and editing operations for an external model (Claude).
 *
 * The model writes the text with its own account. These methods only read
 * Statamic, describe the change they would save, and write the strings the
 * model sends back. DeepL and Infomaniak are not called.
 *
 * A signed-in account only sees entries its Control Panel role may view, and
 * only writes where the role may edit. Without an account (the local stdio
 * server) reads and translations are open, and in-place edits are refused.
 */
class ClaudeTranslationBridge
{
    private ConnectorContext $context;

    public readonly LocalizationTools $localization;

    public readonly EntryEditTools $edits;

    public readonly EntryLifecycleTools $lifecycle;

    public readonly AssetTools $assets;

    public readonly ComposeBrief $brief;

    public readonly ComposeTools $compose;

    public readonly ComposeSaver $composeSave;

    public readonly GlossaryTools $glossary;

    public function __construct(
        EntryTranslator $translator,
        SiteLanguageResolver $sites,
        TranslationGlossaryService $glossary,
        TranslationStyleRulesService $styleRules,
        ContentCommitter $commits,
        ImageUploads $images,
    ) {
        $this->context = new ConnectorContext;
        $this->localization = new LocalizationTools($this->context, $translator, $sites, $glossary, $styleRules, $commits);
        $this->edits = new EntryEditTools($this->context, $translator);
        $this->lifecycle = new EntryLifecycleTools($this->context);
        $this->assets = new AssetTools($this->context, $images);
        $this->brief = new ComposeBrief($this->context);
        $this->compose = new ComposeTools($this->context);
        $this->composeSave = new ComposeSaver($this->context);
        $this->glossary = new GlossaryTools($this->context, $glossary, $styleRules, $commits);
    }

    /**
     * Also the request's logged-in user, so policies, updated_by, git commits
     * and Statamic's own actions see the account as in the Control Panel.
     */
    public function setActor(?UserContract $user): void
    {
        $this->context->setActor($user);
    }

    public function actor(): ?UserContract
    {
        return $this->context->actor();
    }

    /**
     * @return array<string, mixed>
     */
    public function listSites(): array
    {
        $sites = [];
        foreach (Site::all() as $site) {
            $sites[] = [
                'handle' => $site->handle(),
                'locale' => $site->locale(),
                'name' => $site->name(),
            ];
        }

        return ['sites' => $sites];
    }

    /**
     * @return array<string, mixed>
     */
    public function findEntries(string $query, ?string $collection = null): array
    {
        $needle = mb_strtolower(trim($query));
        $entries = EntryFacade::query();
        if (is_string($collection) && $collection !== '') {
            $entries->where('collection', $collection);
        }

        $matches = [];
        foreach ($entries->get() as $entry) {
            $title = mb_strtolower(EntryLabel::for($entry));
            $slug = mb_strtolower((string) $entry->slug());
            if ($needle !== '' && ! str_contains($title, $needle) && ! str_contains($slug, $needle) && ! str_contains((string) $entry->id(), $needle)) {
                continue;
            }
            if ($this->context->actor() !== null && ! $this->context->actor()->can('view', $entry)) {
                continue;
            }
            $matches[] = $this->context->entrySummary($entry);
            if (count($matches) >= 20) {
                break;
            }
        }

        return ['entries' => $matches];
    }
}
