<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Support\PackageInstallers;

/**
 * Installing a content package (its blueprints, collections and forms) on
 * this site, for a provisioning service account.
 */
final class PackageTools
{
    /** What the Control Panel requires to change blueprints and collections. */
    private const PERMISSIONS = ['configure collections', 'configure fields'];

    public function __construct(
        private ConnectorContext $context,
        private PackageInstallers $installers,
        private ContentCommitter $commits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function installPackage(string $key): array
    {
        $key = trim($key);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $key) !== 1) {
            return ['installed' => false, 'error' => 'invalid_key', 'message' => 'Pass the package key, such as showroom-retail (letters, digits, dot, dash, underscore).'];
        }

        $actor = $this->context->actor();
        $missing = $actor === null || $actor->isSuper()
            ? []
            : array_values(array_filter(self::PERMISSIONS, fn (string $permission) => ! $actor->hasPermission($permission)));
        if ($missing !== []) {
            return ['installed' => false, 'error' => 'forbidden', 'message' => 'Installing a package changes blueprints and collections; this account\'s role lacks: '.implode(', ', $missing).'. Nothing was installed.'];
        }

        if ($this->installers->source($key) === null) {
            return ['installed' => false, 'error' => 'no_installer', 'message' => 'No installer is available for '.$key.'. Require the package with Composer first (it registers its installer or the '.PackageInstallers::ARTISAN_COMMAND.' command).'];
        }

        try {
            $result = $this->installers->install($key);
        } catch (\Throwable $e) {
            Log::warning('statamic-ai-assistant: package install failed', ['key' => $key, 'error' => $e->getMessage()]);

            return ['installed' => false, 'error' => 'install_failed', 'message' => $e->getMessage()];
        }

        $this->commits->commit('Install the '.$key.' package', $actor);

        return ['installed' => true, 'key' => $key, 'via' => $result['via'], 'output' => $result['output']];
    }
}
