<?php

return [
  'owner' => [
    'manage_business','manage_users','manage_products',
    'create_invoices','record_payments','refund_payments','void_invoices',
    'close_periods','view_reports', 'export_reports','view_audit',
  ],
  'admin' => [
    'manage_business',
    'manage_users','manage_products',
    'create_invoices','record_payments','refund_payments','void_invoices',
    'close_periods','view_reports', 'export_reports','view_audit',
  ],
  'manager' => [
    'manage_products',
    'create_invoices','record_payments','refund_payments',
    'view_reports','view_audit',
  ],
  'accountant' => [
    'record_payments','refund_payments','void_invoices',
    'close_periods','view_reports', 'export_reports','view_audit',
  ],
  'staff' => [
    'create_invoices','record_payments',
    'view_reports',
  ],
];
