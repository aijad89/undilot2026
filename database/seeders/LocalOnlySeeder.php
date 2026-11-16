<?php

namespace Database\Seeders;

use App\Support\DevEnvironment;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Every seeder that plants demo or test data extends this — never on a server.
 *
 * The guard sits in `__invoke`, which is the one door both `db:seed --class=X`
 * and `$this->call(X::class)` go through, so a subclass cannot forget it and
 * no `run()` body needs its own check.
 *
 * It THROWS rather than returning quietly: some of these seeders delete every
 * event before planting (DemoThreeEventsSeeder), and a refusal must be loud,
 * stop the whole seed run, and leave a non-zero exit code for a deploy script.
 *
 * `tests/Feature/DemoSeedersNeverRunOnAServerTest.php` fails if a seeder other
 * than the reference ones does not extend this class.
 */
abstract class LocalOnlySeeder extends Seeder
{
    public function __invoke(array $parameters = [])
    {
        if (! DevEnvironment::here()) {
            throw new RuntimeException(sprintf(
                '%s plants demo data and runs only when APP_ENV is one of: %s. This environment is "%s". Nothing was changed.',
                class_basename(static::class),
                implode(', ', DevEnvironment::NAMES),
                app()->environment(),
            ));
        }

        return parent::__invoke($parameters);
    }
}
