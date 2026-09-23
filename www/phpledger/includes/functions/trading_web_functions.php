<?php
declare(strict_types=1);

/**
 * Admin > Accounting policies (owner decision B37) and Admin > Company profile (B64).
 *
 * Browser adapters only: every rule, every validation and every audit row belongs to
 * trading_functions.php, which these two screens call exactly as any other caller would.
 */
function pl_web_accounting_policies(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if ($method === 'POST') {
        try {
            pl_require_post($method);
            pl_require_csrf(pl_web_text($_POST, 'csrf'));
            pl_web_assert_scope($company, $_POST);
            pl_save_trading_policies($actorId, $companyId, $bookId, [
                'discount_posting' => pl_web_text($_POST, 'discount_posting'),
                'discount_account_id' => pl_web_text($_POST, 'discount_account_id') === '' ? '' : pl_web_id($_POST, 'discount_account_id'),
                'free_goods_account_id' => pl_web_text($_POST, 'free_goods_account_id') === '' ? '' : pl_web_id($_POST, 'free_goods_account_id'),
                'free_goods_output_tax' => pl_web_text($_POST, 'free_goods_output_tax'),
                'cash_on_invoice_cap' => pl_web_text($_POST, 'cash_on_invoice_cap'),
                'cash_shortfall_policy' => pl_web_text($_POST, 'cash_shortfall_policy'),
                'revision' => pl_web_policy_revision($_POST),
                'reason' => pl_web_text($_POST, 'reason'),
                'idempotency_key' => pl_web_text($_POST, 'request_key'),
            ]);
            pl_notice('Accounting policies saved. Documents already posted keep the entries they carry; the new policy applies to documents posted from now on.');
            pl_redirect('/accounting-policies');
        } catch (DomainException $error) {
            pl_form_failure('/accounting-policies', $_POST, $error->getMessage());
        }
    }
    pl_render('accounting-policies', [
        'title' => 'Accounting policies', 'user' => $user, 'company' => $company,
        'policies' => pl_trading_policies($actorId, $companyId, $bookId),
        'canEditPolicies' => pl_user_can($actorId, $companyId, 'policy.manage') && !pl_demo_enabled(),
        'accounts' => pl_trading_policy_account_options($actorId, $companyId, $bookId),
        'history' => pl_trading_policy_history($actorId, $companyId),
        'requestKey' => bin2hex(random_bytes(16)),
        'form' => pl_form_state('/accounting-policies'),
    ]);
}

/** A revision of 0 is legitimate: it means this book has never saved a policy row. */
function pl_web_policy_revision(array $post): int
{
    $value = pl_web_text($post, 'revision');
    if (!preg_match('/^[0-9]{1,9}$/D', $value)) {
        throw new DomainException('Reload this screen before saving.');
    }
    return (int) $value;
}

function pl_web_company_profile(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if ($method === 'POST') {
        try {
            pl_require_post($method);
            pl_require_csrf(pl_web_text($_POST, 'csrf'));
            pl_web_assert_scope($company, $_POST);
            $input = ['revision' => pl_web_policy_revision($_POST), 'reason' => pl_web_text($_POST, 'reason'),
                'idempotency_key' => pl_web_text($_POST, 'request_key')];
            foreach (pl_company_profile_fields() as $field) { $input[$field] = pl_web_text($_POST, $field); }
            pl_save_company_profile($actorId, $companyId, $input);
            pl_notice('Company profile saved. It appears on the documents printed from now on; documents already printed are unchanged.');
            pl_redirect('/company-profile');
        } catch (DomainException $error) {
            pl_form_failure('/company-profile', $_POST, $error->getMessage());
        }
    }
    pl_render('company-profile', [
        'title' => 'Company profile', 'user' => $user, 'company' => $company,
        'profile' => pl_company_profile($actorId, $companyId),
        'history' => pl_trading_policy_history($actorId, $companyId),
        'requestKey' => bin2hex(random_bytes(16)),
        'form' => pl_form_state('/company-profile'),
    ]);
}
