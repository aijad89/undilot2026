<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The environments where throwaway data and destructive commands are allowed.
 *
 * AN ALLOWLIST, NOT `isProduction()`. Asking "is this production?" only refuses
 * when the server's `.env` says exactly `APP_ENV=production`; a server left on
 * `staging`, a typo, or a missing key would answer "no" and let a seeder that
 * deletes every event run against real data. Asking "is this a machine we know
 * is disposable?" refuses everything it does not recognise.
 *
 * The same three names already open `/auto` (routes/web.php,
 * DevAutoLoginController) for the same reason.
 */
final class DevEnvironment
{
    public const NAMES = ['local', 'testing', 'e2e'];

    public static function here(): bool
    {
        return app()->environment(self::NAMES);
    }

    /**
     * Refuse db:wipe, migrate:fresh, migrate:refresh, migrate:reset and
     * migrate:rollback outside the allowlist. Each would wipe or roll back the
     * whole schema. A normal deploy runs `migrate --force`, which stays allowed.
     */
    public static function lockDestructiveCommands(): void
    {
        DB::prohibitDestructiveCommands(! self::here());
    }
}
