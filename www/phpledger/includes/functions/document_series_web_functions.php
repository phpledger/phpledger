<?php
declare(strict_types=1);

/** Admin > Numbering. Only the business owner may change a series; everyone else reads it. */
function pl_web_numbering(int $actorId, int $companyId, int $bookId, array $user, array $company, string $method): never
{
    if ($method === 'POST') {
        try {
            pl_require_post($method);
            pl_require_csrf(pl_web_text($_POST, 'csrf'));
            pl_web_assert_scope($company, $_POST);
            pl_save_document_series($actorId, $companyId, $bookId, pl_web_text($_POST, 'document_type'), [
                'prefix' => strtoupper(pl_web_text($_POST, 'prefix')),
                'padding' => pl_web_id($_POST, 'padding'),
                'year_segment' => pl_web_text($_POST, 'year_segment') === '1',
                'reset_rule' => pl_web_text($_POST, 'reset_rule'),
                'next_number' => pl_web_id($_POST, 'next_number'),
                'revision' => pl_web_id($_POST, 'revision'),
                'reason' => pl_web_text($_POST, 'reason'),
            ]);
            pl_notice('Numbering saved. Documents already issued keep the numbers they carry.');
            pl_redirect('/numbering');
        } catch (DomainException $error) {
            pl_form_failure('/numbering', $_POST, $error->getMessage());
        }
    }
    pl_render('numbering', [
        'title' => 'Document numbering', 'user' => $user, 'company' => $company,
        'series' => pl_list_document_series($actorId, $companyId, $bookId),
        'form' => pl_form_state('/numbering'),
    ]);
}
