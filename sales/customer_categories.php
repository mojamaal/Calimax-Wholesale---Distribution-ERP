<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('pricing.manage');

$pdo = db();
if (is_post()) {
    verify_csrf();
    $action = input($_POST, 'action'); $id = input_int($_POST, 'id'); $name = input($_POST, 'name');
    try {
        if ($action === 'save') {
            if ($name === '') throw new RuntimeException('Enter a category name.');
            $dup = $pdo->prepare('SELECT COUNT(*) FROM customer_categories WHERE name = :n AND id <> :id'); $dup->execute([':n' => $name, ':id' => $id]);
            if ((int) $dup->fetchColumn()) throw new RuntimeException('That category already exists.');
            if ($id) { $pdo->prepare('UPDATE customer_categories SET name=:n, description=:d WHERE id=:id')->execute([':n' => $name, ':d' => input($_POST, 'description') ?: null, ':id' => $id]); log_activity('price_list_update', "Renamed customer category to $name", 'customer_category', $id); }
            else { $pdo->prepare('INSERT INTO customer_categories (name, description) VALUES (:n,:d)')->execute([':n' => $name, ':d' => input($_POST, 'description') ?: null]); log_activity('price_list_update', "Created customer category $name — set its prices on the Price lists page"); }
            flash('success', 'Customer category saved.');
        } elseif ($action === 'delete') {
            $used = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE category_id = :id'); $used->execute([':id' => $id]);
            if ((int) $used->fetchColumn()) throw new RuntimeException('Customers still use this category.');
            $pdo->prepare('DELETE FROM customer_categories WHERE id = :id')->execute([':id' => $id]); log_activity('price_list_update', "Deleted customer category #$id"); flash('success', 'Category deleted.');
        }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/sales/customer_categories.php');
}
$cats = $pdo->query('SELECT cc.*, (SELECT COUNT(*) FROM customers c WHERE c.category_id = cc.id) AS n, (SELECT COUNT(*) FROM product_prices pp WHERE pp.category_id = cc.id) AS np FROM customer_categories cc ORDER BY cc.id')->fetchAll();
$pageTitle = 'Customer categories';
require __DIR__ . '/../includes/header.php';
page_header('Customers', 'Categories group customers who share a price list — for example small shops, supermarkets and dealers.', '<button class="btn btn-primary" type="button" onclick="openCat()">' . icon('plus', 16) . ' New category</button>');
nav_customers();
?>
<div class="card flush"><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Category</th><th>Description</th><th class="num">Customers</th><th class="num">Products priced</th><th></th></tr></thead>
  <tbody><?php foreach ($cats as $c): ?><tr><td class="strong"><?= e($c['name']) ?></td><td class="text-secondary"><?= e($c['description'] ?? '') ?></td><td class="num"><?= (int) $c['n'] ?></td><td class="num"><?= (int) $c['np'] ?></td>
    <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/catalog/pricing.php')) ?>">Edit prices</a><button class="btn btn-secondary btn-sm" type="button" onclick='openCat(<?= js_json(['id' => $c['id'], 'name' => $c['name'], 'description' => $c['description']]) ?>)'>Rename</button>
    <?php if (!(int) $c['n']): ?><form method="post" class="inline-form" data-confirm="Delete this category and its prices?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-danger-outline btn-sm" type="submit">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody>
</table></div></div>
<div class="modal-overlay" id="catModal" hidden><div class="modal sm">
  <div class="modal-header"><h3 id="cTitle">New customer category</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="c_id">
    <div class="form-group"><label>Name</label><input class="form-control" type="text" name="name" id="c_name" required maxlength="80"></div>
    <div class="form-group"><label>Description <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="description" id="c_description" maxlength="255"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form></div></div>
<script>function openCat(c){document.getElementById('cTitle').textContent=c?'Edit customer category':'New customer category';document.getElementById('c_id').value=c?c.id:'';document.getElementById('c_name').value=c?c.name:'';document.getElementById('c_description').value=c&&c.description?c.description:'';document.getElementById('catModal').hidden=false;document.getElementById('c_name').focus();}</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
