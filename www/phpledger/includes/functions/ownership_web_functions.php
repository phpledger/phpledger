<?php
declare(strict_types=1);

/**
 * The ownership register's two screens (1.2 M8a, issue #92).
 *
 *   /ownership          the registers themselves: people, members, officers, share classes, the
 *                       share ledger, and the related-party markers behind their own capability.
 *   /reports/ownership  the snapshot, book value per share, the partner capital account
 *                       statement, the related-party transaction report and the director loan
 *                       movement report.
 *
 * Every service call here has already been authorised inside the service. These functions read
 * the form, call the service and choose the next screen; they never decide a permission, and
 * they never compute an amount.
 */

/** Admin > Ownership register. */
function pl_web_ownership(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if ($method === 'POST') {
        pl_web_ownership_post($actorId, $companyId, $bookId, $company);
    }
    $form = pl_form_state(pl_url('/ownership'));
    $asOf = pl_web_text($_GET, 'as_of', gmdate('Y-m-d'));
    pl_ledger_date($asOf);
    $profile = pl_company_profile($actorId, $companyId);
    // The marker register is behind B58's authority, so the screen asks the same question the
    // service would and shows the section only to whoever may read it. A refusal here would be a
    // 422 on an ordinary page load for a role that is simply not entitled to that one panel.
    $mayRead = pl_user_can($actorId, $companyId, 'relatedparty.view');
    pl_render('ownership', [
        'title' => pl_t('Ownership register'), 'user' => $user, 'company' => $company, 'asOf' => $asOf,
        'profile' => $profile,
        'people' => pl_list_ownership_parties($actorId, $companyId),
        'members' => pl_list_ownership_members($actorId, $companyId),
        'officers' => pl_list_ownership_officers($actorId, $companyId),
        'classes' => pl_list_share_classes($actorId, $companyId, $asOf),
        'events' => pl_list_share_events($actorId, $companyId),
        'partners' => pl_list_owner_partners($actorId, $companyId, $bookId),
        'partnerLinks' => pl_ownership_partner_links($companyId, $bookId),
        'accounts' => pl_share_posting_accounts($companyId, $bookId),
        // The marker form needs the trade parties to choose from. One hundred is the list's
        // largest page; a company with more than that uses the candidate cross-check below,
        // which is the intended way in anyway.
        'parties' => pl_page_parties($actorId, $companyId, $bookId, ['page' => 1, 'per_page' => 100]),
        'mayReadRelated' => $mayRead,
        'mayManage' => pl_user_can($actorId, $companyId, 'ownership.manage'),
        'markers' => $mayRead ? pl_list_related_party_markers($actorId, $companyId) : [],
        'candidates' => $mayRead ? pl_related_party_candidates($actorId, $companyId) : [],
        'form' => $form, 'input' => $form['input'] ?: ['creation_key' => bin2hex(random_bytes(16)), 'effective_date' => $asOf],
    ]);
}

function pl_web_ownership_post(int $actorId, int $companyId, int $bookId, array $company): void
{
    $return = pl_url('/ownership');
    try {
        pl_web_assert_scope($company, $_POST);
        $action = pl_web_text($_POST, 'action');
        $reason = pl_web_text($_POST, 'reason');
        if ($action === 'person') {
            pl_save_ownership_party($actorId, $companyId, [
                'kind' => pl_web_text($_POST, 'kind'), 'name' => pl_web_text($_POST, 'name'),
                'country_code' => pl_web_text($_POST, 'country_code'), 'identifier' => pl_web_text($_POST, 'identifier'),
                'address' => pl_web_text($_POST, 'address'), 'email' => pl_web_text($_POST, 'email'),
                'note' => pl_web_text($_POST, 'note'), 'is_active' => pl_web_text($_POST, 'is_active') !== '0',
                'reason' => $reason,
            ], pl_web_id($_POST, 'id') ?: null, pl_web_id($_POST, 'id') ? pl_web_id($_POST, 'revision') : null);
            pl_notice(pl_t('Saved to the ownership register.'));
            pl_redirect($return);
        }
        if ($action === 'partner_link') {
            pl_link_ownership_partner($actorId, $companyId, $bookId, pl_web_id($_POST, 'ownership_party_id'),
                pl_web_id($_POST, 'partner_id') ?: null, $reason);
            pl_notice(pl_t('Capital account link saved. The partner record and its accounts are unchanged.'));
            pl_redirect($return);
        }
        if ($action === 'member') {
            pl_save_ownership_member($actorId, $companyId, [
                'ownership_party_id' => pl_web_id($_POST, 'ownership_party_id'),
                'effective_from' => pl_web_text($_POST, 'effective_from'),
                'effective_to' => pl_web_text($_POST, 'effective_to'),
                'profit_share' => pl_web_text($_POST, 'profit_share'),
                'note' => pl_web_text($_POST, 'note'), 'reason' => $reason,
            ], pl_web_id($_POST, 'id') ?: null, pl_web_id($_POST, 'id') ? pl_web_id($_POST, 'revision') : null);
            pl_notice(pl_t('Membership interest recorded. Closing an interest keeps it readable for the period it covered.'));
            pl_redirect($return);
        }
        if ($action === 'officer') {
            pl_save_ownership_officer($actorId, $companyId, [
                'ownership_party_id' => pl_web_id($_POST, 'ownership_party_id'),
                'officer_role' => pl_web_text($_POST, 'officer_role'),
                'role_title' => pl_web_text($_POST, 'role_title'),
                'appointed_on' => pl_web_text($_POST, 'appointed_on'),
                'resigned_on' => pl_web_text($_POST, 'resigned_on'),
                'has_significant_control' => pl_web_text($_POST, 'has_significant_control') === '1',
                'control_nature' => pl_web_text($_POST, 'control_nature'),
                'note' => pl_web_text($_POST, 'note'), 'reason' => $reason,
            ], pl_web_id($_POST, 'id') ?: null, pl_web_id($_POST, 'id') ? pl_web_id($_POST, 'revision') : null);
            pl_notice(pl_t('Appointment recorded. Recording a directorship does not make anybody a related party: that designation is separate and deliberate.'));
            pl_redirect($return);
        }
        if ($action === 'share_class') {
            pl_save_share_class($actorId, $companyId, [
                'code' => pl_web_text($_POST, 'code'), 'name' => pl_web_text($_POST, 'name'),
                'class_type' => pl_web_text($_POST, 'class_type'), 'currency' => pl_web_text($_POST, 'currency'),
                'nominal_value' => pl_web_text($_POST, 'nominal_value'),
                'votes_per_share' => pl_web_text($_POST, 'votes_per_share', '1'),
                'authorised_shares' => pl_web_text($_POST, 'authorised_shares'),
                'is_option_pool' => pl_web_text($_POST, 'is_option_pool') === '1',
                'is_active' => pl_web_text($_POST, 'is_active') !== '0',
                'dividend_rights' => pl_web_text($_POST, 'dividend_rights'),
                'liquidation_rights' => pl_web_text($_POST, 'liquidation_rights'),
                'reason' => $reason,
            ], pl_web_id($_POST, 'id') ?: null, pl_web_id($_POST, 'id') ? pl_web_id($_POST, 'revision') : null);
            pl_notice(pl_t('Share class saved. The issued count is read from the share ledger and is never stored.'));
            pl_redirect($return);
        }
        if ($action === 'share_event') {
            $type = pl_web_text($_POST, 'event_type');
            pl_record_share_event($actorId, $companyId, $bookId, [
                'event_type' => $type,
                'share_class_id' => pl_web_id($_POST, 'share_class_id'),
                'effective_date' => pl_web_text($_POST, 'effective_date'),
                'quantity' => pl_web_text($_POST, 'quantity'),
                'from_party_id' => pl_web_id($_POST, 'from_party_id') ?: null,
                'to_party_id' => pl_web_id($_POST, 'to_party_id') ?: null,
                'to_share_class_id' => pl_web_id($_POST, 'to_share_class_id') ?: null,
                'consideration_amount' => pl_web_text($_POST, 'consideration_amount'),
                'certificate_reference' => pl_web_text($_POST, 'certificate_reference'),
                'post' => pl_web_text($_POST, 'post') === '1',
                'journal_id' => pl_web_id($_POST, 'journal_id') ?: null,
                'debit_account_id' => pl_web_id($_POST, 'debit_account_id') ?: null,
                'share_capital_account_id' => pl_web_id($_POST, 'share_capital_account_id') ?: null,
                'share_premium_account_id' => pl_web_id($_POST, 'share_premium_account_id') ?: null,
                'source_account_id' => pl_web_id($_POST, 'source_account_id') ?: null,
                'reason' => $reason, 'creation_key' => pl_web_text($_POST, 'creation_key'),
            ]);
            pl_notice($type === 'transfer'
                ? pl_t('Transfer recorded. A transfer between two holders changes nothing in the company\'s own books, so nothing was posted.')
                : pl_t('Share ledger event recorded. The ledger is append-only; a correction is a linked reversal.'));
            pl_redirect($return);
        }
        if ($action === 'share_event_reverse') {
            pl_reverse_share_event($actorId, $companyId, pl_web_id($_POST, 'event_id'), $reason,
                pl_web_text($_POST, 'creation_key'));
            pl_notice(pl_t('Event corrected by a linked reversal. The original row is retained, and any journal behind it was reversed too.'));
            pl_redirect($return);
        }
        if ($action === 'marker') {
            pl_save_related_party_marker($actorId, $companyId, [
                'party_id' => pl_web_id($_POST, 'party_id'),
                'related_register' => pl_web_text($_POST, 'related_register'),
                'related_id' => pl_web_id($_POST, 'related_id'),
                'relationship' => pl_web_text($_POST, 'relationship'),
                'effective_from' => pl_web_text($_POST, 'effective_from'),
                'effective_to' => pl_web_text($_POST, 'effective_to'),
                'note' => pl_web_text($_POST, 'note'), 'reason' => $reason,
            ], pl_web_id($_POST, 'id') ?: null, pl_web_id($_POST, 'id') ? pl_web_id($_POST, 'revision') : null);
            pl_notice(pl_t('Related-party marker recorded. It is restricted information and appears only to a role that may read it.'));
            pl_redirect($return);
        }
        throw new DomainException(pl_t('Choose what to record on the ownership register.'));
    } catch (DomainException $error) {
        pl_form_failure($return, $_POST, $error->getMessage());
    }
}

/** Reports > Ownership. */
function pl_web_ownership_reports(int $actorId, int $companyId, int $bookId, array $user, array $company): never
{
    $asOf = pl_web_text($_GET, 'as_of', gmdate('Y-m-d'));
    pl_ledger_date($asOf);
    $from = pl_web_text($_GET, 'from', substr($asOf, 0, 4) . '-01-01');
    pl_ledger_date($from);
    if ($from > $asOf) { $from = $asOf; }
    $mayRead = pl_user_can($actorId, $companyId, 'relatedparty.view');
    pl_render('ownership-reports', [
        'title' => pl_t('Ownership reports'), 'user' => $user, 'company' => $company,
        'asOf' => $asOf, 'from' => $from,
        'snapshot' => pl_ownership_snapshot($actorId, $companyId, $asOf),
        'bookValue' => pl_book_value_per_share($actorId, $companyId, $bookId, $asOf),
        'capital' => pl_partner_capital_statement($actorId, $companyId, $bookId, $from, $asOf),
        'mayReadRelated' => $mayRead,
        'related' => $mayRead ? pl_related_party_transactions($actorId, $companyId, $bookId, $from, $asOf) : null,
        'directorLoans' => $mayRead ? pl_director_loan_movements($actorId, $companyId, $bookId, $from, $asOf) : null,
    ]);
}
