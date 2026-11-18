<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('trace.view');

$pdo = db();
$status = input($_GET, 'status'); $hl = input_int($_GET, 'highlight'); $q = input($_GET, 'q');
if (is_post() && can('warranty.manage')) {
    verify_csrf(); $a = input($_POST, 'action');
    try {
        if ($a === 'update') { warranty_claim_update(input_int($_POST, 'claim_id'), input($_POST, 'outcome'), input($_POST, 'resolution'), input_int($_POST, 'replacement_id') ?: null); flash('success', 'Warranty claim updated.'); }
        elseif ($a === 'new') {
            $x = $pdo->prepare('SELECT id FROM serial_numbers WHERE serial_no = :s'); $x->execute([':s' => input($_POST, 'serial_no')]); $sid = $x->fetchColumn();
            if (!$sid) throw new RuntimeException('That serial number is not registered.');
            $cid = warranty_claim_create((int) $sid, input($_POST, 'issue')); flash('success', 'Warranty claim recorded.'); redirect('/trace/warranty.php?highlight=' . $cid);
        }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/trace/warranty.php');
}
$where = '1=1'; $params = [];
if (in_array($status, ['open', 'in_repair', 'replaced', 'resolved', 'rejected'], true)) { $where .= ' AND wc.status = :s'; $params[':s'] = $status; }
if ($q !== '') { $where .= ' AND (sn.serial_no LIKE :q1 OR c.business_name LIKE :q2 OR wc.claim_no LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
$st = $pdo->prepare("SELECT wc.*, sn.serial_no, sn.warranty_expiry, sn.product_id, p.name, c.business_name, si.inv_no FROM warranty_claims wc JOIN serial_numbers sn ON sn.id = wc.serial_id JOIN products p ON p.id = sn.product_id LEFT JOIN customers c ON c.id = wc.customer_id LEFT JOIN sales_invoices si ON si.id = wc.invoice_id WHERE $where ORDER BY wc.status IN ('open','in_repair') DESC, wc.id DESC LIMIT 100");
$st->execute($params); $rows = $st->fetchAll();
$counts = []; foreach ($pdo->query('SELECT status, COUNT(*) n FROM warranty_claims GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];
// Warranties expiring soon (sold units)
$expiring = $pdo->query("SELECT sn.id, sn.serial_no, sn.warranty_expiry, p.name, c.business_name FROM serial_numbers sn JOIN products p ON p.id = sn.product_id LEFT JOIN customers c ON c.id = sn.customer_id WHERE sn.status = 'sold' AND sn.warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) ORDER BY sn.warranty_expiry LIMIT 8")->fetchAll();

$pageTitle = 'Warranty';
require __DIR__ . '/../includes/header.php';
page_header('Warranty management', 'Check a unit’s warranty by serial number — no more digging through paper invoices — and track repairs and replacements.', can('warranty.manage') ? '<button class="btn btn-primary" type="button" data-modal-open="#newClaim">' . icon('shield-plus', 16) . ' New claim</button>' : '');
nav_trace();
?>
<div class="grid grid-4 mb-4"><?= kpi('Open claims', (string) ($counts['open'] ?? 0), 'shield-alert', ($counts['open'] ?? 0) ? 'amber' : 'gray', 'Awaiting action') ?><?= kpi('In repair', (string) ($counts['in_repair'] ?? 0), 'wrench', 'blue') ?><?= kpi('Replaced', (string) ($counts['replaced'] ?? 0), 'repeat', 'green') ?><?= kpi('Warranties ending ≤ 60 days', (string) count($expiring), 'calendar-clock', 'purple', 'Sold units') ?></div>
<div class="layout-2-1">
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar"><div class="chip-group"><a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null])) ?>">All</a><?php foreach (['open' => 'Open', 'in_repair' => 'In repair', 'replaced' => 'Replaced', 'resolved' => 'Resolved', 'rejected' => 'Rejected'] as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k])) ?>"><?= $l ?> <span class="chip-count"><?= $counts[$k] ?? 0 ?></span></a><?php endforeach; ?></div><div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Serial, customer or claim no."></div></form></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Claim</th><th>Serial / product</th><th>Customer</th><th>Problem</th><th>Warranty</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $c): $open = in_array($c['status'], ['open', 'in_repair'], true); ?>
    <tr class="<?= $hl === (int) $c['id'] ? 'row-warn' : '' ?>"><td class="strong mono"><?= e($c['claim_no']) ?><div class="cell-sub"><?= e(fmt_date($c['created_at'])) ?></div></td><td><a class="link mono" href="<?= e(app_url('/trace/serial_view.php?id=' . $c['serial_id'])) ?>"><?= e($c['serial_no']) ?></a><div class="cell-sub"><?= e($c['name']) ?></div></td><td><?= e($c['business_name'] ?? '—') ?><?= $c['inv_no'] ? '<div class="cell-sub">' . e($c['inv_no']) . '</div>' : '' ?></td><td class="text-sm"><?= e($c['issue']) ?><?= $c['resolution'] ? '<div class="cell-sub">→ ' . e($c['resolution']) . '</div>' : '' ?></td><td><?= $c['in_warranty'] ? '<span class="badge badge-success">In warranty</span>' : '<span class="badge badge-error">Out of warranty</span>' ?></td><td><?= status_badge($c['status']) ?></td>
      <td class="actions"><?php if ($open && can('warranty.manage')): ?><button class="btn btn-primary btn-sm" type="button" onclick='updClaim(<?= js_json(['id' => $c['id'], 'no' => $c['claim_no'], 'product' => $c['product_id'], 'serial' => $c['serial_no']]) ?>)'>Update</button><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$rows) echo empty_state('shield-check', 'No warranty claims', 'Open a claim from a serial number’s page or with “New claim”.'); ?></div></div>
<div class="card"><div class="card-head"><h3>Warranties ending soon</h3></div><div class="list-rows"><?php foreach ($expiring as $x): ?><a href="<?= e(app_url('/trace/serial_view.php?id=' . $x['id'])) ?>"><span><span class="strong mono"><?= e($x['serial_no']) ?></span><div class="cell-sub"><?= e($x['business_name'] ?? '') ?> · <?= e($x['name']) ?></div></span><span class="badge badge-warning"><?= e(fmt_date($x['warranty_expiry'])) ?></span></a><?php endforeach; ?><?php if (!$expiring) echo '<div class="text-muted text-sm">None in the next 60 days.</div>'; ?></div></div>
</div>
<?php if (can('warranty.manage')): ?>
<div class="modal-overlay" id="newClaim" hidden><div class="modal sm"><div class="modal-header"><h3>New warranty claim</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="new"><div class="form-group"><label>Serial number</label><input class="form-control mono" type="text" name="serial_no" required placeholder="Scan or type the serial number"></div><div class="form-group"><label>Problem reported</label><input class="form-control" type="text" name="issue" required maxlength="255"></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Record claim</button></div></form></div></div>
<div class="modal-overlay" id="updModal" hidden><div class="modal sm"><div class="modal-header"><h3>Update <span id="uNo"></span></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="claim_id" id="uId">
  <div class="form-group"><label>Outcome</label><select class="form-control" name="outcome" id="uOut" onchange="document.getElementById('repRow').hidden=this.value!=='replaced'"><option value="in_repair">Sent for repair</option><option value="replaced">Replaced with a new unit</option><option value="resolved">Resolved (repaired / fixed)</option><option value="rejected">Rejected (not covered)</option></select></div>
  <div class="form-group" id="repRow" hidden><label>Replacement unit (available serial)</label><select class="form-control" name="replacement_id" id="uRep"></select></div>
  <div class="form-group"><label>Resolution notes</label><input class="form-control" type="text" name="resolution" maxlength="255"></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div></form></div></div>
<script>function updClaim(c){document.getElementById('uNo').textContent=c.no;document.getElementById('uId').value=c.id;var s=document.getElementById('uRep');s.innerHTML='<option value="">Loading…</option>';fetch(APP.url+'api/stock_lookup.php?product_id='+c.product+'&warehouse_id=1').then(function(r){return r.json()}).then(function(l){var p=l.find(function(x){return x.id==c.product});s.innerHTML=p?p.serials.filter(function(x){return x.status==='available'}).slice(0,60).map(function(x){return '<option value="'+x.id+'">'+x.serial_no+'</option>'}).join(''):'';if(!s.innerHTML)s.innerHTML='<option value="">No available unit in the main warehouse</option>';});document.getElementById('updModal').hidden=false;}</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
