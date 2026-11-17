<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Support\AdminZones;
use App\Support\EntrepreneurCategories;
use App\Support\KrafPicker;
use App\Support\PlotSizes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Loads the office's participant list from a CSV.
 *
 * ONE ROW IS ONE PERSON AND THEIR COMPANY, because `companies.user_id` carries a
 * unique index — one user owns exactly one company, as a schema promise. The
 * office's own previous system had the same shape: its `usahawan` table held the
 * person and the company on one row. Splitting them across two sheets would make
 * the office invent a key to join them back together.
 *
 * MATCHED ON IC. Measured across the office dump: 858 participants, the IC
 * filled on every one and unique on every one once normalised to digits — which
 * it must be, because the sheets hold both `890508-12-0000` and `890508120000`.
 * Nothing else comes close: there is no email column at all, and `comid` is
 * filled on 88%.
 *
 * A BLANK CELL MEANS "the office does not have this", and is stored as null
 * rather than guessed. That includes the craft code, which is blank for 657 of
 * the 858: `companies.kraf_code` only SUGGESTS a code when the office adds the
 * company to a draw list (`EntRegistrationController::addToList()` reads
 * `$code ??= $company->kraf_code`), so a company with none is simply a company
 * whose code the office picks at that moment.
 *
 * THE WHOLE FILE IS PLANNED BEFORE ANY ROW IS WRITTEN. A sheet that gives one IC
 * to two people, or names a zone that does not exist, is reported in full and
 * nothing is saved — because a half-applied participant list cannot be told from
 * a complete one by looking at it.
 */
class ImportParticipants extends Command
{
    protected $signature = 'import:participants
        {path : CSV with headers. Needs `nama` and `ic`; every other column is optional.}
        {--dry-run : Report what would be written, write nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Load participants and their companies from the office CSV, matched on IC';

    /** Only `nama` and `ic` are required; a blank cell is a fact, not an error. */
    private const REQUIRED = ['nama', 'ic'];

    /** Every column the importer reads. Anything else in the sheet is ignored and reported. */
    private const KNOWN = [
        'nama', 'ic', 'telefon', 'syarikat', 'zon',
        'kod_kraf', 'saiz_gerai', 'kategori_usahawan', 'no_komuniti', 'alamat',
    ];

    private array $planned = [];
    private array $failed = [];
    private array $ignoredColumns = [];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = $this->read($path);

        if ($rows === null) {
            return self::FAILURE;
        }

        if ($rows === []) {
            $this->error('The file has no data rows.');

            return self::FAILURE;
        }

        $this->plan($rows);
        $this->report();

        if ($this->planned === []) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line('DRY RUN — nothing was written.');

            return self::SUCCESS;
        }

        if ($this->failed !== [] && ! $this->option('force') && ! $this->confirm(
            count($this->failed).' row(s) will be skipped. Write the other '.count($this->planned).'?'
        )) {
            $this->line('Nothing was written.');

            return self::SUCCESS;
        }

        $written = $this->write();

        $this->newLine();
        $this->line("Written: {$written['created']} new, {$written['updated']} updated.");

        // Participants sign in with community ID + IC (LoginRequest). Without
        // the ID the row is written, but that person cannot sign in.
        $noId = count(array_filter($this->planned, fn ($p) => $p['community'] === ''));

        if ($noId > 0) {
            $this->newLine();
            $this->warn("{$noId} participant(s) have no community ID (no_komuniti) and CANNOT SIGN IN until one is added.");
        }

        return $this->failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function read(string $path): ?array
    {
        $handle = fopen($path, 'r');
        $header = self::row($handle);

        if ($header === false) {
            fclose($handle);
            $this->error('The file is empty.');

            return null;
        }

        // Excel writes a BOM, which would make the first header read as
        // "\u{FEFF}nama" and match nothing. Str::trim() strips it — U+FEFF is one
        // of the characters it lists as invisible — but PHP's own trim() does NOT.
        // So this stays Str::of()->trim() and must not be "simplified" to trim().
        $header = array_map(
            fn ($h) => Str::of((string) $h)->trim()->lower()->toString(),
            $header
        );

        foreach (self::REQUIRED as $needed) {
            if (! in_array($needed, $header, true)) {
                fclose($handle);
                $this->error("The CSV needs a `{$needed}` column. Found: ".implode(', ', $header));

                return null;
            }
        }

        // Columns the office added for its own use are reported, not silently
        // dropped: a misspelled `alamat` looks exactly like an extra column.
        // An `emel` column lands here too — the project keeps no email.
        $this->ignoredColumns = array_values(array_filter(
            $header,
            fn ($h) => $h !== '' && ! in_array($h, self::KNOWN, true) && ! str_starts_with($h, '_')
        ));

        $rows = [];
        $line = 1;

        while (($values = self::row($handle)) !== false) {
            $line++;

            if ($values === [null] || $values === []) {
                continue;
            }

            $row = ['_line' => $line];

            foreach ($header as $i => $name) {
                if ($name !== '') {
                    $row[$name] = trim((string) ($values[$i] ?? ''));
                }
            }

            // A wholly blank line in the middle of a sheet is Excel, not data.
            if (($row['nama'] ?? '') === '' && ($row['ic'] ?? '') === '') {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Works out every write before making any of them.
     */
    private function plan(array $rows): void
    {
        $zones = AdminZones::all();
        $codes = array_map('strtoupper', KrafPicker::codes()->pluck('code')->all());
        $sizes = PlotSizes::all();
        // A flat list of labels, not a key => label map: `array_keys()` here
        // would compare against [0, 1, 2…] and reject every valid category.
        $categories = EntrepreneurCategories::all();

        $claimedIc = [];

        foreach ($rows as $row) {
            $line = $row['_line'];
            $name = $row['nama'] ?? '';
            $ic = self::digits($row['ic'] ?? '');

            if ($name === '') {
                $this->failed[] = "line {$line}: no name";

                continue;
            }

            if ($ic === '') {
                $this->failed[] = "line {$line}: {$name} has no IC, and the IC is how a row is matched";

                continue;
            }

            if (isset($claimedIc[$ic])) {
                // Both rows are dropped. Nothing in the sheet says which person
                // the office meant, so keeping the first would write a coin toss
                // into somebody's identity.
                $this->failed[] = "line {$line}: IC {$row['ic']} is also on line {$claimedIc[$ic]} — both dropped";
                $this->planned = array_values(array_filter($this->planned, fn ($p) => $p['ic'] !== $ic));

                continue;
            }

            $zone = $row['zon'] ?? '';

            if ($zone !== '' && ! in_array($zone, $zones, true)) {
                // Stored raw, a zone outside the list hides the company from its
                // own state admin AND renders its edit form with no option
                // selected — the next save then moves it elsewhere, silently.
                $this->failed[] = "line {$line}: {$name} is on zone '{$zone}', which is not one of the "
                    .count($zones).' zones';

                continue;
            }

            $code = strtoupper($row['kod_kraf'] ?? '');

            if ($code !== '' && ! in_array($code, $codes, true)) {
                $this->failed[] = "line {$line}: {$name} has craft code '{$code}', which is not on the coding sheet";

                continue;
            }

            $size = strtolower($row['saiz_gerai'] ?? '');

            if ($size !== '' && ! in_array($size, $sizes, true)) {
                $this->failed[] = "line {$line}: {$name} has booth size '{$size}'; allowed: ".implode(', ', $sizes);

                continue;
            }

            $category = $row['kategori_usahawan'] ?? '';

            if ($category !== '' && ! in_array($category, $categories, true)) {
                $this->failed[] = "line {$line}: {$name} has entrepreneur category '{$category}', which is not a known one";

                continue;
            }

            $claimedIc[$ic] = $line;

            $this->planned[] = [
                'line' => $line,
                'ic' => $ic,
                'name' => $name,
                'ic_raw' => $row['ic'],
                'phone' => $row['telefon'] ?? '',
                'company' => $row['syarikat'] ?? '',
                'zone' => $zone,
                'code' => $code,
                'size' => $size,
                'category' => $category,
                'community' => $row['no_komuniti'] ?? '',
                'address' => $row['alamat'] ?? '',
            ];
        }
    }

    /**
     * @return array{created: int, updated: int}
     */
    private function write(): array
    {
        $created = 0;
        $updated = 0;

        // Existing participants keyed the same way the sheet is matched, so a
        // re-run updates the same person rather than adding a second one.
        $byIc = User::whereNotNull('ic_number')->get()
            ->groupBy(fn (User $u) => self::digits($u->ic_number))
            ->reject(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => $group->first());

        foreach ($this->planned as $plan) {
            try {
                DB::transaction(function () use ($plan, $byIc, &$created, &$updated) {
                    $user = $byIc[$plan['ic']] ?? null;

                    if ($user === null) {
                        $user = new User;
                        // Random, and never told to anyone: participants sign
                        // in with community ID + IC, not a password.
                        $user->password = bcrypt(Str::random(32));
                        $created++;
                    } else {
                        $updated++;
                    }

                    $user->name = $plan['name'];
                    $user->ic_number = $plan['ic_raw'];
                    $user->phone = $plan['phone'] !== '' ? $plan['phone'] : null;
                    $user->community_id = $plan['community'] !== '' ? $plan['community'] : null;
                    $user->address = $plan['address'] !== '' ? $plan['address'] : null;
                    $user->is_active = true;
                    $user->save();

                    $company = Company::firstOrNew(['user_id' => $user->id]);

                    $company->fill([
                        'user_id' => $user->id,
                        // A participant with no company name still needs a row:
                        // `companies.name` is NOT NULL and the draw list reads it.
                        'name' => $plan['company'] !== '' ? $plan['company'] : $plan['name'],
                        'state' => $plan['zone'] !== '' ? $plan['zone'] : null,
                        'entrepreneur_category' => $plan['category'] !== '' ? $plan['category'] : null,
                        'is_active' => true,
                    ]);

                    // Not fillable, deliberately — the model marks them
                    // admin-only, and `plot_size` normally travels through
                    // syncBoothSize() so a change clears `verified_at` on every
                    // draw list the company sits on. A company being imported
                    // has no draw lists yet: there is no verification to clear.
                    $company->kraf_code = $plan['code'] !== '' ? $plan['code'] : null;
                    $company->plot_size = $plan['size'] !== '' ? $plan['size'] : null;
                    $company->save();
                });
            } catch (Throwable $e) {
                $created = max(0, $created - 1);
                $this->failed[] = "line {$plan['line']}: {$plan['name']} — "
                    .explode(' (', $e->getMessage())[0];
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    private function report(): void
    {
        $this->newLine();

        if ($this->ignoredColumns !== []) {
            $this->warn('Columns not read (check for a misspelling): '.implode(', ', $this->ignoredColumns));
            $this->newLine();
        }

        $this->line('Rows to write: '.count($this->planned).'   skipped: '.count($this->failed));

        $blank = fn (string $key) => count(array_filter($this->planned, fn ($p) => $p[$key] === ''));

        $this->newLine();
        $this->line('Blank cells, stored as nothing rather than guessed:');

        foreach (['phone' => 'telefon', 'company' => 'syarikat', 'zone' => 'zon',
            'code' => 'kod_kraf', 'size' => 'saiz_gerai', 'category' => 'kategori_usahawan',
            'community' => 'no_komuniti', 'address' => 'alamat'] as $key => $label) {
            $n = $blank($key);

            if ($n > 0) {
                $this->line(sprintf('  %-20s %d of %d', $label, $n, count($this->planned)));
            }
        }

        foreach (array_slice($this->failed, 0, 25) as $line) {
            $this->warn('  skip  '.$line);
        }

        if (count($this->failed) > 25) {
            $this->warn('  … and '.(count($this->failed) - 25).' more');
        }
    }

    /**
     * One CSV row, with the escape character named rather than defaulted.
     *
     * `''` is the RFC 4180 reading and what Excel writes: a backslash is an
     * ordinary character. PHP's historical default is `'\\'`, which makes a
     * backslash escape whatever follows it — so `Kraf \"A\"` parses differently
     * under each. PHP 8.4 deprecates relying on the default precisely because
     * PHP 9 switches it from `'\\'` to `''`, which would change how this
     * office file is read on the day the box is upgraded, with no error and no
     * failing test. Naming it fixes the reading to the correct one now.
     *
     * Measured on the office's 858-row export: not one row contains a
     * backslash, so this changes nothing about what is imported today.
     *
     * @param  resource  $handle
     * @return array<int, string|null>|false
     */
    private static function row($handle): array|false
    {
        return fgetcsv($handle, null, ',', '"', '');
    }

    /** Malaysian IC is written both as 890508-12-0000 and as 890508120000. */
    private static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
