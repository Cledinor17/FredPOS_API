<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color:#111; }
        h1 { font-size:18px; margin:0; }
        .muted { color:#666; }
        table { width:100%; border-collapse:collapse; margin-top:12px; }
        th, td { border:1px solid #ddd; padding:6px; }
        th { background:#f5f5f5; text-align:left; }
        .text-right { text-align:right; }
        .totals td { border:none; }
        .label { text-align:right; }
        .value { text-align:right; width:140px; }
    </style>
</head>
<body>
@php $biz = $business; @endphp

<h1>FACTURE</h1>
<div class="muted">N° {{ $invoice->number }}</div>
<div class="muted">Date: {{ optional($invoice->issue_date)->format('Y-m-d') ?? '-' }}</div>
<div class="muted">Échéance: {{ optional($invoice->due_date)->format('Y-m-d') ?? '-' }}</div>

<hr>

<div style="margin-top:8px;">
    <b>{{ $biz->legal_name ?? $biz->name }}</b>
    @if($biz->tax_number) <span class="muted"> — NIF/TVA: {{ $biz->tax_number }}</span>@endif
</div>

<div style="margin-top:8px;">
    <b>Client:</b> {{ $invoice->customer->name ?? 'Client comptoir' }}
    @if($invoice->reference) <span class="muted"> — Réf: {{ $invoice->reference }}</span>@endif
</div>

<table>
    <thead>
    <tr>
        <th>#</th><th>Article</th>
        <th class="text-right">Qté</th>
        <th class="text-right">PU</th>
        <th class="text-right">Taxe</th>
        <th class="text-right">Total</th>
    </tr>
    </thead>
    <tbody>
    @foreach($invoice->items->sortBy('sort_order') as $i => $it)
        <tr>
            <td>{{ $i+1 }}</td>
            <td>{{ $it->name }}</td>
            <td class="text-right">{{ rtrim(rtrim(number_format((float)$it->quantity, 3, '.', ''), '0'), '.') }}</td>
            <td class="text-right">{{ number_format((float)$it->unit_price, 2) }} {{ $invoice->currency }}</td>
            <td class="text-right">{{ number_format((float)$it->tax_amount, 2) }}</td>
            <td class="text-right">{{ number_format((float)$it->line_total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" style="margin-top:10px;">
    <tr><td class="label">Sous-total :</td><td class="value">{{ number_format((float)$invoice->subtotal, 2) }} {{ $invoice->currency }}</td></tr>
    <tr><td class="label">Taxes :</td><td class="value">{{ number_format((float)$invoice->tax_total, 2) }}</td></tr>
    <tr><td class="label">Livraison :</td><td class="value">{{ number_format((float)$invoice->shipping_cost, 2) }}</td></tr>
    @if((float)$invoice->discount_amount > 0)
        <tr><td class="label">Remise :</td><td class="value">- {{ number_format((float)$invoice->discount_amount, 2) }}</td></tr>
    @endif
    <tr><td class="label" style="font-weight:bold;">Total :</td><td class="value" style="font-weight:bold;">{{ number_format((float)$invoice->total, 2) }} {{ $invoice->currency }}</td></tr>
    <tr><td class="label">Payé :</td><td class="value">{{ number_format((float)$invoice->amount_paid, 2) }}</td></tr>
    <tr><td class="label" style="font-weight:bold;">Solde :</td><td class="value" style="font-weight:bold;">{{ number_format((float)$invoice->balance_due, 2) }}</td></tr>
</table>

@if($invoice->notes)
    <div style="margin-top:14px;"><b>Note :</b> {!! nl2br(e($invoice->notes)) !!}</div>
@endif
@if($biz->invoice_footer)
    <div style="margin-top:14px; font-size:10px; color:#444;">{!! nl2br(e($biz->invoice_footer)) !!}</div>
@endif

</body>
</html>
