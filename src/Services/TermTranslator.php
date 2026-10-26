<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Services\Concerns\TranslatesFields;
use DeepL\TranslateTextOptions;

/**
 * Translates one taxonomy term into a target site. Terms localize in place —
 * same term, per-site data via in($site) — so this is a lean sibling of
 * EntryTranslator: no origin graph, no localization creation, no linked-entry
 * recursion. All field walking, DeepL batching, Bard handling and the
 * localizable/force rules come from the shared TranslatesFields trait.
 */
class TermTranslator
{
    use TranslatesFields;

    private DeeplService $deepl;

    private string $sourceLang;

    private string $targetLang;

    public function __construct(DeeplService $deeplService)
    {
        $this->deepl = $deeplService;
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
        $this->skippedNonLocalizable = [];

        $blueprint = $term->blueprint();
        $sourceData = $term->in($sourceSite)->data()->toArray();
        $fields = $this->getFieldDefinitions($blueprint);

        // Same three phases as entries: collect → one DeepL batch → replace.
        $this->resetCollector();
        $dataWithPlaceholders = $this->collectFromFields($sourceData, $fields);

        $translatedTexts = [];
        if (! empty($this->collectedTexts)) {
            $translatedTexts = $this->deepl->translateBatch(
                $this->prepareTextsForApi(),
                $this->sourceLang,
                $this->targetLang,
                [TranslateTextOptions::TAG_HANDLING => 'html'],
            );

            $translatedTexts = $this->decodeTranslatedTexts($translatedTexts);
        }

        $translatedData = $this->replaceInFields($dataWithPlaceholders, $translatedTexts);

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
}
