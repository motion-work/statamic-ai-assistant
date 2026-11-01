<?php

namespace MotionWork\StatamicAiAssistant\Services;

use DeepL\TranslateTextOptions;

/**
 * Translates one taxonomy term into a target site. Terms localize in place —
 * same term, per-site data via in($site) — so this is a lean sibling of
 * EntryTranslator: no origin graph, no localization creation, no linked-entry
 * recursion. All field walking, DeepL batching, Bard handling and the
 * localizable/force rules come from the shared FieldTextCollector.
 */
class TermTranslator
{
    private FieldTextCollector $collector;

    private DeeplService $deepl;

    private string $sourceLang;

    private string $targetLang;

    public function __construct(DeeplService $deeplService)
    {
        $this->deepl = $deeplService;
        $this->collector = new FieldTextCollector;
    }

    /**
     * @param  mixed  $term  Statamic\Taxonomies\Term (or LocalizedTerm — resolved to its term)
     * @return mixed The saved LocalizedTerm for the target site
     */
    public function translateTerm(mixed $term, string $sourceSite, string $targetSite): mixed
    {
        if (method_exists($term, 'term')) {
            $term = $term->term();
        }

        $this->sourceLang = $sourceSite;
        $this->targetLang = $targetSite;
        $this->collector->policy->skippedNonLocalizable = [];

        $blueprint = $term->blueprint();
        $sourceData = $term->in($sourceSite)->data()->toArray();
        $fields = (new TranslatableFields)->getFieldDefinitions($blueprint);

        // Same three phases as entries: collect → one DeepL batch → replace.
        $this->collector->segments->resetCollector();
        $dataWithPlaceholders = $this->collector->collectFromFields($sourceData, $fields);

        $translatedTexts = [];
        if (! empty($this->collector->segments->collectedTexts)) {
            $translatedTexts = $this->deepl->translateBatch(
                $this->collector->segments->prepareTextsForApi(),
                $this->sourceLang,
                $this->targetLang,
                [TranslateTextOptions::TAG_HANDLING => 'html'],
            );

            $translatedTexts = $this->collector->segments->decodeTranslatedTexts($translatedTexts);
        }

        $translatedData = $this->collector->segments->replaceInFields($dataWithPlaceholders, $translatedTexts);

        // Ensure a title is always present so the localization is not blank in
        // listings even when the blueprint marks title non-localizable.
        if (! isset($translatedData['title']) && isset($sourceData['title'])) {
            $translatedData['title'] = $sourceData['title'];
        }

        $localized = $term->in($targetSite);
        $localized->data($translatedData);
        $localized->save();

        return $localized;
    }

    /**
     * Text fields left in the source language because they are not localizable
     * (drained: each call returns the ones since the last).
     *
     * @return array<int, string>
     */
    public function takeSkippedNonLocalizable(): array
    {
        return $this->collector->policy->takeSkippedNonLocalizable();
    }
}
