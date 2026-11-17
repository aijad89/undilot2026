<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Lot Draw Result Slip – {{ $event->title }}</title>
<style>
    /* The PDF renderer reads no CSS variables, so the colours are the token values
       from partials/ui-tokens.blade.php written out: navy #0b2240 (--ui-navy-900),
       blue #1a56db / #1546b3 (--ui-blue / --ui-blue-text), text #101828 / #344054 /
       #586477 (--ui-text / -2 / --ui-muted), borders #dce1e8 / #c3ccd8, tints
       #f8fafc / #ebf1fd / #e6f4ec, ok text #0b6239. Change a token, change it here. */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #101828; background: #fff; }

    /* ── Header ── */
    .header {
        background: #0b2240;
        color: #fff;
        padding: 20px 24px;
        margin-bottom: 0;
    }
    .header h1   { font-size: 17px; font-weight: bold; margin-bottom: 4px; }
    .header .sub { font-size: 10px; opacity: .7; }
    .header .ref { font-size: 10px; opacity: .55; }

    /* ── Green banner ── */
    .success-banner {
        background: #0b6239;
        color: #fff;
        text-align: center;
        padding: 8px 24px;
        font-size: 10.5px;
        font-weight: bold;
        letter-spacing: .06em;
        text-transform: uppercase;
        margin-bottom: 24px;
    }

    /* ── Lot box ── */
    .lot-box {
        margin: 0 24px 24px;
        border: 2.5px solid #1a56db;
        border-radius: 10px;
        background: #ebf1fd;
        text-align: center;
        padding: 22px 20px;
    }
    .lot-box .lot-eyebrow {
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .1em;
        color: #1546b3;
        margin-bottom: 6px;
    }
    .lot-box .lot-code {
        font-size: 44px;
        font-weight: bold;
        color: #1a56db;
        letter-spacing: .06em;
        line-height: 1;
        margin-bottom: 6px;
    }
    .lot-box .lot-size {
        font-size: 11.5px;
        color: #344054;
        margin-bottom: 4px;
    }
    .lot-box .lot-location {
        font-size: 10px;
        color: #586477;
    }

    /* ── Section ── */
    .section { margin-bottom: 18px; padding: 0 24px; }
    .section-title {
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .07em;
        color: #1a56db;
        border-bottom: 1.5px solid #ebf1fd;
        padding-bottom: 4px;
        margin-bottom: 10px;
    }

    /* ── Info table ── */
    table.info { width: 100%; border-collapse: collapse; }
    table.info td { padding: 5px 8px; vertical-align: top; }
    table.info td:first-child { width: 42%; font-weight: bold; color: #344054; }
    table.info tr:nth-child(even) td { background: #f8fafc; }

    /* ── Badge ── */
    .badge {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 10px;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .badge-success { background: #e6f4ec; color: #0b6239; }

    /* ── Official use ── */
    .official-box {
        margin: 0 24px 20px;
        border: 1.5px dashed #c3ccd8;
        border-radius: 8px;
        padding: 12px 18px 14px;
    }
    .official-title { font-size: 9px; font-weight: bold; color: #586477; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px; }
    table.official { width: 100%; border-collapse: collapse; }
    table.official td { padding: 7px 0 3px; vertical-align: bottom; font-size: 10px; }
    table.official td.label { width: 110px; font-weight: bold; color: #344054; white-space: nowrap; }
    table.official td.colon { width: 12px; color: #344054; }
    /* A ruled line to write on; the printed branch sits on the same line. */
    table.official td.fill { border-bottom: 1px solid #586477; height: 16px; }
    .sign-label { font-size: 9px; color: #586477; text-transform: uppercase; letter-spacing: .06em; margin-top: 14px; margin-bottom: 30px; }
    .sign-line  { border-bottom: 1px solid #586477; width: 200px; }

    /* ── Footer ── */
    .footer {
        margin-top: 24px;
        padding: 12px 24px;
        border-top: 1px solid #dce1e8;
        font-size: 9px;
        color: #586477;
    }
    .footer .right { float: right; }

    .no-break { page-break-inside: avoid; }

    .qr-box { text-align: center; margin: 0 0 14px; }
    .qr-note { font-size: 8px; color: #586477; letter-spacing: 0.4px; text-transform: uppercase; margin-top: 3px; }
    .qr-code-text { font-family: monospace; font-size: 10px; color: #101828; letter-spacing: 1.5px; }
    /* Same footprint as the code it stands in for, so the slip does not reflow. */
    /* dompdf ignores line-height centring on a block, so the text is padded
       down to the middle instead: 42 + 12 + 42 = 96. */
    .qr-empty {
        width: 96px; height: 12px; padding: 42px 0; margin: 0 auto;
        border: 1px dashed #c3ccd8; color: #586477;
        font-size: 9px; text-align: center;
    }
</style>
</head>
<body>

{{-- ── Header ── --}}
{{-- A table, not a float: dompdf placed the floated reference above the page edge. --}}
<table class="header" style="width:100%;border-collapse:collapse;">
    <tr>
        <td>
            <div class="sub">Lot Draw Result Slip</div>
            <h1>{{ $event->title }}</h1>
        </td>
        <td class="ref" style="text-align:right;vertical-align:top;white-space:nowrap;">
            Ref: LOT-{{ str_pad($registration->id, 6, '0', STR_PAD_LEFT) }}
        </td>
    </tr>
</table>

<div class="success-banner">
    ✓ &nbsp;Lot Successfully Assigned
</div>

{{-- ── Lot Number ── --}}
<div class="lot-box no-break">
    <div class="lot-eyebrow">Your Assigned Lot Number</div>
    <div class="lot-code">{{ $assignedPlot->plot_code }}</div>
    @if($assignedPlot->plotSize)
        <div class="lot-size">
            Plot Size: {{ $assignedPlot->plotSize->size }}
            @if($assignedPlot->plotSize->location)
                &nbsp;·&nbsp; Location: {{ $assignedPlot->plotSize->location }}
            @endif
        </div>
    @endif
    {{-- The CODE (TBPS), with its sub-category as the admin draw page shows it.
         Not ->name: on a code row that is the craft CATEGORY (TEKSTIL), which
         printed "Craft code: TEKSTIL" above a table saying TBPS. --}}
    @if($assignedPlot->bidang)
        <div class="lot-location">Craft code: {{ $assignedPlot->bidang->code }}@if($sub = $assignedPlot->bidang->pecahan_bidang ?? null) ({{ $sub }})@endif</div>
    @endif
</div>

{{-- ── Verification QR ──
     Encodes an md5 of the participant's IC, so a scanner at the door resolves a
     slip to a person in one beep. The short code beneath is the head of the same
     hash, for when no scanner is to hand.

     The IC itself is printed unless SLIP_SHOWS_IC is false (on by default).
     When it is printed, the hash is a lookup key, not a mask: the
     Participant Information table prints the number a few centimetres down. --}}
<div class="qr-box no-break">
    @if($qrDataUri)
        <img src="{{ $qrDataUri }}" width="96" height="96" alt="">
        <div class="qr-note">Scan to verify holder</div>
        <div class="qr-code-text">{{ strtoupper(substr($icHash, 0, 8)) }}</div>
    @else
        {{-- ic_number is nullable, and the counter needs to know why the code is
             missing rather than wonder whether the slip printed wrong. --}}
        <div class="qr-empty">No scan code</div>
        <div class="qr-note">IC number not on file &mdash; verify this holder by hand</div>
    @endif
</div>

{{-- ── Participant Info ── --}}
<div class="section no-break">
    <div class="section-title">Participant Information</div>
    <table class="info">
        <tr>
            <td>Full Name</td>
            <td>{{ $user->name }}</td>
        </tr>
        {{-- Shown by default; SLIP_SHOWS_IC=false in .env hides it (config app.slip_shows_ic). --}}
        @if(config('app.slip_shows_ic'))
        <tr>
            <td>IC Number</td>
            <td>{{ $user->ic_number ?? '—' }}</td>
        </tr>
        @endif
        @if($registration->company)
        <tr>
            <td>Company Name</td>
            <td>{{ $registration->company->name }}</td>
        </tr>
        @if($registration->company->state)
        <tr>
            <td>Zone</td>
            <td>{{ $registration->company->state }}</td>
        </tr>
        @endif
        @endif
        @if($registration->code)
        <tr>
            <td>Craft Category Code</td>
            <td>{{ $registration->code }}</td>
        </tr>
        @endif
    </table>
</div>

{{-- ── Event & Lot Info ── --}}
<div class="section no-break">
    <div class="section-title">Event & Lot Details</div>
    <table class="info">
        <tr>
            <td>Event</td>
            <td>{{ $event->title }}</td>
        </tr>
        <tr>
            <td>Event Date</td>
            <td>{{ $event->start_date?->format('d F Y') }}@if($event->end_date && $event->end_date->ne($event->start_date)) – {{ $event->end_date->format('d F Y') }}@endif</td>
        </tr>
        <tr>
            <td>Location</td>
            <td>{{ $event->location }}</td>
        </tr>
        <tr>
            <td>Lot Confirmed</td>
            <td>{{ $poll->created_at->format('d F Y, h:i A') }}</td>
        </tr>
        <tr>
            <td>Participation Confirmed</td>
            <td>
                @if($registration->confirmed_at)
                    {{ $registration->confirmed_at->format('d F Y, h:i A') }}
                    &nbsp;<span class="badge badge-success">Confirmed</span>
                @else
                    —
                @endif
            </td>
        </tr>
    </table>
</div>

{{-- ── Official use ──
     Filled in BY HAND at the counter, by whoever checks this participant on the
     day. The slip is printed at home, often weeks before; the officer is not
     known then, so no name is printed — a pre-printed name would claim a check
     that person never made. State Branch is the one fact already known: the
     company's own zone, which tells the counter whose participant this is. --}}
<div class="official-box no-break">
    <div class="official-title">For Official Use</div>
    <table class="official">
        <tr>
            <td class="label">Officer Name</td>
            <td class="colon">:</td>
            <td class="fill"></td>
        </tr>
        <tr>
            <td class="label">State Branch</td>
            <td class="colon">:</td>
            <td class="fill">{{ $registration->company?->state }}</td>
        </tr>
        <tr>
            <td class="label">Date &amp; Time</td>
            <td class="colon">:</td>
            <td class="fill"></td>
        </tr>
    </table>
    <div class="sign-label">Signature &amp; Official Stamp</div>
    <div class="sign-line"></div>
</div>

{{-- ── Footer ── --}}
<div class="footer">
    <span class="right">Generated: {{ now()->format('d F Y, h:i A') }}</span>
    This slip is an official record of your lot draw result. Please retain it for reference during the event.
</div>

</body>
</html>
