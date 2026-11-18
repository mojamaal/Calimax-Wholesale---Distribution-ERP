<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('catalog.manage');

$pdo = db();
if (is_post()) {
    verify_csrf();
    $action = input($_POST, 'action'); $id = input_int($_POST, 'id'); $name = input($_POST, 'name');
    try {
        if ($action === 'save') {
            if ($name === '') throw new RuntimeException('Enter a category name.');
            $dup = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE name = :n AND id <> :id'); $dup->execute([':n' => $name, ':id' => $id]);
            if ((int) $dup->fetchColumn()) throw new RuntimeException('A category with that name already exists.');
            if ($id) { $pdo->prepare('UPDATE categories SET name = :n, description = :d WHERE id = :id')->execute([':n' => $name, ':d' => input($_POST, 'description') ?: null, ':id' => $id]); log_activity('category_update', "Renamed category to $name", 'category', $id); }
            else { $pdo->prepare('INSERT INTO categories (name, description) VALUES (:n,:d)')->execute([':n' => $name, ':d' => input($_POST, 'description') ?: null]); log_activity('category_create', "Created category $name"); }
            flash('success', 'Category saved.');
        } elseif ($action === 'delete') {
            $used = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id'); $used->execute([':id' => $id]);
            if ((int) $used->fetchColumn()) throw new RuntimeException('This category has products. Move them first.');
            $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute([':id' => $id]); log_activity('category_delete', "Deleted category #$id"); flash('success', 'Category deleted.');
        }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/catalog/categories.php');
}
$cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS n FROM categories c ORDER BY c.name')->fetchAll();
$pageTitle = 'Categories';
require __DIR__ . '/../includes/header.php';
page_header('Catalogue', 'Group products into categories for reporting and price lists.', '<button class="btn btn-primary" type="button" onclick="openCat()">' . icon('plus', 16) . ' New category</button>');
nav_catalog();
?>
<div class="card flush"><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Category</th><th>Description</th><th class="num">Products</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($cats as $c): ?>
    <tr><td class="strong"><a href="<?= e(app_url('/catalog/products.php?cat=' . $c['id'])) ?>"><?= e($c['name']) ?></a></td><td class="text-secondary"><?= e($c['description'] ?? '') ?></td><td class="num"><?= (int) $c['n'] ?></td>
      <td class="actions"><button class="btn btn-secondary btn-sm" type="button" onclick='openCat(<?= js_json(['id' => $c['id'], 'name' => $c['name'], 'description' => $c['description']]) ?>)'>Edit</button>
      <?php if (!(int) $c['n']): ?><form method="post" class="inline-form" data-confirm="Delete this category?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-danger-outline btn-sm" type="submit">Delete</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table><?php if (!$cats) echo empty_state('folder-tree', 'No categories yet'); ?></div></div>
<div class="modal-overlay" id="catModal" hidden><div class="modal sm">
  <div class="modal-header"><h3 id="cTitle">New category</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="c_id">
    <div class="form-group"><label>Name</label><input class="form-control" type="text" name="name" id="c_name" required maxlength="100"></div>
    <div class="form-group"><label>Description <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="description" id="c_description" maxlength="255"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form></div></div>
<script>function openCat(c){document.getElementById('cTitle').textContent=c?'Edit category':'New category';document.getElementById('c_id').value=c?c.id:'';document.getElementById('c_name').value=c?c.name:'';document.getElementById('c_description').value=c&&c.description?c.description:'';document.getElementById('catModal').hidden=false;document.getElementById('c_name').focus();}</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
