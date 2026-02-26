<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// PDF (optionnel)
use Barryvdh\DomPDF\Facade\Pdf;

class ReportExportController extends Controller
{
    // Réutilise tes méthodes JSON existantes (ReportsController) sans recopier la logique
    private function reports(): ReportsController
    {
        return app(ReportsController::class);
    }

    public function trialBalanceCsv(Request $request): StreamedResponse
    {
        $json = $this->reports()->trialBalance($request)->getData(true);

        $filename = 'trial_balance_' . ($json['range']['as_of'] ?? ($json['range']['from'].'_'.$json['range']['to'])) . '.csv';

        return response()->streamDownload(function () use ($json) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Code', 'Account', 'Type', 'Debit', 'Credit', 'Balance']);

            foreach ($json['rows'] as $r) {
                fputcsv($out, [
                    $r['code'],
                    $r['name'],
                    $r['type'],
                    $r['debit'],
                    $r['credit'],
                    $r['balance'],
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['TOTAL', '', '', $json['totals']['debit'], $json['totals']['credit'], '']);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function profitLossCsv(Request $request): StreamedResponse
    {
        $json = $this->reports()->profitAndLoss($request)->getData(true);
        $filename = 'profit_loss_' . $json['range']['from'] . '_' . $json['range']['to'] . '.csv';

        return response()->streamDownload(function () use ($json) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['P&L', 'From', $json['range']['from'], 'To', $json['range']['to']]);
            fputcsv($out, []);

            fputcsv($out, ['INCOME']);
            fputcsv($out, ['Code', 'Account', 'Amount']);
            foreach ($json['income'] as $r) {
                fputcsv($out, [$r['code'], $r['name'], $r['amount']]);
            }
            fputcsv($out, ['TOTAL INCOME', '', $json['totals']['total_income']]);
            fputcsv($out, []);

            fputcsv($out, ['EXPENSES']);
            fputcsv($out, ['Code', 'Account', 'Amount']);
            foreach ($json['expenses'] as $r) {
                fputcsv($out, [$r['code'], $r['name'], $r['amount']]);
            }
            fputcsv($out, ['TOTAL EXPENSES', '', $json['totals']['total_expenses']]);
            fputcsv($out, []);

            fputcsv($out, ['NET PROFIT', '', $json['totals']['net_profit']]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function generalLedgerCsv(Request $request): StreamedResponse
    {
        $json = $this->reports()->generalLedger($request)->getData(true);

        $acc = $json['account']['code'] . '_' . preg_replace('/\s+/', '_', strtolower($json['account']['name']));
        $filename = 'general_ledger_' . $acc . '_' . ($json['range']['from'] ?? 'all') . '_' . ($json['range']['to'] ?? 'all') . '.csv';

        return response()->streamDownload(function () use ($json) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Account', $json['account']['code'].' - '.$json['account']['name']]);
            fputcsv($out, []);

            fputcsv($out, ['Date', 'Action', 'Memo', 'Description', 'Debit', 'Credit', 'Running Balance', 'Source Type', 'Source ID']);
            foreach ($json['rows'] as $r) {
                fputcsv($out, [
                    $r['date'],
                    $r['action'],
                    $r['memo'],
                    $r['description'],
                    $r['debit'],
                    $r['credit'],
                    $r['running_balance'],
                    $r['source']['type'] ?? '',
                    $r['source']['id'] ?? '',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------------- PDF (si DomPDF est installé) ----------------

    public function trialBalancePdf(Request $request)
    {
        $json = $this->reports()->trialBalance($request)->getData(true);
        $business = app('currentBusiness');

        $pdf = Pdf::loadView('pdf.reports.trial-balance', [
            'business' => $business,
            'data' => $json,
        ])->setPaper('a4');

        $filename = 'trial_balance.pdf';
        return $pdf->stream($filename);
    }

    public function profitLossPdf(Request $request)
    {
        $json = $this->reports()->profitAndLoss($request)->getData(true);
        $business = app('currentBusiness');

        $pdf = Pdf::loadView('pdf.reports.profit-loss', [
            'business' => $business,
            'data' => $json,
        ])->setPaper('a4');

        $filename = 'profit_loss.pdf';
        return $pdf->stream($filename);
    }
}
