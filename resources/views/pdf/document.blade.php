<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color:#111; }
        .row { width:100%; }
        .col { display:inline-block; vertical-align:top; }
        .left { width:60%; }
        .right { width:39%; text-align:right; }
        h1 { font-size:18px; margin:0; }
        .muted { color:#666; }
        .box { border:1px solid #ddd; padding:10px; border-radius:6px; }
        table { width:100%; border-collapse:collapse; margin-top:12px; }
        th, td { border:1px solid #ddd; padding:6px; }
        th { background:#f5f5f5; text-align:left; }
        .text-right { text-align:right; }
        .no-border td { border:none; }
        .totals td { border:none; }
        .totals .label { text-align:right; }
        .totals .value { text-align:right; width:120px; }
        .footer { margin-top:18px; font-size:10px; color:#444; }
    </style>
</head>
<body>
@php
    $typeLabel = $document->type === 'quote' ? 'DEVIS' : 'FACTURE PROFORMA';
    $biz = $business;
@endphp

<div class="row">
    <div class="col left">
        <h1>{{ $typeLabel }}</h1>
        <div class="muted">N° {{ $document->number }}</div>
        <div class="muted">Date: {{ optional($document->issue_date)->format('Y-m-d') ?? '-' }}</div>
        @if($document->expiry_date)
            <div class="muted">Validité: {{ $document->expiry_date->format('Y-m-d') }}</div>
        @endif
    </div>
    <div class="col right">
        <div style="font-weight:bold;">{{ $biz->legal_name ?? $biz->name }}</div>
        @if($biz->address)
            <div class="muted">
                {{ $biz->address['line1'] ?? '' }}<br>
                {{ $biz->address['line2'] ?? '' }}<br>
                {{ $biz->address['city'] ?? '' }} {{ $biz->address['zip'] ?? '' }}<br>
                {{ $biz->address['country'] ?? '' }}
            </div>
        @endif
        @if($biz->phone)<div class="muted">Tél: {{ $biz->phone }}</div>@endif
        @if($biz->email)<div class="muted">{{ $biz->email }}</div>@endif
        @if($biz->tax_number)<div class="muted">NIF/TVA: {{ $biz->tax_number }}</div>@endif
    </div>
</div>

<div style="margin-top:12px;" class="box">
    <div style="font-weight:bold;">Client</div>
    <div>{{ $document->customer->name ?? 'Client comptoir' }}</div>
    @if($document->customer && $document->customer->company_name)
        <div class="muted">{{ $document->customer->company_name }}</div>
    @endif
    @if($document->customer && $document->customer->email)
        <div class="muted">{{ $document->customer->email }}</div>
    @endif
    @if($document->reference)
        <div class="muted">Référence: {{ $document->reference }}</div>
    @endif
</div>

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Article</th>
        <th class="text-right">Qté</th>
        <th class="text-right">PU</th>
        <th class="text-right">Taxe</th>
        <th class="text-right">Total</th>
    </tr>
    </thead>
    <tbody>
    @foreach($document->items->sortBy('sort_order') as $i => $it)
        <tr>
            <td>{{ $i+1 }}</td>
            <td>
                <div style="font-weight:bold;">{{ $it->name }}</div>
                @if($it->description)<div class="muted">{{ $it->description }}</div>@endif
                @if($it->sku)<div class="muted">SKU: {{ $it->sku }}</div>@endif
            </td>
            <td class="text-right">{{ rtrim(rtrim(number_format((float)$it->quantity, 3, '.', ''), '0'), '.') }}</td>
            <td class="text-right">{{ number_format((float)$it->unit_price, 2) }} {{ $document->currency }}</td>
            <td class="text-right">{{ number_format((float)$it->tax_amount, 2) }}</td>
            <td class="text-right">{{ number_format((float)$it->line_total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" style="margin-top:10px;">
    <tr>
        <td class="label">Sous-total :</td>
        <td class="value">{{ number_format((float)$document->subtotal, 2) }} {{ $document->currency }}</td>
    </tr>
    <tr>
        <td class="label">Taxes :</td>
        <td class="value">{{ number_format((float)$document->tax_total, 2) }}</td>
    </tr>
    <tr>
        <td class="label">Livraison :</td>
        <td class="value">{{ number_format((float)$document->shipping_cost, 2) }}</td>
    </tr>
    @if((float)$document->discount_amount > 0)
    <tr>
        <td class="label">Remise :</td>
        <td class="value">- {{ number_format((float)$document->discount_amount, 2) }}</td>
    </tr>
    @endif
    <tr>
        <td class="label" style="font-weight:bold; font-size:12px;">Total :</td>
        <td class="value" style="font-weight:bold; font-size:12px;">{{ number_format((float)$document->total, 2) }} {{ $document->currency }}</td>
    </tr>
</table>

@if($document->notes)
    <div class="footer"><b>Note :</b> {!! nl2br(e($document->notes)) !!}</div>
@endif
@if($document->terms)
    <div class="footer"><b>Conditions :</b> {!! nl2br(e($document->terms)) !!}</div>
@endif
@if($biz->invoice_footer)
    <div class="footer">{!! nl2br(e($biz->invoice_footer)) !!}</div>
@endif

</body>
</html>
