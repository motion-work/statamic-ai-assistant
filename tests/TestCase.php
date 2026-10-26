<?php

namespace MotionWork\StatamicAiAssistant\Tests;

use MotionWork\StatamicAiAssistant\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
