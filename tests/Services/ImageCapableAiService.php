<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\GeneratesImages;

/**
 * Test double base: a provider that also generates images, like
 * OpenRouterService. Stub it with createStub().
 */
abstract class ImageCapableAiService extends AbstractAiService implements GeneratesImages {}
