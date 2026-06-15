<?php

declare(strict_types=1);

// PHPStan-only stubs for WP_CLI. This file is never loaded at runtime;
// it is referenced exclusively via phpstan.neon.dist bootstrapFiles.

class WP_CLI
{
    public static function success(string $message): void
    {
    }

    public static function add_command(string $name, object $handler): void
    {
    }
}

class WP_CLI_Command
{
}
