<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * HQ took the company off a draw it had been told it is in, and why. Sent only
 * for a company that heard "you are in" — one taken off before HQ verified it
 * never heard anything, and hears nothing now. Carries `join_event_id`, like
 * AddedToDraw: the next step is the event page, not Confirm Lot.
 */
class RemovedFromDraw extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Event $event, public string $reason)
    {
        // Sent from inside the office's transaction: only once it holds.
        $this->afterCommit();
    }

    /**
     * In-app only. The app sends no email at all (owner's decision,
     * 2026-11-17): participants sign in with community ID + IC and most
     * give no address. Do not add 'mail' back without that decision.
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => 'Taken off the draw list',
            'join_event_id' => $this->event->id,
            'approved' => false,
            'event_name' => $this->event->title,
            'message' => 'Your company was taken off the draw list for '.$this->event->title.".\nReason: ".$this->reason,
            'type' => 'alert',
        ];
    }
}
