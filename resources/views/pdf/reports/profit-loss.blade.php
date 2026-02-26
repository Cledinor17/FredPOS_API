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
  <h1>Profit & Loss</h1>
  <div class="muted">{{ $business->legal_name ?? $business->name }}</div>
  <div class="muted" style="margin-top:4px;">
    From: {{ $data['range']['from'] }} — To: {{ $data['range']['to'] }}
  </div>

  <h3 style="margin-top:12px;">Income</h3>
  <table>
    <thead>
      <tr><th>Code</th><th>Account</th><th class="tr">Amount</th></tr>
    </thead>
    <tbody>
      @foreach($data['income'] as $r)
        <tr>
          <td>{{ $r['code'] }}</td>
          <td>{{ $r['name'] }}</td>
          <td class="tr">{{ number_format((float)$r['amount'], 2) }}</td>
        </tr>
      @endforeach
      <tr>
        <td colspan="2"><b>Total Income</b></td>
        <td class="tr"><b>{{ number_format((float)$data['totals']['total_income'], 2) }}</b></td>
      </tr>
    </tbody>
  </table>

  <h3 style="margin-top:12px;">Expenses</h3>
  <table>
    <thead>
      <tr><th>Code</th><th>Account</th><th class="tr">Amount</th></tr>
    </thead>
    <tbody>
      @foreach($data['expenses'] as $r)
        <tr>
          <td>{{ $r['code'] }}</td>
          <td>{{ $r['name'] }}</td>
          <td class="tr">{{ number_format((float)$r['amount'], 2) }}</td>
        </tr>
      @endforeach
      <tr>
        <td colspan="2"><b>Total Expenses</b></td>
        <td class="tr"><b>{{ number_format((float)$data['totals']['total_expenses'], 2) }}</b></td>
      </tr>
    </tbody>
  </table>

  <div style="margin-top:12px;">
    <b>Net Profit:</b> {{ number_format((float)$data['totals']['net_profit'], 2) }}
  </div>
</body>
</html>
