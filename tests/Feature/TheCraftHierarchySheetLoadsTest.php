<?php

use App\Models\KrafSubBidang;
use Database\Seeders\KrafCodeSeeder;

it('loads the sheet: blanks carry down, cells stay whole, "-" is kept, reruns add nothing', function () {
    $this->seed(KrafCodeSeeder::class);
    $csv = tempnam(sys_get_temp_dir(), 'hk');
    file_put_contents($csv, "\u{FEFF}Bidang,Sub-bidang,Pengelasan sub-bidang,Contoh produk\n"
        ."Tekstil,Batik,\"Batik canting, batik lukis\",\"Pakaian, selendang\"\n"
        .",,\"Batik blok\n/terap,\",\n"
        .",Piuter,-,Loket\n");

    $this->artisan('kraf:import-hierarchy', ['file' => $csv])->assertSuccessful();
    $this->artisan('kraf:import-hierarchy', ['file' => $csv])
        ->expectsOutputToContain('Added 0 sub-categories, 0 classifications, 0 products')->assertSuccessful();

    $batik = KrafSubBidang::where('name', 'Batik')->firstOrFail();
    expect($batik->pengelasan()->pluck('name')->all())->toBe(['Batik canting, batik lukis', 'Batik blok /terap'])
        ->and($batik->produks()->pluck('name')->all())->toBe(['Pakaian, selendang'])
        ->and($batik->bidang->name)->toBe('TEKSTIL');

    $piuter = KrafSubBidang::where('name', 'Piuter')->firstOrFail();
    expect($piuter->pengelasan()->pluck('name')->all())->toBe(['-'])
        ->and($piuter->produks()->pluck('name')->all())->toBe(['Loket'])
        ->and($piuter->bidang->name)->toBe('TEKSTIL');
});

it('writes nothing when a category is not one of the seeded five', function () {
    $this->seed(KrafCodeSeeder::class);
    $csv = tempnam(sys_get_temp_dir(), 'hk');
    file_put_contents($csv, "Bidang,Sub-bidang,Pengelasan sub-bidang,Contoh produk\nTekstil,Batik,,\nKaca,Botol,,\n");

    $this->artisan('kraf:import-hierarchy', ['file' => $csv])->assertFailed();

    expect(KrafSubBidang::count())->toBe(0);
});

it('loads the shipped hierarchy file in full', function () {
    $this->seed(KrafCodeSeeder::class);

    $this->artisan('kraf:import-hierarchy', ['file' => database_path('data/hierarki-kraf.csv')])->assertSuccessful();

    // sub-categories, classifications, products as entered 2026-11-18
    expect([KrafSubBidang::count(), \App\Models\KrafPengelasan::count(), \App\Models\KrafProduk::count()])->toBe([35, 76, 138]);
});

it('is loaded by db:seed on a server, with no demo entries beside it', function () {
    foreach (['ADMIN_SEED_USERNAME' => 'superadmin', 'ADMIN_SEED_PASSWORD' => 'not-a-real-one'] as $k => $v) {
        putenv("$k=$v");
        $_ENV[$k] = $_SERVER[$k] = $v;
    }
    app()['env'] = 'production';

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect([KrafSubBidang::count(), \App\Models\KrafPengelasan::count(), \App\Models\KrafProduk::count()])->toBe([35, 76, 138]);
})->after(function () {
    foreach (['ADMIN_SEED_USERNAME', 'ADMIN_SEED_PASSWORD'] as $k) {
        putenv($k);
        unset($_ENV[$k], $_SERVER[$k]);
    }
});
