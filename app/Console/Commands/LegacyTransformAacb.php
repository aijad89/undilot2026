<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\KrafBidang;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns the loaded AACB rows into a real event with its plan, its plots and its
 * draw results.
 *
 * WHY ONLY AACB. The dump holds 6,842 booth allocations across a dozen events,
 * and 1,464 of them point at a usahawan that no longer exists in any table —
 * `usahawan` is a working table, not an archive, and the office emptied it
 * between events. HKK 2022/2023/2024 and FKJ 2023 lost every participant that
 * way. AACB is the one event whose pair survives intact: 175 participants, 175
 * booths, 174 allocations, and `ukhemah.boothid` agrees with `usahawan.lot` on
 * ALL 174 of them. It is also the only data carrying craft codes and plot sizes.
 *
 * WHAT THE DUMP CANNOT SAY, and this command therefore asks for:
 *
 *   The event's TITLE, its VENUE, and the dates the fair itself ran. There is no
 *   events table in the old system at all — `syatemconfiguration` is the 2007
 *   application's own config — so event identity lives only in table names and
 *   in three codes: AACB, FKJ, HKK. Options supply them; the defaults are
 *   placeholders and the report says so in as many words.
 *
 * WHAT THE DUMP DOES SAY, and this command therefore does not invent:
 *
 *   The DRAW WINDOW is real: `votetime` runs 2025-10-06 10:02 to 13:54, so the
 *   draw opened and closed that morning. The LOCATIONS are real (BASEMENT, 111
 *   booths; KHEMAH A, 64). The CODES and SIZES are real and every one of the 21
 *   distinct `crafttype` values resolves against the seeded coding sheet.
 *
 * IT WAS A PRESET DRAW. `usahawan.lot` equals `usahawan.preset` on all 175 rows,
 * so the office assigned every booth and the participant confirmed it. The event
 * is written with `use_preset`, the plots with `allocation_type = 'preset'`, and
 * a `preset_allocations` row is kept for each — a draw rebuilt as if the
 * algorithm had run it would be a different event from the one that happened.
 */
class LegacyTransformAacb extends Command
{
    protected $signature = 'legacy:transform-aacb
        {--title= : The event title. The dump has only the code "AACB".}
        {--venue= : The venue. The dump names halls, never the building.}
        {--starts= : Fair start date (Y-m-d). Not in the dump.}
        {--ends= : Fair end date (Y-m-d). Not in the dump.}
        {--dry-run : Report what would be written, write nothing}
        {--fresh : Delete a previously transformed AACB event first}';

    protected $description = 'Build the AACB event, its lot plan, its plots and its draw results from staging';

    /** The one event code whose participant rows survive in the dump. */
    private const EVENT_CODE = 'AACB';

    private array $notes = [];

    public function handle(): int
    {
        if (DB::table('legacy_ukhemah')->where('source_table', 'ukhemah')->doesntExist()) {
            $this->error('No AACB rows in staging. Run import:legacy first.');

            return self::FAILURE;
        }

        $booths = DB::table('legacy_ukhemah')->where('source_table', 'ukhemah')->get();
        $people = DB::table('legacy_usahawan')->where('source_table', 'usahawan')->get()->keyBy('legacy_id');

        $users = $this->resolveUsers($people);

        if ($users === null) {
            return self::FAILURE;
        }

        $codes = KrafBidang::codes()->get()->keyBy(fn ($row) => strtoupper($row->code));
        $plan = $this->plan($booths, $codes);

        if ($plan['unknown'] !== []) {
            $this->error('Craft codes with no row on the coding sheet: '.implode(', ', $plan['unknown']));
            $this->line('Seed them first — a plot carrying an unknown code can never be matched.');

            return self::FAILURE;
        }

        $window = $this->drawWindow($people);

        if ($this->option('dry-run')) {
            $this->preview($plan, $booths, $window);

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $this->forget();
        }

        DB::transaction(function () use ($plan, $booths, $people, $users, $window, $codes) {
            $event = $this->event($plan, $window);
            $sizeIds = $this->planRows($event, $plan, $codes);
            $plots = $this->plots($event, $booths, $sizeIds, $codes);
            $this->draw($event, $booths, $people, $users, $plots, $sizeIds, $codes);

            // Dicatat di sini, bukan dibaca semula daripada acara: lihat EVENT_KEY.
            Setting::set(self::EVENT_KEY, (string) $event->id);

            $this->info("Event #{$event->id} — {$event->title}");
        });

        $this->summary();

        return self::SUCCESS;
    }

    /**
     * The participants must already exist as accounts. They are not built here
     * because `legacy:accounts` builds them, and a person is not event data: the
     * same usahawan appears across events, and importing a second event must not
     * create a second copy of them.
     *
     * @return array<string, int>|null  legacy usahawan id => users.id
     */
    private function resolveUsers($people): ?array
    {
        // legacy:accounts stamps each account with the dump's own id.
        $found = User::whereIn('legacy_id', $people->keys()->map(fn ($id) => (string) $id))
            ->pluck('id', 'legacy_id');

        $map = [];
        $missing = 0;

        foreach ($people as $legacyId => $row) {
            if (! isset($found[(string) $legacyId])) {
                $missing++;

                continue;
            }

            $map[(string) $legacyId] = $found[(string) $legacyId];
        }

        if ($missing > 0) {
            $this->error("{$missing} of {$people->count()} participants have no account yet.");
            $this->line('Run: php artisan legacy:accounts --users=all');

            return null;
        }

        return $map;
    }

    /**
     * The lot plan, rebuilt from the booths themselves.
     *
     * A `plot_sizes` row is a (location, size) PAIR — the plan screen types a
     * location with a count and nests its craft allocations underneath. So the
     * booths are grouped the same way: by the hall they stand in and the size
     * glued to their `crafttype`, then counted per craft code within each pair.
     *
     * TRIMMING HAPPENS HERE, not in staging. The dump holds 'TBA06 ' and
     * '10-1-62  ', and staging keeps them exactly because cleaning is a decision
     * — this is where the decision is made, and where a test can hold it.
     */
    private function plan($booths, $codes): array
    {
        $pairs = [];
        $unknown = [];

        foreach ($booths as $booth) {
            $location = $this->location($booth->khemah);
            [$code, $size] = $this->codeAndSize((string) $booth->crafttype);

            if ($code === null) {
                // 89% of the dump's rows are like this, but none of AACB's are.
                $unknown[] = '(uncoded: '.trim((string) $booth->crafttype).')';

                continue;
            }

            if (! $codes->has($code)) {
                $unknown[] = $code;

                continue;
            }

            $key = $location.'|'.$size;
            $pairs[$key] ??= ['location' => $location, 'size' => $size, 'quantity' => 0, 'codes' => []];
            $pairs[$key]['quantity']++;
            $pairs[$key]['codes'][$code] = ($pairs[$key]['codes'][$code] ?? 0) + 1;
        }

        ksort($pairs);

        return ['pairs' => $pairs, 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * The draw window, which IS in the dump: `votetime` is when each participant
     * confirmed, so the earliest and latest are when the draw ran. Taken rather
     * than invented, unlike the fair's own dates.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function drawWindow($people): array
    {
        $times = $people
            ->pluck('votetime')
            ->filter(fn ($t) => $t && ! str_starts_with((string) $t, '0000'))
            ->map(fn ($t) => Carbon::parse($t))
            ->sort()
            ->values();

        if ($times->isEmpty()) {
            $this->notes[] = 'No usable `votetime`: the draw window was invented, not read.';

            return [now()->subMonth(), now()->subMonth()->addHours(4)];
        }

        return [$times->first(), $times->last()];
    }

    private function event(array $plan, array $window): Event
    {
        [$openedAt, $closedAt] = $window;

        $title = $this->option('title') ?: self::EVENT_CODE.' '.$openedAt->year;
        $venue = $this->option('venue') ?: 'To be confirmed';

        // The fair's own dates are nowhere in the dump. Anchored to the draw so
        // the row is at least internally consistent, and reported as a guess.
        $starts = $this->option('starts')
            ? Carbon::parse($this->option('starts'))->startOfDay()
            : $openedAt->copy()->addWeek()->startOfDay();

        $ends = $this->option('ends')
            ? Carbon::parse($this->option('ends'))->endOfDay()
            : $starts->copy()->addDays(3)->endOfDay();

        if (! $this->option('title')) {
            $this->notes[] = "Title defaulted to \"{$title}\" — the dump holds only the code AACB.";
        }
        if (! $this->option('venue')) {
            $this->notes[] = 'Venue is a placeholder: the dump names halls (BASEMENT, KHEMAH A), never the building.';
        }
        if (! $this->option('starts') || ! $this->option('ends')) {
            $this->notes[] = 'Fair dates are GUESSES anchored to the draw ('
                .$starts->toDateString().' to '.$ends->toDateString().'). The office must confirm them.';
        }

        $total = array_sum(array_column($plan['pairs'], 'quantity'));

        return Event::create([
            'title' => $title,
            'description' => 'Imported from the previous system (event code '
                .self::EVENT_CODE.'). The lot draw ran on '.$openedAt->toDateString().'.',
            'location' => $venue,
            'start_date' => $starts,
            'end_date' => $ends,

            // Registration closed before the draw opened; there is no record of
            // when it opened, so it is anchored a month earlier.
            'registration_open_at' => $openedAt->copy()->subMonth(),
            'registration_close_at' => $openedAt->copy()->subDay(),

            'status' => 'completed',
            'polling_status' => 'completed',
            'polling_start_date' => $openedAt,
            'polling_deadline' => $closedAt,

            'max_participants' => $total,

            // `lot` equals `preset` on all 175 rows: the office assigned every
            // booth and the participant confirmed it.
            'use_preset' => true,

            // Nothing may auto-transition a finished event back into life.
            'auto_open' => false,
            'auto_close' => false,
            'auto_polling_open' => false,
            'auto_polling_close' => false,
        ]);
    }

    /**
     * `plot_sizes` and the craft allocations nested under them.
     *
     * @return array<string, int>  "LOCATION|SIZE" => plot_sizes.id
     */
    private function planRows(Event $event, array $plan, $codes): array
    {
        $ids = [];
        $total = 0;

        foreach ($plan['pairs'] as $key => $pair) {
            $size = $event->plotSizes()->create([
                'size' => $pair['size'],
                'quantity' => $pair['quantity'],
                'location' => $pair['location'],
            ]);

            $ids[$key] = $size->id;
            $total += $pair['quantity'];

            foreach ($pair['codes'] as $code => $count) {
                $event->bidangAllocations()->create([
                    // THE CODED HALF of kraf_bidangs, never a category row: a
                    // plot tagged with a category can be matched by no
                    // registration, because a registration carries a code.
                    'bidang_id' => $codes[$code]->id,
                    'sub_bidang_id' => null,
                    'plot_size_id' => $size->id,
                    'plots_count' => $count,
                ]);
            }
        }

        // `total_plots` is NOT fillable, deliberately: the model marks it as
        // computed from the plan rather than taken from a form. So it is set
        // here, after the plan rows exist and the count is a fact — which is
        // exactly when `PollingController::savePlan()` sets it too.
        $event->total_plots = $total;
        $event->save();

        return $ids;
    }

    /**
     * One plot per booth, keeping the office's own booth id as the plot code.
     *
     * NOT REGENERATED. `Plot::codeFor()` would produce 'BASEMENT-TBP1' where the
     * office wrote 'TBP01', and the office's id is the one printed on the booth,
     * written on the slip and matched by `usahawan.lot` on all 174 allocations.
     * Renaming them would make the imported draw unrecognisable to the people
     * who ran it.
     *
     * @return array<string, int>  trimmed boothid => plots.id
     */
    private function plots(Event $event, $booths, array $sizeIds, $codes): array
    {
        $now = now();
        $rows = [];

        foreach ($booths as $booth) {
            $location = $this->location($booth->khemah);
            [$code, $size] = $this->codeAndSize((string) $booth->crafttype);

            $rows[] = [
                'event_id' => $event->id,
                'plot_size_id' => $sizeIds[$location.'|'.$size],
                'company_id' => null,
                'allocated_to' => null,
                'bidang_id' => $codes[$code]->id,
                'sub_bidang_id' => null,
                'plot_code' => trim((string) $booth->boothid),
                'is_allocated' => false,
                'allocation_type' => 'preset',
                'is_locked' => false,
                'allocated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('plots')->insert($chunk);
        }

        return DB::table('plots')->where('event_id', $event->id)->pluck('id', 'plot_code')->all();
    }

    /**
     * The draw itself: who was in it, what they were given, and that they
     * confirmed it.
     *
     * EVERY ROW IS WRITTEN VERIFIED, because the draw completed. `PollingGuard`
     * holds a draw shut while any registration is unverified, so an event that
     * reached its end had every row through both checks — the zone's and HQ's.
     * Writing them unverified would describe a state this event was never in.
     */
    private function draw(Event $event, $booths, $people, array $users, array $plots, array $sizeIds, $codes): void
    {
        $now = now();
        $companies = DB::table('companies')
            ->whereIn('user_id', array_values($users))
            ->pluck('id', 'user_id');

        $registrations = [];
        $presets = [];
        $polls = [];
        $allocated = [];

        foreach ($booths as $booth) {
            $legacyUser = trim((string) $booth->usahawanid);

            // A booth nobody holds is a real state: AACB has exactly one.
            if ($legacyUser === '' || ! isset($users[$legacyUser])) {
                continue;
            }

            $userId = $users[$legacyUser];
            $companyId = $companies[$userId] ?? null;
            $person = $people[$legacyUser] ?? null;

            if ($companyId === null || $person === null) {
                continue;
            }

            $boothId = trim((string) $booth->boothid);
            $plotId = $plots[$boothId] ?? null;
            [$code, $size] = $this->codeAndSize((string) $booth->crafttype);
            $location = $this->location($booth->khemah);

            $confirmedAt = $person->votetime && ! str_starts_with((string) $person->votetime, '0000')
                ? Carbon::parse($person->votetime)
                : $event->polling_deadline;

            $registrations[] = [
                'event_id' => $event->id,
                'company_id' => $companyId,
                'user_id' => $userId,
                'code' => $code,
                'code_updated_at' => $confirmedAt,
                'plot_size' => $size,
                'plot_id' => $plotId,
                'confirmed_participation' => true,
                'confirmed_at' => $confirmedAt,
                'reminded_at' => null,
                'verified_by' => null,
                'verified_at' => $confirmedAt,
                'first_verified_at' => $confirmedAt,
                'hq_note' => null,
                'payment_note' => 'Imported from the previous system.',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $presets[] = [
                'event_id' => $event->id,
                'user_id' => $userId,
                'bidang_id' => $codes[$code]->id,
                'sub_bidang_id' => null,
                'plot_size_id' => $sizeIds[$location.'|'.$size],
                'plot_number' => $boothId,
                'plot_size' => $size,
                'plot_code' => $boothId,
                'code' => $code,
                'priority_rank' => (int) ($booth->prio ?: 1),
                'is_confirmed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $polls[] = [
                'user_id' => $userId,
                'event_id' => $event->id,
                'code' => $code,
                'created_at' => $confirmedAt,
                'updated_at' => $confirmedAt,
            ];

            if ($plotId !== null) {
                $allocated[$plotId] = [
                    'company_id' => $companyId,
                    'allocated_to' => $userId,
                    'allocated_at' => $confirmedAt,
                ];
            }
        }

        foreach (array_chunk($registrations, 300) as $chunk) {
            DB::table('event_registrations')->insert($chunk);
        }
        foreach (array_chunk($presets, 300) as $chunk) {
            DB::table('preset_allocations')->insert($chunk);
        }
        foreach (array_chunk($polls, 300) as $chunk) {
            DB::table('polls')->insert($chunk);
        }

        foreach ($allocated as $plotId => $values) {
            DB::table('plots')->where('id', $plotId)->update($values + ['is_allocated' => true]);
        }

        $this->notes[] = count($registrations).' registrations, '
            .count($presets).' preset allocations, '
            .count($polls).' polls, '
            .count($allocated).' plots marked taken.';
    }

    /**
     * `crafttype` holds the craft name, its code and the booth size, in that
     * order, joined by hyphens: 'Manik/Mutiara/Karangan Laut-AMH-3x3'.
     *
     * SPLIT FROM THE RIGHT. The craft name itself carries punctuation and
     * hyphens, so reading left to right turns that example into code 'Manik'.
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

    /**
     * Hall names are upper-cased on the way in. AACB's two tables happen to
     * agree ('KHEMAH A' in both), but the 2011 rows do not — `usahawan.location`
     * reads 'KHEMAH A' where `ukhemah.khemah` reads 'Khemah A' — and a plan
     * keyed on raw text would split one hall into two.
     */
    private function location(?string $khemah): string
    {
        $clean = preg_replace('/\s+/', ' ', trim((string) $khemah));

        return strtoupper($clean) ?: 'LOC';
    }

    private function preview(array $plan, $booths, array $window): void
    {
        $this->line('DRY RUN — nothing was written.');
        $this->newLine();

        $rows = [];
        foreach ($plan['pairs'] as $pair) {
            $rows[] = [
                $pair['location'],
                $pair['size'],
                $pair['quantity'],
                count($pair['codes']).' codes: '.implode(', ', array_map(
                    fn ($c, $n) => "{$c}×{$n}",
                    array_keys($pair['codes']),
                    $pair['codes']
                )),
            ];
        }

        $this->table(['location', 'size', 'booths', 'craft allocation'], $rows);

        $held = $booths->filter(fn ($b) => trim((string) $b->usahawanid) !== '')->count();

        $this->line('Booths: '.$booths->count().'   held: '.$held.'   free: '.($booths->count() - $held));
        $this->line('Draw window from `votetime`: '.$window[0]->toDateTimeString().' to '.$window[1]->toDateTimeString());
    }

    /**
     * Which event this command built last time.
     *
     * KEPT IN `settings`, NOT READ BACK OFF THE EVENT. The first version matched
     * on `description like '%event code AACB%'` — and the description is the
     * first field the office edits once the event has a real name. The moment
     * they do, `--fresh` matches nothing, removes nothing, and builds a SECOND
     * AACB event beside the first: same 175 booths, different id, and the lot
     * draw now exists twice. Measured, not imagined.
     *
     * A settings key is invisible to every screen, so nothing can edit it by
     * accident.
     */
    private const EVENT_KEY = 'legacy_aacb_event_id';

    private function forget(): void
    {
        $recorded = Setting::get(self::EVENT_KEY);

        $events = Event::query()
            ->when($recorded, fn ($q) => $q->orWhere('id', (int) $recorded))
            // The description match stays as a fallback, for an event built
            // before this key existed. It is the weaker of the two and must
            // never be the only one.
            ->orWhere('description', 'like', '%event code '.self::EVENT_CODE.'%')
            ->pluck('id');

        if ($events->isEmpty()) {
            return;
        }

        // `event_registrations.plot_id` has no ON DELETE clause, so the database
        // refuses to drop a plot a registration still points at. Registrations
        // go first, and everything else follows the event's cascades.
        DB::table('event_registrations')->whereIn('event_id', $events)->delete();
        Event::whereIn('id', $events)->delete();

        $this->line('Removed '.$events->count().' previously transformed AACB event(s).');
    }

    private function summary(): void
    {
        $this->newLine();
        foreach ($this->notes as $note) {
            $this->line('  '.$note);
        }

        $this->newLine();
        $this->warn('The title, venue and fair dates are NOT in the dump. Confirm them with the office,');
        $this->warn('then re-run with --fresh --title="…" --venue="…" --starts=… --ends=…');
    }
}
