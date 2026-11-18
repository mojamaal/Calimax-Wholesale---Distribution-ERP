<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_permission('users.manage');

$pdo = db();
$errors = [];
$roles = role_definitions();
$me = (int) current_user()['id'];
$ownerCount = fn() => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner' AND is_active = 1")->fetchColumn();

if (is_post()) {
    verify_csrf();
    $action = input($_POST, 'action');
    $id = input_int($_POST, 'id');
    $target = null;
    if ($id) { $s = $pdo->prepare('SELECT * FROM users WHERE id = :id'); $s->execute([':id' => $id]); $target = $s->fetch(); }

    if ($action === 'create' || $action === 'update') {
        $username = input($_POST, 'username');
        $fullName = input($_POST, 'full_name');
        $email = input($_POST, 'email');
        $phone = input($_POST, 'phone');
        $role = isset($roles[input($_POST, 'role')]) ? input($_POST, 'role') : 'sales_rep';
        $password = (string) ($_POST['password'] ?? '');
        if ($fullName === '') $errors[] = 'Full name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
        if ($action === 'create') {
            if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) $errors[] = 'Username must be 3–50 characters (letters, numbers, . _ -).';
            if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
            $ex = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u'); $ex->execute([':u' => $username]);
            if ((int) $ex->fetchColumn()) $errors[] = 'That username is already taken.';
            if (!$errors) {
                $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, role, territory_id) VALUES (:u,:p,:f,:e,:ph,:r,:t)')
                    ->execute([':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT), ':f' => $fullName, ':e' => $email ?: null, ':ph' => $phone ?: null, ':r' => $role, ':t' => input_int($_POST, 'territory_id') ?: null]);
                log_activity('user_create', 'Created staff account @' . $username . ' (' . $fullName . ') as ' . role_label($role), 'user', (int) $pdo->lastInsertId());
                flash('success', $fullName . ' can now sign in as ' . role_label($role) . '.');
                redirect('/users.php');
            }
        } elseif ($target) {
            if ($target['role'] === 'owner' && $role !== 'owner' && $ownerCount() <= 1) $errors[] = 'There must be at least one active owner.';
            if ($id === $me && $role !== $target['role']) $errors[] = 'You cannot change your own role.';
            if (!$errors) {
                $pdo->prepare('UPDATE users SET full_name=:f, email=:e, phone=:ph, role=:r, territory_id=:t WHERE id=:id')->execute([':f' => $fullName, ':e' => $email ?: null, ':ph' => $phone ?: null, ':r' => $role, ':t' => input_int($_POST, 'territory_id') ?: null, ':id' => $id]);
                log_activity('user_update', 'Updated @' . $target['username'] . ($role !== $target['role'] ? ' · role ' . role_label($target['role']) . ' → ' . role_label($role) : ''), 'user', $id);
                flash('success', 'Staff member updated.');
                redirect('/users.php');
            }
        }
    } elseif ($action === 'toggle' && $target) {
        if ($id === $me) flash('error', 'You cannot deactivate your own account.');
        elseif ($target['role'] === 'owner' && $target['is_active'] && $ownerCount() <= 1) flash('error', 'You cannot deactivate the last owner.');
        else {
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = :id')->execute([':id' => $id]);
            log_activity('user_toggle', ($target['is_active'] ? 'Deactivated' : 'Reactivated') . ' @' . $target['username'], 'user', $id);
            flash('success', $target['full_name'] . ($target['is_active'] ? ' can no longer sign in.' : ' can sign in again.'));
        }
        redirect('/users.php');
    } elseif ($action === 'reset_password' && $target) {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) flash('error', 'Password must be at least 8 characters.');
        else {
            $pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
            log_activity('password_reset', 'Reset password for @' . $target['username'], 'user', $id);
            flash('success', 'Password updated for ' . $target['full_name'] . '.');
        }
        redirect('/users.php');
    }
}

$users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM sales_orders WHERE rep_id = u.id AND order_date >= CURDATE() - INTERVAL 30 DAY) AS sales30, t.name AS territory FROM users u LEFT JOIN territories t ON t.id = u.territory_id ORDER BY u.is_active DESC, FIELD(u.role,'owner','sales_manager','sales_rep','procurement','warehouse_manager','warehouse_staff','accountant'), u.full_name")->fetchAll();
$matrix = permission_matrix();
$territories = $pdo->query('SELECT id, name FROM territories ORDER BY name')->fetchAll();
$permLabels = [
    'Dashboard & management reports' => 'dashboard.view', 'Create sales orders' => 'sales.create', 'Approve orders (credit / discount exceptions)' => 'sales.approve', 'Change selling prices on orders' => 'sales.override_price',
    'Register customers' => 'customers.create', 'Set credit limits & payment terms' => 'customers.credit', 'Manage price lists' => 'pricing.manage', 'Pick, pack & dispatch orders' => 'delivery.fulfil',
    'Confirm deliveries' => 'delivery.confirm', 'Create invoices' => 'invoices.create', 'Record customer payments' => 'payments.receive', 'Issue credit notes' => 'creditnotes.issue',
    'Request customer returns' => 'returns.create', 'Approve returns' => 'returns.approve', 'Inspect returned goods' => 'returns.inspect', 'Add / edit products' => 'catalog.manage', 'See cost prices' => 'products.view_cost',
    'Adjust stock' => 'inventory.adjust', 'Approve stock adjustments & counts' => 'inventory.approve', 'Transfer stock between warehouses' => 'inventory.transfer', 'Quarantine & recall batches' => 'batches.manage',
    'Manage suppliers' => 'suppliers.manage', 'Create purchase orders' => 'purchase.create', 'Approve purchase orders' => 'purchase.approve', 'Receive goods' => 'purchase.receive', 'Supplier invoices & payments' => 'supplier.pay',
    'Finance, expenses & ledger' => 'finance.view', 'Financial statements' => 'reports.finance', 'Audit log' => 'audit.view', 'Staff accounts & settings' => 'users.manage',
];

$pageTitle = 'Staff & Roles';
require __DIR__ . '/includes/header.php';
page_header('Staff & Roles', 'Each person gets their own login, so every sale, refund and change is traceable to them.', '<button class="btn btn-primary" type="button" onclick="openUser()">' . icon('user-plus', 16) . ' Add staff member</button>');
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<div class="card flush mb-4"><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Contact</th><th>Territory</th><th class="num">Orders (30d)</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr class="<?= $u['is_active'] ? '' : 'row-muted' ?>">
      <td><div class="cell-product"><span class="avatar" style="width:30px;height:30px;font-size:12px;"><?= e(strtoupper(substr($u['full_name'], 0, 1))) ?></span><span class="strong"><?= e($u['full_name']) ?><?= (int) $u['id'] === $me ? ' <span class="badge badge-neutral">you</span>' : '' ?></span></div></td>
      <td class="mono text-secondary">@<?= e($u['username']) ?></td>
      <td><span class="badge badge-<?= in_array($u['role'], ['owner', 'sales_manager'], true) ? 'orange' : 'neutral' ?>"><?= e(role_label($u['role'])) ?></span></td>
      <td class="text-secondary text-sm"><?= e($u['phone'] ?? '') ?><?= $u['email'] ? '<div class="cell-sub">' . e($u['email']) . '</div>' : '' ?></td>
      <td class="text-secondary"><?= e($u['territory'] ?? '—') ?></td><td class="num"><?= (int) $u['sales30'] ?: '—' ?></td>
      <td class="text-secondary text-sm"><?= $u['last_login_at'] ? e(fmt_datetime($u['last_login_at'])) : 'Never' ?></td>
      <td><?= status_badge($u['is_active'] ? 'active' : 'inactive') ?></td>
      <td class="actions">
        <button class="btn btn-secondary btn-sm" type="button" onclick='openUser(<?= json_encode(['id' => $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'email' => $u['email'], 'phone' => $u['phone'], 'role' => $u['role'], 'territory_id' => $u['territory_id']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Edit</button>
        <button class="btn btn-secondary btn-sm" type="button" onclick="openReset(<?= (int) $u['id'] ?>, <?= e(json_encode($u['full_name'])) ?>)">Password</button>
        <?php if ((int) $u['id'] !== $me): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn btn-<?= $u['is_active'] ? 'danger-outline' : 'secondary' ?> btn-sm" type="submit"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button></form><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<div class="card flush">
  <div class="card-head"><div><h3>What each role can do</h3><div class="sub">Owners hold every permission. Exceptions such as credit-limit breaches and big discounts are routed to a Sales Manager for approval.</div></div></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Permission</th><?php foreach ($roles as $k => $r): ?><th class="num" title="<?= e($r['desc']) ?>" style="text-align:center;"><?= e($r['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($permLabels as $label => $perm): ?>
      <tr><td><?= e($label) ?></td><?php foreach ($roles as $k => $r): $has = $k === 'owner' || in_array($k, $matrix[$perm] ?? [], true); ?><td style="text-align:center;"><?= $has ? '<span class="text-success">' . icon('check', 16) . '</span>' : '<span class="text-muted">—</span>' ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="modal-overlay" id="userModal" <?= $errors ? '' : 'hidden' ?>>
  <div class="modal">
    <div class="modal-header"><h3 id="uTitle">Add staff member</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post" id="userForm">
      <?= csrf_field() ?><input type="hidden" name="action" id="u_action" value="create"><input type="hidden" name="id" id="u_id">
      <div class="form-group"><label>Full name</label><input class="form-control" type="text" name="full_name" id="u_full_name" required maxlength="120"></div>
      <div class="form-row">
        <div class="form-group"><label>Username</label><input class="form-control" type="text" name="username" id="u_username" maxlength="50" pattern="[a-zA-Z0-9._\-]{3,50}" autocomplete="off"></div>
        <div class="form-group"><label>Phone</label><input class="form-control" type="tel" name="phone" id="u_phone" maxlength="30"></div>
      </div>
      <div class="form-group"><label>Email <span class="label-opt">(optional)</span></label><input class="form-control" type="email" name="email" id="u_email" maxlength="150"></div>
      <div class="form-group"><label>Territory <span class="label-opt">(sales reps)</span></label><select class="form-control" name="territory_id" id="u_territory_id"><option value="">— None —</option><?php foreach ($territories as $tt): ?><option value="<?= (int) $tt['id'] ?>"><?= e($tt['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Role</label>
        <div class="option-cards"><?php foreach ($roles as $k => $r): ?><label class="option-card"><input type="radio" name="role" value="<?= $k ?>" <?= $k === 'sales_rep' ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title"><?= e($r['label']) ?></span><span class="oc-desc"><?= e($r['desc']) ?></span></span></label><?php endforeach; ?></div></div>
      <div class="form-group" id="pwGroup"><label>Initial password</label><input class="form-control" type="password" name="password" id="u_password" minlength="8" autocomplete="new-password"><div class="form-hint">At least 8 characters. Ask them to keep it private — it identifies them in the audit log.</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>
<div class="modal-overlay" id="resetModal" hidden>
  <div class="modal sm">
    <div class="modal-header"><h3>Reset password — <span id="resetName"></span></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" id="resetId">
      <div class="form-group"><label>New password</label><input class="form-control" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Update password</button></div></form>
  </div>
</div>
<script>
function openUser(u) {
  document.getElementById('userForm').reset();
  document.getElementById('uTitle').textContent = u ? 'Edit ' + u.full_name : 'Add staff member';
  document.getElementById('u_action').value = u ? 'update' : 'create';
  document.getElementById('u_id').value = u ? u.id : '';
  const un = document.getElementById('u_username'); un.readOnly = !!u; un.required = !u;
  document.getElementById('pwGroup').hidden = !!u; document.getElementById('u_password').required = !u;
  if (u) { ['full_name', 'username', 'email', 'phone', 'territory_id'].forEach((k) => { document.getElementById('u_' + k).value = u[k] || ''; });
    const r = document.querySelector('input[name=role][value="' + u.role + '"]'); if (r) r.checked = true; }
  document.getElementById('userModal').hidden = false;
}
function openReset(id, name) { document.getElementById('resetId').value = id; document.getElementById('resetName').textContent = name; document.getElementById('resetModal').hidden = false; }
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
