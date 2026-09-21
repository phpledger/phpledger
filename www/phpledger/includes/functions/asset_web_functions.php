<?php
declare(strict_types=1);

/*
 * Browser adapters for the fixed-asset register (1.3 M14, issue #95).
 *
 * Adapters only. Every accounting outcome, permission, rounding decision and refusal comes
 * from asset_functions.php; nothing here decides an amount, and the templates decide less.
 * A screen renders when the module is disabled — it shows what is already recorded and says
 * the module is off — because the definition of done requires historical reads to survive a
 * disablement, and because a screen that answers 500 when a module is off is a screen that
 * shipped unreachable.
 */

require_once __DIR__ . '/starter_web_functions.php';

/** `/fixed-assets`: the register, the new-asset form and the asset classes. */
function pl_web_assets(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $enabled = pl_module_available($actorId, $companyId, $bookId, 'fixed-assets');
    $tab = pl_web_text($_GET, 'tab', 'assets') === 'classes' ? 'classes' : 'assets';
    $return = pl_url('/fixed-assets', ['tab' => $tab]);
    if ($method === 'POST') {
        pl_web_assert_scope($company, $_POST);
        $action = pl_web_text($_POST, 'action');
        $return = pl_url('/fixed-assets', ['tab' => $action === 'class' ? 'classes' : 'assets']);
        try {
            if ($action === 'class') {
                $class = pl_save_asset_class($actorId, $companyId, $bookId, [
                    'code' => pl_web_text($_POST, 'code'), 'name' => pl_web_text($_POST, 'name'),
                    'method' => pl_web_text($_POST, 'method', 'straight_line'),
                    'useful_life_months' => pl_web_id($_POST, 'useful_life_months'),
                    'annual_rate' => pl_web_text($_POST, 'annual_rate') ?: null,
                    'is_active' => true, 'reason' => pl_web_text($_POST, 'reason'),
                    'idempotency_key' => pl_web_text($_POST, 'request_key'),
                ]);
                pl_notice('Asset class ' . $class['code'] . ' saved. Nothing has been posted; a class is a policy, not an entry.');
                pl_redirect(pl_url('/fixed-assets', ['tab' => 'classes']));
            }
            if ($action === 'asset') {
                $asset = pl_save_asset($actorId, $companyId, $bookId, [
                    'class_id' => pl_web_id($_POST, 'class_id'), 'code' => pl_web_text($_POST, 'code'),
                    'name' => pl_web_text($_POST, 'name'),
                    'acquisition_date' => pl_web_text($_POST, 'acquisition_date'),
                    'in_service_date' => pl_web_text($_POST, 'in_service_date') ?: pl_web_text($_POST, 'acquisition_date'),
                    'cost' => pl_web_text($_POST, 'cost'), 'residual_value' => pl_web_text($_POST, 'residual_value', '0'),
                    'useful_life_months' => pl_web_id($_POST, 'useful_life_months') ?: null,
                    'annual_rate' => pl_web_text($_POST, 'annual_rate') ?: null,
                    'location' => pl_web_text($_POST, 'location'), 'supplier' => pl_web_text($_POST, 'supplier'),
                    'reference' => pl_web_text($_POST, 'reference'),
                    'credit_account_id' => pl_web_id($_POST, 'credit_account_id'),
                    'reason' => pl_web_text($_POST, 'reason'), 'idempotency_key' => pl_web_text($_POST, 'request_key'),
                ]);
                pl_notice('Asset ' . $asset['code'] . ' recorded and its acquisition posted as ' . pl_money($asset['posted_cost']) . '.');
                pl_redirect(pl_url('/fixed-assets/detail', ['id' => $asset['id']]));
            }
            throw new DomainException('Choose an action on this screen.');
        } catch (DomainException $error) {
            pl_form_failure($return, array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $_POST), $error->getMessage());
        }
    }
    $form = pl_form_state($return);
    $classes = pl_list_asset_classes($actorId, $companyId, $bookId);
    pl_render('assets', ['title' => 'Fixed assets', 'user' => $user, 'company' => $company, 'enabled' => $enabled,
        'tab' => $tab, 'classes' => $classes, 'assets' => pl_list_assets($actorId, $companyId, $bookId),
        'accounts' => pl_starter_accounts($actorId, $companyId, $bookId),
        'form' => $form, 'input' => $form['input'] ?: ['acquisition_date' => gmdate('Y-m-d'), 'residual_value' => '0',
            'method' => 'straight_line', 'request_key' => bin2hex(random_bytes(20))]]);
}

/** `/fixed-assets/detail`: one asset's statement, its schedule, its events, and the disposal form. */
function pl_web_asset_detail(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $assetId = pl_web_id($method === 'POST' ? $_POST : $_GET, 'id');
    // Reached without an asset (a bookmark, or the route sweep): the register is the
    // page that was meant, not a 403 telling somebody their role is wrong.
    if ($assetId < 1) { pl_redirect(pl_url('/fixed-assets')); }
    $return = pl_url('/fixed-assets/detail', ['id' => $assetId]);
    if ($method === 'POST') {
        pl_web_assert_scope($company, $_POST);
        try {
            $action = pl_web_text($_POST, 'action');
            if ($action === 'dispose') {
                $result = pl_dispose_asset($actorId, $companyId, $bookId, $assetId, [
                    'kind' => pl_web_text($_POST, 'kind', 'sale'), 'date' => pl_web_text($_POST, 'date'),
                    'proceeds' => pl_web_text($_POST, 'proceeds', '0'),
                    'proceeds_account_id' => pl_web_id($_POST, 'proceeds_account_id') ?: null,
                    'reason' => pl_web_text($_POST, 'reason'), 'idempotency_key' => pl_web_text($_POST, 'request_key'),
                ]);
                $gain = (string) $result['disposal']['gain_loss'];
                pl_notice(bccomp($gain, '0', 4) >= 0
                    ? 'Asset disposed of. A gain of ' . pl_money($gain) . ' was posted to the disposal account.'
                    : 'Asset disposed of. A loss of ' . pl_money(bcsub('0', $gain, 4)) . ' was posted to the disposal account.');
            } elseif ($action === 'reverse_disposal') {
                pl_reverse_asset_disposal($actorId, $companyId, $bookId, $assetId, pl_web_text($_POST, 'date') ?: null,
                    ['reason' => pl_web_text($_POST, 'reason'), 'idempotency_key' => pl_web_text($_POST, 'request_key')]);
                pl_notice('The disposal was reversed with a linked reversal. The asset is back in the register unchanged.');
            } elseif ($action === 'reverse_acquisition') {
                pl_reverse_asset_acquisition($actorId, $companyId, $bookId, $assetId, pl_web_text($_POST, 'date') ?: null,
                    ['reason' => pl_web_text($_POST, 'reason'), 'idempotency_key' => pl_web_text($_POST, 'request_key')]);
                pl_notice('The acquisition was reversed with a linked reversal. The asset no longer carries a cost.');
            } elseif ($action === 'details') {
                pl_update_asset_details($actorId, $companyId, $bookId, $assetId, pl_web_id($_POST, 'revision'), [
                    'name' => pl_web_text($_POST, 'name'), 'location' => pl_web_text($_POST, 'location'),
                    'supplier' => pl_web_text($_POST, 'supplier'), 'reference' => pl_web_text($_POST, 'reference'),
                    'reason' => pl_web_text($_POST, 'reason'),
                ]);
                pl_notice('The asset details were updated. Cost, dates and depreciation policy are fixed once posted.');
            } else {
                throw new DomainException('Choose an action for this asset.');
            }
            pl_redirect($return);
        } catch (DomainException $error) {
            pl_form_failure($return, array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $_POST), $error->getMessage());
        }
    }
    $statement = pl_asset_statement($actorId, $companyId, $bookId, $assetId);
    $form = pl_form_state($return);
    pl_render('asset-detail', ['title' => 'Asset ' . $statement['asset']['code'], 'user' => $user, 'company' => $company,
        'enabled' => pl_module_available($actorId, $companyId, $bookId, 'fixed-assets'),
        'statement' => $statement, 'accounts' => pl_starter_accounts($actorId, $companyId, $bookId), 'form' => $form,
        'input' => $form['input'] ?: ['date' => gmdate('Y-m-d'), 'kind' => 'sale', 'proceeds' => '0', 'request_key' => bin2hex(random_bytes(20))]]);
}

/** `/fixed-assets/depreciation`: choose a period, see exactly what would post, then post it. */
function pl_web_asset_depreciation(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    pl_require_company_access($actorId, $companyId);
    pl_ledger_book($companyId, $bookId);
    $periodId = pl_web_id($method === 'POST' ? $_POST : $_GET, 'period');
    $return = pl_url('/fixed-assets/depreciation', ['period' => $periodId ?: '']);
    if ($method === 'POST') {
        pl_web_assert_scope($company, $_POST);
        try {
            $action = pl_web_text($_POST, 'action');
            if ($action === 'run') {
                $run = pl_run_asset_depreciation($actorId, $companyId, $bookId,
                    ['period_id' => $periodId, 'reason' => pl_web_text($_POST, 'reason'), 'idempotency_key' => pl_web_text($_POST, 'request_key')]);
                pl_notice($run['id'] === 0
                    ? (string) $run['message']
                    : 'Depreciation posted as ' . $run['journal_reference'] . ': ' . pl_money((string) $run['total_amount']) . ' across ' . $run['asset_count'] . ' assets.');
            } elseif ($action === 'reverse') {
                pl_reverse_asset_depreciation_run($actorId, $companyId, $bookId, pl_web_id($_POST, 'run_id'), pl_web_text($_POST, 'date') ?: null,
                    ['reason' => pl_web_text($_POST, 'reason'), 'idempotency_key' => pl_web_text($_POST, 'request_key')]);
                pl_notice('The run was reversed with a linked reversal. Running the period again posts the difference, not a duplicate.');
            } else {
                throw new DomainException('Choose whether to post or to correct a depreciation run.');
            }
            pl_redirect($return);
        } catch (DomainException $error) {
            pl_form_failure($return, array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $_POST), $error->getMessage());
        }
    }
    $periods = pl_list_periods($actorId, $companyId, $bookId);
    $preview = $periodId > 0 ? pl_asset_depreciation_preview($actorId, $companyId, $bookId, $periodId) : null;
    $form = pl_form_state($return);
    pl_render('asset-depreciation', ['title' => 'Depreciation run', 'user' => $user, 'company' => $company,
        'enabled' => pl_module_available($actorId, $companyId, $bookId, 'fixed-assets'),
        'periods' => $periods, 'periodId' => $periodId, 'preview' => $preview,
        'runs' => pl_list_asset_depreciation_runs($actorId, $companyId, $bookId), 'form' => $form,
        'input' => $form['input'] ?: ['request_key' => bin2hex(random_bytes(20))]]);
}

/** The manifest's `asset-register` report, on the route the manifest declares. */
function pl_web_asset_register(int $actorId, int $companyId, int $bookId, array $user, array $company): never
{
    $asOf = pl_web_text($_GET, 'as_of', gmdate('Y-m-d'));
    pl_render('asset-register', ['title' => 'Fixed-asset register', 'user' => $user, 'company' => $company,
        'report' => pl_asset_register($actorId, $companyId, $bookId, $asOf),
        'enabled' => pl_module_available($actorId, $companyId, $bookId, 'fixed-assets')]);
}
