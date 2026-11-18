<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_permission('settings.manage');

$pdo = db();
$fields = [
    'company_name' => 'text', 'company_address' => 'text', 'company_phone' => 'text', 'company_email' => 'text', 'tax_name' => 'text', 'tax_no' => 'text',
    'currency_symbol' => 'text', 'allocation_policy' => 'policy', 'expiry_warning_days' => 'int', 'expiry_critical_days' => 'int',
    'credit_include_open_orders' => 'bool', 'flag_overdue_customers' => 'bool', 'max_rep_discount_pct' => 'pct', 'po_approval_threshold' => 'money',
    'invoice_match_tolerance_pct' => 'pct', 'so_prefix' => 'code', 'po_prefix' => 'code', 'grn_prefix' => 'code', 'dn_prefix' => 'code', 'inv_prefix' => 'code',
];
$current = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) $current[$row['setting_key']] = $row['setting_value'];

if (is_post()) {
    verify_csrf();
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2');
    $changes = [];
    foreach ($fields as $field => $type) {
        $value = match ($type) {
            'bool' => isset($_POST[$field]) ? '1' : '0',
            'pct' => (string) max(0, min(100, input_float($_POST, $field))),
            'money' => (string) max(0, input_float($_POST, $field)),
            'int' => (string) max(0, input_int($_POST, $field)),
            'policy' => in_array(input($_POST, $field), ['fefo', 'fifo'], true) ? input($_POST, $field) : 'fefo',
            'code' => strtoupper(preg_replace('/[^A-Z0-9]/i', '', input($_POST, $field))) ?: ($current[$field] ?? 'DOC'),
            default => input($_POST, $field),
        };
        if (($current[$field] ?? null) !== $value) $changes[] = $field . ': "' . ($current[$field] ?? '') . '" → "' . $value . '"';
        $stmt->execute([':k' => $field, ':v' => $value, ':v2' => $value]);
    }
    if ($changes) log_activity('settings_update', 'Changed settings — ' . implode('; ', $changes));
    flash('success', $changes ? 'Settings saved.' : 'No changes to save.');
    redirect('/settings.php');
}
$v = fn($k, $d = '') => e($current[$k] ?? $d);
$chk = fn($k, $d = '0') => ($current[$k] ?? $d) === '1' ? 'checked' : '';

$pageTitle = 'Settings';
require __DIR__ . '/includes/header.php';
page_header('Settings', 'Company details, stock allocation policy, credit control and approval rules.');
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-2">
    <div class="card">
      <h3 class="mb-4">Company details</h3>
      <div class="form-group"><label>Company name</label><input class="form-control" type="text" name="company_name" value="<?= $v('company_name') ?>" maxlength="150"></div>
      <div class="form-group"><label>Address</label><input class="form-control" type="text" name="company_address" value="<?= $v('company_address') ?>" maxlength="255"></div>
      <div class="form-row">
        <div class="form-group"><label>Phone</label><input class="form-control" type="text" name="company_phone" value="<?= $v('company_phone') ?>" maxlength="30"></div>
        <div class="form-group"><label>Email</label><input class="form-control" type="email" name="company_email" value="<?= $v('company_email') ?>" maxlength="150"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Tax name</label><input class="form-control" type="text" name="tax_name" value="<?= $v('tax_name', 'VAT') ?>" maxlength="20"></div>
        <div class="form-group"><label>Tax registration no.</label><input class="form-control" type="text" name="tax_no" value="<?= $v('tax_no') ?>" maxlength="40" placeholder="Printed on invoices"></div>
        <div class="form-group"><label>Currency symbol</label><input class="form-control" type="text" name="currency_symbol" value="<?= $v('currency_symbol', 'Rs.') ?>" maxlength="10"></div>
      </div>
      <h3 class="mb-4 mt-4">Document numbering</h3>
      <div class="form-row">
        <div class="form-group"><label>Sales order</label><input class="form-control mono" type="text" name="so_prefix" value="<?= $v('so_prefix', 'SO') ?>" maxlength="8"></div>
        <div class="form-group"><label>Delivery note</label><input class="form-control mono" type="text" name="dn_prefix" value="<?= $v('dn_prefix', 'DN') ?>" maxlength="8"></div>
        <div class="form-group"><label>Invoice</label><input class="form-control mono" type="text" name="inv_prefix" value="<?= $v('inv_prefix', 'INV') ?>" maxlength="8"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Purchase order</label><input class="form-control mono" type="text" name="po_prefix" value="<?= $v('po_prefix', 'PO') ?>" maxlength="8"></div>
        <div class="form-group"><label>Goods receipt</label><input class="form-control mono" type="text" name="grn_prefix" value="<?= $v('grn_prefix', 'GRN') ?>" maxlength="8"></div>
      </div>
    </div>
    <div class="grid">
      <div class="card">
        <h3 class="mb-4">Stock allocation &amp; expiry</h3>
        <div class="form-group"><label>Allocation policy</label>
          <div class="option-cards">
            <label class="option-card"><input type="radio" name="allocation_policy" value="fefo" <?= ($current['allocation_policy'] ?? 'fefo') === 'fefo' ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title">FEFO — First Expired, First Out</span><span class="oc-desc">Products with expiry dates ship the earliest-expiring batch first. Products without expiry use FIFO.</span></span></label>
            <label class="option-card"><input type="radio" name="allocation_policy" value="fifo" <?= ($current['allocation_policy'] ?? 'fefo') === 'fifo' ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title">FIFO — First In, First Out</span><span class="oc-desc">The batch that was received first always ships first, whatever its expiry.</span></span></label>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>“Upcoming” expiry warning (days)</label><input class="form-control" type="number" min="1" name="expiry_warning_days" value="<?= $v('expiry_warning_days', '90') ?>"></div>
          <div class="form-group"><label>“Expiring soon” critical (days)</label><input class="form-control" type="number" min="1" name="expiry_critical_days" value="<?= $v('expiry_critical_days', '30') ?>"></div>
        </div>
      </div>
      <div class="card">
        <h3 class="mb-4">Credit control &amp; approvals</h3>
        <label class="check mb-2"><input type="checkbox" name="credit_include_open_orders" <?= $chk('credit_include_open_orders', '1') ?>> Count confirmed, not-yet-invoiced orders towards a customer’s credit exposure</label><br>
        <label class="check mb-3"><input type="checkbox" name="flag_overdue_customers" <?= $chk('flag_overdue_customers', '1') ?>> Flag credit orders from customers who have overdue invoices</label>
        <div class="form-row">
          <div class="form-group"><label>Max discount a sales rep can give (%)</label><input class="form-control" type="number" step="0.5" min="0" max="100" name="max_rep_discount_pct" value="<?= $v('max_rep_discount_pct', '5') ?>"><div class="form-hint">Beyond this, the order needs a Sales Manager’s approval.</div></div>
          <div class="form-group"><label>Purchase orders need approval from (LKR)</label><input class="form-control" type="number" step="1000" min="0" name="po_approval_threshold" value="<?= $v('po_approval_threshold', '500000') ?>"><div class="form-hint">0 = never. Orders at or above this value go to Accounts for sign-off.</div></div>
        </div>
        <div class="form-group mb-0"><label>Supplier invoice match tolerance (%)</label><input class="form-control" type="number" step="0.1" min="0" max="100" name="invoice_match_tolerance_pct" value="<?= $v('invoice_match_tolerance_pct', '1') ?>"><div class="form-hint">Invoices within this % of the goods received are marked “matched”; others are flagged for review.</div></div>
      </div>
    </div>
  </div>
  <div class="form-actions"><button type="submit" class="btn btn-primary btn-lg"><?= icon('save', 16) ?> Save settings</button></div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
