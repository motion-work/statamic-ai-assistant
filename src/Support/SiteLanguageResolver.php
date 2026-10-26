<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Facades\Site;
use Statamic\Sites\Site as StatamicSite;

/**
 * Turn a planner-supplied language ("English", "en", "en-US", "englisch")
 * into one Statamic site.
 */
class SiteLanguageResolver
{
    /** @var array<string, array<int, string>> */
    private const ALIASES = [
        'en' => ['en', 'english', 'englisch', 'anglais', 'inglese'],
        'de' => ['de', 'german', 'deutsch', 'allemand', 'tedesco'],
        'fr' => ['fr', 'french', 'franzosisch', 'franzoesisch', 'französisch', 'francais', 'français', 'francese'],
        'it' => ['it', 'italian', 'italienisch', 'italien', 'italiano'],
        'es' => ['es', 'spanish', 'spanisch', 'espanol', 'español'],
    ];

    /**
     * @return array{site: StatamicSite}|array{error: string, message: string, sites: array<int, string>}
     */
    public function resolve(string $target): array
    {
        $query = $this->normalize($target);
        $catalog = $this->catalog();

        if ($query === '') {
            return [
                'error' => 'missing_target',
                'message' => 'Pass the target site. Available sites: '.$this->catalogText($catalog).'.',
                'sites' => $catalog,
            ];
        }

        $matches = [];
        foreach (Site::all() as $site) {
            if ($this->matchesSite($site, $query)) {
                $matches[$site->handle()] = $site;
            }
        }

        if ($matches === []) {
            $primary = $this->aliasPrimary($query);
            if ($primary !== null) {
                foreach (Site::all() as $site) {
                    if ($this->primary($site->locale()) === $primary
                        || $this->primary((string) $site->lang()) === $primary
                        || $this->primary($site->handle()) === $primary) {
                        $matches[$site->handle()] = $site;
                    }
                }
            }
        }

        if (count($matches) === 1) {
            return ['site' => array_values($matches)[0]];
        }

        if ($matches === []) {
            return [
                'error' => 'unknown_site',
                'message' => 'No site matches "'.$target.'". Available sites: '.$this->catalogText($catalog).'.',
                'sites' => $catalog,
            ];
        }

        $handles = array_keys($matches);

        return [
            'error' => 'ambiguous_site',
            'message' => 'Several sites match "'.$target.'": '.implode(', ', $handles).'. Call again with one site handle.',
            'sites' => $handles,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function catalog(): array
    {
        $lines = [];
        foreach (Site::all() as $site) {
            $lines[] = $site->handle().' ('.$this->displayName($site).', '.$site->locale().')';
        }

        return $lines;
    }

    public function displayName(StatamicSite $site): string
    {
        $name = preg_replace('/^[\x{1F1E6}-\x{1F1FF}]{2}\s+/u', '', (string) $site->name()) ?? '';

        return trim($name) !== '' ? trim($name) : $site->handle();
    }

    /**
     * @param  array<int, string>  $catalog
     */
    private function catalogText(array $catalog): string
    {
        return $catalog === [] ? '(none)' : implode('; ', $catalog);
    }

    private function matchesSite(StatamicSite $site, string $query): bool
    {
        $name = $this->normalize($this->displayName($site));

        return $query === $this->normalize($site->handle())
            || $query === $this->normalize($site->locale())
            || $query === $this->normalize((string) $site->lang())
            || ($name !== '' && $query === $name);
    }

    private function aliasPrimary(string $query): ?string
    {
        foreach (self::ALIASES as $primary => $words) {
            foreach ($words as $word) {
                if ($query === $this->normalize($word)) {
                    return $primary;
                }
            }
        }

        return null;
    }

    private function primary(string $value): string
    {
        $normalized = $this->normalize($value);
        $parts = explode('-', $normalized);

        return $parts[0] !== '' ? $parts[0] : $normalized;
    }

    private function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace('_', '-', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}
