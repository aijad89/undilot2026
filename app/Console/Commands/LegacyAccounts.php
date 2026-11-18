<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Company;
use App\Models\User;
use App\Support\LegacyZones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds sign-in accounts out of the loaded dump, for testing on this machine.
 *
 * THE DUMP CANNOT ANSWER TWO THINGS, so this command answers them and says so
 * rather than pretending they came from the office:
 *
 * 1. ROLES. `upengawai.UserRight` holds ONE value across all 137 rows —
 *    'Pengawai'. The old system had no role distinction at all, so super / hq /
 *    state cannot be migrated. The first officer becomes super, the second
 *    becomes hq, and every zone that appears in the dump gets one state admin.
 *    That is an assignment for testing, not a migration of fact.
 *
 * 2. PASSWORDS. `upengawai` signed in with `loginname`, which becomes the
 *    admin's `username`; `usahawan` had no login at all (participants sign in
 *    with community ID + IC). The old passwords are NOT carried over — the old
 *    app is retired, not migrated (owner, 2026-11-17) — so every password is
 *    random, and /auto signs in without one. Nothing here is a credential
 *    usable off this machine. No email anywhere: the project keeps none.
 *
 * Every row built carries `legacy_id` (the dump's own id), which is how a
 * re-run finds it and how `--fresh` removes exactly what this command made.
 *
 * DEV ONLY. It refuses outside local/testing/e2e, the same fence /auto stands
 * behind, because it plants accounts whose passwords nobody knows.
 */
class LegacyAccounts extends Command
{
    protected $signature = 'legacy:accounts
        {--users=10 : How many AACB participants to build, or "all"}
        {--fresh : Delete accounts this command made before rebuilding}';

    protected $description = 'Build super/hq/state admins and participants from the loaded legacy dump';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'e2e'])) {
            $this->error('legacy:accounts is for a developer machine only.');

            return self::FAILURE;
        }

        if (DB::table('legacy_upengawai')->count() === 0) {
            $this->error('Staging is empty. Run import:legacy first.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->forget();
        }

        $admins = $this->admins();
        $users = $this->participants();

        $this->newLine();
        $this->table(
            ['role', 'name', 'username', 'zone'],
            collect($admins)->map(fn (Admin $a) => [
                $a->role, $a->name, $a->username, $a->state ?? '—',
            ])->take(8)->all()
        );

        if (count($admins) > 8) {
            $this->line('… and '.(count($admins) - 8).' more state admins, one per zone in the dump.');
        }

        $this->newLine();
        $this->line('Participants built: '.count($users));
        foreach (array_slice($users, 0, 3) as $u) {
            $this->line("  {$u->community_id}  ({$u->company->name})");
        }

        $this->newLine();
        $this->line('Passwords are random. Sign in at /auto, which needs none.');

        return self::SUCCESS;
    }

    /** @return array<int, Admin> */
    private function admins(): array
    {
        // Ordered by the original id so a re-run hands the same officer the same
        // role — a test that signs in as "the super admin" should not change
        // person between runs.
        $officers = DB::table('legacy_upengawai')
            ->where('source_table', 'upengawai')
            ->whereNotNull('name')
            ->orderByRaw('cast(legacy_id as unsigned)')
            ->get();

        if ($officers->isEmpty()) {
            $this->warn('No rows from the live `upengawai` table.');

            return [];
        }

        $made = [];
        $roles = [Admin::SUPER_ROLE, 'hq'];
        $zonesTaken = [];
        $handlesTaken = [];

        foreach ($officers as $officer) {
            // TRANSLATED, never stored raw. `admins.state` is compared to
            // `companies.state` as a string, so a zone name the office's
            // dropdown does not contain is an account that can be moved out of
            // its own zone by any save. See LegacyZones.
            $zone = LegacyZones::translate($officer->state);
            $handle = $this->handleFor($officer, $handlesTaken);

            if ($roles !== []) {
                // Super and hq see every zone, so their own zone is dropped.
                $made[] = $this->admin($officer, $handle, array_shift($roles), null);

                continue;
            }

            // Two legacy zones can translate to one undilot zone (Selangor and
            // Kuala Lumpur both do), and a zone takes one admin.
            if ($zone === null || isset($zonesTaken[$zone])) {
                continue;
            }

            $zonesTaken[$zone] = true;
            $made[] = $this->admin($officer, $handle, 'admin', $zone);
        }

        return $made;
    }

    /**
     * A unique username per officer, derived from `loginname`.
     *
     * THE DUMP HAS TWO OFFICERS SHARING ONE LOGIN. Ids 5 and 25 are both
     * 'NURUL HAIDA BINTI AHMAD' with `loginname` 'haida' — the same person
     * entered twice — and `admins.username` is unique. Handing both the same
     * username used to make the second silently take over the first one's
     * row (when the key was an email built from it): on the real dump 17
     * admins were built, 16 rows existed, and the hq admin had been demoted to
     * a state admin without a word.
     *
     * So the first officer to claim a slug keeps it and every later one carries
     * its legacy id. Deterministic, because the caller reads officers in id
     * order — the same officer gets the same username on every run.
     *
     * @param  array<string, string>  $taken  slug => the legacy id holding it
     */
    private function handleFor(object $officer, array &$taken): string
    {
        $login = trim((string) $officer->loginname);
        $slug = Str::slug($login !== '' ? $login : (string) $officer->name) ?: 'pegawai';
        $id = (string) $officer->legacy_id;

        if (! isset($taken[$slug])) {
            $taken[$slug] = $id;

            return $slug;
        }

        return $taken[$slug] === $id ? $slug : $slug.'.'.$id;
    }

    private function admin(object $officer, string $handle, string $role, ?string $zone): Admin
    {
        return Admin::updateOrCreate(
            ['legacy_id' => (string) $officer->legacy_id],
            [
                'username' => $handle,
                // The officer's real name and title, which is the point: a test
                // screen should read like the office's own list.
                'name' => trim((string) $officer->name),
                'password' => bcrypt(Str::random(32)),
                'role' => $role,
                'state' => $zone,
            ]
        );
    }

    /** @return array<int, User> */
    private function participants(): array
    {
        $wanted = $this->option('users');

        $query = DB::table('legacy_usahawan')
            ->where('source_table', 'usahawan')
            ->orderByRaw('cast(legacy_id as unsigned)');

        if ($wanted !== 'all') {
            $query->limit(max(0, (int) $wanted));
        }

        $made = [];

        foreach ($query->get() as $row) {
            [$code, $size] = $this->codeAndSize((string) $row->crafttype);

            $user = User::updateOrCreate(
                ['legacy_id' => (string) $row->legacy_id],
                [
                    'name' => trim((string) $row->name),
                    'password' => bcrypt(Str::random(32)),
                    // The dump's IC comes in two formats, dashed and bare. Kept
                    // as written: normalising it is the transform's decision and
                    // belongs where it can be tested.
                    'ic_number' => trim((string) $row->ic) ?: null,
                    // `comid` is the office's community id, filled on 174 of the
                    // 175 live rows — and 2,182 rows across the dump carry it
                    // with trailing spaces, which is why it is trimmed here
                    // rather than in staging.
                    'community_id' => trim((string) $row->comid) ?: null,
                    'is_active' => true,
                ]
            );

            $company = Company::firstOrNew(['user_id' => $user->id]);

            $company->fill([
                'name' => trim((string) $row->companyname),
                'state' => LegacyZones::translate($row->state),
                'is_active' => true,
            ]);

            /*
             * NO `company_krafs` ROW IS BUILT, and that is a limit of the dump
             * rather than an oversight. The craft hierarchy selection needs both
             * halves — `company_krafs.sub_bidang_id` is NOT NULL — and while
             * `usahawan.bidang` gives the category on all 175 rows, the matching
             * `subcrafttype` is 0% filled in every table of the dump. Inventing
             * a sub-category to satisfy the foreign key would put a craft on a
             * company that the office never recorded.
             *
             * So the Companies list shows those two columns empty for imported
             * rows, which is true. `companies.kraf_code` is the field the draw
             * actually matches on, and that one is filled.
             */

            // `kraf_code` and `plot_size` are NOT fillable, deliberately — the
            // model marks them admin-only, and `plot_size` normally moves
            // through `syncBoothSize()` so that a change clears `verified_at`
            // for every draw list the company sits on. Assigned directly here
            // because a company being created has no draw lists yet: there is no
            // verification to clear, and routing through syncBoothSize would
            // dress an import up as an admin edit.
            $company->kraf_code = $code;
            $company->plot_size = $size;
            $company->save();

            $made[] = $user->load('company');
        }

        return $made;
    }

    /**
     * `crafttype` carries the craft code and the booth size glued to the craft
     * name in the newer rows: 'Manik/Mutiara/Karangan Laut-AMH-3x3' is code AMH
     * at 3x3. SPLIT FROM THE RIGHT, because the craft name itself holds
     * punctuation — reading left to right turns that example into code 'Manik'.
     *
     * The older 89% of the dump carries a bare name with no code and no size
     * ('Songket', 'Kayu(Hiasan Ukiran & Kegunaan)'), and returns nulls.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function codeAndSize(string $crafttype): array
    {
        if (! preg_match('/-([A-Za-z]{2,6})-(\d+x\d+)\s*$/', trim($crafttype), $m)) {
            return [null, null];
        }

        return [strtoupper($m[1]), strtolower($m[2])];
    }

    private function forget(): void
    {
        // `legacy_id` is set only by this command, so nothing the office or
        // the demo seeders made is touched.
        $users = User::whereNotNull('legacy_id')->pluck('id');
        Company::whereIn('user_id', $users)->delete();
        User::whereIn('id', $users)->delete();
        Admin::whereNotNull('legacy_id')->delete();

        $this->line('Removed the accounts this command had made.');
    }
}
