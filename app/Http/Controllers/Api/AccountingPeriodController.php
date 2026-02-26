<?php
// app/Http/Controllers/Api/AccountingPeriodController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Services\AccountingPeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingPeriodController extends Controller
{
  public function index()
  {
    return AccountingPeriod::orderByDesc('start_date')->paginate(20);
  }

  public function store(Request $request, AccountingPeriodService $svc)
  {
    $data = $request->validate([
      'name' => ['nullable','string','max:50'],
      'start_date' => ['required','date'],
      'end_date' => ['required','date'],
      'notes' => ['nullable','string'],
    ]);

    $svc->assertRangeValid($data['start_date'], $data['end_date']);

    return AccountingPeriod::create([
      'name' => $data['name'] ?? null,
      'start_date' => $data['start_date'],
      'end_date' => $data['end_date'],
      'status' => 'open',
      'notes' => $data['notes'] ?? null,
    ]);
  }

  public function close(Request $request, string $business, string $period)
  {
    $user = $request->user();
    $periodModel = $this->resolvePeriodOrFail($period);

    $before = app(\App\Services\AuditService::class)->snapshot($periodModel);

    if ($periodModel->status === 'closed') {
      return $periodModel;
    }

    // Verif rapide: debits == credits sur la periode (sanity check)
    $totals = DB::table('journal_lines as jl')
      ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
      ->where('je.status', 'posted')
      ->whereBetween('je.entry_date', [$periodModel->start_date->toDateString(), $periodModel->end_date->toDateString()])
      ->selectRaw('ROUND(SUM(jl.debit),2) as d, ROUND(SUM(jl.credit),2) as c')
      ->first();

    $d = (float) ($totals->d ?? 0);
    $c = (float) ($totals->c ?? 0);
    if (abs($d - $c) > 0.01) {
      abort(422, 'Cannot close: journal not balanced.');
    }

    $periodModel->update([
      'status' => 'closed',
      'closed_at' => now(),
      'closed_by' => $user->id,
      'reopened_at' => null,
      'reopened_by' => null,
    ]);

    $after = app(\App\Services\AuditService::class)->snapshot($periodModel);

    app(\App\Services\AuditService::class)->log(
      'period.closed',
      $periodModel,
      $before,
      $after,
      ['range' => [$periodModel->start_date->toDateString(), $periodModel->end_date->toDateString()]]
    );

    return $periodModel;
  }

  public function reopen(Request $request, string $business, string $period)
  {
    $user = $request->user();
    $periodModel = $this->resolvePeriodOrFail($period);

    if ($periodModel->status === 'open') {
      return $periodModel;
    }

    $periodModel->update([
      'status' => 'open',
      'reopened_at' => now(),
      'reopened_by' => $user->id,
    ]);

    return $periodModel;
  }

  private function resolvePeriodOrFail(string $period): AccountingPeriod
  {
    $businessId = (int) app('currentBusiness')->id;

    return AccountingPeriod::query()
      ->where('business_id', $businessId)
      ->whereKey($period)
      ->firstOrFail();
  }
}
