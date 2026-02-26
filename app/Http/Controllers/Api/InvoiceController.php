<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    private function currentBusinessIdOrFail(): int
    {
        $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
        if (!$currentBusiness) {
            abort(403, 'Business context is required.');
        }

        return (int) $currentBusiness->id;
    }

    private function resolveInvoiceOrFail(int $businessId, string $invoice): Invoice
    {
        if (!ctype_digit($invoice)) {
            abort(404, 'Invoice not found.');
        }

        $resolved = Invoice::query()
            ->where('business_id', $businessId)
            ->whereKey((int) $invoice)
            ->first();

        if (!$resolved) {
            abort(404, 'Invoice not found.');
        }

        return $resolved;
    }

    public function index(Request $request)
    {
        $q = Invoice::query()
            ->with(['customer', 'creator:id,name,email', 'voidedByUser:id,name,email'])
            ->withCount(['items', 'payments']);

        if ($request->filled('status')) {
            $q->where('status', (string) $request->status);
        }
        if ($request->filled('customer_id')) {
            $q->where('customer_id', $request->customer_id);
        }
        if ($request->filled('from')) {
            $q->whereDate('issue_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('issue_date', '<=', $request->to);
        }

        return $q->orderByDesc('id')->paginate(20);
    }

    public function show(string $business, string $invoice)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $resolvedInvoice = $this->resolveInvoiceOrFail($businessId, $invoice);

        return $resolvedInvoice->load([
            'customer',
            'creator:id,name,email',
            'voidedByUser:id,name,email',
            'items',
            'payments',
        ]);
    }

    // Paiement partiel ou total
    public function addPayment(Request $request, string $business, string $invoice)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $resolvedInvoice = $this->resolveInvoiceOrFail($businessId, $invoice);

        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'card', 'bank', 'moncash', 'cheque', 'other'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['sometimes', 'string', 'max:10'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0.000001'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        return DB::transaction(function () use ($businessId, $resolvedInvoice, $data) {
            $invoice = Invoice::query()
                ->where('business_id', $businessId)
                ->whereKey($resolvedInvoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($invoice->status, ['void', 'refunded'], true)) {
                abort(422, 'Cannot pay a void/refunded invoice.');
            }

            $amount = round((float) $data['amount'], 2);
            if ($amount - (float) $invoice->balance_due > 0.000001) {
                abort(422, 'Payment exceeds remaining balance.');
            }

            $beforeAmountPaid = (float) $invoice->amount_paid;
            $beforeBalance = (float) $invoice->balance_due;

            $payment = $invoice->payments()->create([
                'kind' => 'payment',
                'method' => $data['method'],
                'amount' => $amount,
                'currency' => $data['currency'] ?? $invoice->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $invoice->exchange_rate,
                'paid_at' => $data['paid_at'] ?? now(),
                'reference' => $data['reference'] ?? null,
                'received_by' => auth()->id(),
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            app(\App\Services\LedgerService::class)->postInvoicePayment($invoice, $payment);

            $newPaid = round($beforeAmountPaid + $amount, 2);
            $newBalance = round(max(0, (float) $invoice->total - $newPaid), 2);
            $newStatus = $newBalance <= 0.00001 ? 'paid' : 'partially_paid';

            $invoice->update([
                'amount_paid' => $newPaid,
                'balance_due' => $newBalance,
                'status' => $newStatus,
                'paid_at' => $newStatus === 'paid' ? now() : null,
            ]);

            app(\App\Services\AuditService::class)->log(
                'invoice.payment_added',
                $invoice,
                [
                    'amount_paid_before' => $beforeAmountPaid,
                    'balance_before' => $beforeBalance,
                ],
                [
                    'amount_paid_after' => (float) $invoice->amount_paid,
                    'balance_after' => (float) $invoice->balance_due,
                    'status' => $invoice->status,
                ],
                [
                    'payment_id' => $payment->id,
                    'method' => $payment->method,
                    'amount' => (float) $payment->amount,
                ]
            );

            return $invoice->load([
                'customer',
                'creator:id,name,email',
                'voidedByUser:id,name,email',
                'items',
                'payments',
            ]);
        });
    }

    public function void(string $business, string $invoice)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $resolvedInvoice = $this->resolveInvoiceOrFail($businessId, $invoice);

        if ((float) $resolvedInvoice->amount_paid > 0) {
            abort(422, 'Cannot void an invoice that has payments. Refund first.');
        }

        $beforeStatus = (string) $resolvedInvoice->status;

        app(\App\Services\LedgerService::class)->postInvoiceVoid($resolvedInvoice);
        app(\App\Services\LedgerService::class)->postInvoiceCogsVoid($resolvedInvoice);
        app(\App\Services\StockService::class)->voidInvoiceStock($resolvedInvoice);

        $resolvedInvoice->update([
            'status' => 'void',
            'voided_at' => now(),
            'voided_by' => auth()->id(),
        ]);

        app(\App\Services\AuditService::class)->log(
            'invoice.void',
            $resolvedInvoice,
            ['status_before' => $beforeStatus],
            [
                'status_after' => $resolvedInvoice->status,
                'voided_at' => (string) $resolvedInvoice->voided_at,
            ],
            ['invoice_number' => $resolvedInvoice->number]
        );

        return $resolvedInvoice->load([
            'customer',
            'creator:id,name,email',
            'voidedByUser:id,name,email',
            'items',
            'payments',
        ]);
    }
}

