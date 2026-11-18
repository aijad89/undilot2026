<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Every GET screen in the application, and enough of a world to render them.
 *
 * Shared by the sweeps that open every screen — "does it throw"
 * (EveryScreenOpensForTheRolesThatMayOpenItTest) and "does every link survive
 * a subpath install" (EveryLinkSurvivesASubpathInstallTest). A class rather than
 * functions in one test file: Pest defines such functions only when that file
 * is loaded, so running the other file alone would die on an undefined function.
 */
final class Screens
{
    /** Builds enough of a world that parameterised screens have a row to render. */
    public static function world(): array
    {
        $code = KrafBidang::factory()->code('TBPS')->create();

        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);

        $event = Event::factory()->create([
            'status' => 'open',
            'polling_status' => 'polling',
            'polling_deadline' => now()->addDays(3),
            'state' => null,
        ]);

        $size = PlotSize::create([
            'event_id' => $event->id,
            'size' => '3x3',
            'quantity' => 1,
            'location' => 'Dewan Utama',
        ]);

        Plot::create([
            'event_id' => $event->id,
            'plot_size_id' => $size->id,
            'company_id' => null,
            'allocated_to' => null,
            'bidang_id' => $code->id,
            'sub_bidang_id' => null,
            'plot_code' => 'DEWAN-TBPS1',
            'is_allocated' => false,
            'allocation_type' => 'algorithm',
            'is_locked' => false,
            'allocated_at' => null,
        ]);

        EventRegistration::create([
            'event_id' => $event->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'code' => 'TBPS',
            'code_updated_at' => now(),
            'plot_size' => '3x3',
            'plot_id' => null,
            'confirmed_participation' => false,
            'confirmed_at' => null,
            'verified_by' => null,
            'verified_at' => now(),
            'first_verified_at' => now(),
            'hq_note' => null,
            'payment_note' => null,
        ]);

        return [
            'user' => $user,
            'company' => $company,
            'event' => $event,
            'plotSize' => $size,
        ];
    }

    /**
     * Every named GET route, with its parameters filled from the world above,
     * keyed by route name. `$absolute = false` gives paths without a host, for
     * a caller that mounts them somewhere else.
     *
     * Routes needing a parameter this cannot supply are skipped by name rather
     * than guessed at, and each skip says why — a silent skip would let a screen
     * fall out of the sweep and look swept.
     */
    public static function urls(array $world, bool $absolute = true): array
    {
        $skip = [
            // Registers in local/testing only and signs a user in as a side effect,
            // which would change who the rest of this sweep is acting as.
            'dev.auto',
        ];

        $bind = [
            'event' => $world['event']->id,
            'company' => $world['company']->id,
            'plotSize' => $world['plotSize']->id,
            'admin' => null,   // bound per-case by the tests that need it
        ];

        $out = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || in_array($name, $skip, true) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (str_starts_with($route->uri(), 'storage/')) {
                continue;
            }

            $params = [];
            $missing = false;

            foreach ($route->parameterNames() as $param) {
                if (! array_key_exists($param, $bind) || $bind[$param] === null) {
                    $missing = true;

                    break;
                }

                $params[$param] = $bind[$param];
            }

            if ($missing) {
                continue;
            }

            $out[$name] = route($name, $params, $absolute);
        }

        return $out;
    }
}
