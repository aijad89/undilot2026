<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\User;
use App\Support\AdminZones;
use Illuminate\Support\Facades\DB;

/*
 * The accounts command exists because the dump cannot answer two questions, and
 * these tests pin the answers it invents instead:
 *
 *   `UserRight` is 'Pengawai' on all 137 rows, so the three undilot roles are
 *   ASSIGNED here, not migrated — and the assignment has to be stable, or a test
 *   that signs in as "the super admin" changes person between runs.
 *
 *   Officers become admins with their `loginname` as username; the old
 *   passwords are not carried (random ones are set). Every row built carries
 *   `legacy_id`, because --fresh deletes by it — never what the office or the
 *   demo seeders made.
 */

function loadForAccounts(): void
{
    Artisan::call('import:legacy', [
        'path' => base_path('tests/Fixtures/legacy-dump-sample.sql'),
    ]);
}

it('refuses to run before anything is loaded', function () {
    expect(Artisan::call('legacy:accounts'))->toBe(1);

    expect(Admin::whereNotNull('legacy_id')->exists())->toBeFalse();
});

it('gives the first officer super, and keeps the office name and title', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $super = Admin::where('role', Admin::SUPER_ROLE)
        ->whereNotNull('legacy_id')
        ->first();

    // The fixture's one live officer, by name, from `upengawai.Name`.
    expect($super->name)->toBe('NORAINI BINTI OSMAN')
        ->and($super->username)->toBe('noraini')
        ->and($super->state)->toBeNull('Super sees every zone, so it carries none.');
});

it('builds a participant with their company, craft code and booth size', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // 'Manik/Mutiara/Karangan Laut-AMH-3x3' — the code and size are glued to a
    // craft name that itself holds punctuation.
    $user = User::where('legacy_id', '204')->first();

    expect($user->name)->toBe('SITTI WARDAHAH BINTI ALI DAHLAN')
        ->and($user->ic_number)->toBe('890508-12-0000');

    $company = Company::where('user_id', $user->id)->first();

    expect($company->name)->toBe('WARDAHAMIRAH LEGACY')
        ->and($company->state)->toBe('Perlis')
        ->and($company->kraf_code)->toBe('AMH', 'Split from the RIGHT, or the code reads "Manik".')
        ->and($company->plot_size)->toBe('3x3');
});

it('leaves the code and size null for the older rows that carry neither', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // The fixture's snapshot rows carry a bare 'Songket' — 89% of the real dump
    // looks like this, so guessing a code here would invent data.
    $user = User::where('legacy_id', '205')->first();
    $company = Company::where('user_id', $user->id)->first();

    expect($company->kraf_code)->toBe('RBH')
        ->and($company->plot_size)->toBe('3x3');

    // And one from the live table only: the command reads `source_table` =
    // 'usahawan', so a snapshot row must not become an account at all.
    expect(User::where('legacy_id', '2')->exists())
        ->toBeFalse('Only the live AACB rows become accounts.');
});

it('hands out the same role to the same officer on a re-run', function () {
    loadForAccounts();

    Artisan::call('legacy:accounts');
    $first = Admin::where('username', 'noraini')->first();

    Artisan::call('legacy:accounts');
    $second = Admin::where('username', 'noraini')->first();

    expect($second->id)->toBe($first->id, 'A re-run must update, not duplicate.')
        ->and($second->role)->toBe($first->role);

    expect(Admin::where('username', 'noraini')->count())->toBe(1);
});

it('gives two officers who share one login two accounts, and does not let the second take the first one\'s role', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // The fixture carries ids 30 and 31, both 'NURUL HAIDA BINTI AHMAD' with
    // loginname 'haida' — the same shape as ids 5 and 25 in the real dump.
    //
    // THIS IS THE BUG THIS TEST EXISTS FOR. Keyed on a colliding address,
    // updateOrCreate does not fail: it updates the FIRST officer's row, so the
    // second one takes over the role already handed out. On the real dump that
    // built 17 admins into 16 rows and left no hq admin at all, silently.
    $first = Admin::where('username', 'haida')->first();
    $second = Admin::where('username', 'haida.31')->first();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull('The second officer was swallowed by the first.');

    expect($first->role)->toBe('hq', 'The second officer demoted the hq admin.')
        ->and($first->state)->toBeNull()
        ->and($second->role)->toBe('admin')
        ->and($second->state)->toBe('Terengganu');

    // One role of each at the top, not two state admins.
    expect(Admin::whereNotNull('legacy_id')->where('role', 'hq')->count())->toBe(1)
        ->and(Admin::whereNotNull('legacy_id')->where('role', Admin::SUPER_ROLE)->count())->toBe(1);
});

it('translates a legacy zone the office dropdown does not have', function () {
    loadForAccounts();

    // 'P.Penang' and 'Kedah(Langkawi)' are real values in the dump and neither
    // is an undilot zone. The dump is inconsistent with itself too: it also
    // writes 'Pulau Pinang' and 'Negeri Sembilan' in other rows.
    DB::table('legacy_usahawan')->insert([
        'legacy_id' => '300', 'source_table' => 'usahawan',
        'name' => 'ABDUL AZMI BIN JAAFAR', 'ic' => '591202-07-5507', 'state' => 'P.Penang',
        'companyname' => 'JATI INDAH MULIA', 'crafttype' => 'Batik-TBP-3x3',
        'lot' => 'TBP07', 'location' => 'BASEMENT', 'votetime' => '2025-10-06 11:00:00',
        'bidang' => 'Tekstil', 'salu' => 'Encik', 'preset' => 'TBP07',
        'comid' => '07-3-21  ', 'byuser' => '40', 'imported_at' => now(),
    ]);

    Artisan::call('legacy:accounts', ['--users' => 'all']);

    $company = Company::whereHas('user', fn ($q) => $q->where('legacy_id', '300'))->first();

    // THE BUG THIS LOCKS. Stored raw, `companies.state` is 'P.Penang', which no
    // zone dropdown contains — so the edit form renders with NO option selected
    // and the next save silently moves the company to whichever zone sits first
    // in the list, taking it away from its own state admin with no error.
    expect($company->state)->toBe('Pulau Pinang');

    expect(AdminZones::has($company->state))->toBeTrue(
        "A company on a zone outside AdminZones cannot be edited without being moved."
    );
});

it('puts every imported zone inside the office vocabulary, admins included', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts', ['--users' => 'all']);

    // The fixture's officer 30 carries 'Kedah(Langkawi)'.
    foreach (Admin::whereNotNull('legacy_id')->whereNotNull('state')->get() as $admin) {
        expect(AdminZones::has($admin->state))->toBeTrue(
            "Admin {$admin->username} is on '{$admin->state}', which is not a zone."
        );
    }

    foreach (Company::whereHas('user', fn ($q) => $q->whereNotNull('legacy_id'))->get() as $company) {
        expect(AdminZones::has($company->state))->toBeTrue(
            "Company {$company->name} is on '{$company->state}', which is not a zone."
        );
    }
});

it('collapses two legacy zones that became one, and still gives it one admin', function () {
    loadForAccounts();

    // The old system ran Selangor and Kuala Lumpur as separate offices; undilot
    // has one zone covering Selangor, KL and Putrajaya.
    DB::table('legacy_upengawai')->insert([
        [
            'legacy_id' => '50', 'source_table' => 'upengawai', 'name' => 'OFFICER SELANGOR',
            'userright' => 'Pengawai', 'loginname' => 'sel', 'state' => 'Selangor',
            'imported_at' => now(),
        ],
        [
            'legacy_id' => '51', 'source_table' => 'upengawai', 'name' => 'OFFICER KL',
            'userright' => 'Pengawai', 'loginname' => 'kl', 'state' => 'Kuala Lumpur',
            'imported_at' => now(),
        ],
    ]);

    Artisan::call('legacy:accounts');

    $zone = 'Selangor, Kuala Lumpur dan Putrajaya';

    expect(Admin::where('state', $zone)->count())
        ->toBe(1, 'A zone takes one state admin, however many offices fed into it.');
});

it('refuses a legacy zone it has no mapping for, rather than planting it raw', function () {
    loadForAccounts();

    DB::table('legacy_upengawai')->insert([
        'legacy_id' => '60', 'source_table' => 'upengawai', 'name' => 'OFFICER NOWHERE',
        'userright' => 'Pengawai', 'loginname' => 'nowhere', 'state' => 'Wilayah Baru',
        'imported_at' => now(),
    ]);

    // Silently storing it would put an admin where no company can ever be, and
    // storing the company's side of it would hide the company from everyone.
    expect(fn () => Artisan::call('legacy:accounts'))
        ->toThrow(RuntimeException::class, 'Wilayah Baru');
});

it('carries the community id across, trimmed', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // `comid` is filled on 174 of the 175 live rows, and 2,182 rows across the
    // dump carry it with trailing spaces: '10-1-62  '.
    expect(User::where('legacy_id', '205')->value('community_id'))
        ->toBe('10-1-62');
});

it('never writes a password anybody could know', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $admin = Admin::where('username', 'noraini')->first();

    // Not the login name, not the office name, not a word from the dump.
    foreach (['noraini', 'password', 'Pengawai', 'NORAINI BINTI OSMAN'] as $guess) {
        expect(Hash::check($guess, $admin->password))->toBeFalse("'{$guess}' opened the account.");
    }
});

it('stamps every account it builds with the dump id', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    expect(Admin::whereNull('legacy_id')->count())->toBe(0)
        ->and(User::whereNull('legacy_id')->count())->toBe(0)
        ->and(Admin::count())->toBeGreaterThan(0);
});

it('clears out what it made when asked, and leaves the demo accounts alone', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $outsider = User::factory()->create(['community_id' => 'KOM-REAL']);

    // THE ONE THAT MATTERS. This command once shared a marker with DemoSeeder
    // (an email domain), so `--fresh` deleted both. A seeded demo participant
    // has to survive, or a developer loses their whole demo dataset to a flag
    // that promised to clean up after one command.
    $demoUser = User::factory()->create(['community_id' => 'KOM-0001']);
    $demoAdmin = Admin::factory()->create(['username' => 'demo.hq', 'role' => 'hq', 'state' => null]);

    Artisan::call('legacy:accounts', ['--fresh' => true]);

    expect(User::where('id', $outsider->id)->exists())->toBeTrue()
        ->and(User::where('id', $demoUser->id)->exists())
        ->toBeTrue('--fresh deleted a DemoSeeder participant.')
        ->and(Admin::where('id', $demoAdmin->id)->exists())
        ->toBeTrue('--fresh deleted a DemoSeeder admin.');

    // And it did do its own job: the rebuild leaves exactly one of its admins.
    expect(Admin::where('username', 'noraini')->count())->toBe(1);
});
