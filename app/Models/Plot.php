<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plot extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'event_id',
        'plot_size_id',
        'company_id',
        'allocated_to',
        'bidang_id',
        'sub_bidang_id',
        'plot_code',
        'is_allocated',
        'allocation_type',
        'is_locked',
        'allocated_at',
    ];

    protected $casts = [
        'is_allocated' => 'boolean',
        'is_locked' => 'boolean',
        'allocated_at' => 'datetime',
    ];

    /**
     * A plot's readable number: "TBPS02".
     *
     * Minted wherever plots are built or renumbered, and printed on the slip, in
     * the QR, on every PDF and in every admin listing — so the rule lives once.
     *
     * The office's own format (decided 2026-11-17): the craft code, then the
     * number padded to two digits — TBPS01, TBPS02 … TBPS10, and TBPS100 once
     * past 99. NO LOCATION: the slip prints the location on its own line, and
     * the number is counted per code across the whole event, so TBPS02 is one
     * plot even when TBPS plots stand in two halls. Callers key their counters
     * on the code alone for that reason.
     *
     * $token is the craft code where a plot has one, and the plot size where it
     * does not. A size ends in a digit, so it takes a hyphen before the number —
     * "3X3-04", not "3X304", which reads as plot 304.
     */
    public static function codeFor(?string $token, int $n): string
    {
        $tag = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $token)) ?: 'PLOT';
        $separator = ctype_digit(substr($tag, -1)) ? '-' : '';

        return $tag.$separator.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Set-aside lots that are still the office's surprise: the company has not
     * confirmed yet and the draw has not closed. Only super and HQ see them.
     */
    public function scopeSecretPresets($query)
    {
        return $query->where('plots.allocation_type', 'preset')
            ->whereHas('event', fn ($e) => $e->whereNotIn('polling_status', ['closed', 'completed']))
            ->whereNotExists(fn ($p) => $p->from('polls')
                ->whereColumn('polls.event_id', 'plots.event_id')
                ->whereColumn('polls.user_id', 'plots.allocated_to'));
    }

    /**
     * How a zone admin sees these plots: a secret preset looks free, any other
     * preset looks drawn. In memory only — a masked plot is never saved.
     */
    public static function maskPresets(iterable $plots): void
    {
        $plots = collect($plots)->filter(fn ($p) => $p?->allocation_type === 'preset');
        if ($plots->isEmpty()) {
            return;
        }

        $secret = static::secretPresets()->whereIn('plots.id', $plots->pluck('id'))->pluck('plots.id')->flip();

        foreach ($plots as $plot) {
            if (isset($secret[$plot->id])) {
                $plot->forceFill(['is_allocated' => false, 'is_locked' => false, 'company_id' => null, 'allocated_to' => null, 'allocated_at' => null])
                    ->setRelation('company', null);
            }
            $plot->allocation_type = 'algorithm';
        }
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function plotSize()
    {
        return $this->belongsTo(PlotSize::class);
    }

    /** The craft CODE row the plot is set aside for, or null for the open pool. */
    public function bidang()
    {
        return $this->belongsTo(KrafBidang::class, 'bidang_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'allocated_to');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
