<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color:#111; }
    h1 { margin:0; font-size:16px; }
    .muted { color:#666; }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th, td { border:1px solid #ddd; padding:6px; }
    th { background:#f5f5f5; text-align:left; }
    .tr { text-align:right; }
  </style>
</head>
<body>
  <h1>Trial Balance</h1>
  <div class="muted">{{ $business->legal_name ?? $business->name }}</div>

  <div class="muted" style="margin-top:4px;">
    @if(isset($data['range']['as_of']))
      As of: {{ $data['range']['as_of'] }}
    @else
      From: {{ $data['range']['from'] }} — To: {{ $data['range']['to'] }}
    @endif
  </div>

  <table>
    <thead>
      <tr>
        <th>Code</th>
        <th>Account</th>
        <th>Type</th>
        <th class="tr">Debit</th>
        <th class="tr">Credit</th>
        <th class="tr">Balance</th>
      </tr>
    </thead>
    <tbody>
      @foreach($data['rows'] as $r)
        <tr>
          <td>{{ $r['code'] }}</td>
          <td>{{ $r['name'] }}</td>
          <td>{{ $r['type'] }}</td>
          <td class="tr">{{ number_format((float)$r['debit'], 2) }}</td>
          <td class="tr">{{ number_format((float)$r['credit'], 2) }}</td>
          <td class="tr">{{ number_format((float)$r['balance'], 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div style="margin-top:10px;">
    <b>Total Debit:</b> {{ number_format((float)$data['totals']['debit'], 2) }}
    &nbsp;&nbsp; <b>Total Credit:</b> {{ number_format((float)$data['totals']['credit'], 2) }}
  </div>
</body>
</html>
