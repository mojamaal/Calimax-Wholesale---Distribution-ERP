# Calimax ERP — Trading, Wholesale & Distribution

PHP 8 + MySQL/MariaDB (XAMPP). Uses its **own database `calimax`** — it never reads or writes the `calipos` database.

## Install / reset

```
php database/install.php --force      # drops & rebuilds the calimax DB, loads the schema and demo data
php database/install.php --no-demo    # schema only (owner login: admin / admin123)
```

Open http://localhost/calimax/ — demo logins:

| User | Password | Role |
|------|----------|------|
| admin | admin123 | Owner / Director |
| nimal | demo123 | Sales Manager |
| kasun, dilani, ruwan | demo123 | Sales Representatives (Colombo / Gampaha / Kalutara) |
| sanduni | demo123 | Procurement |
| mahesh | demo123 | Warehouse Manager |
| pradeep | demo123 | Warehouse Staff |
| ayesha | demo123 | Accounts / Finance |

## Layout

- `includes/` — engines: `inventory.php` (stock, batches, serials, FEFO, transfers, recalls, warranty), `sales.php` (pricing, credit, orders, deliveries, invoices, collections, returns), `purchasing.php` (PO, receiving, 3-way match, payments), `accounting.php` (double-entry), `reporting.php`
- `catalog/ sales/ warehouse/ trace/ purchasing/ finance/ reports/ reps/` — screens
- `api/` — typeahead, price, credit and serial-check endpoints
- `database/schema.sql`, `install.php`, `seed_demo.php`

## About

A full-stack trading, wholesale and distribution ERP: multi-warehouse inventory, serial-number and batch/lot tracking (FEFO, expiry, recalls, warranty), purchase-to-pay and order-to-cash workflows with approvals, credit control, and a double-entry general ledger. Built with PHP 8, MySQL/MariaDB and vanilla JavaScript.

## License

Copyright (c) 2026 Mohammed Jamaal Rushdi. **All Rights Reserved.** This repository is published for viewing and portfolio purposes only. No permission is granted to use, copy, modify, distribute or deploy this software, especially for commercial purposes. See [LICENSE](LICENSE).
