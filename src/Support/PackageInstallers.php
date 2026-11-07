<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Installers for content packages (blueprints, collections, forms a package
 * ships), run by the connector's install_package tool.
 *
 * A package registers its installer from its service provider:
 *
 *   app(PackageInstallers::class)->register('showroom-retail', fn (string $key) => …);
 *
 * The callable returns a short report (string) or null, and throws on
 * failure. A key nobody registered falls back to the `mateo-package:install`
 * artisan command when an installed package provides it; otherwise there is
 * no installer and the tool says so.
 */
final class PackageInstallers
{
    /** The command a mateo package registers; called with the key as its first argument. */
    public const ARTISAN_COMMAND = 'mateo-package:install';

    /** @var array<string, callable(string): (string|null)> */
    private array $installers = [];

    /**
     * @param  callable(string): (string|null)  $installer
     */
    public function register(string $key, callable $installer): void
    {
        $this->installers[$key] = $installer;
    }

    /**
     * Where the key would be installed from: "registered", "artisan:<command>",
     * or null when nothing can install it.
     */
    public function source(string $key): ?string
    {
        if (isset($this->installers[$key])) {
            return 'registered';
        }

        return array_key_exists(self::ARTISAN_COMMAND, Artisan::all()) ? 'artisan:'.self::ARTISAN_COMMAND : null;
    }

    /**
     * Run the installer for the key. Throws when it fails.
     *
     * @return array{via: string, output: string}
     */
    public function install(string $key): array
    {
        $source = $this->source($key);
        if ($source === null) {
            throw new \RuntimeException('No installer is registered for the package '.$key.' and no '.self::ARTISAN_COMMAND.' command exists.');
        }

        if ($source === 'registered') {
            return ['via' => $source, 'output' => $this->trim((string) ($this->installers[$key])($key))];
        }

        $command = Artisan::all()[self::ARTISAN_COMMAND];
        $first = array_key_first($command->getDefinition()->getArguments());
        $parameters = $first === null ? [] : [$first => $key];

        $exit = Artisan::call(self::ARTISAN_COMMAND, $parameters + ['--no-interaction' => true]);
        $output = $this->trim(Artisan::output());
        if ($exit !== 0) {
            throw new \RuntimeException(self::ARTISAN_COMMAND.' exited with '.$exit.($output !== '' ? ': '.$output : '.'));
        }

        return ['via' => $source, 'output' => $output];
    }

    private function trim(string $output): string
    {
        return Str::limit(trim($output), 4000);
    }
}
