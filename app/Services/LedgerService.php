<?php
namespace App\Services;

use App\Models\{AccountMapping, JournalEntry, Invoice, InvoicePayment};
use Illuminate\Support\Facades\DB;


use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LedgerService
{
  private function accountId(string $key): int
  {
    $business = app()->bound('currentBusiness') ? app('currentBusiness') : null;
    if (!$business) {
      abort(500, 'Business context is required for ledger posting.');
    }

    $m = AccountMapping::withoutGlobalScopes()
      ->where('business_id', $business->id)
      ->where('key', $key)
      ->first();

    if (!$m) {
      app(\App\Services\AccountingSetupService::class)->setupForBusiness($business);

      $m = AccountMapping::withoutGlobalScopes()
        ->where('business_id', $business->id)
        ->where('key', $key)
        ->first();
    }

    if (!$m) abort(500, "Missing account mapping: {$key}");

    return (int) $m->account_id;
  }

  public function postInvoiceIssued(Invoice $invoice): JournalEntry
  {
    return DB::transaction(function () use ($invoice) {

      // Idempotent
      $existing = JournalEntry::where('action','invoice_issued')
        ->where('source_type','Invoice')->where('source_id',$invoice->id)
        ->first();
      if ($existing) return $existing->load('lines');

      $ar = $this->accountId('AR');
      $sales = $this->accountId('SALES');
      $tax = $this->accountId('TAX_PAYABLE');
      $ship = $this->accountId('SHIPPING_INCOME');

      $lines = [];
      $lineNo = 1;

      // DR Accounts Receivable = total
      $lines[] = [
        'account_id'=>$ar, 'line_no'=>$lineNo++,
        'description'=>"Invoice {$invoice->number} issued",
        'debit'=>(float)$invoice->total, 'credit'=>0,
        'customer_id'=>$invoice->customer_id
      ];

      // CR Sales = subtotal
      if ((float)$invoice->subtotal > 0) {
        $lines[] = [
          'account_id'=>$sales, 'line_no'=>$lineNo++,
          'description'=>"Sales - {$invoice->number}",
          'debit'=>0, 'credit'=>(float)$invoice->subtotal,
          'customer_id'=>$invoice->customer_id
        ];
      }

      // CR Tax payable = tax_total
      if ((float)$invoice->tax_total > 0) {
        $lines[] = [
          'account_id'=>$tax, 'line_no'=>$lineNo++,
          'description'=>"Tax - {$invoice->number}",
          'debit'=>0, 'credit'=>(float)$invoice->tax_total,
          'customer_id'=>$invoice->customer_id
        ];
      }

      // CR Shipping income = shipping_cost
      if ((float)$invoice->shipping_cost > 0) {
        $lines[] = [
          'account_id'=>$ship, 'line_no'=>$lineNo++,
          'description'=>"Shipping - {$invoice->number}",
          'debit'=>0, 'credit'=>(float)$invoice->shipping_cost,
          'customer_id'=>$invoice->customer_id
        ];
      }

      $totalDebit = array_sum(array_column($lines,'debit'));
      $totalCredit = array_sum(array_column($lines,'credit'));

      if (round($totalDebit - $totalCredit, 2) !== 0.0) {
        abort(500, "Unbalanced journal entry for invoice {$invoice->id}");
      }
      $entryDate = ($invoice->issue_date ?? now()->toDateString());
      app(\App\Services\AccountingPeriodService::class)
        ->assertDateOpen($entryDate, "Period closed: cannot post ledger on {$entryDate}");


      $entry = JournalEntry::create([
        'entry_date' => $entryDate,
        'action' => 'invoice_issued',
        'status' => 'posted',
        'memo' => "Invoice {$invoice->number} issued",
        'source_type' => 'Invoice',
        'source_id' => $invoice->id,
        'currency' => $invoice->currency,
        'exchange_rate' => $invoice->exchange_rate,
        'total_debit' => $totalDebit,
        'total_credit' => $totalCredit,
        'posted_by' => auth()->id(),
      ]);

      app(\App\Services\AuditService::class)->log(
  'ledger.posted',
  $entry,
  null,
  ['id' => $entry->id, 'action' => $entry->action, 'total_debit' => (float)$entry->total_debit, 'total_credit' => (float)$entry->total_credit],
  ['source_type' => $entry->source_type, 'source_id' => $entry->source_id]
);


      $entry->lines()->createMany($lines);

      return $entry->load('lines');
    });
  }

  public function postInvoicePayment(Invoice $invoice, InvoicePayment $payment): JournalEntry
  {
    return DB::transaction(function () use ($invoice, $payment) {

      $existing = JournalEntry::where('action','invoice_payment')
        ->where('source_type','InvoicePayment')->where('source_id',$payment->id)
        ->first();
      if ($existing) return $existing->load('lines');

      $cash = $this->paymentAccountId((string) ($payment->method ?? 'cash'));
      $ar = $this->accountId('AR');

      $amount = (float)$payment->amount;

      $lines = [
        [
          'account_id'=>$cash, 'line_no'=>1,
          'description'=>"Payment {$payment->id} for {$invoice->number}",
          'debit'=>$amount, 'credit'=>0,
          'customer_id'=>$invoice->customer_id
        ],
        [
          'account_id'=>$ar, 'line_no'=>2,
          'description'=>"A/R settlement {$invoice->number}",
          'debit'=>0, 'credit'=>$amount,
          'customer_id'=>$invoice->customer_id
        ],
      ];
      $entryDate = ($payment->paid_at ?? now())->toDateString();
      app(\App\Services\AccountingPeriodService::class)
        ->assertDateOpen($entryDate, "Period closed: cannot post ledger on {$entryDate}");

      $entry = JournalEntry::create([
        'entry_date' => $entryDate,
        'action' => 'invoice_payment',
        'status' => 'posted',
        'memo' => "Payment received for {$invoice->number}",
        'source_type' => 'InvoicePayment',
        'source_id' => $payment->id,
        'currency' => $payment->currency,
        'exchange_rate' => $payment->exchange_rate,
        'total_debit' => $amount,
        'total_credit' => $amount,
        'posted_by' => auth()->id(),
      ]);
      app(\App\Services\AuditService::class)->log(
  'ledger.posted',
  $entry,
  null,
  ['id' => $entry->id, 'action' => $entry->action, 'total_debit' => (float)$entry->total_debit, 'total_credit' => (float)$entry->total_credit],
  ['source_type' => $entry->source_type, 'source_id' => $entry->source_id]
);


      $entry->lines()->createMany($lines);

      return $entry->load('lines');
    });
  }
  private function paymentAccountId(string $method): int
{
    $map = [
        'cash' => 'CASH',
        'bank' => 'BANK',
        'card' => 'CARD',
        'moncash' => 'MONCASH',
        'cheque' => 'CHEQUE',
        'other' => 'CASH',
    ];

    $key = $map[$method] ?? 'CASH';
    return $this->accountId($key); // utilise AccountMapping
}

// use App\Models\{Invoice, JournalEntry};

public function postInvoiceVoid(Invoice $invoice): JournalEntry
{
    return DB::transaction(function () use ($invoice) {

        // idempotent
        $existing = JournalEntry::where('action','invoice_void')
            ->where('source_type','Invoice')->where('source_id',$invoice->id)
            ->first();
        if ($existing) return $existing->load('lines');

        // retrouver l’écriture d’émission
        $issued = JournalEntry::where('action','invoice_issued')
            ->where('source_type','Invoice')->where('source_id',$invoice->id)
            ->with('lines')
            ->first();

        if (!$issued) {
            abort(422, "Missing invoice_issued entry for invoice {$invoice->id}");
        }

        // créer les lignes inversées
        $revLines = [];
        foreach ($issued->lines as $l) {
            $revLines[] = [
                'account_id' => $l->account_id,
                'line_no' => $l->line_no,
                'description' => "VOID {$invoice->number} - ".$l->description,
                'debit' => (float)$l->credit,
                'credit' => (float)$l->debit,
                'customer_id' => $l->customer_id,
                'vendor_id' => $l->vendor_id,
            ];
        }

        $totalDebit = array_sum(array_column($revLines,'debit'));
        $totalCredit = array_sum(array_column($revLines,'credit'));
        $entryDate = now()->toDateString();
        app(\App\Services\AccountingPeriodService::class)
          ->assertDateOpen($entryDate, "Period closed: cannot post ledger on {$entryDate}");

        $entry = JournalEntry::create([
            'entry_date' => $entryDate,
            'action' => 'invoice_void',
            'status' => 'posted',
            'memo' => "Void invoice {$invoice->number}",
            'source_type' => 'Invoice',
            'source_id' => $invoice->id,
            'currency' => $invoice->currency,
            'exchange_rate' => $invoice->exchange_rate,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'posted_by' => auth()->id(),
            'reverses_entry_id' => $issued->id,
        ]);

        app(\App\Services\AuditService::class)->log(
  'ledger.posted',
  $entry,
  null,
  ['id' => $entry->id, 'action' => $entry->action, 'total_debit' => (float)$entry->total_debit, 'total_credit' => (float)$entry->total_credit],
  ['source_type' => $entry->source_type, 'source_id' => $entry->source_id]
);


        $entry->lines()->createMany($revLines);

        return $entry->load('lines');
    });
}

// use App\Models\{Invoice, InvoicePayment, JournalEntry};

public function postInvoiceRefund(Invoice $invoice, InvoicePayment $refund): JournalEntry
{
    return DB::transaction(function () use ($invoice, $refund) {

        if ($refund->kind !== 'refund') {
            abort(422, 'Payment kind must be refund.');
        }

        $existing = JournalEntry::where('action','invoice_refund')
            ->where('source_type','InvoicePayment')->where('source_id',$refund->id)
            ->first();
        if ($existing) return $existing->load('lines');

        $ar = $this->accountId('AR');
        $payAcc = $this->paymentAccountId($refund->method);

        $amount = (float)$refund->amount;

        // REFUND = inverse paiement : DR AR, CR Cash/Bank
        $lines = [
            [
                'account_id'=>$ar, 'line_no'=>1,
                'description'=>"Refund for {$invoice->number}",
                'debit'=>$amount, 'credit'=>0,
                'customer_id'=>$invoice->customer_id
            ],
            [
                'account_id'=>$payAcc, 'line_no'=>2,
                'description'=>"Cash/Bank out - refund {$invoice->number}",
                'debit'=>0, 'credit'=>$amount,
                'customer_id'=>$invoice->customer_id
            ],
        ];
        $entryDate = ($refund->paid_at ?? now())->toDateString();
        app(\App\Services\AccountingPeriodService::class)
          ->assertDateOpen($entryDate, "Period closed: cannot post ledger on {$entryDate}");

        $entry = JournalEntry::create([
            'entry_date' => $entryDate,
            'action' => 'invoice_refund',
            'status' => 'posted',
            'memo' => "Refund issued for {$invoice->number}",
            'source_type' => 'InvoicePayment',
            'source_id' => $refund->id,
            'currency' => $refund->currency,
            'exchange_rate' => $refund->exchange_rate,
            'total_debit' => $amount,
            'total_credit' => $amount,
            'posted_by' => auth()->id(),
        ]);

        app(\App\Services\AuditService::class)->log(
  'ledger.posted',
  $entry,
  null,
  ['id' => $entry->id, 'action' => $entry->action, 'total_debit' => (float)$entry->total_debit, 'total_credit' => (float)$entry->total_credit],
  ['source_type' => $entry->source_type, 'source_id' => $entry->source_id]
);


        $entry->lines()->createMany($lines);

        return $entry->load('lines');
    });
}

// use App\Models\Invoice;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Validation\Rule;

public function refund(Request $request, Invoice $invoice)
{
    $data = $request->validate([
        'method' => ['required', Rule::in(['cash','card','bank','moncash','cheque','other'])],
        'amount' => ['required','numeric','min:0.01'],
        'paid_at' => ['nullable','date'],
        'reference' => ['nullable','string','max:190'],
        'notes' => ['nullable','string'],
        'metadata' => ['nullable','array'],
    ]);

    return DB::transaction(function () use ($invoice, $data) {

        $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

        if ($invoice->status === 'void') {
            abort(422, 'Invoice already void.');
        }

        $amount = (float)$data['amount'];

        // On rembourse au maximum ce qui a été payé net
        if ($amount > (float)$invoice->amount_paid) {
            abort(422, 'Refund exceeds amount paid.');
        }

        $refund = $invoice->payments()->create([
            'kind' => 'refund',
            'method' => $data['method'],
            'amount' => $amount,
            'currency' => $invoice->currency,
            'exchange_rate' => $invoice->exchange_rate,
            'paid_at' => $data['paid_at'] ?? now(),
            'reference' => $data['reference'] ?? null,
            'received_by' => auth()->id(),
            'notes' => $data['notes'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        // Mise à jour invoice (amount_paid = net)
        $newPaid = max(0, (float)$invoice->amount_paid - $amount);
        $newBalance = min((float)$invoice->total, (float)$invoice->balance_due + $amount);

        // statut simple
        $newStatus = $newPaid <= 0.00001 ? 'issued' : 'partially_paid';

        $invoice->update([
            'amount_paid' => $newPaid,
            'balance_due' => $newBalance,
            'status' => $newStatus,
            'paid_at' => null,
        ]);

        // Ledger: inverse paiement
        app(\App\Services\LedgerService::class)->postInvoiceRefund($invoice, $refund);

        return $invoice->load('payments');
    });
}

public function void(Invoice $invoice)
{
    return DB::transaction(function () use ($invoice) {

        $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

        if ($invoice->status === 'void') {
            return $invoice;
        }

        // strict: pas de void si paiements existent
        if ((float)$invoice->amount_paid > 0) {
            abort(422, 'Refund payments first, then void.');
        }

        // Ledger: inverse l’écriture d’émission
        app(\App\Services\LedgerService::class)->postInvoiceVoid($invoice);

        // fermer la facture
        $invoice->update([
            'status' => 'void',
            'voided_at' => now(),
            'balance_due' => 0,
            'amount_paid' => 0,
            'paid_at' => null,
        ]);

        return $invoice;
    });
}


public function postInvoiceCogs(Invoice $invoice): ?JournalEntry
{
  return DB::transaction(function () use ($invoice) {

    $existing = JournalEntry::where('action','invoice_cogs')
      ->where('source_type','Invoice')->where('source_id',$invoice->id)
      ->first();
    if ($existing) return $existing->load('lines');

    $invoice->load('items');

    $costTotal = 0.0;
    foreach ($invoice->items as $it) {
      $costTotal += (float)$it->line_cost_total;
    }

    if ($costTotal <= 0.00001) return null;

    $invAcc = $this->accountId('INVENTORY');
    $cogsAcc = $this->accountId('COGS');
    $entryDate = ($invoice->issue_date ?? now()->toDateString());
    app(\App\Services\AccountingPeriodService::class)
      ->assertDateOpen($entryDate, "Period closed: cannot post ledger on {$entryDate}");

    $entry = JournalEntry::create([
      'entry_date' => $entryDate,
      'action' => 'invoice_cogs',
      'status' => 'posted',
      'memo' => "COGS for {$invoice->number}",
      'source_type' => 'Invoice',
      'source_id' => $invoice->id,
      'currency' => $invoice->currency,
      'exchange_rate' => $invoice->exchange_rate,
      'total_debit' => $costTotal,
      'total_credit' => $costTotal,
      'posted_by' => auth()->id(),
    ]);
    app(\App\Services\AuditService::class)->log(
  'ledger.posted',
  $entry,
  null,
  ['id' => $entry->id, 'action' => $entry->action, 'total_debit' => (float)$entry->total_debit, 'total_credit' => (float)$entry->total_credit],
  ['source_type' => $entry->source_type, 'source_id' => $entry->source_id]
);


    $entry->lines()->createMany([
      [
        'account_id'=>$cogsAcc, 'line_no'=>1,
        'description'=>"COGS {$invoice->number}",
        'debit'=>$costTotal, 'credit'=>0,
        'customer_id'=>$invoice->customer_id
      ],
      [
        'account_id'=>$invAcc, 'line_no'=>2,
        'description'=>"Inventory decrease {$invoice->number}",
        'debit'=>0, 'credit'=>$costTotal,
        'customer_id'=>$invoice->customer_id
      ],
    ]);

    return $entry->load('lines');
  });
}

public function postInvoiceCogsVoid(Invoice $invoice): JournalEntry
{
  return DB::transaction(function () use ($invoice) {

    $existing = JournalEntry::where('action','invoice_cogs_void')
      ->where('source_type','Invoice')->where('source_id',$invoice->id)
      ->first();
    if ($existing) return $existing->load('lines');

    $cogsEntry = JournalEntry::where('action','invoice_cogs')
      ->where('source_type','Invoice')->where('source_id',$invoice->id)
      ->with('lines')
      ->firstOrFail();

    $revLines = [];
    foreach ($cogsEntry->lines as $l) {
      $revLines[] = [
        'account_id'=>$l->account_id,
        'line_no'=>$l->line_no,
        'description'=>"VOID COGS {$invoice->number} - ".$l->description,
        'debit'=>(float)$l->credit,
        'credit'=>(float)$l->debit,
        'customer_id'=>$l->customer_id,
        'vendor_id'=>$l->vendor_id,
      ];
    }

    $totalDebit = array_sum(array_column($revLines,'debit'));
    $totalCredit = array_sum(array_column($revLines,'credit'));
    $entryDate = now()->toDateString();
    app(\App\Services\AccountingPeriodService::class)
      ->assertDateOpen($entryDate, "Period closed: cannot post ledger on {$entryDate}");

    $entry = JournalEntry::create([
      'entry_date' => $entryDate,
      'action' => 'invoice_cogs_void',
      'status' => 'posted',
      'memo' => "Reverse COGS for {$invoice->number}",
      'source_type' => 'Invoice',
      'source_id' => $invoice->id,
      'currency' => $invoice->currency,
      'exchange_rate' => $invoice->exchange_rate,
      'total_debit' => $totalDebit,
      'total_credit' => $totalCredit,
      'posted_by' => auth()->id(),
      'reverses_entry_id' => $cogsEntry->id,
    ]);
    app(\App\Services\AuditService::class)->log(
  'ledger.posted',
  $entry,
  null,
  ['id' => $entry->id, 'action' => $entry->action, 'total_debit' => (float)$entry->total_debit, 'total_credit' => (float)$entry->total_credit],
  ['source_type' => $entry->source_type, 'source_id' => $entry->source_id]
);


    $entry->lines()->createMany($revLines);

    return $entry->load('lines');
  });
}


}
