<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The whole application schema, in one file.
 *
 * WHY ONE FILE. This repo starts with an empty database, so there is no history
 * to replay and nothing to be gained from spreading the schema over a series of
 * incremental files. The system this replaces had reached 156 migrations, about
 * seventy of which dropped something an earlier one had added; its net schema
 * was a fraction of its file count, and no reader could see the net shape
 * without running the whole chain.
 *
 * TWO RULES THIS FILE FOLLOWS, both learned the expensive way:
 *
 * 1. NO `enum` COLUMNS, anywhere. The vocabulary lives in model constants
 *    (`Event::STATUSES`, `Event::POLLING_STATUSES`) and the column is a plain
 *    string. An enum is identical on MySQL and SQLite the day it is created and
 *    stops being identical the first time anyone reaches for raw SQL to change
 *    it — `ALTER TABLE … MODIFY COLUMN` is silently skipped on SQLite, so the
 *    production schema and the test schema drift with nothing failing. In the
 *    old system that went unnoticed until no test could set
 *    `polling_status = 'closed'`, which is the state the lot draw is about.
 *    There is no enum here to drift.
 *
 * 2. THE UNIQUE INDEX ON `companies.user_id` IS CREATED HERE, not added later.
 *    One user owns exactly one company, and that is a schema promise rather than
 *    a controller's validation rule — a seeder, an artisan command or a second
 *    write path does not read `Rule::unique`. Adding it afterwards is the
 *    awkward version: MySQL drops the plain foreign-key index when a unique one
 *    lands on the same column, so the order of operations has to be managed by
 *    hand.
 *
 * DELETE RULES ARE DELIBERATE and were read off the live database of the system
 * this replaces, not off its migration files — those gave the wrong answer in
 * both directions. The ones worth knowing before changing anything:
 *
 *   companies.user_id          RESTRICT — a user cannot be deleted while they
 *                                         own a company. User::booted() turns
 *                                         that into a readable message.
 *   event_registrations.plot_id NO ACTION — deleting a plot does NOT clear the
 *                                         registration that points at it. That
 *                                         is a sharp edge, kept on purpose: the
 *                                         draw result must not evaporate
 *                                         silently because someone edited a plan.
 *   plots.allocated_to          SET NULL — the plot survives and stops naming
 *   plots.company_id            SET NULL   who drew it. A plot that loses its
 *                                         allocatee is not a destroyed plot, and
 *                                         the audit summaries keep the two apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ── Administration ──────────────────────────────────────────────── */

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // What the officer signs in with, with `password`. NO EMAIL: the
            // project uses none, anywhere (owner's decision, 2026-11-17).
            // Unique, compared lower-cased by AdminLoginRequest — MySQL's
            // collation compares it that way too.
            $table->string('username', 50)->unique();
            $table->string('password');

            // The `upengawai` id this account was built from by legacy:accounts
            // (dev only), so `--fresh` finds its own rows. Null for every
            // account made in the app.
            $table->string('legacy_id', 20)->nullable()->index();

            // Three roles: admin (one state), hq, super. The vocabulary and the
            // rules about who may hand out which live in Admin::ROLES and
            // Admin::assignableRolesFor() — not in a column constraint, because
            // the answer depends on who is asking.
            $table->string('role')->default('admin');

            // The office zone, for a state admin. Null for hq and super, who see
            // everything. NOTE this is an administrative zone, not geography.
            $table->string('state')->nullable();

            // Switched off by whoever manages this account (super for hq and
            // super, super or hq for a state admin): the login refuses it and
            // EnforceAdminIsActive ends a session already open. Kept, not
            // deleted, so its audit trail and verified_by rows stay named.
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        /* ── Key/value settings ──────────────────────────────────────────── */

        Schema::create('settings', function (Blueprint $table) {
            // Keyed by name, no surrogate id. Setting::get()/set() are the only
            // readers and writers, and App\Support\ManagedList stores every
            // editable dropdown list here as JSON.
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        /* ── Audit trail ─────────────────────────────────────────────────── */

        Schema::create('audit_trails', function (Blueprint $table) {
            $table->id();

            // Nullable morphs both sides: the performer may be an Admin, a User,
            // or nobody at all (a console command), and the subject may already
            // be gone by the time the row is read. No foreign keys in either
            // direction, on purpose — an audit row must outlive what it describes.
            $table->nullableMorphs('performer');
            $table->nullableMorphs('auditable');

            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url')->nullable();

            // Written once, never updated.
            $table->timestamp('created_at')->useCurrent();
        });

        /* ── Craft taxonomy ──────────────────────────────────────────────── */

        /**
         * `kraf_bidangs` HOLDS TWO LISTS IN ONE TABLE, and telling them apart is
         * the single most common mistake in this area:
         *
         *   `code` IS NULL     — a craft CATEGORY (TEKSTIL, HASIL RIMBA, …), the
         *                        top of the hierarchy a company picks from.
         *   `code` IS NOT NULL — a craft CODE (TBPS, RKP, …) from the printed
         *                        coding sheet, which a plot is categorised by and
         *                        a draw entry is matched against.
         *
         * They are two lists, two screens, two jobs, and they do not meet: a
         * company's code is its own field (`companies.kraf_code`).
         *
         * Every query against one of them must say which. A listing that forgets
         * shows both, and a delete that forgets removes a row from the list
         * nobody was looking at.
         */
        Schema::create('kraf_bidangs', function (Blueprint $table) {
            $table->id();

            // Display order from the printed coding sheet. NOT alphabetical:
            // sorting A-Z puts ANEKA KRAF above TEKSTIL, a shape nobody working
            // from that sheet recognises.
            $table->integer('bil')->nullable();

            $table->string('name');
            $table->string('pecahan_bidang')->nullable();
            $table->string('pecahan_sub_bidang')->nullable();
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_multiple_sub_bidang')->default(false);
            $table->timestamps();

            $table->index('code');
        });

        Schema::create('kraf_sub_bidangs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bidang_id')->constrained('kraf_bidangs')->cascadeOnDelete();

            // The optional link to the coded half of `kraf_bidangs`. Optional is
            // the point: setting a company's craft category must never require a
            // code to exist first.

            $table->string('name');
            $table->timestamps();
        });

        Schema::create('kraf_pengelasan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_bidang_id')->constrained('kraf_sub_bidangs')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('kraf_produks', function (Blueprint $table) {
            $table->id();
            // A product sits in a SUB-CATEGORY. `bidang_id` still names its
            // category, kept in step with the sub-category's. `sub_bidang_id` is
            // nullable only for products entered before it existed: those are
            // shown as "not placed yet" and cannot be ticked by a company.
            $table->foreignId('bidang_id')->constrained('kraf_bidangs')->cascadeOnDelete();
            $table->foreignId('sub_bidang_id')->nullable()->constrained('kraf_sub_bidangs')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /* ── Companies ───────────────────────────────────────────────────── */

        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            // RESTRICT, and unique. See the file docblock.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unique('user_id');

            $table->string('name');

            // The office zone this company is administered under — what a state
            // admin is scoped to. There is no address here and no premises table:
            // the draw needs to know which zone a company belongs to, not where
            // its workshop is.
            $table->string('state')->nullable();

            // One of EntrepreneurCategories: 'Pengeluar atau Pedagang' or
            // 'Pemborong'. A label only; the draw does not read it.
            $table->string('entrepreneur_category')->nullable();

            // The company's usual craft code (TBP, RKP…), set by an admin and never
            // shown to the participant. A string like event_registrations.code, and
            // only a SUGGESTION: the draw list copies it in when the company is
            // added, and the code that counts stays the one on the draw list.
            $table->string('kraf_code')->nullable();

            // The company's usual booth size (3x3, 3x6…), the same kind of
            // suggestion: copied to event_registrations.plot_size when the
            // company is added to a draw list, where it can still be changed.
            $table->string('plot_size')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('state');
        });

        Schema::create('company_krafs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // `bidang_id` is the CATEGORY half of kraf_bidangs (`code` IS NULL) —
            // the same half `kraf_sub_bidangs.bidang_id` points at, which is what
            // makes the pair below consistent. A company's craft CODE is not part of
            // its selections — it is `companies.kraf_code`. Both are NOT NULL.
            $table->foreignId('bidang_id')->constrained('kraf_bidangs')->cascadeOnDelete();
            $table->foreignId('sub_bidang_id')->constrained('kraf_sub_bidangs')->cascadeOnDelete();

            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // The classifications a company ticked under one selection's sub-category.
        // Goes with its selection; a classification still ticked here cannot be
        // deleted — the database refuses, and Settings refuses first, by name.
        Schema::create('company_kraf_pengelasan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_kraf_id')->constrained('company_krafs')->cascadeOnDelete();
            $table->foreignId('pengelasan_id')->constrained('kraf_pengelasan')->restrictOnDelete();
            $table->unique(['company_kraf_id', 'pengelasan_id']);
        });

        // The products a company ticked under one selection's sub-category, as
        // classifications are. Goes with its selection; a product still ticked
        // here cannot be deleted in Settings.
        Schema::create('company_kraf_produk', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_kraf_id')->constrained('company_krafs')->cascadeOnDelete();
            $table->foreignId('produk_id')->constrained('kraf_produks')->restrictOnDelete();
            $table->unique(['company_kraf_id', 'produk_id']);
        });

        /* ── Events ──────────────────────────────────────────────────────── */

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location');
            $table->string('state')->nullable();

            $table->dateTime('start_date');
            $table->dateTime('end_date');

            // The registration window. With auto_open / auto_close it drives
            // `events.status` — the office enters companies inside it.
            $table->dateTime('registration_open_at');
            $table->dateTime('registration_close_at');
            $table->boolean('auto_open')->default(false);
            $table->boolean('auto_close')->default(false);

            // draft | open | closed | completed. Event::STATUSES is the list.
            $table->string('status', 20)->default('draft');

            // draft | polling | closed | completed | cancelled.
            // Event::POLLING_STATUSES is the list.
            $table->string('polling_status', 20)->default('draft');
            $table->timestamp('polling_start_date')->nullable();
            $table->timestamp('polling_deadline')->nullable();
            $table->boolean('auto_polling_open')->default(false);
            $table->boolean('auto_polling_close')->default(false);

            // Computed by PollingController::savePlan() from the plan, never
            // taken from the form.
            $table->integer('total_plots')->nullable();

            $table->boolean('use_preset')->default(false);
            $table->unsignedInteger('max_participants');

            $table->string('banner')->nullable();

            // Calendar tint only.
            $table->string('colour', 7)->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('polling_status');
        });

        /* ── The draw plan ───────────────────────────────────────────────── */

        /**
         * A `plot_sizes` row is a (LOCATION, SIZE) PAIR, and that pair is what a
         * plot carries.
         *
         * The plan form does not type the pair directly: one row on the form is a
         * LOCATION with a count, and each craft allocation nested under it names
         * its OWN size — the same location can hold 3x6 and 3x3 side by side.
         * PollingController::savePlan() resolves those back onto rows here,
         * matching on (location, size) so a surviving pair keeps its id.
         */
        Schema::create('plot_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('size');
            $table->integer('quantity');
            $table->string('location')->nullable();
            $table->timestamps();
        });

        Schema::create('bidang_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bidang_id')->constrained('kraf_bidangs')->cascadeOnDelete();
            $table->foreignId('sub_bidang_id')->nullable()->constrained('kraf_sub_bidangs')->cascadeOnDelete();
            $table->foreignId('plot_size_id')->constrained('plot_sizes')->cascadeOnDelete();
            $table->integer('plots_count')->default(0);
            $table->timestamps();
        });

        Schema::create('plots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plot_size_id')->constrained('plot_sizes')->cascadeOnDelete();

            // SET NULL, both. A plot that loses its allocatee is still a plot.
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('allocated_to')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('bidang_id')->nullable()->constrained('kraf_bidangs')->cascadeOnDelete();
            $table->foreignId('sub_bidang_id')->nullable()->constrained('kraf_sub_bidangs')->cascadeOnDelete();

            $table->string('plot_code');
            $table->boolean('is_allocated')->default(false);

            // algorithm | preset | manual
            $table->string('allocation_type')->default('algorithm');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('allocated_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'is_allocated']);

            /*
             * The lot code as it is printed on the booth and on the slip, so it is
             * also what anybody looks a plot up by.
             *
             * NOT unique, and the reason is in the data: `legacy:transform-aacb`
             * keeps the office's own `boothid` as `plot_code`, and those are
             * unique within one event (`TBP01`, `KH01`) but repeat across events —
             * every fair has a `TBP01`. Unique here would refuse the second event
             * imported. A unique on (event_id, plot_code) would be true today and
             * is still not added: nothing reads a plot by that pair, so it would
             * be a constraint carried for its own sake.
             */
            $table->index('plot_code');
        });

        /* ── Who is in the draw ──────────────────────────────────────────── */

        /**
         * THE EXISTENCE OF A ROW IS THE APPROVAL.
         *
         * There is no status flow here. An admin puts a company into an event's
         * draw list, gives it a craft code and a booth size, and that is what
         * being in the draw means. The system this replaces carried a seven-value
         * status column and the workflow around it; none of it is what the draw
         * reads.
         */
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Ownership is `company_id` — that column is NOT NULL and
            // foreign-keyed. `user_id` is kept because the draw itself writes and
            // reads it (plots.allocated_to, polls, preset allocations all key on
            // the account), but it is NOT the ownership predicate and no
            // user-facing path should ask it. Two keys, two questions.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // The craft code from the coding sheet (TBPS, RKP, …), as a string.
            // It matches against the code on `kraf_bidangs` rows that plots are
            // categorised by. A string rather than a foreign key because that is
            // what the allocator compares, and changing it would mean rewriting
            // the allocator rather than carrying it across intact.
            $table->string('code')->nullable();
            $table->timestamp('code_updated_at')->nullable();

            // A string, defaulting to 3x3, NOT a reference to plot_sizes: the
            // office states a booth size before any plan exists, because the plan
            // is built from those numbers.
            $table->string('plot_size', 20)->nullable()->default('3x3');

            // NO `onDelete` CLAUSE ON PURPOSE — see the file docblock. Without
            // one the database restricts, so a plot cannot be deleted while a
            // draw entry still points at it. A plan rewrite must clear `plot_id`
            // first and be seen to do it, rather than having the result vanish
            // underneath.
            $table->foreignId('plot_id')->nullable()->constrained('plots');

            $table->boolean('confirmed_participation')->default(false);
            $table->timestamp('confirmed_at')->nullable();

            // Stamped when the close reminder is QUEUED, not when it is delivered.
            $table->timestamp('reminded_at')->nullable();

            // Two checks before a row counts. The zone admin checks the company,
            // its payment proof, code and booth size, and adds the row; HQ checks
            // it again and stamps `verified_at`. The draw will not open while any
            // row is unverified, and the participant hears nothing until then.
            // `first_verified_at` is when the participant was told, and is never
            // cleared: a zone edit sends the row back to HQ (`verified_at` null)
            // but the company stays in its own portal, and is not told twice.
            // `hq_note` is HQ sending the row back to the zone with a reason.
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('first_verified_at')->nullable();
            $table->text('hq_note')->nullable();

            // How the company paid — receipt number, date, how — written by the
            // zone for HQ to check. The office enters every company, so this is
            // the only record of payment there is.
            $table->string('payment_note', 500)->nullable();

            // Paid or not, chosen by the officer beside the note: a key of
            // EventRegistration::PAYMENT_STATUSES. Null until someone says.
            $table->string('payment_status', 20)->nullable();

            $table->timestamps();

            /*
             * One entry per company per event: one draw per event, and a company
             * cannot be in the same draw twice.
             *
             * THIS ALSO SERVES EVERY BY-EVENT READ, which is why there is no
             * separate index on `code` beside it. `PollingGuard` and
             * `LotAllocator` both scan one event's rows grouped by code and booth
             * size, and an `(event_id, code)` index was added for them and then
             * MEASURED at 17,200 rows across twenty events: the planner chose this
             * unique both times and the query took 1.56 ms without the extra index
             * and 1.50 ms with it.
             *
             * The reason it cannot help is structural rather than a matter of
             * size: `event_id` alone already narrows to one event, and the group
             * is by `plot_size` too — which is in neither index — so the rows get
             * read either way. Only a covering index over every column the query
             * touches could change that, for a query that runs once when an admin
             * asks whether a draw may open.
             */
            $table->unique(['event_id', 'company_id']);
        });

        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->nullable();
            $table->timestamps();

            // "Has this account drawn yet?" is asked here and nowhere else.
            $table->unique(['user_id', 'event_id']);
        });

        Schema::create('preset_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bidang_id')->constrained('kraf_bidangs')->cascadeOnDelete();
            $table->foreignId('sub_bidang_id')->nullable()->constrained('kraf_sub_bidangs')->cascadeOnDelete();
            $table->foreignId('plot_size_id')->constrained('plot_sizes')->cascadeOnDelete();
            $table->string('plot_number');
            $table->string('plot_size')->nullable();
            $table->string('plot_code')->nullable();
            $table->string('code', 50)->nullable();
            $table->integer('priority_rank');
            $table->boolean('is_confirmed')->default(false);
            $table->timestamps();
        });

        /* ── Notifications ───────────────────────────────────────────────── */

        /**
         * Laravel's own notifications table, written out here rather than
         * published, so the whole schema stays in one file.
         *
         * It is NOT optional even if nothing has ever been sent:
         * AppServiceProvider's `notifications.unread-count` binding calls
         * `unreadNotifications()` for any signed-in user, so a missing table is a
         * 500 on every portal page rather than a feature that is merely absent.
         */
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        /* ── Legacy import staging ───────────────────────────────────────── */

        /**
         * A landing strip for the old system's dump. Nothing here is application
         * data: these three tables hold the columns of the office's MySQL 4-era
         * tables that the import actually uses, so a wrong mapping costs a
         * re-transform rather than another export request.
         *
         * NOT EVERY COLUMN, AND THAT IS DELIBERATE. The dump has 64 tables and
         * 21,032 rows, and the fill rate of each column was measured against it
         * rather than guessed. Twenty-one columns were left out (2 + 13 + 6): some are 0%
         * filled in all 21,032 rows, some hold a single repeated value, and some
         * the office stopped capturing a decade ago. Each table below says which
         * and why. `kraf_lama.sql` itself is the archive, so narrowing these
         * tables loses nothing — a later historical import re-reads the file.
         *
         * FIVE RULES, and the first is the one that matters:
         *
         * 1. EVERY DATA COLUMN IS A NULLABLE STRING. Not a convenience — the
         *    dump cannot be stored any other way on this MySQL. `usahawan`
         *    declares `votetime` as `datetime NOT NULL` with no default, which on
         *    MySQL 4/5 means "never" was written as `0000-00-00 00:00:00` — and
         *    45% of the snapshot rows carry exactly that. This server runs
         *    `sql_mode = STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE`, which
         *    REJECTS that value for a date column outright. `prio` has the same
         *    shape of problem: an `int` column in a dump that may hold ''.
         *    Typing a staging column is how the load fails on row one.
         *
         * 2. COLUMN NAMES ARE THE LEGACY NAMES, LOWERCASED, and nothing else.
         *    One rule, so the load needs no per-column mapping and an audit is a
         *    diff rather than a judgement. That is why `userright` and `loginname`
         *    read oddly: they are `UserRight` and `LoginName` in the dump.
         *
         * 3. `legacy_id` KEEPS THE ORIGINAL `id`. Without it the links between
         *    the old tables cannot be rebuilt: `ukhemah.usahawanid` points at
         *    `usahawan.id`, and `usahawan.byuser` at `upengawai.id`. It is a
         *    string, not an integer, for rule 1's reason.
         *
         * 4. `source_table` NAMES THE TABLE THE ROW CAME FROM. The dump holds
         *    fifteen `ukhemah*` tables — one live, fourteen hourly snapshots with
         *    names like `ukhemah_day1_2pm` and `ukhemah_18 feb 11`. One staging
         *    table with provenance beats fifteen staging tables, and the column
         *    carries those names (spaces, brackets and all) as data, where they
         *    cannot break anything.
         *
         * 5. NO FOREIGN KEY, NO UNIQUE INDEX. Staging has to accept duplicates,
         *    orphans and half-filled rows — `ukhemah10` has only six of the
         *    fourteen columns, so the rest land NULL. Finding that is the
         *    transform's job and a refusal here would only hide it.
         *
         * `imported_at` instead of `timestamps()`: a staging row is written once
         * and never updated, so `updated_at` would be a copy of `created_at`
         * forever. The only fact worth keeping is when the load ran.
         *
         * THE THREE CRAFT COLUMNS DO NOT MEAN WHAT THEY ARE CALLED, and getting
         * them backwards is the mistake this schema's `kraf_bidangs` note warns
         * about. Read off real rows:
         *
         *   `bidang`    the craft CATEGORY — 'Tekstil', 'Hasil Rimba',
         *               'Hasil Tanah', 'Aneka Kraf'. Title case here, upper case
         *               in `kraf_bidangs`.
         *   `crafttype` the craft CODE, with the plot size glued on in the newer
         *               data: 'Batik-TBA-3x3', 'Buluh/Rotan-RBH-3x3'. Older rows
         *               carry a bare name instead — 'Songket', 'Tenun',
         *               'Kayu(Hiasan Ukiran & Kegunaan)' — with no code and no
         *               size. Split from the RIGHT: the craft name itself can
         *               hold punctuation.
         *   `category`  NOT a craft at all — the ENTREPRENEUR category,
         *               'Pentahapan 2 (Pengilang/Pedagang,Pengeluar,Agensi)'.
         *               It belongs in `companies.entrepreneur_category`.
         *
         * `boothid` repeats the code as its prefix in the newer data (TBA02 for
         * TBA, AMH03 for AMH), which is a second reading of the same fact and a
         * way to check the first. Older booth ids (KH01, PS05, ST11) follow no
         * code list.
         *
         * `salu` is a SALUTATION ('Puan'), not a status.
         */
        Schema::create('legacy_upengawai', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id', 20)->nullable();
            $table->string('source_table', 100)->nullable();

            // `upengawai`: the old officers table. There is NO email column in
            // it — it signed in with `loginname`, which legacy:accounts turns
            // into `admins.username`.
            $table->string('name', 100)->nullable();
            $table->string('password', 100)->nullable();
            $table->string('userright', 100)->nullable();
            $table->string('loginname', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('ic', 100)->nullable();
            $table->string('jawatan', 100)->nullable();

            // DROPPED, measured over all 137 rows of every `upengawai*` table:
            // `sessionID` and `lastlogin` are 0% filled — the old app wrote
            // neither. `userright` is kept although it holds ONE value across
            // every row ('Pengawai'), because that single value is the answer to
            // "which role does this officer get": the old system had none, so
            // admin/hq/super has to be assigned here, not migrated.

            $table->timestamp('imported_at')->nullable();

            $table->index('legacy_id');
            $table->index('source_table');
        });

        Schema::create('legacy_usahawan', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id', 20)->nullable();
            $table->string('source_table', 100)->nullable();

            /*
             * `usahawan`: the office's REGISTER OF PARTICIPANTS — one row per
             * usahawan, not one per event. That is why it has no `event` column
             * and why the dump indexes `name`, `ic` and `companyname`: it is
             * looked up, not appended to per draw.
             *
             * One row holds both the person and their company, which is exactly
             * the pair undilot keeps as `users` + `companies` — and
             * `companies.user_id` is unique, so the shapes agree.
             *
             * THE TRAP IS THE DRAW COLUMNS. `lot`, `location`, `votetime`,
             * `undi`, `preset`, `payment`, `bayarstatus` and `votetimepay` sit
             * on this row with nothing naming an event, so they hold the LATEST
             * draw only, overwritten each time. Per-event history lives in
             * `ukhemah`, which does carry `event`. A transform that reads a lot
             * from here would hand the last draw's result to every event.
             *
             * IDENTITY IS THE OTHER PROBLEM. There is no email and no password.
             * `ic` is the only candidate key — and the dump indexes it without a
             * UNIQUE, so two rows may share one, and a row may carry ''.
             * legacy:accounts keys the accounts it builds on `users.legacy_id`
             * instead, and `companies.user_id` unique still has to be resolved
             * before the insert rather than discovered during it.
             */
            $table->string('name', 200)->nullable();
            $table->string('ic', 20)->nullable();
            $table->string('state', 20)->nullable();
            $table->string('companyname', 100)->nullable();
            $table->string('crafttype', 100)->nullable();
            $table->string('lot', 10)->nullable();
            $table->string('location', 20)->nullable();
            $table->string('votetime', 25)->nullable();
            $table->string('bidang', 30)->nullable();
            $table->string('salu', 20)->nullable();
            $table->string('preset', 10)->nullable();
            $table->string('comid', 10)->nullable();
            $table->string('byuser', 11)->nullable();

            /*
             * DROPPED ELEVEN COLUMNS, measured not assumed. Fill rate over the
             * 175 rows of the live `usahawan` — the AACB event, the only one
             * whose participants survive:
             *
             *   subcrafttype, votetimepay   0% HERE AND IN EVERY SNAPSHOT.
             *                               `votetimepay` is '0000-00-00' in all
             *                               20,000-odd rows; it was never used.
             *   address, postcode, region   0% live, 92-97% in the 2011/2012
             *   register, category          snapshots. The office STOPPED
             *   payment, sstatus,           capturing these. They are real data
             *   sstatusexport, protokol     for historical rows and nothing for
             *                               the import we are doing.
             *
             * `bayarstatus` and `undi` are dropped too, for the opposite reason:
             * 100% filled but ONE distinct value each ('Ya', 'Yes'). A column
             * that never varies carries no row-level fact. What they mean —
             * every AACB participant had paid and had confirmed — belongs in the
             * transform, which sets `confirmed_participation` and writes a
             * payment note, not in a column repeating one word 175 times.
             *
             * THE DUMP FILE IS THE ARCHIVE, which is what makes this safe.
             * Nothing is lost by narrowing the table: a later historical import
             * re-reads `kraf_lama.sql` and widens the schema then, with the
             * office's answers about events in hand.
             */

            $table->timestamp('imported_at')->nullable();

            $table->index('legacy_id');
            $table->index('source_table');

            // The three the dump itself indexed, kept because the transform
            // joins on exactly these, plus the two keys it resolves.
            $table->index('name');
            $table->index('ic');
            $table->index('companyname');
            $table->index('comid');
            $table->index('byuser');
        });

        Schema::create('legacy_ukhemah', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id', 20)->nullable();
            $table->string('source_table', 100)->nullable();

            /*
             * `ukhemah` IS THE PLAN, NOT THE REGISTRATIONS. Read against real
             * rows: one row is one booth — `boothid` ('KH01', 'TBA02') standing
             * at a location named by `khemah` ('Khemah A', 'Basement', 'PADANG
             * AKASIA , ANGSANA JOHOR BAHRU MALL'), categorised by craft, with
             * `usahawanid` EMPTY until somebody holds it. That is undilot's
             * `plots` table with its allocation inlined, so the transform builds
             * plots and their allocation from here — not draw entries.
             *
             * THE WIDEST SHAPE OF THE `ukhemah*` TABLES, and there are more than
             * the snapshots: `ukhemah_fkj2024` is a named per-event table that
             * DOES carry `event` ('FKJ'), so `event` is not the live table's
             * alone. `ukhemah10` stops after `subtypeitem`. `usahawan` has
             * copies of its own in the same style (`usahawan_19 feb 6pm`), which
             * is why `source_table` is on all three staging tables and not just
             * this one.
             *
             * `prio` is the priority a booth carries, and `protokol` ('Ya' or
             * empty) marks a reserved one — the sample's `protokol = 'Ya'` rows
             * are the ones with `prio = 6`.
             */
            $table->string('khemah', 100)->nullable();
            $table->string('usahawanid', 11)->nullable();
            $table->string('boothid', 10)->nullable();
            $table->string('bidang', 100)->nullable();
            $table->string('crafttype', 100)->nullable();
            $table->string('prio', 20)->nullable();
            $table->string('event', 100)->nullable();

            /*
             * DROPPED SIX. Fill rate over the live `ukhemah` (175 booths, event
             * AACB): `typeitem`, `subtypeitem`, `category`, `sstatusexport`,
             * `sstatus` and `protokol` are ALL 0%. Across every `ukhemah*` table
             * they reach 3-17%, entirely in the 2011/2012 rows.
             *
             * `prio` is kept even though the live table holds one value for all
             * 175 rows, because it varies in the older plans (five values) and
             * it is what tells a reserved booth from an ordinary one.
             */

            $table->timestamp('imported_at')->nullable();

            $table->index('legacy_id');
            $table->index('source_table');
            $table->index('usahawanid');
            $table->index('boothid');
            $table->index('event');
        });
    }

    public function down(): void
    {
        // Reverse creation order, so a foreign key never outlives what it points at.
        foreach ([
            'legacy_ukhemah',
            'legacy_usahawan',
            'legacy_upengawai',
            'notifications',
            'preset_allocations',
            'polls',
            'event_registrations',
            'plots',
            'bidang_allocations',
            'plot_sizes',
            'events',
            'company_kraf_produk',
            'company_kraf_pengelasan',
            'company_krafs',
            'companies',
            'kraf_produks',
            'kraf_pengelasan',
            'kraf_sub_bidangs',
            'kraf_bidangs',
            'audit_trails',
            'settings',
            'admins',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
