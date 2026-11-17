<?php

use App\Models\Company;
use App\Models\KrafBidang;
use App\Models\User;
use App\Support\AdminZones;

/*
 * The office keeps its participant list in Excel, so a CSV is how it reaches
 * this app. These tests pin the decisions that make the import safe to re-run
 * and safe to get wrong:
 *
 *   A BLANK CELL IS A FACT, not a thing to guess at. Measured on the office's
 *   own 858-row list: no email on any row, no phone on any row, and no craft
 *   code on 657 of them. Filling those in would be inventing data.
 *
 *   MATCHED ON IC, because nothing else can be. Across those 858 rows the IC is
 *   filled on every one and unique on every one once normalised to digits — and
 *   it has to be normalised, because the sheets hold both 890508-12-0000 and
 *   890508120000.
 */

function csvFile(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'peserta').'.csv';
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

beforeEach(function () {
    // The coding sheet is reference data a real install seeds; the two codes
    // these tests name have to exist for the importer to accept them.
    KrafBidang::factory()->code('TBPS')->create();
    KrafBidang::factory()->code('TBP')->create();
});

const CSV_HEAD = 'nama,ic,telefon,emel,syarikat,zon,kod_kraf,saiz_gerai,kategori_usahawan,no_komuniti';

it('writes a participant and their company from one row', function () {
    $exit = Artisan::call('import:participants', [
        'path' => csvFile([
            CSV_HEAD,
            'SITI BINTI ALI,890508-12-0000,0123456789,siti@gmail.com,Batik Warisan,Johor,TBPS,3x3,,07-3-8',
        ]),
        '--force' => true,
    ]);

    expect($exit)->toBe(0);

    $user = User::where('ic_number', '890508-12-0000')->first();

    expect($user->name)->toBe('SITI BINTI ALI')
        ->and($user->email)->toBe('siti@gmail.com')
        ->and($user->phone)->toBe('0123456789')
        ->and($user->community_id)->toBe('07-3-8');

    // One row is one person AND their company: companies.user_id is unique, so
    // the two cannot be anything but 1:1.
    $company = Company::where('user_id', $user->id)->first();

    expect($company->name)->toBe('Batik Warisan')
        ->and($company->state)->toBe('Johor')
        ->and($company->kraf_code)->toBe('TBPS')
        ->and($company->plot_size)->toBe('3x3');
});

it('stores a blank cell as nothing rather than guessing', function () {
    Artisan::call('import:participants', [
        'path' => csvFile([CSV_HEAD, 'SALMAH BINTI AZIZ,660814-02-0000,,,ABAS SAL BATIK,Johor,,,,']),
        '--force' => true,
    ]);

    $user = User::where('ic_number', '660814-02-0000')->first();
    $company = Company::where('user_id', $user->id)->first();

    expect($user->phone)->toBeNull()
        ->and($user->community_id)->toBeNull()
        ->and($company->kraf_code)->toBeNull('657 of the office 858 have no code; inventing one is worse than none.')
        ->and($company->plot_size)->toBeNull()
        ->and($company->entrepreneur_category)->toBeNull();
});

it('gives a participant with no email one that cannot receive mail', function () {
    Artisan::call('import:participants', [
        'path' => csvFile([CSV_HEAD, 'AHMAD BIN ALI,700101-01-1111,,,Kraf Ahmad,Kedah,,,,']),
        '--force' => true,
    ]);

    $email = User::where('ic_number', '700101-01-1111')->value('email');

    // `users.email` is unique and required, so a row with none still needs an
    // address — on a reserved TLD, so nobody mistakes it for a working one. The
    // participant stays locked out until the office supplies a real address and
    // `legacy:emails` moves it across, matched on the same IC.
    expect(str_ends_with($email, '@'.App\Console\Commands\LegacyAccounts::MAIL_DOMAIN))
        ->toBeTrue("Generated address '{$email}' is not on the reserved domain.");
});

it('refuses a zone that is not one of the office zones', function () {
    // Stored raw, a zone outside the list hides the company from its own state
    // admin AND renders its edit form with no option selected — the next save
    // then moves it elsewhere with no error. That was measured on real rows.
    $exit = Artisan::call('import:participants', [
        'path' => csvFile([CSV_HEAD, 'LIM AH KAU,600101-02-1111,,,Kraf Lim,Selangor,,,,']),
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(User::where('ic_number', '600101-02-1111')->exists())->toBeFalse();

    // And the correct spelling of that same zone is accepted.
    Artisan::call('import:participants', [
        'path' => csvFile([CSV_HEAD, 'LIM AH KAU,600101-02-1111,,,Kraf Lim,"Selangor, Kuala Lumpur dan Putrajaya",,,,']),
        '--force' => true,
    ]);

    $state = Company::whereHas('user', fn ($q) => $q->where('ic_number', '600101-02-1111'))->value('state');

    expect(in_array($state, AdminZones::all(), true))->toBeTrue("Zone '{$state}' is not in the list.");
});

it('refuses a craft code that is not on the coding sheet', function () {
    $exit = Artisan::call('import:participants', [
        'path' => csvFile([CSV_HEAD, 'ZAID BIN OMAR,750101-03-1111,,,Kraf Zaid,Kedah,ZZZ,3x3,,']),
        '--force' => true,
    ]);

    // A code nothing recognises cannot be matched by any plot, so a draw carrying
    // it could never open.
    expect($exit)->toBe(1)
        ->and(User::where('ic_number', '750101-03-1111')->exists())->toBeFalse();
});

it('refuses a booth size the plan cannot build', function () {
    $exit = Artisan::call('import:participants', [
        'path' => csvFile([CSV_HEAD, 'NOOR BINTI HASSAN,760101-04-1111,,,Kraf Noor,Kedah,TBPS,4x4,,']),
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(User::where('ic_number', '760101-04-1111')->exists())->toBeFalse();
});

it('drops both rows when one IC names two people', function () {
    $exit = Artisan::call('import:participants', [
        'path' => csvFile([
            CSV_HEAD,
            'FIRST PERSON,890508-12-0000,,,Company One,Johor,,,,',
            'SECOND PERSON,890508120000,,,Company Two,Kedah,,,,',
        ]),
        '--force' => true,
    ]);

    // The two spellings are the same IC once normalised. Nothing in the sheet
    // says which person the office meant, so keeping the first would write a
    // coin toss into somebody's identity.
    expect($exit)->toBe(1)
        ->and(User::where('name', 'FIRST PERSON')->exists())->toBeFalse()
        ->and(User::where('name', 'SECOND PERSON')->exists())->toBeFalse();
});

it('updates on a re-run instead of colliding with the unique email', function () {
    $file = csvFile([CSV_HEAD, 'SITI BINTI ALI,890508-12-0000,,,Batik Warisan,Johor,TBPS,3x3,,']);

    Artisan::call('import:participants', ['path' => $file, '--force' => true]);
    Artisan::call('import:participants', ['path' => $file, '--force' => true]);

    expect(User::where('ic_number', '890508-12-0000')->count())->toBe(1)
        ->and(Company::count())->toBe(1);

    // A corrected sheet changes the row rather than adding a second one.
    Artisan::call('import:participants', [
        'path' => csvFile([CSV_HEAD, 'SITI BINTI ALI,890508-12-0000,,,Batik Warisan Baharu,Kedah,TBP,3x6,,']),
        '--force' => true,
    ]);

    $company = Company::first();

    expect(Company::count())->toBe(1)
        ->and($company->name)->toBe('Batik Warisan Baharu')
        ->and($company->state)->toBe('Kedah')
        ->and($company->kraf_code)->toBe('TBP');
});

it('does not overwrite a real address with a generated one on a re-run', function () {
    $user = User::factory()->create([
        'ic_number' => '890508-12-0000',
        'email' => 'siti@gmail.com',
    ]);

    // The sheet's email column is blank, but this participant already proved an
    // address is theirs. Generating over it would lock them out again.
    Artisan::call('import:participants', [
        'path' => csvFile([CSV_HEAD, 'SITI BINTI ALI,890508-12-0000,,,Batik Warisan,Johor,,,,']),
        '--force' => true,
    ]);

    expect($user->fresh()->email)->toBe('siti@gmail.com');
});

it('reads a file Excel saved, byte-order mark and all', function () {
    $path = tempnam(sys_get_temp_dir(), 'bom').'.csv';
    file_put_contents($path, "\xEF\xBB\xBF".CSV_HEAD."\nSITI,890508-12-0000,,,Kraf Siti,Johor,,,,\n");

    expect(Artisan::call('import:participants', ['path' => $path, '--force' => true]))->toBe(0)
        ->and(User::where('ic_number', '890508-12-0000')->exists())->toBeTrue();
});

it('reads a backslash as an ordinary character, not as an escape', function () {
    // Measured, because the two readings differ by more than one character. PHP's
    // historical escape default is '\\', under which the \" below reads as an
    // escaped quote: the quoted field never closes and the WHOLE REST OF THE LINE
    // becomes one value —
    //
    //     escape '\\'  =>  ["Kraf A\",890508120000\n"]
    //     escape ''    =>  ["Kraf A\", "890508120000"]
    //
    // so the IC column disappears, and a row the office can see plainly is
    // reported as having no IC and skipped. PHP 9 changes that default to '',
    // which is why the importer names it rather than inheriting it.
    $path = tempnam(sys_get_temp_dir(), 'bslash').'.csv';
    file_put_contents($path, "nama,ic,syarikat\n".'SITI,890508-12-0000,"Kraf A\\"'."\n");

    $exit = Artisan::call('import:participants', ['path' => $path, '--force' => true]);

    expect($exit)->toBe(0);

    $user = User::where('ic_number', '890508-12-0000')->first();

    expect($user)->not->toBeNull('The IC column was swallowed by the backslash.')
        ->and($user->company->name)->toBe('Kraf A\\');
});

it('writes nothing on a dry run', function () {
    $exit = Artisan::call('import:participants', [
        'path' => csvFile([CSV_HEAD, 'SITI,890508-12-0000,,,Kraf Siti,Johor,,,,']),
        '--dry-run' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(User::where('ic_number', '890508-12-0000')->exists())->toBeFalse();
});

it('names a column it does not read, because a misspelling looks like an extra one', function () {
    Artisan::call('import:participants', [
        'path' => csvFile([
            'nama,ic,emal,syarikat',
            'SITI,890508-12-0000,siti@gmail.com,Kraf Siti',
        ]),
        '--force' => true,
    ]);

    // `emal` is a typo for `emel`; silently ignoring it would generate an
    // address and lock the participant out while the sheet looked right.
    expect(Artisan::output())->toContain('emal');

    expect(str_ends_with(User::where('ic_number', '890508-12-0000')->value('email'), '.test'))
        ->toBeTrue('The misspelled column should not have been read as the email.');
});

it('stops when the sheet has no IC column at all', function () {
    $exit = Artisan::call('import:participants', [
        'path' => csvFile(['nama,syarikat', 'SITI,Kraf Siti']),
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(User::count())->toBe(0);
});

it('reads the address, commas and all, onto the participant', function () {
    $exit = Artisan::call('import:participants', [
        'path' => csvFile([
            CSV_HEAD.',alamat',
            'SITI BINTI ALI,890508-12-0000,,,,Johor,,,,,"No 12, Jalan Kraf, 81100 Johor Bahru"',
        ]),
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(User::where('ic_number', '890508-12-0000')->value('address'))->toBe('No 12, Jalan Kraf, 81100 Johor Bahru');
});

it('takes only the office\'s two entrepreneur categories', function () {
    $exit = Artisan::call('import:participants', [
        'path' => csvFile([
            CSV_HEAD,
            'SITI BINTI ALI,890508-12-0000,,,,Johor,,,Pemborong,',
            'ALI BIN ABU,800601-10-0000,,,,Johor,,,"Micro, Small & Medium (MSM)",',
        ]),
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Company::whereHas('user', fn ($q) => $q->where('ic_number', '890508-12-0000'))->value('entrepreneur_category'))->toBe('Pemborong')
        ->and(User::where('ic_number', '800601-10-0000')->exists())->toBeFalse();
});
