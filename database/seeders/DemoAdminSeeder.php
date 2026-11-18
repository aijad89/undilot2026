<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Support\AdminZones;
use Illuminate\Support\Str;

/**
 * DEMO: one state admin per zone (14), plus one HQ admin.
 *
 * Usernames are `demo.hq` and `demo.<zone>`. Every password is random and
 * thrown away — sign in as any of them through /auto, which exists only
 * locally. Re-runnable: matched on username.
 */
class DemoAdminSeeder extends LocalOnlySeeder
{
    /** The demo HQ admin's username; DemoScenarioSeeder acts as this account. */
    public const HQ = 'demo.hq';

    public function run(): void
    {
        $this->admin(self::HQ, 'Demo HQ Admin', 'hq', null);

        foreach (AdminZones::DEFAULTS as $zone) {
            $this->admin('demo.'.Str::slug($zone), "Demo Admin {$zone}", 'admin', $zone);
        }
    }

    private function admin(string $username, string $name, string $role, ?string $zone): void
    {
        Admin::firstOrCreate(['username' => $username], [
            'name' => $name,
            'password' => Str::random(40),
            'role' => $role,
            'state' => $zone,
        ]);
    }
}
