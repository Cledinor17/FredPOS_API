<?php
namespace App\Services;

use App\Models\{Account, AccountMapping, Business};
use Illuminate\Support\Facades\DB;

class AccountingSetupService
{
  public function setupForBusiness(Business $business): void
  {
    DB::transaction(function () use ($business) {

      // comptes système minimaux
      $cash = Account::withoutGlobalScopes()->firstOrCreate(
        ['business_id'=>$business->id, 'code'=>'1000'],
        ['name'=>'Cash on Hand', 'type'=>'asset', 'subtype'=>'cash', 'normal_balance'=>'debit', 'is_system'=>true]
      );

      $ar = Account::withoutGlobalScopes()->firstOrCreate(
        ['business_id'=>$business->id, 'code'=>'1100'],
        ['name'=>'Accounts Receivable', 'type'=>'asset', 'subtype'=>'ar', 'normal_balance'=>'debit', 'is_system'=>true]
      );

      $taxPayable = Account::withoutGlobalScopes()->firstOrCreate(
        ['business_id'=>$business->id, 'code'=>'2100'],
        ['name'=>'Tax Payable', 'type'=>'liability', 'subtype'=>'tax', 'normal_balance'=>'credit', 'is_system'=>true]
      );

      $sales = Account::withoutGlobalScopes()->firstOrCreate(
        ['business_id'=>$business->id, 'code'=>'4000'],
        ['name'=>'Sales Revenue', 'type'=>'income', 'subtype'=>'sales', 'normal_balance'=>'credit', 'is_system'=>true]
      );

      $shippingIncome = Account::withoutGlobalScopes()->firstOrCreate(
        ['business_id'=>$business->id, 'code'=>'4010'],
        ['name'=>'Shipping Income', 'type'=>'income', 'subtype'=>'shipping', 'normal_balance'=>'credit', 'is_system'=>true]
      );

      $inventory = Account::withoutGlobalScopes()->firstOrCreate(
  ['business_id'=>$business->id, 'code'=>'1200'],
  ['name'=>'Inventory', 'type'=>'asset', 'subtype'=>'inventory', 'normal_balance'=>'debit', 'is_system'=>true]
);

$cogs = Account::withoutGlobalScopes()->firstOrCreate(
  ['business_id'=>$business->id, 'code'=>'5000'],
  ['name'=>'Cost of Goods Sold', 'type'=>'expense', 'subtype'=>'cogs', 'normal_balance'=>'debit', 'is_system'=>true]
);

$this->map($business->id, 'INVENTORY', $inventory->id);
$this->map($business->id, 'COGS', $cogs->id);

      // mappings
      $this->map($business->id, 'CASH', $cash->id);
      $this->map($business->id, 'BANK', $cash->id);
      $this->map($business->id, 'CARD', $cash->id);
      $this->map($business->id, 'MONCASH', $cash->id);
      $this->map($business->id, 'CHEQUE', $cash->id);
      $this->map($business->id, 'AR', $ar->id);
      $this->map($business->id, 'TAX_PAYABLE', $taxPayable->id);
      $this->map($business->id, 'SALES', $sales->id);
      $this->map($business->id, 'SHIPPING_INCOME', $shippingIncome->id);
    });
  }

  private function map(int $businessId, string $key, int $accountId): void
  {
    AccountMapping::withoutGlobalScopes()->updateOrCreate(
      ['business_id'=>$businessId, 'key'=>$key],
      ['account_id'=>$accountId]
    );
  }
}
