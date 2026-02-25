<?php

declare(strict_types=1);

namespace App\Logging;

use Symfony\Component\Console\Output\OutputInterface;

final class ColoredConsoleOutput
{
    private const PREFIXES = [
        'BOOT' => "\033[36m",      // cyan
        'MQTT' => "\033[35m",      // magenta
        'BAY' => "\033[33m",       // yellow
        'SESSION' => "\033[32m",   // green
        'HEARTBEAT' => "\033[34m", // blue
        'METER' => "\033[90m",     // gray
        'SCENARIO' => "\033[96m",  // bright cyan
        'API' => "\033[93m",       // bright yellow
        'FIRMWARE' => "\033[95m",  // bright magenta
        'DIAG' => "\033[94m",      // bright blue
        'CONFIG' => "\033[92m",    // bright green
        'RESET' => "\033[33m",     // yellow
        'OFFLINE' => "\033[91m",   // bright red
        'SECURITY' => "\033[31m",  // red
        'ERROR' => "\033[31m",     // red
        'WARNING' => "\033[33m",   // yellow
        'DEBUG' => "\033[90m",     // gray
        'INFO' => "\033[37m",      // white
    ];

    private const RESET = "\033[0m";

    private string $logLevel = 'info';

    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    public function setLogLevel(string $level): void
    {
        $this->logLevel = strtolower($level);
    }

    public function boot(string $message): void
    {
        $this->write('BOOT', $message, 'info');
    }

    public function mqtt(string $message): void
    {
        $this->write('MQTT', $message, 'info');
    }

    public function bay(string $message): void
    {
        $this->write('BAY', $message, 'info');
    }

    public function session(string $message): void
    {
        $this->write('SESSION', $message, 'info');
    }

    public function heartbeat(string $message): void
    {
        $this->write('HEARTBEAT', $message, 'debug');
    }

    public function meter(string $message): void
    {
        $this->write('METER', $message, 'debug');
    }

    public function scenario(string $message): void
    {
        $this->write('SCENARIO', $message, 'info');
    }

    public function api(string $message): void
    {
        $this->write('API', $message, 'info');
    }

    public function firmware(string $message): void
    {
        $this->write('FIRMWARE', $message, 'info');
    }

    public function diag(string $message): void
    {
        $this->write('DIAG', $message, 'info');
    }

    public function config(string $message): void
    {
        $this->write('CONFIG', $message, 'info');
    }

    public function reset(string $message): void
    {
        $this->write('RESET', $message, 'info');
    }

    public function offline(string $message): void
    {
        $this->write('OFFLINE', $message, 'info');
    }

    public function security(string $message): void
    {
        $this->write('SECURITY', $message, 'warning');
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message, 'error');
    }

    public function warning(string $message): void
    {
        $this->write('WARNING', $message, 'warning');
    }

    public function debug(string $message): void
    {
        $this->write('DEBUG', $message, 'debug');
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message, 'info');
    }

    private function write(string $prefix, string $message, string $level): void
    {
        $currentLevel = self::LEVELS[$this->logLevel] ?? 1;
        $messageLevel = self::LEVELS[$level] ?? 1;

        if ($messageLevel < $currentLevel) {
            return;
        }

        $color = self::PREFIXES[$prefix] ?? self::PREFIXES['INFO'];
        $timestamp = (new \DateTimeImmutable())->format('H:i:s.v');

        $this->output->writeln(
            sprintf('%s[%s]%s %s[%s]%s %s',
                "\033[90m", $timestamp, self::RESET,
                $color, $prefix, self::RESET,
                $message
            )
        );
    }
}
