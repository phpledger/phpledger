<?php declare(strict_types=1);
/*
 * Shell-less print document. No sidebar, topbar, context strips, notices or navigation:
 * the printed page carries only the letterhead, the document and the format links, which
 * @media print removes. The workspace layout and its partials are never required here;
 * a print document is a standalone page.
 *
 * @var array $template  Resolved registry entry.
 * @var string $view     Absolute path of the document view.
 */
?>
<!doctype html>
<html lang="<?= pl_e(pl_locale()) ?>" dir="<?= pl_e(pl_text_direction()) ?>" data-print="<?= pl_e($template['type']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex">
    <title><?= pl_e($title) ?> · <?= pl_e($letterhead['name']) ?></title>
    <link rel="stylesheet" href="<?= pl_e(pl_url('/assets/app.css', ['v' => 'redesign-foundation'])) ?>">
</head>
<body class="print-body">
<div class="print-sheet paper-<?= pl_e($template['paper']) ?>">
    <header class="print-letterhead">
        <?php if ($letterhead['logo'] !== null): ?><img class="print-logo" src="<?= pl_e($letterhead['logo']['url']) ?>" alt="" width="<?= (int) $letterhead['logo']['width'] ?>" height="<?= (int) $letterhead['logo']['height'] ?>"><?php endif; ?>
        <div class="print-issuer">
            <p class="print-company"><?= pl_e($letterhead['name']) ?></p>
            <?php foreach ($letterhead['address'] ?? [] as $addressLine): ?><p class="print-company-meta"><?= pl_e($addressLine) ?></p><?php endforeach; ?>
            <?php $contact = trim(implode(' · ', array_filter([$letterhead['phone'] ?? '', $letterhead['email'] ?? '']))); ?>
            <?php if ($contact !== ''): ?><p class="print-company-meta"><?= pl_e($contact) ?></p><?php endif; ?>
            <?php if (($letterhead['registrations'] ?? '') !== ''): ?><p class="print-company-meta"><?= pl_e($letterhead['registrations']) ?></p><?php endif; ?>
            <p class="print-company-meta"><?= pl_e(trim($letterhead['book'] . ' · ' . $letterhead['currency'], ' ·')) ?></p>
        </div>
        <div class="print-doc-title">
            <p class="print-kind"><?= pl_e($template['label']) ?></p>
            <p class="print-reference"><?= pl_e($reference) ?></p>
        </div>
    </header>
    <?php require $view; ?>
    <footer class="print-footnote">
        <?php if (($letterhead['terms'] ?? '') !== ''): ?><p><?= pl_e($letterhead['terms']) ?></p><?php endif; ?>
        <p><?= pl_e(pl_t('This is a computer-generated document from {company}. Posted entries are preserved; corrections use a linked reversal.', ['company' => $letterhead['name']])) ?></p>
    </footer>
</div>
<nav class="print-controls" aria-label="<?= pl_e(pl_t('Print formats')) ?>">
    <?php foreach ($formats as $option): ?>
        <a class="print-control<?= $option['format'] === $template['format'] ? ' print-control-current' : '' ?>" href="<?= pl_e($option['url']) ?>"<?= $option['format'] === $template['format'] ? ' aria-current="page"' : '' ?>><?= pl_e($option['label']) ?></a>
    <?php endforeach; ?>
    <a class="print-control" href="<?= pl_e(pl_url($template['record'], ['id' => $recordId])) ?>"><?= pl_e(pl_t('Back to the record')) ?></a>
</nav>
</body>
</html>
