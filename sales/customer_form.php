<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission(['customers.create', 'customers.edit']);

$pdo = db();
$id = input_int($_GET, 'id'); $c = null;
if ($id) {
    if (!can('customers.edit')) deny_access();
    $s = $pdo->prepare('SELECT * FROM customers WHERE id = :id'); $s->execute([':id' => $id]); $c = $s->fetch();
    if (!$c) { flash('error', 'Customer not found.'); redirect('/sales/customers.php'); }
}
$canCredit = can('customers.credit');
$cats = $pdo->query('SELECT id, name FROM customer_categories ORDER BY id')->fetchAll();
$reps = $pdo->query("SELECT id, full_name FROM users WHERE role = 'sales_rep' AND is_active = 1 ORDER BY full_name")->fetchAll();
$terrs = $pdo->query('SELECT id, name FROM territories ORDER BY name')->fetchAll();
$errors = [];

if (is_post()) {
    verify_csrf();
    $name = input($_POST, 'business_name');
    if ($name === '') $errors[] = 'Enter the business name.';
    $email = input($_POST, 'email'); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    $data = [':bn' => $name, ':cp' => input($_POST, 'contact_person') ?: null, ':ph' => input($_POST, 'phone') ?: null, ':em' => $email ?: null, ':ad' => input($_POST, 'address') ?: null, ':ci' => input($_POST, 'city') ?: null,
        ':tx' => input($_POST, 'tax_no') ?: null, ':cat' => input_int($_POST, 'category_id') ?: null, ':nt' => input($_POST, 'notes') ?: null];
    $repId = can('customers.edit') ? (input_int($_POST, 'rep_id') ?: null) : (int) current_user()['id'];
    $terr = input_int($_POST, 'territory_id') ?: null;
    $limit = $canCredit ? max(0, input_float($_POST, 'credit_limit')) : (float) ($c['credit_limit'] ?? 0);
    $terms = $canCredit ? max(0, input_int($_POST, 'payment_terms_days')) : (int) ($c['payment_terms_days'] ?? 0);
    if (!$errors) {
        try {
            if ($c) {
                $pdo->prepare('UPDATE customers SET business_name=:bn, contact_person=:cp, phone=:ph, email=:em, address=:ad, city=:ci, tax_no=:tx, category_id=:cat, notes=:nt, rep_id=:rep, territory_id=:t, credit_limit=:cl, payment_terms_days=:pt, is_active=:act WHERE id=:id')
                    ->execute($data + [':rep' => $repId, ':t' => $terr, ':cl' => $limit, ':pt' => $terms, ':act' => isset($_POST['is_active']) ? 1 : 0, ':id' => $c['id']]);
                $cid = (int) $c['id'];
                if ((float) $c['credit_limit'] !== $limit) log_change('credit_limit_change', 'Credit limit', format_money($c['credit_limit']), format_money($limit), 'customer', $cid, $name);
                if ((int) $c['payment_terms_days'] !== $terms) log_change('payment_terms_change', 'Payment terms', (int) $c['payment_terms_days'] . ' days', $terms . ' days', 'customer', $cid, $name);
                if ((int) $c['category_id'] !== (int) $data[':cat']) log_change('customer_update', 'Customer category', $c['category_id'], $data[':cat'], 'customer', $cid, $name);
                if ((int) $c['rep_id'] !== (int) $repId) log_change('customer_update', 'Sales rep (user id)', $c['rep_id'], $repId, 'customer', $cid, $name);
                log_activity('customer_update', "Updated customer $name", 'customer', $cid);
            } else {
                $code = 'C' . str_pad((string) ((int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(code, 2) AS UNSIGNED)),0) FROM customers WHERE code REGEXP '^C[0-9]+$'")->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
                $pdo->prepare('INSERT INTO customers (code, business_name, contact_person, phone, email, address, city, tax_no, category_id, notes, rep_id, territory_id, credit_limit, payment_terms_days, created_by) VALUES (:code,:bn,:cp,:ph,:em,:ad,:ci,:tx,:cat,:nt,:rep,:t,:cl,:pt,:u)')
                    ->execute($data + [':code' => $code, ':rep' => $repId, ':t' => $terr, ':cl' => $limit, ':pt' => $terms, ':u' => current_user()['id']]);
                $cid = (int) $pdo->lastInsertId();
                log_activity('customer_create', "Created customer $name ($code)" . ($limit > 0 ? ' · credit limit ' . format_money($limit) : ''), 'customer', $cid);
            }
            flash('success', "$name saved." . (!$canCredit && !$c ? ' Credit terms can be set by a Sales Manager — until then orders are cash only.' : ''));
            redirect('/sales/customer_view.php?id=' . $cid);
        } catch (Throwable $e) { error_log($e->getMessage()); $errors[] = 'Could not save the customer.'; }
    }
    $c = array_merge($c ?? [], ['business_name' => $name, 'contact_person' => input($_POST, 'contact_person'), 'phone' => input($_POST, 'phone'), 'email' => $email, 'address' => input($_POST, 'address'), 'city' => input($_POST, 'city'), 'tax_no' => input($_POST, 'tax_no'),
        'category_id' => $data[':cat'], 'notes' => input($_POST, 'notes'), 'rep_id' => $repId, 'territory_id' => $terr, 'credit_limit' => $limit, 'payment_terms_days' => $terms, 'is_active' => isset($_POST['is_active']) ? 1 : 0]);
}
$v = fn($k, $d = '') => e((string) ($c[$k] ?? $d));
$editing = $c && isset($c['id']);
$pageTitle = $editing ? 'Edit ' . $c['business_name'] : 'New customer';
require __DIR__ . '/../includes/header.php';
page_header($editing ? 'Edit customer' : 'New customer', 'A business account: who they are, who looks after them and how they pay.', '<a class="btn btn-secondary" href="' . e(app_url($editing ? '/sales/customer_view.php?id=' . $c['id'] : '/sales/customers.php')) . '">Cancel</a>', [['Customers', '/sales/customers.php'], [$editing ? $c['business_name'] : 'New customer', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post"><?= csrf_field() ?>
  <div class="grid grid-2 mb-4">
    <div class="card">
      <h3 class="mb-4">Business details</h3>
      <div class="form-group"><label>Business name</label><input class="form-control" type="text" name="business_name" value="<?= $v('business_name') ?>" required maxlength="150" autofocus></div>
      <div class="form-row"><div class="form-group"><label>Contact person</label><input class="form-control" type="text" name="contact_person" value="<?= $v('contact_person') ?>" maxlength="100"></div><div class="form-group"><label>Telephone</label><input class="form-control" type="tel" name="phone" value="<?= $v('phone') ?>" maxlength="30"></div></div>
      <div class="form-group"><label>Email <span class="label-opt">(optional)</span></label><input class="form-control" type="email" name="email" value="<?= $v('email') ?>" maxlength="150"></div>
      <div class="form-group"><label>Address</label><input class="form-control" type="text" name="address" value="<?= $v('address') ?>" maxlength="255"></div>
      <div class="form-row"><div class="form-group"><label>City / town</label><input class="form-control" type="text" name="city" value="<?= $v('city') ?>" maxlength="80"></div><div class="form-group"><label>Tax / VAT no. <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="tax_no" value="<?= $v('tax_no') ?>" maxlength="40"></div></div>
      <div class="form-group mb-0"><label>Notes <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="notes" value="<?= $v('notes') ?>" maxlength="255"></div>
    </div>
    <div class="grid">
      <div class="card">
        <h3 class="mb-4">Classification</h3>
        <div class="form-group"><label>Customer category <span class="label-opt">(decides the price list)</span></label><select class="form-control" name="category_id"><option value="">—</option><?php foreach ($cats as $k): ?><option value="<?= (int) $k['id'] ?>" <?= (int) ($c['category_id'] ?? 0) === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-row">
          <div class="form-group"><label>Assigned sales rep</label><select class="form-control" name="rep_id" <?= can('customers.edit') ? '' : 'disabled' ?>><option value="">— Unassigned —</option><?php foreach ($reps as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) ($c['rep_id'] ?? (can('customers.edit') ? 0 : current_user()['id'])) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['full_name']) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Territory</label><select class="form-control" name="territory_id"><option value="">—</option><?php foreach ($terrs as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) ($c['territory_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        </div>
      </div>
      <div class="card">
        <h3 class="mb-2">Credit &amp; payment terms</h3>
        <?php if (!$canCredit): ?><div class="callout mb-3">Credit limits and payment terms are set by a Sales Manager. New customers buy on cash terms until then.</div><?php endif; ?>
        <div class="form-row">
          <div class="form-group"><label>Credit limit (<?= e(setting('currency_symbol', 'Rs.')) ?>)</label><input class="form-control" type="number" step="1000" min="0" name="credit_limit" value="<?= $v('credit_limit', '0') ?>" <?= $canCredit ? '' : 'readonly' ?>><div class="form-hint">0 = no credit facility (cash orders only).</div></div>
          <div class="form-group"><label>Payment terms (days)</label><input class="form-control" type="number" min="0" name="payment_terms_days" value="<?= $v('payment_terms_days', '0') ?>" <?= $canCredit ? '' : 'readonly' ?>><div class="form-hint">Invoices fall due after this many days. 0 = cash on delivery.</div></div>
        </div>
        <?php if ($editing): ?><label class="check mt-2"><input type="checkbox" name="is_active" <?= !empty($c['is_active']) ? 'checked' : '' ?>> Customer is active</label><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="action-bar"><span class="text-muted text-sm">Credit limit and terms changes are recorded in the audit log.</span><button type="submit" class="btn btn-primary btn-lg"><?= icon('save', 16) ?> Save customer</button></div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
