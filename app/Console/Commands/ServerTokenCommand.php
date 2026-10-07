<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

class ServerTokenCommand extends Command
{
    protected $signature = 'servers:token
                            {server : Server ID (integer) or name (string)}
                            {--regenerate : Force a new token even when one already exists}';

    protected $description = 'Generate (or regenerate) the push-agent ingest token for a server.
                              The plain token is displayed ONCE and never stored.';

    public function handle(): int
    {
        $identifier = $this->argument('server');

        // Resolve by numeric ID first, then by exact name.
        $server = is_numeric($identifier)
            ? Server::withoutGlobalScopes()->find((int) $identifier)
            : Server::withoutGlobalScopes()->where('name', $identifier)->first();

        if ($server === null) {
            $this->error("No server found for identifier: {$identifier}");

            return self::FAILURE;
        }

        // Guard: refuse to silently overwrite an existing token.
        if ($server->ingest_token_hash !== null && ! $this->option('regenerate')) {
            $this->warn("Server \"{$server->name}\" (ID {$server->id}) already has an ingest token.");
            $this->warn('Use --regenerate to replace it with a new one.');

            return self::FAILURE;
        }

        $plain = Server::generateIngestToken();

        $server->update(['ingest_token_hash' => Server::hashIngestToken($plain)]);

        $this->info("Ingest token for server \"{$server->name}\" (ID {$server->id}):");
        $this->line('');
        $this->line("  {$plain}");
        $this->line('');
        $this->warn('Copy this token now. It will NOT be shown again.');
        $this->warn('Set it as the Authorization: Bearer header in your push agent.');

        return self::SUCCESS;
    }
}
