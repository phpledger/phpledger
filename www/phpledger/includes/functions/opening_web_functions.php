<?php
declare(strict_types=1);

function pl_opening_web_csv(string $field): string
{
    $file = $_FILES[$field . '_file'] ?? null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 524288
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new DomainException('Choose a CSV file no larger than 512 KiB and try the upload again.');
        }
        $csv = file_get_contents($file['tmp_name']);
        if ($csv === false) {
            throw new DomainException('The uploaded CSV could not be read.');
        }
        return $csv;
    }
    return pl_web_text($_POST, $field);
}

function pl_web_opening(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if (pl_demo_enabled()) {
        throw new DomainException('Opening cutover is unavailable in the public sample.');
    }
    if ($method === 'POST') {
        try {
            pl_web_assert_scope($company, $_POST);
            $action = pl_web_text($_POST, 'action');
            if ($action === 'preview') {
                $balanceCsv = pl_opening_web_csv('balances_csv');
                $documentCsv = pl_opening_web_csv('documents_csv');
                // Preserve uploaded text on validation failure; no private files are stored publicly.
                $_POST['balances_csv'] = $balanceCsv;
                $_POST['documents_csv'] = $documentCsv;
                $balances = $balanceCsv !== '' ? pl_opening_csv($balanceCsv, ['account_code', 'debit', 'credit']) : [];
                if ($balanceCsv === '') {
                    foreach ($company['accounts'] as $account) {
                        // A class or group heading aggregates; an opening balance belongs on the
                        // account underneath it, and the posting funnel would refuse it anyway.
                        if ($account['is_active'] && $account['is_postable']) {
                            $balances[] = ['account_code' => (string) $account['code'], 'debit' => pl_web_text($_POST, 'debit_' . $account['id'], '0') ?: '0', 'credit' => pl_web_text($_POST, 'credit_' . $account['id'], '0') ?: '0'];
                        }
                    }
                }
                $input = ['cutover_date' => pl_web_text($_POST, 'cutover_date'), 'source' => pl_web_text($_POST, 'source'),
                    'zero_confirmed' => pl_web_text($_POST, 'zero_confirmed') === '1', 'balances' => $balances,
                    'unpaid_documents' => pl_opening_csv($documentCsv, ['kind', 'account_code', 'party', 'reference', 'document_date', 'due_date', 'outstanding'])];
                $preview = pl_preview_opening($actorId, $companyId, $bookId, $input, pl_web_text($_POST, 'request_key'));
                pl_redirect(pl_url('/opening-balances', ['preview' => $preview['id']]));
            }
            if ($action === 'confirm') {
                $cutover = pl_confirm_opening($actorId, $companyId, $bookId, pl_web_id($_POST, 'preview_id'), pl_web_text($_POST, 'payload_hash'), pl_web_text($_POST, 'confirmed') === '1');
                pl_notice($cutover['status'] === 'confirmed' ? 'Opening balances confirmed. New transactions can now be dated after cutover.' : 'This preview was already confirmed and subsequently reversed. Prepare a new preview.');
                pl_redirect('/opening-balances');
            }
            if ($action === 'reverse') {
                pl_reverse_opening($actorId, $companyId, $bookId, pl_web_id($_POST, 'cutover_id'), pl_web_text($_POST, 'reason'));
                pl_notice('Cutover reversed with history retained. Review and confirm corrected opening balances.');
                pl_redirect('/opening-balances');
            }
            throw new DomainException('Choose a valid opening-balance action.');
        } catch (DomainException $error) {
            $target = pl_web_text($_POST, 'action') === 'confirm' ? pl_url('/opening-balances', ['preview' => pl_web_id($_POST, 'preview_id')]) : '/opening-balances';
            pl_form_failure($target, $_POST, $error->getMessage());
        }
    }
    $previewId = pl_web_id($_GET, 'preview');
    $form = pl_form_state($previewId > 0 ? pl_url('/opening-balances', ['preview' => $previewId]) : '/opening-balances');
    $history = pl_opening_history($actorId, $companyId, $bookId);
    $preview = $previewId > 0 ? pl_get_opening_preview($actorId, $companyId, $bookId, $previewId) : null;
    if (!$preview && $history !== []) {
        $preview = pl_get_opening_preview($actorId, $companyId, $bookId, (int) $history[0]['preview_id']);
    }
    pl_render('opening-balances', ['title' => 'Opening balances and cutover', 'user' => $user, 'company' => $company,
        'form' => $form, 'input' => $form['input'], 'preview' => $preview, 'history' => $history, 'reviewing' => $previewId > 0]);
}
