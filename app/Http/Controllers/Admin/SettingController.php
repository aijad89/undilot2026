<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\Event;
use App\Models\KrafBidang;
use App\Models\KrafPengelasan;
use App\Models\KrafProduk;
use App\Models\KrafSubBidang;
use App\Models\Setting;
use App\Support\AdminZones;
use App\Support\EntrepreneurCategories;
use App\Support\PlotSizes;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The lists the rest of the application picks from.
 *
 * THREE OF THEM ARE SETTINGS ROWS, TWO ARE TABLES, and the difference is not
 * arbitrary. Zones, booth sizes and entrepreneur categories are short lists of
 * strings that nothing references by id, so they live as JSON in `settings`. The
 * craft hierarchy and the craft codes are rows that companies, plots and draw
 * entries point at, so they are tables.
 *
 * ZONES ARE NOT EDITED AS TEXT, and that is the one real trap on this screen. A
 * zone string IS the access-control value: a state admin reaches a company when
 * `companies.state` equals their own `admins.state` exactly. Retyping a zone in
 * a textarea would leave every row holding the old spelling stranded, silently
 * and with no error anywhere — so a zone is renamed through AdminZones::rename(),
 * which carries all three columns across in one transaction, and a zone still in
 * use cannot be deleted at all.
 */
class SettingController extends Controller
{
    /**
     * The lists that are settings rows.
     *
     * @return array<string, class-string<\App\Support\ManagedList>>
     */
    private const LISTS = [
        'plot_sizes' => PlotSizes::class,
        'entrepreneur_categories' => EntrepreneurCategories::class,
    ];

    public function lists(Request $request)
    {
        return view('admin.settings.lists', [
            'zones' => AdminZones::all(),
            'zoneUsage' => AdminZones::usageForAll(),
            'plotSizes' => PlotSizes::all(),
            'entrepreneurCategories' => EntrepreneurCategories::all(),
            'canEditZones' => $request->user('admin')->isSuperAdmin(),
        ]);
    }

    /** Setting › Lot Draw: how a participant sees their lot, for every event. */
    public function lotDraw()
    {
        return view('admin.settings.lot-draw', ['reveal' => Event::lotReveal()]);
    }

    /** Presentation only — the lot itself is decided before any reveal plays. */
    public function updateLotDraw(Request $request)
    {
        $data = $request->validate([
            'draw_animation' => ['required', Rule::in(array_keys(Event::DRAW_ANIMATIONS))],
        ]);

        Setting::set(Event::DRAW_ANIMATION_SETTING, $data['draw_animation']);

        return back()->with('success', 'Lot reveal set to "'.Event::DRAW_ANIMATIONS[$data['draw_animation']].'" for every event.');
    }

    /**
     * Replace one managed list wholesale.
     *
     * The textarea is one value per line. ManagedList::set() trims, drops blanks
     * and refuses an empty result, so a cleared box cannot empty a dropdown the
     * forms depend on.
     */
    /**
     * Every column holding a value from each textarea list, as table => column.
     * A value still held by any of them cannot be dropped from its list.
     */
    private const LIST_COLUMNS = [
        'plot_sizes' => [
            'event_registrations' => 'plot_size',
            'companies' => 'plot_size',
            'plot_sizes' => 'size',
        ],
        'entrepreneur_categories' => [
            'companies' => 'entrepreneur_category',
        ],
    ];

    /**
     * Save a textarea list — refused whole if it would drop a value rows still
     * hold. Every dropdown is built from the list, so a dropped value stops
     * being offered while rows carry it: a company form then opens on "None"
     * and the next save blanks the category. Zones refuse the same thing in
     * destroyZone(); this was the door without the guard.
     */
    public function updateList(Request $request, string $list)
    {
        abort_unless(isset(self::LISTS[$list]), 404);

        $data = $request->validate(['values' => ['required', 'string']]);

        $class = self::LISTS[$list];
        $values = preg_split('/\R/', $data['values']) ?: [];

        // Case-insensitive, as ManagedList and MySQL both are: re-casing a
        // value is not dropping it.
        $kept = array_map(fn ($v) => mb_strtolower(trim($v)), $values);
        $inUse = [];

        foreach ($class::all() as $current) {
            if (in_array(mb_strtolower(trim($current)), $kept, true)) {
                continue;
            }

            $rows = 0;
            foreach (self::LIST_COLUMNS[$list] as $table => $column) {
                $rows += DB::table($table)->where($column, $current)->count();
            }

            if ($rows > 0) {
                $inUse[] = "{$current} ({$rows} ".($rows === 1 ? 'row' : 'rows').')';
            }
        }

        if ($inUse) {
            return back()->with('error', 'Not saved: still in use — '.implode(', ', $inUse)
                .'. Move those rows to another value first.');
        }

        $class::set($values);

        return back()->with('success', 'List saved.');
    }

    public function storeZone(Request $request)
    {
        $this->authorizeZones($request);

        $data = $request->validate(['zone' => ['required', 'string', 'max:255']]);

        if (AdminZones::has($data['zone'])) {
            return back()->with('error', 'That zone is already on the list.');
        }

        AdminZones::set([...AdminZones::all(), $data['zone']]);

        return back()->with('success', 'Zone added.');
    }

    /**
     * Rename a zone AND every row filed under it, in one transaction.
     *
     * This is why zones are not a textarea. The rows are the point: the list is
     * only a label for what `admins.state`, `companies.state` and `events.state`
     * already hold.
     */
    public function renameZone(Request $request)
    {
        $this->authorizeZones($request);

        $data = $request->validate([
            'from' => ['required', AdminZones::rule()],
            'to' => ['required', 'string', 'max:255'],
        ]);

        $moved = AdminZones::rename($data['from'], $data['to']);

        return back()->with('success', "Renamed. {$moved} rows carried across.");
    }

    public function destroyZone(Request $request)
    {
        $this->authorizeZones($request);

        $data = $request->validate(['zone' => ['required', AdminZones::rule()]]);

        if (AdminZones::isInUse($data['zone'])) {
            return back()->with('error', 'That zone is still in use. Rename it or move its rows first.');
        }

        // ManagedList::set() silently keeps a list it would empty, and this
        // used to report "Zone removed." over a zone that was still there.
        if (count(AdminZones::all()) === 1) {
            return back()->with('error', 'The list needs at least one zone, so the last one stays.');
        }

        AdminZones::set(array_values(array_diff(AdminZones::all(), [$data['zone']])));

        return back()->with('success', 'Zone removed.');
    }

    /**
     * The coding sheet — the `code` IS NOT NULL half of `kraf_bidangs`.
     *
     * Named explicitly, as every query against this table must be: the other half
     * is the craft hierarchy and shares the table.
     */
    public function krafCodes()
    {
        return view('admin.settings.kraf-codes', [
            'codes' => KrafBidang::codes()->orderBy('bil')->orderBy('name')->get(),
        ]);
    }

    public function storeKrafCode(Request $request)
    {
        $data = $this->validatedCode($request);

        KrafBidang::create($data);

        return back()->with('success', "Code {$data['code']} added.");
    }

    /**
     * Save a code row — and when the code TEXT changes, carry every row holding
     * the old text across in the same transaction.
     *
     * A code is held two ways. Plots and preset allocations point at this row by
     * `bidang_id`, so they follow a rename by themselves. Registrations,
     * companies, polls and presets also hold the text, and LotAllocator matches
     * a registration's text against its plots' code — so a rename that moved
     * only the key half left every company carrying the old text unable to win
     * any lot, with nothing on screen saying so.
     *
     * A relabel, not a re-coding: `verified_at` stays (HQ verified the same
     * craft), so nobody drops off their own dashboard. That holds on its own —
     * EventRegistration::booted() unverifies only for a state admin, and this
     * screen is behind `manage-settings`. Mass updates are for reach, one
     * statement per table however many rows; they fire no model events, so the
     * audit row is written here by hand.
     *
     * `plots.plot_code` is the printed booth label (TBP01), not a key, and stays.
     */
    public function updateKrafCode(Request $request, KrafBidang $code)
    {
        $this->abortUnlessCode($code);

        $data = $this->validatedCode($request, $code);
        $from = $code->code;
        $to = $data['code'];

        if ($from === $to) {
            $code->update($data);

            return back()->with('success', 'Code saved.');
        }

        if ($live = $this->liveDrawsUsing($code)) {
            return back()->with('error', "Cannot rename {$from} while a draw using it is open ({$live}). "
                .'Rename it once the draw has closed.');
        }

        $carried = DB::transaction(function () use ($code, $data, $from, $to) {
            $code->update($data);

            $carried = [
                'event_registrations' => DB::table('event_registrations')->where('code', $from)->update(['code' => $to]),
                'companies' => DB::table('companies')->where('kraf_code', $from)->update(['kraf_code' => $to]),
                'polls' => DB::table('polls')->where('code', $from)->update(['code' => $to]),
                'preset_allocations' => DB::table('preset_allocations')->where('code', $from)->update(['code' => $to]),
            ];

            AuditTrail::record('kraf_code_renamed', $code, ['code' => $from], [
                'code' => $to,
                'carried' => $carried,
            ]);

            return $carried;
        });

        return back()->with('success', "Code renamed {$from} → {$to}. ".array_sum($carried).' rows carried across.');
    }

    /**
     * Titles of draws that are open right now and use this code — by plot (the
     * key) or by registration (the text). Empty string when there are none.
     */
    private function liveDrawsUsing(KrafBidang $code): string
    {
        return Event::where('polling_status', 'polling')
            ->where(fn ($q) => $q
                ->whereHas('plots', fn ($p) => $p->where('bidang_id', $code->id))
                ->orWhereHas('registrations', fn ($r) => $r->where('code', $code->code)))
            ->pluck('title')
            ->implode(', ');
    }

    /**
     * Withdraw a code, or put it back.
     *
     * `is_active` is how a code is retired, which is why it is not fillable and
     * moves only through here. Deleting one is a different act with a far longer
     * reach — see destroyKrafCode().
     */
    public function toggleKrafCode(KrafBidang $code)
    {
        $this->abortUnlessCode($code);

        $code->is_active = ! $code->is_active;
        $code->save();

        return back()->with('success', $code->is_active
            ? "Code {$code->code} is offered again."
            : "Code {$code->code} withdrawn. Rows already carrying it keep it.");
    }

    /**
     * Deleting a code row takes rows in five other tables with it, by cascade —
     * including plots and preset allocations. Withdrawing it with
     * toggleKrafCode() is almost always what was meant, so the count is put in
     * front of the admin first.
     */
    public function destroyKrafCode(KrafBidang $code)
    {
        $this->abortUnlessCode($code);

        if ($used = $this->krafBidangUsage($code)) {
            return back()->with('error', sprintf(
                'Cannot delete %s: %s still point at it. Withdraw it instead.',
                $code->code,
                $used
            ));
        }

        $code->delete();

        return back()->with('success', 'Code deleted.');
    }

    /** The craft hierarchy — the `code` IS NULL half, with its levels below. */
    public function krafHierarchy()
    {
        return view('admin.settings.kraf-hierarchy', [
            'categories' => KrafBidang::categories()
                // Products with no sub-category predate products sitting in one: "not placed yet".
                ->with(['subBidangs.pengelasan', 'subBidangs.produks', 'produks' => fn ($q) => $q->whereNull('sub_bidang_id')])
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * The companies that picked this category, narrowed by sub-category,
     * classification and product. All three filters must hold on the SAME
     * selection: a company with Batik/canting and Songket/benang emas does not
     * match "Batik + benang emas".
     */
    public function krafHierarchyCompanies(Request $request, KrafBidang $category)
    {
        $this->abortUnlessCategory($category);

        $category->load(['subBidangs.pengelasan', 'subBidangs.produks']);

        $filters = $this->companyFilters($request);
        $matches = $this->selectionsMatching($category, $filters);

        $companies = Company::whereHas('krafSelections', $matches)
            ->with(['user', 'krafSelections' => fn ($q) => $q->where('bidang_id', $category->id)
                ->with(['subBidang', 'pengelasan', 'produks'])])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.settings.kraf-hierarchy-companies', compact('category', 'companies', 'filters'));
    }

    /**
     * Clears one company's selections under this category. The company stays;
     * its ticked classifications and products go with each selection, by cascade.
     */
    public function resetCompanyKraf(Request $request, KrafBidang $category, Company $company)
    {
        $this->abortUnlessCategory($category);

        $count = $this->deleteSelections(
            $company->krafSelections()->where('bidang_id', $category->id)
        );

        return back()->with('success', "{$company->name}: {$count} craft selection(s) under {$category->display_name} removed.");
    }

    /**
     * Clears every selection the current filters match — all of the category's
     * when no filter is set. Only the matching selections go, so a company with
     * Batik and Songket loses only Batik when the filter is Batik.
     */
    public function resetCategoryKraf(Request $request, KrafBidang $category)
    {
        $this->abortUnlessCategory($category);

        $filters = $this->companyFilters($request);
        $query = CompanyKraf::query();
        ($this->selectionsMatching($category, $filters))($query);

        $count = $this->deleteSelections($query);
        $message = "{$count} craft selection(s) under {$category->display_name} removed.";

        // The same button sits on the category's card in Craft Hierarchy.
        if ($request->input('from') === 'hierarchy') {
            return $this->backTo("category-{$category->id}")->with('success', $message);
        }

        return redirect()->route('admin.settings.kraf-hierarchy.companies', $category)->with('success', $message);
    }

    private function companyFilters(Request $request): array
    {
        return $request->validate([
            'sub' => ['nullable', 'integer'],
            'pengelasan' => ['nullable', 'integer'],
            'produk' => ['nullable', 'integer'],
        ]);
    }

    /** All three filters hold on ONE selection together. */
    private function selectionsMatching(KrafBidang $category, array $filters): \Closure
    {
        return function ($q) use ($category, $filters) {
            $q->where('bidang_id', $category->id)
                ->when($filters['sub'] ?? null, fn ($q, $id) => $q->where('sub_bidang_id', $id))
                ->when($filters['pengelasan'] ?? null, fn ($q, $id) => $q->whereHas('pengelasan', fn ($q) => $q->where('kraf_pengelasan.id', $id)))
                ->when($filters['produk'] ?? null, fn ($q, $id) => $q->whereHas('produks', fn ($q) => $q->where('kraf_produks.id', $id)));
        };
    }

    /** One model delete per row, so Auditable writes a line for each. */
    private function deleteSelections($query): int
    {
        return DB::transaction(function () use ($query) {
            $selections = $query->get();
            $selections->each->delete();

            return $selections->count();
        });
    }

    public function storeSubBidang(Request $request, KrafBidang $category)
    {
        $this->abortUnlessCategory($category);

        $split = $request->boolean('split');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Classifications and products may come on the same row (several when
            // split at commas), or be added under the sub-category after. Both
            // optional. No craft code: that is set per event on the draw list.
            'classification' => ['nullable', 'string', 'max:5000'],
            'products' => ['nullable', 'string', 'max:5000'],
        ], ['name.max' => 'Sub-category is too long: :max characters at most.']);

        $classifications = $this->names($data['classification'] ?? '', $split);
        $products = $this->names($data['products'] ?? '', $split);
        if ($error = $this->tooLong($classifications, 'Classification') ?? $this->tooLong($products, 'Product')) {
            return $this->backTo("category-{$category->id}")->with('error', $error);
        }

        DB::transaction(function () use ($category, $data, $classifications, $products) {
            $sub = $category->subBidangs()->create(Arr::only($data, 'name'));
            $sub->pengelasan()->createMany($classifications->map(fn ($name) => ['name' => $name]));
            $this->createProduks($sub, $products);
        });

        return $this->backTo("category-{$category->id}")->with('success', 'Sub-category added.');
    }

    public function storePengelasan(Request $request, KrafSubBidang $subBidang)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:5000']]);

        $names = $this->names($data['name'], $request->boolean('split'));
        if ($error = $this->tooLong($names, 'Classification')) {
            return $this->backTo("category-{$subBidang->bidang_id}")->with('error', $error);
        }

        // Names already under this sub-category are skipped, not doubled.
        $have = $subBidang->pengelasan()->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $names = $names->reject(fn ($name) => $have->contains(mb_strtolower($name)));

        $subBidang->pengelasan()->createMany($names->map(fn ($name) => ['name' => $name]));

        return $this->backTo("category-{$subBidang->bidang_id}")->with('success', $names->isEmpty() ? 'Already there.' : 'Classification added.');
    }

    /**
     * The names typed in one field. By default the whole field is ONE name, commas
     * included: the book's names hold commas ("Batik canting, batik lukis"). With
     * "Split at commas" ticked, "A, B ,, a" → ["A", "B"]: trimmed, blanks and
     * repeats dropped. Length is checked after, per name, by tooLong().
     *
     * A comma inside brackets does not split, and a piece wrapped whole in
     * brackets loses them: "(A - x, y),(B - z)" → ["A - x, y", "B - z"], while
     * "Pakaian (kain, baju), cenderamata" → ["Pakaian (kain, baju)", "cenderamata"].
     */
    private function names(string $input, bool $split)
    {
        // Two or more bracket groups and nothing else, "(A, b)(C, d)" or "(A, b), (C, d)",
        // can only mean a list, so it splits with the box unticked too.
        $groups = collect($this->splitOutsideBrackets($input))->map(fn ($p) => trim($p))->filter();
        $split = $split || ($groups->count() > 1 && $groups->every(fn ($p) => $this->wrappedWhole($p)));

        return collect($split ? $this->splitOutsideBrackets($input) : [$input])
            ->map(fn ($name) => trim($name))
            ->map(fn ($name) => $split && $this->wrappedWhole($name) ? trim(mb_substr($name, 1, -1)) : $name)
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->values();
    }

    /**
     * Split at commas that are not inside ( ), and between back-to-back bracket
     * groups: "(A, b)(C)" → ["(A, b)", "(C)"]. "(a) b (c)" stays one piece.
     */
    private function splitOutsideBrackets(string $input): array
    {
        $pieces = [''];
        $depth = 0;
        foreach (mb_str_split($input) as $ch) {
            $current = trim($pieces[array_key_last($pieces)]);
            if ($ch === '(' && $depth === 0 && $current !== '' && $this->wrappedWhole($current)) {
                $pieces[] = '';
            }
            $depth += match ($ch) { '(' => 1, ')' => $depth > 0 ? -1 : 0, default => 0 };
            if ($ch === ',' && $depth === 0) {
                $pieces[] = '';
            } else {
                $pieces[array_key_last($pieces)] .= $ch;
            }
        }

        return $pieces;
    }

    /** "(…)" whose first bracket closes at the very end — not "(a) b (c)". */
    private function wrappedWhole(string $name): bool
    {
        if (! str_starts_with($name, '(') || ! str_ends_with($name, ')')) {
            return false;
        }
        $depth = 0;
        foreach (mb_str_split($name) as $i => $ch) {
            $depth += match ($ch) { '(' => 1, ')' => -1, default => 0 };
            if ($depth === 0) {
                return $i === mb_strlen($name) - 1;
            }
        }

        return false;
    }

    /**
     * Each name must fit its column (255). Checked per name, after splitting, so a
     * long list of short names passes and one long name says which it is. Nothing
     * is saved when one is too long.
     */
    private function tooLong($names, string $what): ?string
    {
        $long = $names->first(fn ($name) => mb_strlen($name) > 255);

        return $long === null ? null : sprintf(
            '%s is too long (%d characters, 255 at most), nothing was added: "%s…"',
            $what, mb_strlen($long), mb_substr($long, 0, 60)
        );
    }

    /**
     * Delete a sub-category. Anything outside the hierarchy that points at it —
     * a company, a plot, a plan — refuses the delete; the counts come from the
     * tables themselves rather than from the migration, since a cascade is a
     * property of the live database.
     *
     * Its own classifications and products are NOT a reason to refuse: they go
     * with it by cascade. One a company ticked is covered above — ticks only
     * exist on a company selection, and any selection refuses the delete.
     *
     * Categories themselves are fixed: seeded, never added or deleted here.
     */
    public function destroySubBidang(KrafSubBidang $subBidang)
    {
        $counts = collect([
            'company selections' => DB::table('company_krafs')->where('sub_bidang_id', $subBidang->id)->count(),
            'plots' => DB::table('plots')->where('sub_bidang_id', $subBidang->id)->count(),
            'plan allocations' => DB::table('bidang_allocations')->where('sub_bidang_id', $subBidang->id)->count(),
            'preset allocations' => DB::table('preset_allocations')->where('sub_bidang_id', $subBidang->id)->count(),
        ])->filter();

        if ($counts->isNotEmpty()) {
            return $this->backTo("category-{$subBidang->bidang_id}")->with('error', sprintf(
                'Cannot delete %s: %s still point at it.',
                $subBidang->name,
                $counts->map(fn ($n, $what) => "{$n} {$what}")->join(', ', ' and ')
            ));
        }

        $subBidang->delete();

        return $this->backTo("category-{$subBidang->bidang_id}")->with('success', 'Sub-category deleted.');
    }

    public function destroyPengelasan(KrafPengelasan $pengelasan)
    {
        // Ticked by a company: the database would refuse too, but without a reason.
        if ($used = DB::table('company_kraf_pengelasan')->where('pengelasan_id', $pengelasan->id)->count()) {
            return $this->backTo("category-{$pengelasan->subBidang->bidang_id}")->with('error', "Cannot delete {$pengelasan->name}: {$used} company selections still use it.");
        }

        $pengelasan->delete();

        return $this->backTo("category-{$pengelasan->subBidang->bidang_id}")->with('success', 'Classification deleted.');
    }

    public function storeProduk(Request $request, KrafSubBidang $subBidang)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:5000']]);

        $names = $this->names($data['name'], $request->boolean('split'));
        if ($error = $this->tooLong($names, 'Product')) {
            return $this->backTo("category-{$subBidang->bidang_id}")->with('error', $error);
        }

        // Like classifications; names already in the sub-category are skipped.
        $have = $subBidang->produks()->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $names = $names->reject(fn ($name) => $have->contains(mb_strtolower($name)));

        $this->createProduks($subBidang, $names);

        return $this->backTo("category-{$subBidang->bidang_id}")
            ->with('success', $names->isEmpty() ? 'Already there.' : 'Product added.');
    }

    /** Products in a sub-category, with `bidang_id` kept in step with the sub-category's. */
    private function createProduks(KrafSubBidang $subBidang, $names): void
    {
        $subBidang->produks()->createMany(
            $names->map(fn ($name) => ['name' => $name, 'bidang_id' => $subBidang->bidang_id])
        );
    }

    /** Every product of one sub-category, or none: refused while a company has ticked any. */
    public function destroyAllProduks(KrafSubBidang $subBidang)
    {
        $ids = $subBidang->produks()->pluck('id');
        if ($used = DB::table('company_kraf_produk')->whereIn('produk_id', $ids)->distinct()->count('produk_id')) {
            return $this->backTo("category-{$subBidang->bidang_id}")
                ->with('error', "Cannot delete the products of {$subBidang->name}: {$used} of them are still used by companies.");
        }

        $subBidang->produks()->get()->each->delete();

        return $this->backTo("category-{$subBidang->bidang_id}")->with('success', 'All products deleted.');
    }

    public function destroyProduk(KrafProduk $produk)
    {
        // Ticked by a company: the database would refuse too, but without a reason.
        if ($used = DB::table('company_kraf_produk')->where('produk_id', $produk->id)->count()) {
            return $this->backTo("category-{$produk->bidang_id}")
                ->with('error', "Cannot delete {$produk->name}: {$used} company selections still use it.");
        }

        $produk->delete();

        return $this->backTo("category-{$produk->bidang_id}")->with('success', 'Product deleted.');
    }

    /**
     * What still points at a `kraf_bidangs` row, as a readable phrase, or '' when
     * nothing does.
     *
     * One method for both halves of the table because the cascade does not care
     * which half a row belongs to: every one of these foreign keys names
     * `kraf_bidangs`.
     */
    private function krafBidangUsage(KrafBidang $bidang): string
    {
        $counts = collect([
            'company selections' => DB::table('company_krafs')->where('bidang_id', $bidang->id)->count(),
            'sub-categories' => DB::table('kraf_sub_bidangs')->where('bidang_id', $bidang->id)->count(),
            'products' => DB::table('kraf_produks')->where('bidang_id', $bidang->id)->count(),
            'plots' => DB::table('plots')->where('bidang_id', $bidang->id)->count(),
            'plan allocations' => DB::table('bidang_allocations')->where('bidang_id', $bidang->id)->count(),
            'preset allocations' => DB::table('preset_allocations')->where('bidang_id', $bidang->id)->count(),
        ])->filter();

        return $counts->map(fn ($n, $what) => "{$n} {$what}")->join(', ', ' and ');
    }

    /** @return array<string, mixed> */
    private function validatedCode(Request $request, ?KrafBidang $except = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('kraf_bidangs', 'code')->ignore($except?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'bil' => ['nullable', 'integer', 'min:1'],
            'pecahan_bidang' => ['nullable', 'string', 'max:255'],
            'pecahan_sub_bidang' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** A coded row and a category are not interchangeable, so each route says which. */
    private function abortUnlessCode(KrafBidang $bidang): void
    {
        abort_if(blank($bidang->code), 404);
    }

    private function abortUnlessCategory(KrafBidang $bidang): void
    {
        abort_unless(blank($bidang->code), 404);
    }

    private function authorizeZones(Request $request): void
    {
        abort_unless(
            $request->user('admin')->isSuperAdmin(),
            403,
            'Zones decide who sees what. Only a super admin may change them.'
        );
    }
}
