<?php

namespace App\Console\Commands;

use App\Models\KrafBidang;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the office's craft hierarchy sheet into Settings › Craft Hierarchy.
 *
 * Columns: Bidang, Sub-bidang, Pengelasan sub-bidang, Contoh produk. A blank
 * Bidang or Sub-bidang cell means "same as the row above".
 *
 * EVERY CELL IS ONE NAME, commas included ("Batik canting, batik lukis") —
 * the same default as the screen's own add box. Line breaks inside a cell are
 * folded to spaces. A "-" cell is kept as the entry "-", as the sheet shows it.
 *
 * Products go under the SUB-CATEGORY, not the classification beside them on the
 * sheet: that is where the schema and the company tick screen keep them.
 *
 * ADDS ONLY. Names already there (case-insensitive) are skipped, nothing is
 * deleted, so it can be run again. Categories are the five seeded ones; a
 * Bidang that matches none stops the whole file before anything is written.
 * Saved through the models so `Auditable` records each row.
 */
class ImportKrafHierarchy extends Command
{
    protected $signature = 'kraf:import-hierarchy {file} {--dry-run : Report what would be added, write nothing}';

    protected $description = 'Load the craft hierarchy CSV (Bidang, Sub-bidang, Pengelasan, Contoh produk)';

    public function handle(): int
    {
        $handle = @fopen($this->argument('file'), 'r');
        if (! $handle) {
            $this->error('Cannot open '.$this->argument('file'));

            return self::FAILURE;
        }

        $categories = KrafBidang::categories()->get()->keyBy(fn ($c) => mb_strtolower($c->name));

        // Plan: [category name => [sub name => ['pengelasan' => [...], 'produk' => [...]]]]
        $plan = [];
        $problems = [];
        $bidang = $sub = null;
        fgetcsv($handle); // header

        for ($line = 2; ($row = fgetcsv($handle)) !== false; $line++) {
            [$b, $s, $p, $prod] = array_map([$this, 'clean'], array_pad($row, 4, ''));
            if ($b !== '') {
                $bidang = $b;
                $sub = null;
            }
            if ($s !== '') {
                $sub = $s;
            }
            if ($bidang === null || $sub === null) {
                if ($p !== '' || $prod !== '') {
                    $problems[] = "Row $line: no category or sub-category to put it under.";
                }

                continue;
            }
            if (! $categories->has(mb_strtolower($bidang))) {
                $problems[] = "Row $line: category \"$bidang\" is not one of the seeded categories.";

                continue;
            }
            foreach (['pengelasan' => $p, 'produk' => $prod, 'sub' => $sub] as $what => $name) {
                if (mb_strlen($name) > 255) {
                    $problems[] = "Row $line: $what longer than 255 characters.";
                }
            }

            $entry = &$plan[mb_strtolower($bidang)][$sub];
            $entry ??= ['pengelasan' => [], 'produk' => []];
            foreach (['pengelasan' => $p, 'produk' => $prod] as $what => $name) {
                if ($name !== '') {
                    $entry[$what][mb_strtolower($name)] ??= $name;
                }
            }
            unset($entry);
        }
        fclose($handle);

        if ($problems) {
            foreach (array_unique($problems) as $problem) {
                $this->error($problem);
            }
            $this->error('Nothing written.');

            return self::FAILURE;
        }

        $added = ['sub' => 0, 'pengelasan' => 0, 'produk' => 0];
        DB::beginTransaction();
        try {
            foreach ($plan as $key => $subs) {
                $category = $categories[$key];
                foreach ($subs as $subName => $lists) {
                    $model = $category->subBidangs()->get()
                        ->first(fn ($s) => mb_strtolower($s->name) === mb_strtolower($subName));
                    if (! $model) {
                        $model = $category->subBidangs()->create(['name' => $subName]);
                        $added['sub']++;
                    }

                    $have = $model->pengelasan()->pluck('name')->map(fn ($n) => mb_strtolower($n));
                    foreach ($lists['pengelasan'] as $lower => $name) {
                        if (! $have->contains($lower)) {
                            $model->pengelasan()->create(['name' => $name]);
                            $added['pengelasan']++;
                        }
                    }

                    $have = $model->produks()->pluck('name')->map(fn ($n) => mb_strtolower($n));
                    foreach ($lists['produk'] as $lower => $name) {
                        if (! $have->contains($lower)) {
                            $model->produks()->create(['name' => $name, 'bidang_id' => $category->id]);
                            $added['produk']++;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $this->option('dry-run') ? DB::rollBack() : DB::commit();

        $this->info(($this->option('dry-run') ? 'Would add' : 'Added')
            ." {$added['sub']} sub-categories, {$added['pengelasan']} classifications, {$added['produk']} products.");

        return self::SUCCESS;
    }

    /** One cell as one name: BOM gone, line breaks folded, stray end commas dropped. */
    private function clean(string $cell): string
    {
        $cell = str_replace("\u{FEFF}", '', $cell);
        $cell = trim(preg_replace('/\s+/u', ' ', $cell), " ,");

        return $cell;
    }
}
