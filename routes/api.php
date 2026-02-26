<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProformaController;
use App\Http\Controllers\Api\SalesDocumentController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PdfController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\ReportExportController;
use App\Http\Controllers\Api\AccountingPeriodController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\BusinessUserController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PosSaleController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeePaymentController;

// PUBLIC
Route::post('/auth/login', [AuthController::class, 'login']);

// PROTECTED (token required)
Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    $user = $request->user()->load([
        'businesses' => function ($q) {
            $q->select('businesses.id','businesses.name','businesses.slug')
              ->withPivot(['role','status','joined_at']);
        }
    ]);

    return response()->json($user);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/me/password', [\App\Http\Controllers\Api\ProfileController::class, 'changePassword']);
    Route::post('/me/avatar', [\App\Http\Controllers\Api\ProfileController::class, 'uploadAvatar']);
    Route::get('/currencies', [CurrencyController::class, 'index']);
});
Route::middleware('auth:sanctum')->group(function () {

 // Route::get('/me', fn (Request $r) => $r->user());
  Route::post('/auth/logout', [AuthController::class, 'logout']);

  // Liste des business accessibles a l'utilisateur
  Route::get('/app/businesses', [BusinessController::class, 'index']);

  // TOUT ce qui depend d'un business
  Route::prefix('app/{business}')
    ->middleware('setBusiness')
    ->group(function () {

      // CRUD
      Route::apiResource('customers', CustomerController::class);
      Route::apiResource('suppliers', SupplierController::class);
      Route::apiResource('categories', CategoryController::class);

      // Produits : Lecture accessible à tous les employés, Gestion protégée
      Route::apiResource('products', ProductController::class)->only(['index', 'show']);
      Route::apiResource('products', ProductController::class)->except(['index', 'show'])
          ->middleware('ability:manage_products');

      Route::get('business', [BusinessController::class, 'show']);
      Route::patch('business', [BusinessController::class, 'update'])
        ->middleware('ability:manage_business');
      Route::get('inventory/summary', [InventoryController::class, 'summary']);
      Route::get('inventory/movements.csv', [InventoryController::class, 'movementsCsv']);
      Route::get('inventory/movements', [InventoryController::class, 'movements']);
      Route::post('inventory/adjustments', [InventoryController::class, 'adjust'])
        ->middleware('ability:manage_products');
      Route::apiResource('proformas', ProformaController::class);
      Route::apiResource('documents', SalesDocumentController::class);

      // Workflow documents
      Route::prefix('documents/{document}')->group(function () {
          Route::post('sent',   [SalesDocumentController::class, 'markSent']);
          Route::post('accept', [SalesDocumentController::class, 'accept']);
          Route::post('reject', [SalesDocumentController::class, 'reject']);
          Route::post('cancel', [SalesDocumentController::class, 'cancel']);
          Route::post('convert-to-invoice', [SalesDocumentController::class, 'convertToInvoice'])
              ->middleware('ability:create_invoices');
      });

      // Invoices
      Route::apiResource('invoices', InvoiceController::class)->only(['index','show']);
      Route::get('sales', [PosSaleController::class, 'index']);
      Route::post('sales', [PosSaleController::class, 'store']);
      Route::get('sales/{sale}', [PosSaleController::class, 'show']);

      // Actions sensibles (Void & Refund)
      Route::middleware('ability:void_invoices')->group(function () {
          Route::post('sales/{sale}/void', [PosSaleController::class, 'void']);
          Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
      });

      Route::middleware('ability:refund_payments')->group(function () {
          Route::post('sales/{sale}/refund', [PosSaleController::class, 'refund']);
          Route::post('invoices/{invoice}/refunds', [InvoiceController::class, 'refund']);
      });

      // Paiements
      Route::middleware('ability:record_payments')->group(function () {
          Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'addPayment']);
          Route::get('employees/{employee}/payments', [EmployeePaymentController::class, 'index']);
          Route::post('employees/{employee}/payments', [EmployeePaymentController::class, 'store']);
      });

      // PDFs
      Route::get('documents/{document}/pdf', [PdfController::class, 'document']);
      Route::get('invoices/{invoice}/pdf', [PdfController::class, 'invoice']);

      // Reports (web)
      Route::middleware('ability:view_reports')->group(function () {
        Route::get('reports/trial-balance', [ReportsController::class, 'trialBalance']);
        Route::get('reports/general-ledger', [ReportsController::class, 'generalLedger']);
        Route::get('reports/profit-loss', [ReportsController::class, 'profitAndLoss']);
        Route::get('reports/balance-sheet', [ReportsController::class, 'balanceSheet']);
        Route::get('reports/ar-summary', [ReportsController::class, 'arSummary']);
        Route::get('reports/ar-aging', [ReportsController::class, 'arAging']);
      });

      // Exports
      Route::middleware('ability:export_reports')->group(function () {
        Route::get('reports/trial-balance.csv', [ReportExportController::class, 'trialBalanceCsv']);
        Route::get('reports/profit-loss.csv', [ReportExportController::class, 'profitLossCsv']);
        Route::get('reports/general-ledger.csv', [ReportExportController::class, 'generalLedgerCsv']);
        Route::get('reports/trial-balance.pdf', [ReportExportController::class, 'trialBalancePdf']);
        Route::get('reports/profit-loss.pdf', [ReportExportController::class, 'profitLossPdf']);
      });

      // Audit
      Route::get('audit/logs', [AuditLogController::class, 'index'])
        ->middleware('ability:view_audit');
      Route::get('audit/logs.csv', [AuditLogController::class, 'exportCsv'])
        ->middleware('ability:view_audit');
      Route::get('audit/logs.pdf', [AuditLogController::class, 'exportPdf'])
        ->middleware('ability:view_audit');

      // Accounting periods
      Route::get('accounting/periods', [AccountingPeriodController::class, 'index']);
      Route::middleware('ability:close_periods')->prefix('accounting/periods')->group(function () {
          Route::post('/', [AccountingPeriodController::class, 'store']);
          Route::post('{period}/close', [AccountingPeriodController::class, 'close']);
          Route::post('{period}/reopen', [AccountingPeriodController::class, 'reopen']);
      });

      // Business users (dans le business)
      Route::middleware('ability:manage_users')->group(function () {
        Route::get('business/users', [BusinessUserController::class, 'index']);
        Route::post('business/users', [BusinessUserController::class, 'store']);
        Route::patch('business/users/{user}/role', [BusinessUserController::class, 'updateRole']);
        Route::delete('business/users/{user}', [BusinessUserController::class, 'destroy']);

        Route::apiResource('employees', EmployeeController::class);
      });

    });

});
