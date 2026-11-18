<?php
/** Module tab bars. Each entry: [href, label, permission, icon, [alias scripts]] */

function nav_catalog(): void
{
    render_subnav([
        ['/catalog/products.php', 'Products', 'catalog.view', 'package', ['/catalog/product_form.php', '/catalog/product_view.php']],
        ['/catalog/pricing.php', 'Price lists', 'catalog.view', 'tags'],
        ['/catalog/categories.php', 'Categories', 'catalog.manage', 'folder-tree'],
    ]);
}

function nav_customers(): void
{
    render_subnav([
        ['/sales/customers.php', 'Customers', 'customers.view', 'contact', ['/sales/customer_form.php', '/sales/customer_view.php']],
        ['/sales/customer_categories.php', 'Customer categories', 'pricing.manage', 'users-round'],
    ]);
}

function nav_sales(): void
{
    render_subnav([
        ['/sales/orders.php', 'Sales orders', 'sales.view', 'clipboard-list', ['/sales/order_form.php', '/sales/order_view.php']],
        ['/sales/deliveries.php', 'Deliveries', 'delivery.view', 'truck', ['/sales/delivery_view.php']],
        ['/sales/invoices.php', 'Invoices', 'invoices.view', 'receipt', ['/sales/invoice_view.php', '/sales/invoice_create.php']],
        ['/sales/payments.php', 'Collections', 'invoices.view', 'hand-coins', ['/sales/payment_form.php']],
        ['/sales/returns.php', 'Returns & credit notes', 'returns.create', 'undo-2', ['/sales/return_form.php', '/sales/return_view.php', '/sales/credit_notes.php']],
    ]);
}

function nav_warehouse(): void
{
    render_subnav([
        ['/warehouse/fulfilment.php', 'Fulfilment queue', 'delivery.view', 'package-check'],
        ['/warehouse/stock.php', 'Stock', 'inventory.view', 'warehouse', ['/warehouse/product_stock.php']],
        ['/warehouse/transfers.php', 'Transfers', 'inventory.view', 'arrow-left-right', ['/warehouse/transfer_form.php', '/warehouse/transfer_view.php']],
        ['/warehouse/adjustments.php', 'Adjustments', 'inventory.view', 'clipboard-check', ['/warehouse/adjustment_form.php', '/warehouse/adjustment_view.php']],
        ['/warehouse/counts.php', 'Stock counts', 'inventory.view', 'list-checks', ['/warehouse/count_view.php']],
        ['/warehouse/movements.php', 'Movement history', 'inventory.view', 'history'],
        ['/warehouse/warehouses.php', 'Warehouses', 'inventory.view', 'building'],
    ]);
}

function nav_trace(): void
{
    render_subnav([
        ['/trace/serials.php', 'Serial numbers', 'trace.view', 'barcode', ['/trace/serial_view.php']],
        ['/trace/batches.php', 'Batches / lots', 'trace.view', 'layers', ['/trace/batch_view.php']],
        ['/trace/expiry.php', 'Expiry monitor', 'trace.view', 'calendar-clock'],
        ['/trace/recalls.php', 'Recalls', 'trace.view', 'siren', ['/trace/recall_view.php']],
        ['/trace/warranty.php', 'Warranty', 'trace.view', 'shield-check'],
    ]);
}

function nav_purchasing(): void
{
    render_subnav([
        ['/purchasing/orders.php', 'Purchase orders', 'purchase.view', 'file-text', ['/purchasing/order_form.php', '/purchasing/order_view.php']],
        ['/purchasing/receipts.php', 'Goods received', 'purchase.view', 'package-check', ['/purchasing/receive.php', '/purchasing/grn_view.php']],
        ['/purchasing/bills.php', 'Supplier invoices', 'purchase.view', 'file-spreadsheet', ['/purchasing/bill_form.php', '/purchasing/bill_view.php']],
        ['/purchasing/payments.php', 'Payments', 'purchase.view', 'banknote', ['/purchasing/payment_form.php']],
        ['/purchasing/returns.php', 'Returns to supplier', 'purchase.view', 'undo-2'],
        ['/purchasing/suppliers.php', 'Suppliers', 'suppliers.view', 'building-2', ['/purchasing/supplier_view.php']],
    ]);
}

function nav_finance(): void
{
    render_subnav([
        ['/finance/expenses.php', 'Expenses', 'finance.view', 'receipt-text'],
        ['/finance/cashbook.php', 'Cash & bank', 'finance.view', 'landmark'],
        ['/finance/receivables.php', 'Receivables', 'finance.view', 'hand-coins'],
        ['/finance/payables.php', 'Payables', 'finance.view', 'file-clock'],
        ['/finance/ledger.php', 'General ledger', 'finance.view', 'book-open', ['/finance/journal.php']],
        ['/finance/statements.php', 'Financial statements', 'reports.finance', 'scale'],
    ]);
}

function nav_reports(): void
{
    render_subnav([
        ['/reports/index.php', 'Overview', 'reports.view', 'layout-grid'],
        ['/reports/sales.php', 'Sales', 'reports.view', 'trending-up'],
        ['/reports/inventory.php', 'Inventory', 'reports.view', 'warehouse'],
        ['/reports/tax.php', 'Tax', 'reports.finance', 'percent'],
        ['/reps/index.php', 'Sales reps', 'reps.view', 'users'],
    ]);
}
