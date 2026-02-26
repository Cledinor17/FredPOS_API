<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Audit Logs</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
    h1 { margin: 0 0 6px; font-size: 16px; }
    .muted { color: #6b7280; font-size: 10px; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
    th { background: #f3f4f6; text-align: left; font-weight: 700; }
    .small { font-size: 10px; color: #6b7280; }
  </style>
</head>
<body>
  <h1>Journal d'audit</h1>
  <div class="muted">
    {{ $business?->name ?? 'Business' }} | Généré le {{ now()->format('Y-m-d H:i:s') }}
  </div>

  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Action</th>
        <th>Entité</th>
        <th>Utilisateur</th>
        <th>Group ID</th>
        <th>IP</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $row)
        <tr>
          <td>{{ $row->occurred_at ? (string) $row->occurred_at : '-' }}</td>
          <td>{{ $row->action ?? '-' }}</td>
          <td>
            {{ $row->entity_type ?? '-' }}@if($row->entity_id) #{{ $row->entity_id }}@endif
            @if($row->metadata)
              <div class="small">{{ json_encode($row->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</div>
            @endif
          </td>
          <td>
            {{ $row->user?->name ?? '-' }}
            @if($row->user?->email)
              <div class="small">{{ $row->user->email }}</div>
            @endif
            @if($row->user_id)
              <div class="small">#{{ $row->user_id }}</div>
            @endif
          </td>
          <td>{{ $row->group_id ?? '-' }}</td>
          <td>{{ $row->ip ?? '-' }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="6">Aucun log.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
</body>
</html>
