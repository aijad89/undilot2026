<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Notifications\AddedToDraw;
use App\Notifications\RemovedFromDraw;
use App\Support\AdminZones;
use App\Support\EventVisibility;
use App\Support\KrafPicker;
use App\Support\PlotSizes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The draw list: which companies are in one event's lot draw.
 *
 * PUTTING A COMPANY HERE IS THE APPROVAL. The row exists, so the company is in
 * the draw — the office adds it, through addToList(). What the office still
 * has to say is the craft CODE it draws under and the booth SIZE it needs,
 * because the plan is built from those two numbers.
 *
 * THE CODE IS A STRING, NOT A REFERENCE, and that is deliberate: the allocator
 * compares `event_registrations.code` against the code on the `kraf_bidangs` row
 * a plot is categorised by. The dropdown offers the active codes; the column
 * stores what was picked.
 *
 * TWO CHECKS. The zone admin checks everything — the company, its payment
 * note, the craft code and booth size — and the row goes on the list waiting
 * for HQ. HQ (gate `verify-draw-list`) checks it again and either verifies it,
 * fixing the code or size in the same step, or returns it to the zone with a
 * note. The participant hears nothing until HQ verifies; the draw does not
 * open while any row waits (PollingGuard). A zone admin's later change to the
 * code or size clears the verification again — EventRegistration::booted() —
 * so HQ sees it once more.
 *
 * SCOPE IS DOUBLE HERE, and both halves are needed. EventVisibility::authorize()
 * answers "may you open this event at all"; the query below answers "whose rows
 * may you see inside it". Dropping either leaves a hole the other does not cover.
 */
class EntRegistrationController extends Controller
{
    /** How many removed rows an undo may hold. One bulk operation's worth. */
    private const UNDO_LIMIT = 200;

    /** Other draw lists this request's booth sizes reached, for the message. */
    private int $moved = 0;

    public function index(Request $request, Event $event)
    {
        EventVisibility::authorize($event);

        $admin = $request->user('admin');
        $scoped = fn ($query) => $query->unless(
            $admin->hasGlobalAccess(),
            fn ($q) => $q->whereHas('company', fn ($c) => $c->where('state', $admin->state))
        );

        $registrations = $scoped($event->registrations()->with(['company.user', 'plot']))
            ->when($request->input('code'), fn ($q, $code) => $q->where('code', $code))
            ->when($request->input('status'), fn ($q, $status) => match ($status) {
                'waiting' => $q->whereNull('verified_at')->whereNull('hq_note'),
                'returned' => $q->whereNotNull('hq_note'),
                'verified' => $q->whereNotNull('verified_at'),
                default => $q,
            })
            ->when($request->input('missing'), fn ($q, $missing) => array_key_exists($missing, EventRegistration::missingFilters())
                ? $q->missing($missing === 'any' ? null : $missing)
                : $q)
            ->when($request->input('search'), fn ($q, $search) => $q->whereHas(
                'company', fn ($c) => $c->where('name', 'like', "%{$search}%")
            ))
            ->join('companies', 'companies.id', '=', 'event_registrations.company_id')
            ->orderBy('companies.name')
            ->select('event_registrations.*')
            ->paginate(25)
            ->withQueryString();

        // A set-aside lot stays a surprise to the zone until it is confirmed.
        if (! $admin->hasGlobalAccess()) {
            \App\Models\Plot::maskPresets($registrations->pluck('plot'));
            $registrations->each(fn ($r) => $r->plot && ! $r->plot->is_allocated && $r->setRelation('plot', null));
        }

        return view('admin.events.registrations.index', [
            'event' => $event,
            'registrations' => $registrations,
            'isHq' => $admin->can('verify-draw-list'),
            'codeFrozen' => $this->codeFrozen($event),
            // "12 / 200" beside the filter: rows matching it, of the rows this
            // admin's list holds. filled(), not has(): the form posts every
            // field, blank ones included, and blank is no filter.
            'filtered' => collect(['search', 'code', 'status', 'missing'])->contains(fn ($field) => $request->filled($field)),
            'missingFilters' => EventRegistration::missingFilters(),
            'listTotal' => $scoped($event->registrations())->count(),
            // The filter offers only codes some row in view carries — a code
            // nobody on this list holds can only filter to an empty table. The
            // one being filtered on stays, so the dropdown shows what is applied.
            'filterCodes' => $scoped($event->registrations())
                ->whereNotNull('event_registrations.code')
                ->where('event_registrations.code', '!=', '')
                ->distinct()
                ->pluck('event_registrations.code')
                ->push($request->input('code'))
                ->filter()
                ->unique()
                ->sort()
                ->values(),
            'waitingCount' => $scoped($event->registrations()->whereNull('verified_at')->whereNull('hq_note'))->count(),
            'codes' => KrafPicker::codes(),
            'plotSizes' => PlotSizes::all(),
            'undoCount' => count(session('draw_list_undo', [])),
        ]);
    }

    /**
     * Every company in this admin's zone, each marked in or out of this draw,
     * with the action its state allows: add it, or edit or remove its row.
     *
     * A READ screen. Every button posts to the draw list's existing doors —
     * store(), update(), destroy() — so the zone lock, the freeze, the reason a
     * told company is owed and the zone scope are each still asked in one
     * place. Nothing here decides; it only offers what those doors accept.
     *
     * Active companies, plus any inactive one already in this draw: a company
     * switched off cannot be added, but one already in must stay reachable to
     * be taken out.
     */
    public function companies(Request $request, Event $event)
    {
        EventVisibility::authorize($event);

        $admin = $request->user('admin');
        $inThisDraw = fn ($q) => $q->where('event_id', $event->id);

        $companies = Company::query()
            ->unless($admin->hasGlobalAccess(), fn ($q) => $q->where('state', $admin->state))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('eventRegistrations', $inThisDraw))
            ->with(['user:id,name', 'eventRegistrations' => $inThisDraw])
            ->when($request->input('search'), fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->input('status'), fn ($q, $status) => match ($status) {
                'in' => $q->whereHas('eventRegistrations', $inThisDraw),
                'out' => $q->whereDoesntHave('eventRegistrations', $inThisDraw),
                default => $q,
            })
            ->when($admin->hasGlobalAccess() && $request->input('zone'), fn ($q) => $q->where('state', $request->input('zone')))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.events.registrations.companies', [
            'event' => $event,
            'companies' => $companies,
            'isHq' => $admin->can('verify-draw-list'),
            'isGlobal' => $admin->hasGlobalAccess(),
            'codeFrozen' => $this->codeFrozen($event),
            'zoneLocked' => $this->zoneLocked($request, $event),
            'codes' => KrafPicker::codes(),
            'plotSizes' => PlotSizes::all(),
            'zones' => AdminZones::all(),
        ]);
    }

    /**
     * Add one or more companies to the draw list.
     *
     * `user_id` is copied from the company at this point because the draw keys on
     * the ACCOUNT — polls, preset allocations and `plots.allocated_to` all do —
     * while ownership stays `company_id`. Two keys, two questions.
     */
    public function store(Request $request, Event $event)
    {
        EventVisibility::authorize($event);

        $data = $request->validate([
            'company_id' => ['required', 'array', 'min:1'],
            'company_id.*' => ['integer'],
            'code' => ['nullable', 'string', 'max:50'],
            'plot_size' => ['nullable', Rule::in(PlotSizes::all())],
        ]);

        if ($this->zoneLocked($request, $event)) {
            return back()->with('error', self::HQ_ONLY_NOW);
        }

        // Re-read the companies through the same scope the picker used, so a
        // typed id from another zone is not added by posting it directly.
        $companies = $this->scopedCompanies($request, $data['company_id']);

        if ($companies->isEmpty()) {
            return back()->with('error', 'No company from your zone matched that.');
        }

        $added = 0;

        DB::transaction(function () use ($companies, $event, $data, &$added) {
            foreach ($companies as $company) {
                $added += $this->addToList($event, $company, $data['code'] ?? null, $data['plot_size'] ?? null) ? 1 : 0;
            }
        });

        return back()->with('success', ($added === 1
            ? 'One company added to the draw list.'
            : "{$added} companies added to the draw list.")
            .($this->isHq($request) ? '' : ' HQ will verify before the company is told.')
            .Company::boothMovedNote($this->moved, $this->isHq($request)));
    }

    /**
     * Put one company on the draw list. The one way a company enters a draw:
     * the office adds it, and HQ's verification is what tells the participant.
     *
     * A company cannot be in the same draw twice — there is a unique index
     * saying so. firstOrCreate makes a double submit harmless rather than a 500.
     * A code left blank takes the company's own usual code, if any; the booth
     * size likewise, 3x3 when neither says. Added by HQ, the row is verified
     * at once; added by a zone, it waits for HQ.
     *
     * Returns whether a row was added.
     */
    private function addToList(Event $event, Company $company, ?string $code, ?string $size): bool
    {
        $code ??= $company->kraf_code;

        $registration = EventRegistration::firstOrCreate(
            ['event_id' => $event->id, 'company_id' => $company->id],
            [
                'user_id' => $company->user_id,
                'code' => $code,
                'code_updated_at' => $code ? now() : null,
                'plot_size' => $size ?? $company->plot_size ?? '3x3',
            ]
        );

        // A size picked here becomes the company's own too.
        if ($registration->wasRecentlyCreated && $size) {
            $this->moved += $company->syncBoothSize($size);
        }

        if (auth('admin')->user()->can('verify-draw-list')) {
            $this->verify($registration);
        }

        return $registration->wasRecentlyCreated;
    }

    /**
     * HQ's check: the row counts, and the participant is told — once: a row
     * HQ verified before, changed by a zone and verified again, says nothing
     * new.
     */
    private function verify(EventRegistration $registration): void
    {
        $first = $registration->first_verified_at === null;

        $registration->update([
            'verified_at' => now(),
            'first_verified_at' => $registration->first_verified_at ?? now(),
            'verified_by' => auth('admin')->id(),
            'hq_note' => null,
        ]);

        // Told once, on the first verification. A row HQ verified before,
        // edited by a zone and verified again, says nothing new.
        if ($first) {
            $registration->company->user?->notify(new AddedToDraw($registration->event));
        }
    }

    private const FROZEN = 'The draw has started: the craft code and booth size can no longer change.';

    /**
     * Once the draw has started the code and size stay as drawn, for HQ too.
     * A zone change would also clear the verification, and the participant
     * pages read verified rows only — the company would vanish from its own
     * dashboard mid-draw. Company::syncBoothSize() asks the same of the
     * company page.
     */
    private function codeFrozen(Event $event): bool
    {
        return $event->polling_status !== 'draft';
    }

    /**
     * A zone admin removes only what HQ has not yet confirmed to the company,
     * and nothing holding a lot. Taking a company out of a draw it was told it
     * is in is HQ's call, like every other zone change after HQ's check.
     */
    private function zoneMayRemove(Request $request, EventRegistration $registration): bool
    {
        return $this->isHq($request)
            || (! $registration->plot_id && ! $registration->first_verified_at);
    }

    private const HQ_ONLY_NOW = 'The draw has started: only HQ can add companies now.';

    /**
     * Once the draw has started a zone adds no one. A zone's row waits for HQ,
     * and a row waiting while the draw runs is a company that may miss it — or
     * reach the lot page before anyone checked it. HQ's own add is verified at
     * once, so HQ may still add a latecomer.
     */
    private function zoneLocked(Request $request, Event $event): bool
    {
        return ! $this->isHq($request) && $this->codeFrozen($event);
    }

    /**
     * Take one row off the list. A company that was told it is in hears that it
     * is out, and why. One never told hears nothing.
     */
    private function removeFromList(EventRegistration $registration, ?string $reason): void
    {
        $registration->delete();

        if (! $registration->first_verified_at) {
            return;
        }

        $registration->company->user?->notify(new RemovedFromDraw($registration->event, $reason));
    }

    private function isHq(Request $request): bool
    {
        return $request->user('admin')->can('verify-draw-list');
    }

    /**
     * Set this row's craft code and booth size. From a zone admin it answers
     * HQ's note, if any, and goes back to waiting for HQ. From HQ it is the
     * check itself: saved and verified in one step.
     */
    public function update(Request $request, Event $event, EventRegistration $registration)
    {
        EventVisibility::authorize($event);
        $this->authorizeScope($request, $registration);

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'plot_size' => ['required', Rule::in(PlotSizes::all())],
        ]);

        if ($this->codeFrozen($event)) {
            return back()->with('error', self::FROZEN);
        }

        if ($registration->code !== ($data['code'] ?? null)) {
            $data['code_updated_at'] = now();
        }

        $isHq = $this->isHq($request);

        DB::transaction(function () use ($registration, $data, $isHq) {
            $registration->update($data + ['hq_note' => null]);
            // The company's booth size follows, and with it its other draw lists.
            $this->moved += $registration->company->syncBoothSize($data['plot_size']);

            if ($isHq) {
                $this->verify($registration);
            }
        });

        return back()->with('code_success', ($isHq ? 'Saved and verified.' : 'Saved. Waiting for HQ to verify.')
            .Company::boothMovedNote($this->moved, $isHq));
    }

    /**
     * How a company the office added paid, for HQ to check. Writing it answers
     * HQ's note, and from a zone admin sends the row back to HQ (booted()).
     * Not frozen with the code and size: it changes nothing the draw reads.
     */
    public function paymentNote(Request $request, Event $event, EventRegistration $registration)
    {
        EventVisibility::authorize($event);
        $this->authorizeScope($request, $registration);

        $data = $request->validate([
            'payment_status' => ['nullable', Rule::in(array_keys(EventRegistration::PAYMENT_STATUSES))],
            'payment_note' => ['nullable', 'string', 'max:500'],
        ]);

        $registration->update($data + ['hq_note' => null]);

        return back()->with('success', 'Payment saved.');
    }

    /**
     * HQ sends a row back to its zone, with what to fix. It stays on the list,
     * unverified. Not once the draw has started: the code and size are frozen
     * then, so the zone could fix nothing — HQ corrects the row itself.
     */
    public function returnToZone(Request $request, Event $event, EventRegistration $registration)
    {
        EventVisibility::authorize($event);

        if ($this->codeFrozen($event)) {
            return back()->with('error', self::RETURN_FROZEN);
        }

        $data = $request->validate(['hq_note' => ['required', 'string', 'max:1000']]);

        $this->returnRow($registration, $data['hq_note']);

        return back()->with('success', "{$registration->company->name} returned to its zone.");
    }

    private const RETURN_FROZEN = 'The draw has started: a row can no longer go back to its zone.';

    /**
     * Send one row back to its zone with what to fix: unverified, so the draw
     * cannot open on it. Through the model, so `Auditable` records it — the
     * single return and the bulk one both come here.
     */
    private function returnRow(EventRegistration $registration, string $note): void
    {
        $registration->update(['hq_note' => $note, 'verified_at' => null]);
    }

    /**
     * Take one company back out of the draw.
     *
     * A row holding a lot is refused rather than deleted. The draw result is the
     * thing this whole application exists to produce, and it may not disappear as
     * a side effect of tidying a list — the lot is withdrawn first, deliberately,
     * on the draw's own screen.
     */
    public function destroy(Request $request, Event $event, EventRegistration $registration)
    {
        EventVisibility::authorize($event);
        $this->authorizeScope($request, $registration);

        if (! $this->zoneMayRemove($request, $registration)) {
            // Worded the same for a set-aside lot, which a zone admin must not learn of.
            return back()->with('error', 'Only HQ can remove that company from the list now.');
        }

        if ($registration->plot_id) {
            return back()->with('error', 'That company holds a lot. Withdraw the lot before removing it from the list.');
        }

        $reason = $request->validate(['removal_reason' => ['nullable', 'string', 'max:1000']])['removal_reason'] ?? null;
        if ($registration->first_verified_at && blank($reason)) {
            return back()->with('error', 'This company was told it is in the draw. Give a reason; it is sent to the company.');
        }

        $this->rememberForUndo([$registration]);

        DB::transaction(fn () => $this->removeFromList($registration, $reason));

        return back()->with('success', 'Removed from the draw list.');
    }

    /**
     * One action over many ticked rows: remove them, or set a code or a size.
     *
     * The ids are re-read through this admin's scope rather than trusted, so a
     * hand-edited checkbox cannot reach another zone's row.
     */
    public function bulk(Request $request, Event $event)
    {
        EventVisibility::authorize($event);

        $data = $request->validate([
            'action' => ['required', Rule::in(['remove', 'set_code', 'set_size', 'verify', 'return'])],
            'registration_id' => ['required', 'array', 'min:1'],
            'registration_id.*' => ['integer'],
            'code' => ['required_if:action,set_code', 'nullable', 'string', 'max:50'],
            'plot_size' => ['required_if:action,set_size', 'nullable', Rule::in(PlotSizes::all())],
            'removal_reason' => ['nullable', 'string', 'max:1000'],
            'hq_note' => ['required_if:action,return', 'nullable', 'string', 'max:1000'],
        ]);

        $admin = $request->user('admin');
        abort_if($data['action'] === 'verify' && ! $admin->can('verify-draw-list'), 403, 'Only HQ verifies the draw list.');
        // The single Return is gated on its route; the bulk one asks here.
        abort_if($data['action'] === 'return' && ! $admin->can('verify-draw-list'), 403, 'Only HQ returns rows to a zone.');

        if ($data['action'] === 'return' && $this->codeFrozen($event)) {
            return back()->with('error', self::RETURN_FROZEN);
        }

        $rows = $event->registrations()
            ->whereIn('id', $data['registration_id'])
            ->unless($admin->hasGlobalAccess(), fn ($q) => $q->whereHas(
                'company', fn ($c) => $c->where('state', $admin->state)
            ))
            ->get();

        if ($rows->isEmpty()) {
            return back()->with('error', 'Nothing matched.');
        }

        if ($data['action'] === 'remove') {
            $holding = $rows->filter(fn ($r) => $r->plot_id || ! $this->zoneMayRemove($request, $r));
            $removable = $rows->diff($holding);

            $told = $removable->whereNotNull('first_verified_at')->count();
            if ($told > 0 && blank($data['removal_reason'] ?? null)) {
                return back()->with('error', ($told === 1 ? '1 of these companies was' : "{$told} of these companies were")
                    .' told they are in the draw. Give a reason; it is sent to them.');
            }

            $this->rememberForUndo($removable);

            // Through the model one at a time, not a mass delete: `Auditable`
            // listens to Eloquent events, and a query-builder delete fires none
            // of them — the rows would go with nothing written about them.
            DB::transaction(fn () => $removable->each(fn ($r) => $this->removeFromList($r, $data['removal_reason'] ?? null)));

            return back()->with('success', trim(sprintf(
                '%d removed from the draw list.%s',
                $removable->count(),
                $holding->isEmpty() ? '' : ($admin->hasGlobalAccess()
                    ? " {$holding->count()} kept: they hold lots."
                    : " {$holding->count()} kept: only HQ can remove them now.")
            )));
        }

        if ($data['action'] === 'verify') {
            DB::transaction(fn () => $rows->each(fn ($r) => $this->verify($r)));

            return back()->with('success', "{$rows->count()} verified.");
        }

        if ($data['action'] === 'return') {
            DB::transaction(fn () => $rows->each(fn ($r) => $this->returnRow($r, $data['hq_note'])));

            return back()->with('success', $rows->count() === 1
                ? '1 returned to its zone.'
                : "{$rows->count()} returned to their zones.");
        }

        if ($this->codeFrozen($event)) {
            return back()->with('error', self::FROZEN);
        }

        // A fix answers HQ's note, as the row's own save does.
        $changes = ($data['action'] === 'set_code'
            ? ['code' => $data['code'], 'code_updated_at' => now()]
            : ['plot_size' => $data['plot_size']]) + ['hq_note' => null];

        DB::transaction(function () use ($rows, $changes, $data) {
            $rows->each->update($changes);

            // A size set here becomes each company's own, and moves its other draw lists.
            if ($data['action'] === 'set_size') {
                $rows->load('company')->pluck('company')->unique('id')
                    ->each(fn ($company) => $this->moved += $company->syncBoothSize($data['plot_size']));
            }
        });

        return back()->with('success', "{$rows->count()} updated.".Company::boothMovedNote($this->moved, $admin->can('verify-draw-list')));
    }

    /**
     * Put back what the last removal took.
     *
     * The rows are rebuilt from what was stored, not resurrected — their ids are
     * gone and nothing pointed at them, because a row holding a lot is never
     * removed in the first place. One step deep: the session holds the last
     * removal only, which is the one an admin is still looking at.
     *
     * Not once the draw has started, for anyone. Undo is a way of ADDING rows,
     * and it puts them back as they were — a zone's row still waiting for HQ,
     * in a draw where no waiting row may exist (zoneLocked() refuses the same
     * through store()). And it puts them back silently: a company already sent
     * RemovedFromDraw would be in again with nothing saying so. HQ adds the
     * company again instead, verified at once and announced.
     */
    public function undo(Request $request, Event $event)
    {
        EventVisibility::authorize($event);

        if ($this->codeFrozen($event)) {
            return back()->with('error', 'The draw has started: nothing can be put back. HQ can add the company again.');
        }

        $rows = collect(session('draw_list_undo', []))
            ->where('event_id', $event->id);

        if ($rows->isEmpty()) {
            return back()->with('error', 'Nothing to undo.');
        }

        $companies = $this->scopedCompanies($request, $rows->pluck('company_id')->all())->keyBy('id');

        DB::transaction(function () use ($rows, $companies) {
            foreach ($rows as $row) {
                if (! $companies->has($row['company_id'])) {
                    continue;
                }

                EventRegistration::firstOrCreate(
                    ['event_id' => $row['event_id'], 'company_id' => $row['company_id']],
                    collect($row)->except(['event_id', 'company_id'])->all()
                );
            }
        });

        session()->forget('draw_list_undo');

        return back()->with('success', 'Put back.');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, EventRegistration>|array<int, EventRegistration>  $registrations
     */
    private function rememberForUndo($registrations): void
    {
        session(['draw_list_undo' => collect($registrations)
            ->take(self::UNDO_LIMIT)
            ->map(fn (EventRegistration $r) => [
                'event_id' => $r->event_id,
                'company_id' => $r->company_id,
                'user_id' => $r->user_id,
                'code' => $r->code,
                'code_updated_at' => $r->code_updated_at,
                'plot_size' => $r->plot_size,
                'confirmed_participation' => $r->confirmed_participation,
                'confirmed_at' => $r->confirmed_at,
                'verified_by' => $r->verified_by,
                'verified_at' => $r->verified_at,
                'first_verified_at' => $r->first_verified_at,
                'hq_note' => $r->hq_note,
                'payment_note' => $r->payment_note,
                'payment_status' => $r->payment_status,
            ])
            ->values()
            ->all()]);
    }

    /**
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, Company>
     */
    private function scopedCompanies(Request $request, array $ids)
    {
        $admin = $request->user('admin');

        return Company::whereIn('id', $ids)
            ->unless($admin->hasGlobalAccess(), fn ($q) => $q->where('state', $admin->state))
            ->get();
    }

    private function authorizeScope(Request $request, EventRegistration $registration): void
    {
        $this->authorizeCompany($request, $registration->company);
    }

    private function authorizeCompany(Request $request, Company $company): void
    {
        $admin = $request->user('admin');

        abort_if(
            ! $admin->hasGlobalAccess() && $company->state !== $admin->state,
            403,
            'That company is administered by another zone.'
        );
    }
}
