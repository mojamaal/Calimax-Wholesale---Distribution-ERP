<?php
/** Print-friendly document shell used by sales/print.php and purchasing/print.php. */

function doc_start(string $title, string $docNo, string $docTitle, array $metaRows = [], string $back = ''): void
{
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?= e($docTitle . ' ' . $docNo) ?> · <?= e(setting('company_name', APP_NAME)) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/style.css')) ?>?v=6"><link rel="stylesheet" href="<?= e(app_url('/assets/css/erp.css')) ?>?v=1">
<style>body{background:#EEF0F4}@media print{@page{margin:14mm}body{background:#fff}}</style></head>
<body>
<div class="no-print" style="max-width:860px;margin:16px auto 0;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:0 4px;">
  <a class="btn btn-secondary btn-sm" id="backBtn" href="<?= e(app_url($back ?: '/index.php')) ?>">← Back</a><button class="btn btn-primary btn-sm" onclick="window.print()">Print / Save as PDF</button></div>
<script>document.getElementById('backBtn').addEventListener('click',function(e){if(history.length>1&&document.referrer){e.preventDefault();history.back();}});</script>
<div class="doc-page">
  <div class="doc-head">
    <div class="doc-co"><strong><?= e(setting('company_name', APP_NAME)) ?></strong><?= e(setting('company_address')) ?><br><?= e(setting('company_phone')) ?> · <?= e(setting('company_email')) ?><?php if (setting('tax_no')): ?><br><?= e(setting('tax_name', 'VAT')) ?> reg: <?= e(setting('tax_no')) ?><?php endif; ?></div>
    <div style="text-align:right;"><h1><?= e($docTitle) ?></h1><div style="font-size:15px;font-weight:700;margin-top:4px;"><?= e($docNo) ?></div>
    <?php foreach ($metaRows as $k => $v): ?><div style="font-size:12px;color:#5B6472;"><?= e($k) ?>: <strong style="color:#1F2328;"><?= e($v) ?></strong></div><?php endforeach; ?></div>
  </div>
<?php
}

function doc_end(string $footer = ''): void
{
    ?>
  <?php if ($footer): ?><div style="margin-top:24px;font-size:12px;color:#5B6472;border-top:1px solid #E4E7EC;padding-top:10px;"><?= $footer ?></div><?php endif; ?>
</div></body></html>
<?php
}
