<?php

namespace MotionWork\StatamicAiAssistant\Tests;

use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\ServiceProvider;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationPlanner;
use MotionWork\StatamicAiAssistant\Services\EntryReferenceResolver;
use MotionWork\StatamicAiAssistant\Services\EntrySearch;
use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Services\TermTranslator;

/**
 * The services are autowired. The container passes the default (null) for an
 * optional constructor parameter whose class is not bound, so every such
 * dependency must be bound, unless the class builds its own when given null.
 */
class ServiceProviderTest extends TestCase
{
    /** Optional dependencies a class replaces itself when it gets null. */
    private const SELF_BUILT = [TermTranslator::class, EntrySearch::class];

    public function test_optional_dependencies_of_shared_services_are_bound(): void
    {
        $singletons = (new \ReflectionClassConstant(ServiceProvider::class, 'SINGLETONS'))->getValue();
        $unbound = [];

        foreach ($singletons as $class) {
            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if (! $type instanceof \ReflectionNamedType || $type->isBuiltin() || ! $parameter->isDefaultValueAvailable()) {
                    continue;
                }
                if (! in_array($type->getName(), self::SELF_BUILT, true) && ! app()->bound($type->getName())) {
                    $unbound[] = "{$class}::\${$parameter->getName()}";
                }
            }
        }

        $this->assertSame([], $unbound, 'These would be null when autowired');
    }

    public function test_services_are_shared(): void
    {
        $singletons = (new \ReflectionClassConstant(ServiceProvider::class, 'SINGLETONS'))->getValue();
        $notShared = array_values(array_filter($singletons, fn (string $class) => app($class) !== app($class)));

        $this->assertSame([], $notShared);
    }

    public function test_planner_gets_its_session_store_and_decorator(): void
    {
        $planner = app(EntryGenerationPlanner::class);

        $this->assertNotNull((fn () => $this->batch)->call($planner));
        $this->assertNotNull((fn () => $this->decorator)->call($planner));
    }

    public function test_translator_shares_the_reference_resolver(): void
    {
        $this->assertSame(
            app(EntryReferenceResolver::class),
            (fn () => $this->referenceResolver)->call(app(EntryTranslator::class)),
        );
        $this->assertNotSame(app(TranslationMcpServer::class), app(TranslationMcpServer::class));
    }
}
