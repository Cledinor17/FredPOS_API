<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
  public function index(Request $request)
  {
    $filters = $this->validatedFilters($request);
    $q = $this->queryWithFilters($filters);

    return $q->orderByDesc('occurred_at')->paginate(30);
  }

  public function exportCsv(Request $request): StreamedResponse
  {
    $filters = $this->validatedFilters($request);
    $rows = $this->queryWithFilters($filters)
      ->orderByDesc('occurred_at')
      ->get();

    $filename = 'audit_logs_' . now()->format('Ymd_His') . '.csv';

    return response()->streamDownload(function () use ($rows) {
      $out = fopen('php://output', 'w');
      fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

      fputcsv($out, [
        'Date',
        'Action',
        'Entity',
        'User',
        'User Email',
        'User ID',
        'Group ID',
        'IP',
        'User Agent',
        'Metadata',
      ]);

      foreach ($rows as $row) {
        $entity = trim((string) (($row->entity_type ?? '') . ($row->entity_id ? ' #' . $row->entity_id : '')));
        $occurredAt = $row->occurred_at ? (string) $row->occurred_at : '';
        $metadata = $row->metadata ? json_encode($row->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        fputcsv($out, [
          $occurredAt,
          (string) ($row->action ?? ''),
          $entity,
          (string) ($row->user?->name ?? ''),
          (string) ($row->user?->email ?? ''),
          $row->user_id ? (string) $row->user_id : '',
          (string) ($row->group_id ?? ''),
          (string) ($row->ip ?? ''),
          (string) ($row->user_agent ?? ''),
          is_string($metadata) ? $metadata : '',
        ]);
      }

      fclose($out);
    }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
  }

  public function exportPdf(Request $request)
  {
    $filters = $this->validatedFilters($request);
    $rows = $this->queryWithFilters($filters)
      ->orderByDesc('occurred_at')
      ->get();

    $business = app()->bound('currentBusiness') ? app('currentBusiness') : null;

    $pdf = Pdf::loadView('pdf.reports.audit-logs', [
      'business' => $business,
      'rows' => $rows,
    ])->setPaper('a4', 'landscape');

    return $pdf->stream('audit_logs.pdf');
  }

  private function queryWithFilters(array $filters): Builder
  {
    $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
    if (!$currentBusiness) {
      abort(403, 'Business context is required.');
    }

    $q = AuditLog::query()
      ->where('business_id', (int) $currentBusiness->id)
      ->with('user:id,name,email');

    foreach (['action', 'entity_type', 'entity_id', 'user_id', 'group_id'] as $field) {
      if (!empty($filters[$field])) {
        $q->where($field, $filters[$field]);
      }
    }

    if (!empty($filters['from'])) {
      $q->whereDate('occurred_at', '>=', $filters['from']);
    }
    if (!empty($filters['to'])) {
      $q->whereDate('occurred_at', '<=', $filters['to']);
    }

    return $q;
  }

  private function validatedFilters(Request $request): array
  {
    return $request->validate([
      'action' => ['nullable', 'string'],
      'entity_type' => ['nullable', 'string'],
      'entity_id' => ['nullable', 'integer'],
      'user_id' => ['nullable', 'integer'],
      'from' => ['nullable', 'date'],
      'to' => ['nullable', 'date'],
      'group_id' => ['nullable', 'uuid'],
    ]);
  }
}
