<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Queued: until a worker runs, a sent reminder is a row in `jobs`. The command
 * that sends it marks the registration itself, so it never depends on that.
 */
class VotingCloseReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Event $event) {}

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
            'title' => 'Lot draw closing soon',
            'event_id' => $this->event->id,
            'event_name' => $this->event->title,
            'message' => 'The lot draw closes in 30 minutes.',
            'type' => 'alert',
        ];
    }
}
