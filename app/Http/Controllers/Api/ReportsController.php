<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Account, JournalLine, Invoice};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    /**
     * Trial Balance (Balance générale)
     * Params:
     * - from (YYYY-MM-DD) optional
     * - to (YYYY-MM-DD) optional
     * - as_of (YYYY-MM-DD) optional (si pas de from/to)
     * - include_zero (0/1)
     */
    public function trialBalance(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'as_of' => ['nullable','date'],
            'include_zero' => ['nullable','boolean'],
        ]);

        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        $asOf = $data['as_of'] ?? null;

        if ($from && !$to) $to = now()->toDateString();
        if (!$from && !$asOf) $asOf = now()->toDateString();

        // Base query: accounts LEFT JOIN journal_lines + journal_entries
        $q = Account::query()
            ->select([
                'accounts.id',
                'accounts.code',
                'accounts.name',
                'accounts.type',
                'accounts.normal_balance',
                DB::raw('COALESCE(SUM(CASE WHEN je.status="posted" THEN jl.debit ELSE 0 END),0) as total_debit_all'),
                DB::raw('COALESCE(SUM(CASE WHEN je.status="posted" THEN jl.credit ELSE 0 END),0) as total_credit_all'),
            ])
            ->leftJoin('journal_lines as jl', function ($join) {
                $join->on('jl.account_id', '=', 'accounts.id');
            })
            ->leftJoin('journal_entries as je', function ($join) use ($from, $to, $asOf) {
                $join->on('je.id', '=', 'jl.journal_entry_id');

                if ($from && $to) {
                    // période
                    $join->whereBetween('je.entry_date', [$from, $to]);
                } else {
                    // à date
                    $join->whereDate('je.entry_date', '<=', $asOf);
                }
            })
            ->groupBy('accounts.id','accounts.code','accounts.name','accounts.type','accounts.normal_balance')
            ->orderBy('accounts.code');

        $rows = $q->get()->map(function ($r) {
            $debit = (float)$r->total_debit_all;
            $credit = (float)$r->total_credit_all;

            // Solde selon normal_balance
            $balance = $r->normal_balance === 'debit'
                ? ($debit - $credit)
                : ($credit - $debit);

            return [
                'account_id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'balance' => round($balance, 2),
                'balance_side' => $balance >= 0 ? $r->normal_balance : ($r->normal_balance === 'debit' ? 'credit' : 'debit'),
            ];
        });

        if (!($data['include_zero'] ?? false)) {
            $rows = $rows->filter(fn($r) => !((float)$r['debit'] === 0.0 && (float)$r['credit'] === 0.0));
        }

        $totalDebit = round($rows->sum('debit'), 2);
        $totalCredit = round($rows->sum('credit'), 2);

        return response()->json([
            'range' => $from ? ['from'=>$from,'to'=>$to] : ['as_of'=>$asOf],
            'totals' => ['debit'=>$totalDebit,'credit'=>$totalCredit, 'balanced'=>abs($totalDebit-$totalCredit) < 0.01],
            'rows' => $rows->values(),
        ]);
    }

    /**
     * General Ledger (Grand livre) par compte
     * Params:
     * - account_id (required)
     * - from, to optional
     */
    public function generalLedger(Request $request)
    {
        $data = $request->validate([
            'account_id' => ['required','integer','exists:accounts,id'],
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
        ]);

        $account = Account::findOrFail($data['account_id']);
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;

        $linesQ = JournalLine::query()
            ->select([
                'jl.id',
                'je.entry_date',
                'je.action',
                'je.memo',
                'je.source_type',
                'je.source_id',
                'jl.description',
                'jl.debit',
                'jl.credit',
                'jl.customer_id',
            ])
            ->from('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $account->id)
            ->where('je.status', 'posted')
            ->orderBy('je.entry_date')
            ->orderBy('jl.id');

        if ($from) $linesQ->whereDate('je.entry_date', '>=', $from);
        if ($to) $linesQ->whereDate('je.entry_date', '<=', $to);

        $lines = $linesQ->get();

        // running balance
        $running = 0.0;
        $out = $lines->map(function ($l) use ($account, &$running) {
            $debit = (float)$l->debit;
            $credit = (float)$l->credit;

            $delta = $account->normal_balance === 'debit'
                ? ($debit - $credit)
                : ($credit - $debit);

            $running += $delta;

            return [
                'date' => (string)$l->entry_date,
                'action' => $l->action,
                'memo' => $l->memo,
                'description' => $l->description,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'running_balance' => round($running, 2),
                'source' => ['type'=>$l->source_type, 'id'=>$l->source_id],
                'customer_id' => $l->customer_id,
            ];
        });

        return response()->json([
            'account' => [
                'id'=>$account->id,'code'=>$account->code,'name'=>$account->name,
                'type'=>$account->type,'normal_balance'=>$account->normal_balance
            ],
            'range' => ['from'=>$from,'to'=>$to],
            'rows' => $out,
        ]);
    }

    /**
     * Profit & Loss (Compte de résultat)
     * Params: from, to (required for P&L)
     */
    public function profitAndLoss(Request $request)
    {
        $data = $request->validate([
            'from' => ['required','date'],
            'to' => ['required','date'],
        ]);

        $from = $data['from'];
        $to = $data['to'];

        // Sum par account sur période
        $rows = Account::query()
            ->select([
                'accounts.id','accounts.code','accounts.name','accounts.type','accounts.normal_balance',
                DB::raw('COALESCE(SUM(jl.debit),0) as debit'),
                DB::raw('COALESCE(SUM(jl.credit),0) as credit'),
            ])
            ->join('journal_lines as jl','jl.account_id','=','accounts.id')
            ->join('journal_entries as je','je.id','=','jl.journal_entry_id')
            ->where('je.status','posted')
            ->whereBetween('je.entry_date', [$from,$to])
            ->whereIn('accounts.type',['income','expense'])
            ->groupBy('accounts.id','accounts.code','accounts.name','accounts.type','accounts.normal_balance')
            ->orderBy('accounts.code')
            ->get()
            ->map(function($r){
                $debit = (float)$r->debit;
                $credit = (float)$r->credit;

                // Convention P&L:
                // income = credit - debit
                // expense = debit - credit
                $amount = $r->type === 'income'
                    ? ($credit - $debit)
                    : ($debit - $credit);

                return [
                    'account_id'=>$r->id,
                    'code'=>$r->code,
                    'name'=>$r->name,
                    'type'=>$r->type,
                    'amount'=>round($amount,2),
                ];
            });

        $income = $rows->where('type','income')->values();
        $expenses = $rows->where('type','expense')->values();

        $totalIncome = round($income->sum('amount'),2);
        $totalExpense = round($expenses->sum('amount'),2);
        $net = round($totalIncome - $totalExpense,2);

        return response()->json([
            'range' => ['from'=>$from,'to'=>$to],
            'income' => $income,
            'expenses' => $expenses,
            'totals' => [
                'total_income'=>$totalIncome,
                'total_expenses'=>$totalExpense,
                'net_profit'=>$net,
            ],
        ]);
    }

    /**
     * Balance Sheet (Bilan) à une date
     * Params: as_of (optional)
     */
    public function balanceSheet(Request $request)
    {
        $data = $request->validate([
            'as_of' => ['nullable','date'],
        ]);

        $asOf = $data['as_of'] ?? now()->toDateString();

        $rows = Account::query()
            ->select([
                'accounts.id','accounts.code','accounts.name','accounts.type','accounts.normal_balance',
                DB::raw('COALESCE(SUM(jl.debit),0) as debit'),
                DB::raw('COALESCE(SUM(jl.credit),0) as credit'),
            ])
            ->leftJoin('journal_lines as jl','jl.account_id','=','accounts.id')
            ->leftJoin('journal_entries as je', function($join) use ($asOf){
                $join->on('je.id','=','jl.journal_entry_id')
                     ->where('je.status','posted')
                     ->whereDate('je.entry_date','<=',$asOf);
            })
            ->whereIn('accounts.type',['asset','liability','equity'])
            ->groupBy('accounts.id','accounts.code','accounts.name','accounts.type','accounts.normal_balance')
            ->orderBy('accounts.code')
            ->get()
            ->map(function($r){
                $debit = (float)$r->debit;
                $credit = (float)$r->credit;

                $balance = $r->normal_balance === 'debit'
                    ? ($debit - $credit)
                    : ($credit - $debit);

                return [
                    'account_id'=>$r->id,
                    'code'=>$r->code,
                    'name'=>$r->name,
                    'type'=>$r->type,
                    'balance'=>round($balance,2),
                ];
            })
            ->filter(fn($r) => abs((float)$r['balance']) > 0.00001)
            ->values();

        $assets = $rows->where('type','asset')->values();
        $liab = $rows->where('type','liability')->values();
        $equity = $rows->where('type','equity')->values();

        $totalAssets = round($assets->sum('balance'),2);
        $totalLiab = round($liab->sum('balance'),2);
        $totalEquity = round($equity->sum('balance'),2);

        return response()->json([
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liab,
            'equity' => $equity,
            'totals' => [
                'assets'=>$totalAssets,
                'liabilities'=>$totalLiab,
                'equity'=>$totalEquity,
                'balanced'=>abs($totalAssets - ($totalLiab + $totalEquity)) < 0.05,
            ],
        ]);
    }

    /**
     * A/R Summary (solde clients) base sur les factures ouvertes.
     */
    public function arSummary(Request $request)
    {
        $data = $request->validate([
            'as_of' => ['nullable','date'],
        ]);
        $asOf = $data['as_of'] ?? now()->toDateString();

        $rows = $this->openReceivableInvoicesAsOf($asOf)
            ->get(['id', 'customer_id', 'balance_due'])
            ->groupBy(fn ($invoice) => (string) ($invoice->customer_id ?? 0))
            ->map(function ($group) {
                $first = $group->first();
                $balance = round((float) $group->sum(fn ($invoice) => (float) $invoice->balance_due), 2);

                return [
                    'customer_id' => $first?->customer_id,
                    'name' => $first?->customer?->name ?? 'Client comptoir',
                    'ar_balance' => $balance,
                ];
            })
            ->filter(fn ($row) => abs((float) $row['ar_balance']) > 0.00001)
            ->sortByDesc('ar_balance')
            ->values();

        return response()->json([
            'as_of' => $asOf,
            'rows' => $rows,
            'total_ar' => round($rows->sum('ar_balance'), 2),
        ]);
    }

    /**
     * A/R Aging (retards) basé sur invoices.balance_due + due_date
     * Buckets: current, 1-30, 31-60, 61-90, 90+
     */
    public function arAging(Request $request)
    {
        $data = $request->validate([
            'as_of' => ['nullable','date'],
        ]);
        $asOf = $data['as_of'] ?? now()->toDateString();

        // Factures ouvertes a date.
        $invoices = $this->openReceivableInvoicesAsOf($asOf)
            ->get(['id','customer_id','number','due_date','issue_date','total','amount_paid','balance_due']);

        $buckets = [
            'current' => [],
            '1_30' => [],
            '31_60' => [],
            '61_90' => [],
            '90_plus' => [],
        ];

        $as = \Carbon\Carbon::parse($asOf);

        foreach ($invoices as $inv) {
            $due = $inv->due_date ? \Carbon\Carbon::parse($inv->due_date) : $as;
            $days = $due->diffInDays($as, false); // positif si en retard

            $row = [
                'invoice_id'=>$inv->id,
                'number'=>$inv->number,
                'customer_id'=>$inv->customer_id,
                'customer'=>$inv->customer?->name,
                'due_date'=>$inv->due_date,
                'balance_due'=>(float)$inv->balance_due,
                'days_past_due'=>$days,
            ];

            if ($days <= 0) $buckets['current'][] = $row;
            elseif ($days <= 30) $buckets['1_30'][] = $row;
            elseif ($days <= 60) $buckets['31_60'][] = $row;
            elseif ($days <= 90) $buckets['61_90'][] = $row;
            else $buckets['90_plus'][] = $row;
        }

        $sumBucket = fn($arr) => round(array_sum(array_map(fn($r) => (float)$r['balance_due'], $arr)),2);

        return response()->json([
            'as_of'=>$asOf,
            'totals'=>[
                'current'=>$sumBucket($buckets['current']),
                '1_30'=>$sumBucket($buckets['1_30']),
                '31_60'=>$sumBucket($buckets['31_60']),
                '61_90'=>$sumBucket($buckets['61_90']),
                '90_plus'=>$sumBucket($buckets['90_plus']),
            ],
            'details'=>$buckets,
        ]);
    }

    private function openReceivableInvoicesAsOf(string $asOf)
    {
        return Invoice::query()
            ->with('customer:id,name')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance_due', '>', 0)
            ->where(function ($q) use ($asOf) {
                $q->whereDate('issue_date', '<=', $asOf)
                    ->orWhere(function ($sub) use ($asOf) {
                        $sub->whereNull('issue_date')
                            ->whereDate('created_at', '<=', $asOf);
                    });
            });
    }
}

