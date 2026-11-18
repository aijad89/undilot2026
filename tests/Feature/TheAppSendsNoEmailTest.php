<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\AddedToDraw;
use App\Notifications\RemovedFromDraw;
use App\Notifications\VotingCloseReminder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * NO EMAIL ANYWHERE IN THIS PROJECT (owner's decision, 2026-11-17).
 *
 * Participants sign in with community ID + IC; officers with username +
 * password. No account holds an email, every notification is in-app only, and
 * the forgot-password / reset-password flow — the other thing that mailed — is
 * gone. A mail server configured on the box later must still send nothing.
 */

dataset('participant notifications', [
    'added to draw' => [fn (Event $e) => new AddedToDraw($e)],
    'removed from draw' => [fn (Event $e) => new RemovedFromDraw($e, 'Duplicate entry')],
    'voting close reminder' => [fn (Event $e) => new VotingCloseReminder($e)],
]);

it('keeps no email column on any account, and no reset-token table', function () {
    expect(Schema::hasColumn('users', 'email'))->toBeFalse('users.email exists')
        ->and(Schema::hasColumn('admins', 'email'))->toBeFalse('admins.email exists')
        ->and(Schema::hasColumn('admins', 'username'))->toBeTrue('admins.username missing')
        ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse('password_reset_tokens exists');
});

it('sends each notification in-app only', function (Closure $make) {
    $notification = $make(Event::factory()->create());

    expect($notification->via(User::factory()->create()))->toBe(['database'])
        ->and(method_exists($notification, 'toMail'))->toBeFalse();
})->with('participant notifications');

it('has no notification class that can mail, including any added later', function () {
    $classes = collect(glob(app_path('Notifications/*.php')))
        ->map(fn ($path) => 'App\\Notifications\\'.basename($path, '.php'));

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(method_exists($class, 'toMail'))->toBeFalse("{$class} has toMail()");
    }
});

it('tells a company it is in the draw in-app, not by mail', function () {
    Notification::fake();
    $admin = superAdmin();
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $registration = EventRegistration::factory()->create([
        'event_id' => $event->id, 'company_id' => $company->id, 'user_id' => $user->id,
        'verified_at' => null, 'first_verified_at' => null,
    ]);

    $this->actingAs($admin, 'admin')
        ->put(route('admin.events.registrations.update', [$event, $registration]), ['code' => 'TBPS', 'plot_size' => '3x3'])
        ->assertRedirect();

    Notification::assertSentTo($user, AddedToDraw::class, fn ($n, array $channels) => $channels === ['database']);
});

it('has no forgot-password or reset-password door', function () {
    foreach (['password.request', 'password.email', 'password.reset', 'password.store'] as $name) {
        expect(Route::has($name))->toBeFalse("{$name} still exists");
    }

    $this->get('/forgot-password')->assertNotFound();
    $this->post('/forgot-password', [])->assertNotFound();
    $this->get('/reset-password/abc')->assertNotFound();
    $this->post('/reset-password', [])->assertNotFound();
});

it('asks for no email on the officer, company or sign-in forms', function () {
    $super = Admin::factory()->super()->create();
    $company = Company::factory()->create(['state' => 'Johor']);

    $screens = [
        route('admin.login'),
        route('login'),
    ];
    foreach ($screens as $url) {
        auth()->forgetGuards();
        $html = $this->get($url)->assertOk()->getContent();
        expect(preg_match('/name="email"|type="email"/', $html))->toBe(0, "{$url} asks for an email");
    }

    foreach ([
        route('admin.admins.create', ['group' => 'state']),
        route('admin.companies.create'),
        route('admin.companies.edit', $company),
    ] as $url) {
        $html = $this->actingAs($super, 'admin')->get($url)->assertOk()->getContent();
        expect(preg_match('/name="email"|type="email"/', $html))->toBe(0, "{$url} asks for an email");
    }
});
