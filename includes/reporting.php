<?php
/**
 * Shared reporting queries for the dashboard, reports and alerts.
 */

/** SQL expression: saleable stock of product alias $p (optionally one warehouse id literal). */
function sql_saleable(string $p = 'p', ?int $wh = null): string
{
    $w = $wh ? ' AND s.warehouse_id = ' . (int) $wh : '';
    return "(SELECT COALESCE(SUM(s.on_hand),0) FROM stock s LEFT JOIN batches b ON b.id = s.batch_id WHERE s.product_id = $p.id $w AND (s.batch_id = 0 OR (b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE()))))";
}

function sql_reserved(string $p = 'p', ?int $wh = null): string
{
    $w = $wh ? ' AND so.warehouse_id = ' . (int) $wh : '';
    return "(SELECT COALESCE(SUM(soi.qty - soi.qty_dispatched - soi.qty_cancelled),0) FROM sales_order_items soi JOIN sales_orders so ON so.id = soi.so_id WHERE soi.product_id = $p.id AND so.status IN ('confirmed','partially_fulfilled') $w)";
}

/** Products whose available stock is at or below the reorder level. */
function low_stock_products(int $limit = 0, ?int $wh = null): array
{
    $sql = 'SELECT p.id, p.code, p.name, p.base_unit, p.reorder_level, ' . sql_saleable('p', $wh) . ' AS saleable, ' . sql_reserved('p', $wh) . ' AS reserved
            FROM products p WHERE p.is_active = 1 AND p.reorder_level > 0 HAVING (saleable - reserved) <= p.reorder_level ORDER BY (saleable - reserved) ASC, p.name' . ($limit ? ' LIMIT ' . (int) $limit : '');
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$r) $r['available'] = (int) $r['saleable'] - (int) $r['reserved'];
    return $rows;
}

/** Batches holding stock that expire within $days (or are already expired). */
function expiring_batches(int $days, ?int $wh = null, int $limit = 0): array
{
    $w = $wh ? ' AND s.warehouse_id = ' . (int) $wh : '';
    $sql = "SELECT b.*, p.name AS product_name, p.code, p.base_unit, SUM(s.on_hand) AS on_hand, SUM(s.quarantine) AS quarantine,
            DATEDIFF(b.expiry_date, CURDATE()) AS days_left, sup.name AS supplier_name
            FROM batches b JOIN products p ON p.id = b.product_id JOIN stock s ON s.batch_id = b.id LEFT JOIN suppliers sup ON sup.id = b.supplier_id
            WHERE b.expiry_date IS NOT NULL AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . (int) $days . " DAY) $w
            GROUP BY b.id HAVING (on_hand + quarantine) > 0 ORDER BY b.expiry_date" . ($limit ? ' LIMIT ' . (int) $limit : '');
    return db()->query($sql)->fetchAll();
}

/**
 * WHERE fragment for invoice-based sales reporting. $f: warehouse_id, customer_id, rep_id, product_id.
 * Uses aliases si (sales_invoices) and sii (sales_invoice_items).
 */
function sales_scope(array $f, array &$params, string $from, string $to): string
{
    $w = "si.status <> 'void' AND si.invoice_date BETWEEN :sf AND :st";
    $params[':sf'] = $from; $params[':st'] = $to;
    if (!empty($f['warehouse_id'])) { $w .= ' AND si.warehouse_id = :swh'; $params[':swh'] = (int) $f['warehouse_id']; }
    if (!empty($f['customer_id'])) { $w .= ' AND si.customer_id = :scu'; $params[':scu'] = (int) $f['customer_id']; }
    if (!empty($f['rep_id']))      { $w .= ' AND si.rep_id = :srp'; $params[':srp'] = (int) $f['rep_id']; }
    if (!empty($f['product_id']))  { $w .= ' AND sii.product_id = :spr'; $params[':spr'] = (int) $f['product_id']; }
    return $w;
}

function sales_totals(array $f, string $from, string $to): array
{
    $p = [];
    $w = sales_scope($f, $p, $from, $to);
    $s = db()->prepare("SELECT COUNT(DISTINCT si.id) AS invoices, COUNT(DISTINCT si.customer_id) AS customers, COALESCE(SUM(sii.line_net),0) AS net, COALESCE(SUM(sii.tax_amount),0) AS tax,
        COALESCE(SUM(sii.line_total),0) AS total, COALESCE(SUM(sii.qty * sii.unit_cost),0) AS cogs
        FROM sales_invoice_items sii JOIN sales_invoices si ON si.id = sii.invoice_id WHERE $w");
    $s->execute($p);
    $r = $s->fetch();
    $r['gross_profit'] = (float) $r['net'] - (float) $r['cogs'];
    return $r;
}

/** Group sales by customer | product | rep | warehouse | day | month | category. */
function sales_grouped(string $dim, array $f, string $from, string $to, int $limit = 0): array
{
    $p = [];
    $w = sales_scope($f, $p, $from, $to);
    $map = [
        'customer'  => ['si.customer_id', 'c.business_name', 'JOIN customers c ON c.id = si.customer_id'],
        'product'   => ['sii.product_id', "CONCAT(pr.name)", 'JOIN products pr ON pr.id = sii.product_id'],
        'rep'       => ['COALESCE(si.rep_id,0)', "COALESCE(u.full_name,'Unassigned')", 'LEFT JOIN users u ON u.id = si.rep_id'],
        'warehouse' => ['COALESCE(si.warehouse_id,0)', "COALESCE(wh.name,'—')", 'LEFT JOIN warehouses wh ON wh.id = si.warehouse_id'],
        'day'       => ['si.invoice_date', 'si.invoice_date', ''],
        'month'     => ["DATE_FORMAT(si.invoice_date,'%Y-%m')", "DATE_FORMAT(si.invoice_date,'%Y-%m')", ''],
        'category'  => ['COALESCE(pr.category_id,0)', "COALESCE(cat.name,'Uncategorised')", 'JOIN products pr ON pr.id = sii.product_id LEFT JOIN categories cat ON cat.id = pr.category_id'],
    ];
    [$key, $label, $join] = $map[$dim];
    $order = in_array($dim, ['day', 'month'], true) ? 'k' : 'net DESC';
    $s = db()->prepare("SELECT $key AS k, $label AS label, COUNT(DISTINCT si.id) AS invoices, COALESCE(SUM(sii.qty),0) AS qty, COALESCE(SUM(sii.line_net),0) AS net, COALESCE(SUM(sii.line_total),0) AS total,
        COALESCE(SUM(sii.qty * sii.unit_cost),0) AS cogs FROM sales_invoice_items sii JOIN sales_invoices si ON si.id = sii.invoice_id $join WHERE $w GROUP BY k, label ORDER BY $order" . ($limit ? ' LIMIT ' . (int) $limit : ''));
    $s->execute($p);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) $r['profit'] = (float) $r['net'] - (float) $r['cogs'];
    return $rows;
}

/** Customer money received in a period (optionally for a rep / customer). */
function collections_total(string $from, string $to, array $f = []): float
{
    $w = 'pay_date BETWEEN :f AND :t'; $p = [':f' => $from, ':t' => $to];
    if (!empty($f['customer_id'])) { $w .= ' AND customer_id = :c'; $p[':c'] = (int) $f['customer_id']; }
    if (!empty($f['rep_id'])) { $w .= ' AND rep_id = :r'; $p[':r'] = (int) $f['rep_id']; }
    $s = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE $w");
    $s->execute($p);
    return (float) $s->fetchColumn();
}

function receivables_total(): float
{
    return (float) db()->query("SELECT COALESCE(SUM(total - paid_amount - credited_amount),0) FROM sales_invoices WHERE status <> 'void'")->fetchColumn();
}

function receivables_overdue(): float
{
    return (float) db()->query("SELECT COALESCE(SUM(total - paid_amount - credited_amount),0) FROM sales_invoices WHERE status IN ('unpaid','partial') AND due_date < CURDATE()")->fetchColumn();
}

function payables_total(): float
{
    return (float) db()->query("SELECT COALESCE(SUM(total),0) - (SELECT COALESCE(SUM(amount),0) FROM supplier_payments) FROM supplier_invoices WHERE status <> 'void'")->fetchColumn();
}

function payables_overdue(): float
{
    return (float) db()->query("SELECT COALESCE(SUM(total - paid_amount),0) FROM supplier_invoices WHERE status IN ('unpaid','partial') AND due_date < CURDATE()")->fetchColumn();
}
