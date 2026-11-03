<?php

namespace MotionWork\StatamicAiAssistant\Mcp\Concerns;

use MotionWork\StatamicAiAssistant\Mcp\ConnectorContext;
use Statamic\Facades\AssetContainer;

/**
 * @property ConnectorContext $context
 */
trait ResolvesViewableAssetContainer
{
    /**
     * @return \Statamic\Contracts\Assets\AssetContainer|array<string, mixed>
     */
    protected function viewableAssetContainer(?string $containerHandle): mixed
    {
        $visible = AssetContainer::all()
            ->filter(fn ($container) => $this->context->actor() === null || $this->context->actor()->can('view', $container))
            ->values();

        if ($containerHandle === null || $containerHandle === '') {
            if ($visible->count() !== 1) {
                return [
                    'containers' => $visible->map(fn ($c) => ['handle' => $c->handle(), 'title' => $c->title()])->all(),
                    'message' => $visible->isEmpty() ? 'Your Control Panel role cannot view any assets.' : 'Pass one of these containers.',
                ];
            }
            $containerHandle = $visible->first()->handle();
        }

        $container = $visible->first(fn ($c) => $c->handle() === $containerHandle);
        if (! $container) {
            return AssetContainer::find($containerHandle)
                ? ['error' => 'forbidden', 'message' => 'Your Control Panel role cannot view the '.$containerHandle.' assets.']
                : ['error' => 'missing_container', 'message' => 'No asset container has the handle '.$containerHandle.'.'];
        }

        return $container;
    }
}
