<?php

namespace BoldWeb\StatamicAiAssistant\Tests;

use BoldWeb\StatamicAiAssistant\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
