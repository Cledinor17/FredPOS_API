<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesDocument;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfController extends Controller
{
    private function currentBusinessIdOrFail(): int
    {
        $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
        if (!$currentBusiness) {
            abort(403, 'Business context is required.');
        }

        return (int) $currentBusiness->id;
    }

    private function resolveDocumentOrFail(int $businessId, string $document): SalesDocument
    {
        if (!ctype_digit($document)) {
            abort(404, 'Document not found.');
        }

        $resolved = SalesDocument::query()
            ->where('business_id', $businessId)
            ->whereKey((int) $document)
            ->first();

        if (!$resolved) {
            abort(404, 'Document not found.');
        }

        return $resolved;
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

    public function document(string $business, string $document)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $document = $this->resolveDocumentOrFail($businessId, $document);
        $document->load(['customer','items']);
        $business = app('currentBusiness');

        $pdf = Pdf::loadView('pdf.document', [
            'document' => $document,
            'business' => $business,
        ])->setPaper('a4');

        return $pdf->stream($document->number.'.pdf');
    }

    public function invoice(string $business, string $invoice)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $invoice = $this->resolveInvoiceOrFail($businessId, $invoice);
        $invoice->load(['customer','items','payments']);
        $business = app('currentBusiness');

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'business' => $business,
        ])->setPaper('a4');

        return $pdf->stream($invoice->number.'.pdf');
    }
}

