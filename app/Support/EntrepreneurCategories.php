<?php

namespace App\Support;

/**
 * What kind of trader a company is.
 *
 * Two values, the office's own (decided 2026-11-17): a producer or trader, or a
 * wholesaler. The earlier scheme list (ODOI, MSM, government agency, NGO…) was
 * replaced, and with it the "external entity" rule that read two of its values —
 * nothing called that rule any more.
 *
 * Validated with `Rule::in()` rather than an `in:` string, so a value holding a
 * comma stays safe if one is ever added in Settings.
 */
class EntrepreneurCategories extends ManagedList
{
    protected const KEY = 'entrepreneur_categories';

    public const DEFAULTS = [
        'Pengeluar atau Pedagang',
        'Pemborong',
    ];
}
