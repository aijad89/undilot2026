<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The participant sign-in: COMMUNITY ID + IC NUMBER, no password.
 *
 * The owner's decision (2026-11-17): participants are not given an email, so
 * the password flow — which can only be started through an emailed reset link —
 * could never let them in. Both values are ones the office already holds and
 * the participant already knows.
 *
 * KNOWN WEAKNESS, ACCEPTED FOR NOW: neither value is a secret. The IC is printed
 * on the lot slip and both are known to the office and to anyone holding the
 * participant's documents. The owner has asked for this to be revisited by the
 * team later; until then the rate limit below is the only brake on guessing.
 *
 * Admins are untouched: /admin/login is still email + password.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'community_id' => ['required', 'string', 'max:50'],
            'ic_number' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = $this->matchingUser();

        if ($user === null) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'community_id' => 'No participant has that community ID and IC number.',
            ]);
        }

        // Checked AFTER the match, as the admin login does: only someone who
        // already holds both values learns the account exists but is off.
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'community_id' => 'Your account has been deactivated. Please contact the administrator.',
            ]);
        }

        if ($user->company && ! $user->company->is_active) {
            throw ValidationException::withMessages([
                'community_id' => 'Your company has been deactivated. Please contact the administrator.',
            ]);
        }

        Auth::guard('web')->login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * The one account holding both values, or null.
     *
     * The community ID is compared trimmed and without regard to case. The IC
     * is compared as DIGITS: the office writes both 890508-12-0000 and
     * 890508120000, and a participant may type either.
     *
     * Two accounts holding the same pair is null, not the first one: nothing
     * says which of the two is signing in.
     */
    private function matchingUser(): ?User
    {
        $id = Str::lower(trim((string) $this->input('community_id')));
        $ic = self::digits($this->input('ic_number'));

        if ($id === '' || $ic === '') {
            return null;
        }

        $matches = User::whereRaw('lower(trim(community_id)) = ?', [$id])
            ->whereNotNull('ic_number')
            ->get()
            ->filter(fn (User $u) => self::digits($u->ic_number) === $ic);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private static function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'community_id' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Per community ID and per address. The IC is left out on purpose: keyed
     * on it, every wrong guess would start a fresh counter.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower(trim((string) $this->input('community_id'))).'|'.$this->ip());
    }
}
