<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('suppliers.view');

$pdo = db();
if (is_post() && can('suppliers.manage')) {
    verify_csrf();
    $id = input_int($_POST, 'id'); $name = input($_POST, 'name');
    try {
        if ($name === '') throw new RuntimeException('Enter the supplier name.');
        $email = input($_POST, 'email'); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
        $v = [':n' => $name, ':cp' => input($_POST, 'contact_person') ?: null, ':ph' => input($_POST, 'phone') ?: null, ':em' => $email ?: null, ':ad' => input($_POST, 'address') ?: null, ':tx' => input($_POST, 'tax_no') ?: null, ':pt' => max(0, input_int($_POST, 'payment_terms_days', 30)), ':nt' => input($_POST, 'notes') ?: null];
        if ($id) { $old = $pdo->prepare('SELECT payment_terms_days FROM suppliers WHERE id = :id'); $old->execute([':id' => $id]); $ot = $old->fetchColumn();
            $pdo->prepare('UPDATE suppliers SET name=:n, contact_person=:cp, phone=:ph, email=:em, address=:ad, tax_no=:tx, payment_terms_days=:pt, notes=:nt, is_active=:a WHERE id=:id')->execute($v + [':a' => isset($_POST['is_active']) ? 1 : 0, ':id' => $id]);
            log_activity('supplier_update', "Updated supplier $name" . ((int) $ot !== $v[':pt'] ? " · terms $ot → {$v[':pt']} days" : ''), 'supplier', $id); }
        else { $code = 'SUP' . str_pad((string) ((int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)),0) FROM suppliers")->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO suppliers (code, name, contact_person, phone, email, address, tax_no, payment_terms_days, notes) VALUES (:c,:n,:cp,:ph,:em,:ad,:tx,:pt,:nt)')->execute($v + [':c' => $code]); log_activity('supplier_create', "Created supplier $name ($code)", 'supplier', (int) $pdo->lastInsertId()); }
        flash('success', 'Supplier saved.');
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/purchasing/suppliers.php');
}
$q = input($_GET, 'q'); $show = input($_GET, 'show');
$where = $show === 'inactive' ? 's.is_active = 0' : 's.is_active = 1'; $params = [];
if ($q !== '') { $where .= ' AND (s.name LIKE :q1 OR s.code LIKE :q2 OR s.contact_person LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
$st = $pdo->prepare("SELECT s.*, (COALESCE((SELECT SUM(total) FROM supplier_invoices WHERE supplier_id = s.id AND status <> 'void'),0) - COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE supplier_id = s.id),0)) AS balance,
    COALESCE((SELECT SUM(total - paid_amount) FROM supplier_invoices WHERE supplier_id = s.id AND status IN ('unpaid','partial') AND due_date < CURDATE()),0) AS overdue,
    (SELECT MIN(due_date) FROM supplier_invoices WHERE supplier_id = s.id AND status IN ('unpaid','partial')) AS next_due, (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.id AND status IN ('approved','sent','partially_received')) AS open_pos
    FROM suppliers s WHERE $where ORDER BY s.name"); $st->execute($params); $rows = $st->fetchAll();
$pageTitle = 'Suppliers';
require __DIR__ . '/../includes/header.php';
page_header('Suppliers', 'Manufacturers and importers you buy from, what you owe each one and when it is due.', can('suppliers.manage') ? '<button class="btn btn-primary" type="button" onclick="openSup()">' . icon('plus', 16) . ' New supplier</button>' : '');
nav_purchasing();
?>
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar"><div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, code or contact"></div><div class="chip-group"><a class="chip <?= $show !== 'inactive' ? 'active' : '' ?>" href="<?= e(query_with(['show' => null])) ?>">Active</a><a class="chip <?= $show === 'inactive' ? 'active' : '' ?>" href="<?= e(query_with(['show' => 'inactive'])) ?>">Inactive</a></div></form></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Supplier</th><th>Contact</th><th>Terms</th><th class="num">Open POs</th><th class="num">We owe</th><th class="num">Overdue</th><th>Next due</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $s): ?><tr class="<?= $s['is_active'] ? '' : 'row-muted' ?>"><td><a class="strong" href="<?= e(app_url('/purchasing/supplier_view.php?id=' . $s['id'])) ?>"><?= e($s['name']) ?></a><div class="cell-sub mono"><?= e($s['code']) ?></div></td><td class="text-sm text-secondary"><?= e($s['contact_person'] ?? '') ?><div class="cell-sub"><?= e($s['phone'] ?? '') ?></div></td><td class="text-secondary"><?= (int) $s['payment_terms_days'] ?> days</td><td class="num"><?= (int) $s['open_pos'] ?: '—' ?></td><td class="num strong"><?= $s['balance'] > 0.005 ? format_money($s['balance']) : '—' ?></td><td class="num <?= $s['overdue'] > 0 ? 'text-error strong' : 'text-muted' ?>"><?= $s['overdue'] > 0 ? format_money($s['overdue']) : '—' ?></td><td class="text-secondary nowrap"><?= e(fmt_date($s['next_due'])) ?></td>
    <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/purchasing/supplier_view.php?id=' . $s['id'])) ?>">View</a><?php if (can('suppliers.manage')): ?><button class="btn btn-secondary btn-sm" type="button" onclick='openSup(<?= js_json($s) ?>)'>Edit</button><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$rows) echo empty_state('building-2', 'No suppliers', 'Register the manufacturers and importers you buy from.'); ?></div></div>
<?php if (can('suppliers.manage')): ?>
<div class="modal-overlay" id="supModal" hidden><div class="modal"><div class="modal-header"><h3 id="sTitle">New supplier</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" id="s_id">
    <div class="form-group"><label>Supplier name</label><input class="form-control" type="text" name="name" id="s_name" required maxlength="150"></div>
    <div class="form-row"><div class="form-group"><label>Contact person</label><input class="form-control" type="text" name="contact_person" id="s_contact_person" maxlength="100"></div><div class="form-group"><label>Telephone</label><input class="form-control" type="tel" name="phone" id="s_phone" maxlength="30"></div></div>
    <div class="form-row"><div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" id="s_email" maxlength="150"></div><div class="form-group"><label>Payment terms (days)</label><input class="form-control" type="number" min="0" name="payment_terms_days" id="s_payment_terms_days" value="30"></div></div>
    <div class="form-group"><label>Address</label><input class="form-control" type="text" name="address" id="s_address" maxlength="255"></div>
    <div class="form-row"><div class="form-group"><label>Tax / VAT no.</label><input class="form-control" type="text" name="tax_no" id="s_tax_no" maxlength="40"></div><div class="form-group"><label>Notes</label><input class="form-control" type="text" name="notes" id="s_notes" maxlength="255"></div></div>
    <label class="check" id="s_activeRow" hidden><input type="checkbox" name="is_active" id="s_active" checked> Supplier is active</label>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save supplier</button></div></form></div></div>
<script>function openSup(s){document.getElementById('sTitle').textContent=s?'Edit supplier':'New supplier';['id','name','contact_person','phone','email','payment_terms_days','address','tax_no','notes'].forEach(function(k){var el=document.getElementById('s_'+k);el.value=s&&s[k]!=null?s[k]:(k==='payment_terms_days'?30:'');});document.getElementById('s_activeRow').hidden=!s;document.getElementById('s_active').checked=s?!!+s.is_active:true;document.getElementById('supModal').hidden=false;document.getElementById('s_name').focus();}</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
