<?php
declare(strict_types=1);

/**
 * Shared compact quantity/price editor. Row changes work through POST without JS.
 *
 * `$trading` turns on the 1.2 M3 columns for a customer document: a pack with its pack and
 * unit entry, a line discount percentage with the resulting amount shown beside it, and a
 * free-goods flag. It is empty for every other caller, so the bill and purchase editors keep
 * exactly the columns they had.
 */
function pl_ui_commercial_lines(array $rows, array $options, bool $credit = false, bool $purchase = false, array $errors = [], array $trading = []): void
{
    $rows = $rows === [] ? [[]] : array_values($rows);
    $packs = $trading['packs'] ?? [];
    $tradingOn = ($trading['enabled'] ?? false) && !$purchase;
    $packOptions = [];
    foreach ($packs as $pack) {
        $packOptions[(string) $pack['id']] = $pack['code'] . ' · ' . $pack['name'] . ' (' . rtrim(rtrim((string) $pack['units_per_pack'], '0'), '.') . ')';
    }
    if ($tradingOn && $packOptions !== []) { $options['pack_id'] = $packOptions; }
    $fields = ['description' => pl_t('Description'), 'product_id' => pl_t('Product')];
    if (!$purchase) { $fields['account_id'] = pl_t('Account'); }
    if ($credit) { $fields['original_line_number'] = pl_t('Original line'); }
    if ($tradingOn && $packOptions !== []) { $fields['pack_id'] = pl_t('Pack'); $fields['pack_quantity'] = pl_t('Packs'); $fields['unit_quantity'] = pl_t('Units'); }
    $fields += ['quantity' => pl_t('Qty'), 'unit_price' => pl_t('Unit price')];
    if ($tradingOn) { $fields['discount_percent'] = pl_t('Disc %'); }
    if (!$purchase) { $fields['tax_code_id'] = pl_t('Tax code'); }
    $numeric = ['quantity', 'unit_price', 'pack_quantity', 'unit_quantity', 'discount_percent'];
    $widths = $purchase ? ['description' => 'w-[35%]', 'product_id' => 'w-[22%]', 'quantity' => 'w-[10%]', 'unit_price' => 'w-[14%]']
        : ($tradingOn
            ? ['description' => $credit ? 'w-[16%]' : 'w-[20%]', 'product_id' => 'w-[12%]', 'account_id' => 'w-[11%]', 'original_line_number' => 'w-[6%]',
                'pack_id' => 'w-[11%]', 'pack_quantity' => 'w-[6%]', 'unit_quantity' => 'w-[6%]', 'quantity' => 'w-[6%]', 'unit_price' => 'w-[8%]',
                'discount_percent' => 'w-[6%]', 'tax_code_id' => 'w-[8%]']
            : ['description' => $credit ? 'w-[24%]' : 'w-[31%]', 'product_id' => 'w-[14%]', 'account_id' => 'w-[14%]', 'original_line_number' => 'w-[7%]', 'quantity' => 'w-[7%]', 'unit_price' => 'w-[10%]', 'tax_code_id' => 'w-[10%]']);
    ?>
    <div class="table-wrap relative" tabindex="0" role="region" aria-label="<?= pl_e(pl_t('Document lines')) ?>">
    <table class="table doc-lines-table"><caption class="sr-only"><?= pl_e(pl_t('Document lines')) ?></caption><thead><tr>
    <?php foreach ($fields as $name => $label): ?><th scope="col" class="<?= $widths[$name] ?? '' ?><?= in_array($name, $numeric, true) ? ' text-end' : '' ?>"><?= pl_e($label) ?></th><?php endforeach; ?>
    <?php if ($tradingOn): ?><th scope="col" class="w-[6%]"><?= pl_e(pl_t('Free')) ?></th><?php endif; ?>
    <th scope="col" class="text-end <?= $purchase ? 'w-[15%]' : ($tradingOn ? 'w-[10%]' : 'w-[10%]') ?>"><?= pl_e(pl_t('Entered amount')) ?></th><th scope="col" class="w-[4%]"><span class="sr-only"><?= pl_e(pl_t('Actions')) ?></span></th></tr></thead><tbody data-commercial-rows>
    <?php foreach ($rows as $index => $raw): $line = is_array($raw) ? $raw : []; $isFree = $tradingOn && (string) ($line['is_free_goods'] ?? '') === '1'; ?>
    <tr data-commercial-row<?= $isFree ? ' class="doc-line-free" data-commercial-free' : '' ?>>
    <?php foreach ($fields as $name => $label): $id = 'commercial-' . $index . '-' . $name; $error = $errors['lines.' . $index . '.' . $name] ?? ''; ?>
    <td><label class="sr-only" for="<?= $id ?>" data-commercial-label="<?= pl_e($label) ?>"><?= pl_e($label . ', ' . pl_t('line') . ' ' . ($index + 1)) ?></label>
    <?php if (isset($options[$name])): ?><select class="select" id="<?= $id ?>"<?= pl_ui_error_attributes($id, $error) ?> name="lines[<?= $index ?>][<?= $name ?>]" data-commercial-field="<?= $name ?>"><option value=""><?= pl_e(pl_t('Choose…')) ?></option>
    <?php if (($line[$name] ?? '') !== '' && $line[$name] !== null && !isset($options[$name][$line[$name]])): ?><option value="<?= pl_e((string) $line[$name]) ?>" selected><?= pl_e(pl_t('Unavailable selection')) ?> <?= pl_e((string) $line[$name]) ?> — <?= pl_e(pl_t('choose another')) ?></option><?php endif; ?>
    <?php foreach ($options[$name] as $value => $text): ?><option value="<?= pl_e((string) $value) ?>"<?= (string) ($line[$name] ?? '') === (string) $value ? ' selected' : '' ?>><?= pl_e($text) ?></option><?php endforeach; ?></select>
    <?php else: ?><input class="input<?= in_array($name, $numeric, true) ? ' input-amount' : '' ?>" id="<?= $id ?>"<?= pl_ui_error_attributes($id, $error) ?> name="lines[<?= $index ?>][<?= $name ?>]" data-commercial-field="<?= $name ?>" value="<?= pl_e((string) ($line[$name] ?? ($name === 'discount_percent' ? '' : ''))) ?>" maxlength="<?= $name === 'description' ? 500 : 21 ?>"<?= $name === 'description' ? '' : ' inputmode="decimal"' ?>><?php endif; ?><?php if ($error !== ''): ?><p class="field-error-text" id="<?= $id ?>-error"><?= pl_e($error) ?></p><?php endif; ?></td>
    <?php endforeach; ?>
    <?php if ($tradingOn): $freeId = 'commercial-' . $index . '-is_free_goods'; ?>
    <?php // Same shape as every other cell — a sr-only label immediately before its control —
          // because app.js renumbers a cloned row through each control's previousElementSibling. ?>
    <td><label class="sr-only" for="<?= $freeId ?>" data-commercial-label="<?= pl_e(pl_t('Free goods')) ?>"><?= pl_e(pl_t('Free goods') . ', ' . pl_t('line') . ' ' . ($index + 1)) ?></label><input type="checkbox" id="<?= $freeId ?>" name="lines[<?= $index ?>][is_free_goods]" value="1"<?= $isFree ? ' checked' : '' ?> data-commercial-field="is_free_goods" data-commercial-flag></td>
    <?php endif; ?>
    <?php
    $amount = '—'; $discountNote = '';
    try {
        if ($isFree) {
            $amount = pl_money('0');
            $discountNote = pl_t('Bonus, not charged');
        } else {
            $gross = pl_ar_line_amount((string) ($line['quantity'] ?? ''), (string) ($line['unit_price'] ?? ''));
            if ($tradingOn) {
                $split = pl_trading_line_discount($gross, (string) ($line['discount_percent'] ?? '0') !== '' ? (string) ($line['discount_percent'] ?? '0') : '0');
                $amount = pl_money($split['net']);
                if (bccomp($split['discount'], '0', 4) > 0) { $discountNote = pl_t('less') . ' ' . pl_money($split['discount']); }
            } else {
                $amount = $gross;
            }
        }
    } catch (DomainException) {}
    ?>
    <td class="amount" data-commercial-amount><?= pl_e($amount) ?><?php if ($discountNote !== ''): ?><span class="row-sub"><?= pl_e($discountNote) ?></span><?php endif; ?></td><td><button class="btn btn-ghost btn-icon btn-sm" name="remove_line" value="<?= $index ?>" formnovalidate data-remove-commercial-row aria-label="<?= pl_e(pl_t('Remove line') . ' ' . ($index + 1)) ?>"><?= pl_icon('trash') ?></button></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <div class="flex flex-wrap items-center justify-between gap-3 mt-3"><button class="btn btn-secondary btn-sm" name="editor_action" value="add_line" formnovalidate data-add-commercial-row><?= pl_icon('plus') ?> <?= pl_e(pl_t('Add line')) ?></button><p class="text-xs text-ink-muted" data-commercial-total aria-live="polite"><?= pl_e($tradingOn
        ? pl_t('A pack resolves to base units before pricing, tax and stock. A discount is entered as a percentage and the amount is stored. A free-goods line is never charged, and its cost leaves stock to the promotional account.')
        : pl_t('Entered amounts use quantity × unit price. Review tax and posting totals before confirming.')) ?></p></div>
    <?php
}
