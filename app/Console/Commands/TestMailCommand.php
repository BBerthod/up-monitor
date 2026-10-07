<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

final class TestMailCommand extends Command
{
    protected $signature = 'mail:test {email : The recipient email address}';

    protected $description = 'Test multiple SMTP configurations to find which one works';

    private array $results = [];

    public function handle(): int
    {
        $email = $this->argument('email');

        $host = config('mail.mailers.smtp.host');
        $username = config('mail.mailers.smtp.username');
        $password = config('mail.mailers.smtp.password');

        if (! $host || ! $username) {
            $this->error('Missing SMTP credentials. Ensure MAIL_HOST and MAIL_USERNAME are set.');

            return self::FAILURE;
        }

        $this->info('Testing SMTP configurations');
        $this->line("Host: <info>{$host}</info>");
        $this->line("Username: <info>{$username}</info>");
        $this->line("Recipient: <info>{$email}</info>");
        $this->newLine();

        $configs = [
            1 => ['name' => 'Port 465, smtps (SSL implicit)', 'port' => 465, 'scheme' => 'smtps'],
            2 => ['name' => 'Port 587, smtp (STARTTLS)', 'port' => 587, 'scheme' => 'smtp'],
            3 => ['name' => 'Port 587, no scheme (auto)', 'port' => 587, 'scheme' => null],
            4 => ['name' => 'Port 25, smtp (plain)', 'port' => 25, 'scheme' => 'smtp'],
        ];

        foreach ($configs as $testNumber => $config) {
            $this->runTest($testNumber, $config, $email, $host, $username, $password);
        }

        $this->displaySummary();

        return self::SUCCESS;
    }

    private function runTest(int $testNumber, array $config, string $email, string $host, string $username, string $password): void
    {
        $this->line("Test #{$testNumber}: <comment>{$config['name']}</comment>");

        Config::set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'scheme' => $config['scheme'],
            'host' => $host,
            'port' => $config['port'],
            'username' => $username,
            'password' => $password,
            'timeout' => 15,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ]);

        app('mail.manager')->purge('smtp');

        $schemeLabel = $config['scheme'] ?? 'auto';
        $timestamp = now()->toDateTimeString();

        $body = "SMTP Test #{$testNumber}\nConfig: {$config['name']}\nHost: {$host}\nPort: {$config['port']}\nScheme: {$schemeLabel}\nTime: {$timestamp}";

        try {
            Mail::raw($body, fn ($message) => $message
                ->to($email)
                ->subject("SMTP Test #{$testNumber} - {$config['name']}")
            );

            $this->info('  SUCCESS');
            $this->results[] = ['test' => "#{$testNumber}", 'config' => $config['name'], 'success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $this->error("  FAILED: {$errorMsg}");
            $this->results[] = ['test' => "#{$testNumber}", 'config' => $config['name'], 'success' => false, 'error' => $errorMsg];
        }

        $this->newLine();
    }

    private function displaySummary(): void
    {
        $this->newLine();
        $this->info('=== SUMMARY ===');

        $rows = array_map(
            fn ($r) => [
                $r['test'],
                $r['config'],
                $r['success'] ? 'SUCCESS' : 'FAILED',
                $r['error'] ? substr($r['error'], 0, 80) : '-',
            ],
            $this->results
        );

        $this->table(['#', 'Configuration', 'Status', 'Error'], $rows);

        $passed = count(array_filter($this->results, fn ($r) => $r['success']));
        $total = count($this->results);

        match (true) {
            $passed > 0 => $this->info("Result: {$passed}/{$total} succeeded. Check your inbox."),
            default => $this->warn("Result: 0/{$total} succeeded. Check credentials and network."),
        };
    }
}
