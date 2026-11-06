<?php

namespace MotionWork\StatamicAiAssistant\Support;

/**
 * Queued agent jobs run without the editor's request, so the app is in its
 * default locale and every editor-facing string (summaries, card notes,
 * activity lines) would come out in English. The session records the CP
 * language the request was sent in; jobs run their work in it.
 */
final class CpLocale
{
    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function run(?string $locale, callable $fn): mixed
    {
        $locale = is_string($locale) ? trim($locale) : '';
        if ($locale === '' || $locale === app()->getLocale()) {
            return $fn();
        }

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $fn();
        } finally {
            app()->setLocale($previous);
        }
    }
}
