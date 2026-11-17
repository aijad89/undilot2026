<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * The public landing page: every published event, upcoming first.
     *
     * Read-only for everyone. The office enters every company into an event's
     * draw list; there is nothing here for a participant to do but look. How
     * many lots an event holds stays off it, as it does everywhere a
     * participant looks.
     */
    public function index(Request $request)
    {
        // Published and ended only. Unpublished is hidden here for everyone,
        // admins included — they reach it from the admin event list (K10).
        $events = Event::whereIn('status', Event::PUBLIC_STATUSES)
            ->when($request->input('search'), fn ($q, $search) => $q->where(
                fn ($w) => $w->where('title', 'like', "%{$search}%")->orWhere('location', 'like', "%{$search}%")
            ))
            ->get();

        [$past, $upcoming] = $events->partition(fn (Event $e) => $e->end_date->isPast());

        // Past events come six at a time; "More" asks for six more by URL, so it
        // needs no script and a reload keeps the place.
        $pastStep = 6;
        $pastShown = min(max((int) $request->input('past', $pastStep), $pastStep), 600);

        return view('home', [
            'upcoming' => $upcoming->sortBy('start_date')->values(),
            'past' => $past->sortByDesc('start_date')->values(),
            'pastShown' => $pastShown,
            'pastStep' => $pastStep,
        ]);
    }

    /**
     * One published event, for anyone. A draft is not public, so it is a 404 —
     * the same as an id that does not exist.
     *
     * Unpublished is a 404 too, except to a signed-in admin, who sees the page
     * with a banner saying it is hidden (K10). An admin is a guest on the `web`
     * guard, so the admin guard is asked by name.
     */
    public function show(Event $event)
    {
        abort_if($event->status === 'draft', 404);

        $adminPreview = ! $event->isPublic();

        abort_if($adminPreview && ! auth('admin')->check(), 404);

        // What a signed-in participant's company has to do with this event: on
        // the draw list, being added to it, or neither. There is no third state:
        // a participant cannot ask to join.
        $company = auth('web')->user()?->company;

        return view('events.show', [
            'event' => $event,
            'adminPreview' => $adminPreview,
            'onList' => $company && $event->registrations()->announced()->where('company_id', $company->id)->exists(),
            // Put on the list by the office, waiting for HQ to verify. The only
            // waiting state there is: a participant cannot ask to join, so
            // this is the whole of "something is happening, sit tight".
            'beingAdded' => $company && $event->registrations()->whereNull('first_verified_at')->where('company_id', $company->id)->exists(),
        ]);
    }

    /**
     * The participant's own dashboard: every draw their company is in, and what
     * is left to do in each.
     *
     * Every draw list the company is on shows, even before a voting window is
     * set: an empty page reads as "you are in nothing", which is false the moment
     * the office adds them. Cancelled draws do not show. How many lots exist stays
     * withheld, as on the vote page.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        $entries = $user->registrations()->announced()
            ->with(['event', 'plot.plotSize'])
            ->get()
            ->reject(fn ($r) => $r->event->polling_status === 'cancelled')
            // An event that has ended is on Past events, not here.
            ->reject(fn ($r) => $r->event->end_date->isPast())
            // Latest event first; on the same day, the newer event first.
            ->sortBy([fn ($a, $b) => [$b->event->start_date->timestamp, $b->event_id] <=> [$a->event->start_date->timestamp, $a->event_id]])
            ->values();

        $drawn = \App\Models\Poll::where('user_id', $user->id)->pluck('event_id')->flip();

        return view('dashboard', [
            'user' => $user,
            'company' => $company,
            'entries' => $entries,
            'drawn' => $drawn,
            'todo' => $this->todo($entries, $drawn),
        ]);
    }

    /**
     * The events the company took part in that have ended, latest first: the
     * lot it had.
     */
    public function pastEvents(Request $request)
    {
        $user = $request->user();

        $entries = $user->registrations()->announced()
            ->with(['event', 'plot'])
            ->get()
            ->filter(fn ($r) => $r->event->end_date->isPast() && $r->event->polling_status !== 'cancelled')
            ->sortByDesc(fn ($r) => $r->event->start_date->timestamp)
            ->values();

        return view('past-events', [
            'entries' => $entries,
            // A slip is printed only for a lot the participant confirmed.
            'drawn' => \App\Models\Poll::where('user_id', $user->id)->pluck('event_id')->flip(),
        ]);
    }

    /**
     * One past event's summary, for a company that took part in it. Any other
     * event, from here, does not exist: 404, as on the lot page.
     */
    public function pastEvent(Request $request, Event $event)
    {
        $company = $request->user()->company;

        abort_unless(
            $company && $event->end_date->isPast() && $event->polling_status !== 'cancelled'
                && $event->registrations()->announced()->where('company_id', $company->id)->exists(),
            404
        );

        return view('past-event', ['event' => $event]);
    }

    /**
     * What is left to do, most pressing first.
     *
     * One thing only: confirm a lot while the draw is open. A participant does
     * not ask to join and does not keep certificates here, so there is nothing
     * else they can act on.
     */
    private function todo($entries, $drawn): array
    {
        $todo = [];

        foreach ($entries as $entry) {
            $event = $entry->event;
            if ($event->polling_status === 'polling' && ! $event->polling_deadline?->isPast() && ! isset($drawn[$event->id])) {
                $todo[] = ['bad', "Confirm your lot for {$event->title} before {$event->polling_deadline->format('d M Y, h:i A')}.", route('zvote.index', $event), 'Confirm Lot'];
            }
        }

        return $todo;
    }
}
