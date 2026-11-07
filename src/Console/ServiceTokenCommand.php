<?php

namespace MotionWork\StatamicAiAssistant\Console;

use Illuminate\Console\Command;
use MotionWork\StatamicAiAssistant\Mcp\ServiceTokens;
use Statamic\Facades\User;

/**
 * php artisan statamic-ai-assistant:service-token {email} [--name=mateo] [--revoke] [--plain]
 *
 * Mints a bearer token for /mcp that acts as the Control Panel user with that
 * email (same role and Claude level). The plaintext is printed once and only
 * its hash is stored; minting again with the same name rotates the token.
 * --plain prints the token alone, for scripts. Tokens are accepted only while
 * STATAMIC_AI_ASSISTANT_MCP_SERVICE_TOKENS=true.
 */
class ServiceTokenCommand extends Command
{
    protected $signature = 'statamic-ai-assistant:service-token
        {email : Email of the Control Panel user the token acts as}
        {--name=mateo : Token name; minting the same name again replaces it}
        {--revoke : Remove the user\'s token of that name instead of minting one}
        {--plain : Print only the token (for scripts)}';

    protected $description = 'Mint (or revoke) a service-account bearer token for the /mcp connector.';

    public function handle(ServiceTokens $tokens): int
    {
        $user = User::findByEmail(trim((string) $this->argument('email')));
        if ($user === null) {
            $this->error('No Control Panel user has the email '.$this->argument('email').'.');

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $this->error('Pass a token name with --name.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $removed = $tokens->revoke($user, $name);
            $this->info($removed > 0 ? "Revoked the token \"{$name}\" of {$user->email()}." : "{$user->email()} has no token named \"{$name}\".");

            return self::SUCCESS;
        }

        $token = $tokens->mint($user, $name);

        if ($this->option('plain')) {
            $this->line($token);

            return self::SUCCESS;
        }

        $this->info("Service token \"{$name}\" for {$user->email()} (shown once, store it now):");
        $this->line($token);
        $this->line('Send it as "Authorization: Bearer <token>" to '.rtrim((string) config('app.url'), '/').'/mcp.');
        if (! ServiceTokens::enabled()) {
            $this->warn('Service tokens are off: set STATAMIC_AI_ASSISTANT_MCP_SERVICE_TOKENS=true (and clear the config cache) before the token is accepted.');
        }

        return self::SUCCESS;
    }
}
