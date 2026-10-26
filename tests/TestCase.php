<?php

namespace MotionWork\StatamicAiAssistant\Tests;

use MotionWork\StatamicAiAssistant\ServiceProvider;
use Statamic\Facades\Entry;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * Delete every entry, localizations first. Statamic refuses to delete an
     * origin that still has localizations, so the naive Entry::all()->each
     * ->delete() wipe explodes on leftover localized fixtures (and each failed
     * run then strands MORE debris for the next one). Use this in setUp()
     * whenever a test needs an empty entry state.
     */
    protected function wipeEntries(): void
    {
        Entry::all()->filter(fn ($e) => $e->hasOrigin())->each(fn ($e) => $e->delete());
        Entry::all()->each(fn ($e) => $e->delete());
    }
}
