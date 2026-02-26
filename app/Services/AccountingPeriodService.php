<?php
namespace App\Services;

use App\Models\AccountingPeriod;
use Carbon\Carbon;

class AccountingPeriodService
{
  public function isDateClosed(string $date): bool
  {
    $d = Carbon::parse($date)->toDateString();

    return AccountingPeriod::where('status','closed')
      ->whereDate('start_date','<=',$d)
      ->whereDate('end_date','>=',$d)
      ->exists();
  }

  public function assertDateOpen(string $date, string $message = 'Accounting period is closed.'): void
  {
    if ($this->isDateClosed($date)) {
      abort(422, $message);
    }
  }

  public function assertRangeValid(string $start, string $end): void
  {
    $s = Carbon::parse($start)->toDateString();
    $e = Carbon::parse($end)->toDateString();
    if ($s > $e) abort(422, 'Invalid period range (start_date > end_date).');

    // éviter chevauchement
    $overlap = AccountingPeriod::where(function($q) use ($s,$e){
      $q->whereBetween('start_date', [$s,$e])
        ->orWhereBetween('end_date', [$s,$e])
        ->orWhere(function($qq) use ($s,$e){
          $qq->whereDate('start_date','<=',$s)->whereDate('end_date','>=',$e);
        });
    })->exists();

    if ($overlap) abort(422, 'Period overlaps an existing period.');
  }
}
