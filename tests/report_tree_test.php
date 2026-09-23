<?php
declare(strict_types=1);

/** Find a node by its code anywhere in a tree. */
function tree_node(array $nodes, string $code): ?array
{
    foreach ($nodes as $node) {
        if ($node['code'] === $code) { return $node; }
        $found = tree_node($node['children'] ?? [], $code);
        if ($found !== null) { return $found; }
    }
    return null;
}

test('the report tree aggregates every measure at the class, group and account levels', function (): void {
    $rows = [
        ['id' => 1, 'code' => '1-000-00000-00', 'name' => 'Assets', 'type' => 'asset', 'debit' => '0.0000', 'credit' => '0.0000'],
        ['id' => 2, 'code' => '1-100-00000-00', 'name' => 'Cash and bank', 'type' => 'asset', 'debit' => '0.0000', 'credit' => '0.0000'],
        ['id' => 3, 'code' => '1-100-10001-00', 'name' => 'Cash in hand', 'type' => 'asset', 'debit' => '100.0000', 'credit' => '0.0000'],
        ['id' => 4, 'code' => '1-100-10002-01', 'name' => 'Bank — current', 'type' => 'asset', 'debit' => '250.5000', 'credit' => '0.0000'],
        ['id' => 5, 'code' => '1-100-10002-02', 'name' => 'Bank — savings', 'type' => 'asset', 'debit' => '49.5000', 'credit' => '0.0000'],
        ['id' => 6, 'code' => '1-110-10001-00', 'name' => 'Trade receivables', 'type' => 'asset', 'debit' => '600.0000', 'credit' => '0.0000'],
        ['id' => 7, 'code' => '2-100-10001-00', 'name' => 'Trade payables', 'type' => 'liability', 'debit' => '0.0000', 'credit' => '1000.0000'],
    ];
    $tree = pl_report_tree($rows, ['debit', 'credit']);
    assert_same(2, count($tree));
    // Level 1: the class totals its groups.
    $assets = $tree[0];
    assert_same('1-000-00000-00', $assets['code']);
    assert_same('Assets', $assets['label']);
    assert_same('1', $assets['short_code']);
    assert_same('class', $assets['level']);
    assert_same(0, $assets['depth']);
    assert_same('1000.0000', $assets['debit']);
    assert_same('0.0000', $assets['credit']);
    assert_same(2, count($assets['children']));
    // Level 2: the group totals its accounts, and takes its name from the heading row.
    $cash = tree_node($tree, '1-100-00000-00');
    assert_same('Cash and bank', $cash['label']);
    assert_same('1-100', $cash['short_code']);
    assert_same('group', $cash['level']);
    assert_same(1, $cash['depth']);
    assert_same('400.0000', $cash['debit']);
    assert_same(2, count($cash['children']));
    // A group with no heading row of its own is still named from its code.
    assert_same('Group 1-110', tree_node($tree, '1-110-00000-00')['label']);
    // Level 3: the account totals its sub-accounts even though it has no posting of its own.
    $bank = tree_node($tree, '1-100-10002-00');
    assert_same('account', $bank['level']);
    assert_same(2, $bank['depth']);
    assert_same('300.0000', $bank['debit']);
    assert_same(2, count($bank['children']));
    assert_same('sub_account', $bank['children'][0]['level']);
    assert_same(3, $bank['children'][0]['depth']);
    assert_same('250.5000', $bank['children'][0]['debit']);
    // A heading row never becomes a figure of its own, and a leaf keeps its account id.
    assert_same(3, tree_node($tree, '1-100-10001-00')['id']);
    assert_same(false, tree_node($tree, '1-100-10001-00')['is_heading']);
    assert_same(true, $cash['is_heading']);
});

test('the depth control and the flattened rows keep the same totals', function (): void {
    $rows = [
        ['id' => 1, 'code' => '5-100-10001-00', 'name' => 'Rent', 'type' => 'expense', 'amount' => '400.0000'],
        ['id' => 2, 'code' => '5-100-10002-01', 'name' => 'Fuel — van', 'type' => 'expense', 'amount' => '60.0000'],
        ['id' => 3, 'code' => '5-200-10001-00', 'name' => 'Utilities', 'type' => 'expense', 'amount' => '140.0000'],
    ];
    $tree = pl_report_tree($rows, ['amount']);
    assert_same('600.0000', $tree[0]['amount']);
    foreach ([1 => 0, 2 => 2, 3 => 5, 4 => 6] as $depth => $expectedChildren) {
        $limited = pl_report_tree_limit($tree, $depth);
        assert_same('600.0000', $limited[0]['amount'], 'Depth ' . $depth . ' changed the class total.');
        $flat = pl_report_tree_rows($limited);
        assert_same($expectedChildren, count($flat) - 1, 'Depth ' . $depth . ' showed the wrong number of rows.');
    }
    assert_same([], pl_report_tree_limit($tree, 1)[0]['children']);
    assert_same('sub_account', pl_report_tree_rows(pl_report_tree_limit($tree, 4))[4]['level']);
    assert_throws(fn () => pl_report_tree_limit($tree, 0), DomainException::class);
    assert_throws(fn () => pl_report_tree_limit($tree, 5), DomainException::class);
});

test('an account still numbered the old way keeps its balance under its own classification', function (): void {
    $rows = [
        ['id' => 1, 'code' => '4-100-10001-00', 'name' => 'Sales', 'type' => 'income', 'amount' => '900.0000'],
        ['id' => 2, 'code' => '4050', 'name' => 'Not yet converted', 'type' => 'income', 'amount' => '100.0000'],
    ];
    $tree = pl_report_tree($rows, ['amount']);
    assert_same(1, count($tree));
    assert_same('1000.0000', $tree[0]['amount']);
    assert_same('Revenue', $tree[0]['label']);
    $unstructured = tree_node($tree, 'unstructured:4-000-00000-00');
    assert_same('Other accounts (unstructured codes)', $unstructured['label']);
    assert_same('100.0000', $unstructured['amount']);
    $legacy = tree_node($tree, '4050');
    assert_same('account', $legacy['level']);
    assert_same('100.0000', $legacy['amount']);
});

test('contra accounts are deductions inside their own section and the trial balance still balances', function (): void {
    $f = ledger_fixture('USD', '2026-01-01');
    $semantic = [];
    foreach (DB::query('SELECT id, semantic_key FROM pl_accounts WHERE company_id = %i', $f['company_id']) as $row) {
        $semantic[(string) $row['semantic_key']] = (int) $row['id'];
    }
    $post = static function (array $f, int $debit, int $credit, string $amount, string $key): void {
        pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], [
            'date' => '2026-02-10', 'currency' => 'USD', 'source_type' => 'general_journal',
            'source_reference' => $key, 'idempotency_key' => $key, 'description' => 'Contra presentation fixture',
            'lines' => [['account_id' => $debit, 'debit' => $amount, 'credit' => '0'],
                        ['account_id' => $credit, 'debit' => '0', 'credit' => $amount]]]);
    };
    // A sale, a sales return, an expense, a purchase return and depreciation.
    $post($f, $f['accounts']['1000'], $semantic['core.income.sales'], '1000.0000', 'sale');
    $post($f, $semantic['core.income.sales_returns'], $f['accounts']['1000'], '150.0000', 'sales-return');
    $post($f, $semantic['core.expense.general'], $f['accounts']['1000'], '400.0000', 'expense');
    $post($f, $f['accounts']['1000'], $semantic['core.expense.purchase_returns'], '25.0000', 'purchase-return');
    $post($f, $semantic['core.expense.general'], $semantic['core.asset.accumulated_depreciation'], '200.0000', 'depreciation');

    $trial = pl_trial_balance($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-28');
    assert_true($trial['balanced'], 'Contra accounts must not break the trial balance.');
    assert_same($trial['total_debit'], $trial['total_credit']);

    $profit = pl_profit_loss($f['actor_id'], $f['company_id'], $f['book_id'], '2026-01-01', '2026-02-28');
    // Sales returns stay inside Income as a deduction; they never become an expense.
    $returns = null;
    foreach ($profit['income'] as $row) { if ($row['id'] === $semantic['core.income.sales_returns']) { $returns = $row; } }
    assert_true($returns !== null, 'Sales returns left the income section.');
    assert_same(true, $returns['is_contra']);
    assert_same('-150.0000', $returns['amount']);
    assert_same('850.0000', $profit['total_income']);
    // Purchase returns stay inside the expense section as a deduction.
    $purchaseReturns = null;
    foreach ($profit['expenses'] as $row) { if ($row['id'] === $semantic['core.expense.purchase_returns']) { $purchaseReturns = $row; } }
    assert_true($purchaseReturns !== null, 'Purchase returns left the expense section.');
    assert_same('-25.0000', $purchaseReturns['amount']);
    assert_same('575.0000', $profit['total_expenses']);
    assert_same('275.0000', $profit['net_profit']);
    // The class totals in the tree carry the deduction, so a collapsed report reads the same.
    assert_same('850.0000', $profit['trees']['income'][0]['amount']);
    assert_same('575.0000', $profit['trees']['expenses'][0]['amount']);
    assert_same(true, tree_node($profit['trees']['income'], '4-900-10001-00')['is_contra']);

    $sheet = pl_balance_sheet($f['actor_id'], $f['company_id'], $f['book_id'], '2026-02-28');
    assert_true($sheet['balanced']);
    $depreciation = null;
    foreach ($sheet['assets'] as $row) { if ($row['id'] === $semantic['core.asset.accumulated_depreciation']) { $depreciation = $row; } }
    assert_true($depreciation !== null, 'Accumulated depreciation left the asset section.');
    assert_same(true, $depreciation['is_contra']);
    assert_same('-200.0000', $depreciation['amount']);
    assert_same('275.0000', $sheet['total_assets']);
    assert_same('275.0000', $sheet['total_liabilities_equity']);
    assert_same('-200.0000', tree_node($sheet['trees']['assets'], '1-900-00000-00')['amount']);
});
