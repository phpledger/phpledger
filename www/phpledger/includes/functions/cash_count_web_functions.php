<?php
declare(strict_types=1);

function pl_web_cash_counts(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if ($method === 'POST') {
        try {
            pl_require_post($method);
            pl_require_csrf(pl_web_text($_POST, 'csrf'));
            pl_web_assert_scope($company, $_POST);
            $denominations = [];
            foreach ((array) ($_POST['denomination'] ?? []) as $index => $value) {
                if ((string) $value === '') { continue; }
                $denominations[] = ['denomination' => (string) $value, 'quantity' => (string) ($_POST['quantity'][$index] ?? '')];
            }
            $count = pl_record_cash_count($actorId, $companyId, $bookId, [
                'account_id' => pl_web_id($_POST, 'account_id'), 'count_date' => pl_web_text($_POST, 'count_date'),
                'counted_at' => pl_web_text($_POST, 'counted_at'), 'counted_amount' => pl_web_text($_POST, 'counted_amount'),
                'note' => pl_web_text($_POST, 'note'), 'creation_key' => pl_web_text($_POST, 'creation_key'), 'denominations' => $denominations,
            ]);
            pl_notice('Cash count recorded. Any difference has been posted to cash over and short.');
            pl_redirect(pl_url('/cash-counts', ['id' => $count['id']]));
        } catch (DomainException $error) { pl_form_failure('/cash-counts', $_POST, $error->getMessage()); }
    }
    $accountId = pl_web_id($_GET, 'account_id');
    $id = pl_web_id($_GET, 'id');
    pl_render('cash-counts', ['title' => 'Cash counts', 'user' => $user, 'company' => $company,
        'accounts' => pl_cash_count_accounts($actorId, $companyId, $bookId),
        'history' => pl_cash_count_history($actorId, $companyId, $bookId, $accountId ?: null),
        'selectedAccount' => $accountId, 'count' => $id ? pl_get_cash_count($actorId, $companyId, $bookId, $id) : null,
        'form' => pl_form_state('/cash-counts')]);
}
