<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/web_functions.php';
require_once dirname(__DIR__) . '/www/phpledger/includes/functions/print_functions.php';

/** A posted settlement plus an accountant, a viewer and an unrelated company. */
function print_fixture(): array
{
    $f = settlement_fixture();
    $input = settlement_input($f);
    $posted = pl_settle_open_items($f['actor_id'], $f['company_id'], $f['book_id'], $input);
    $suffix = bin2hex(random_bytes(8));
    $members = [];
    foreach (['accountant', 'viewer'] as $role) {
        $members[$role] = pl_create_user('print-' . $role . '-' . $suffix . '@example.test', 'Sample print ' . $role, 'Sample-test-password-' . $suffix);
        sample_membership_insert(['company_id' => $f['company_id'], 'user_id' => $members[$role], 'role' => $role]);
    }
    $stranger = pl_create_user('print-stranger-' . $suffix . '@example.test', 'Sample print stranger', 'Sample-test-password-' . $suffix);
    return $f + ['journal_id' => (int) $posted['journal_id'], 'members' => $members, 'stranger_id' => $stranger, 'other' => settlement_fixture()];
}

test('the print registry pairs every document type with formats, labels and existing views', function (): void {
    $templates = pl_print_templates();
    assert_true($templates !== [], 'The print registry is empty.');
    assert_true(isset($templates['settlement']), 'The settlement receipt is not registered.');
    foreach ($templates as $type => $document) {
        assert_true((bool) preg_match('~^[a-z0-9-]{1,40}$~D', (string) $type), 'Document type ' . $type . ' is not routable.');
        assert_true(is_callable($document['loader']) && is_callable($document['reference']), 'Type ' . $type . ' has no callable loader or reference.');
        assert_true($document['label'] !== '' && $document['record'] !== '', 'Type ' . $type . ' has no label or record screen.');
        assert_true(isset($document['formats'][PL_PRINT_DEFAULT_FORMAT]), 'Type ' . $type . ' has no default format.');
        assert_true(count($document['formats']) >= 1, 'Type ' . $type . ' registers no format.');
        foreach ($document['formats'] as $format => $paper) {
            $resolved = pl_print_template((string) $type, (string) $format);
            assert_same($paper['view'], $resolved['view']);
            assert_same($paper['label'], $resolved['format_label']);
            assert_true($paper['label'] !== '', 'Format ' . $format . ' of ' . $type . ' has no human label.');
            assert_true(is_file(dirname(__DIR__) . '/www/phpledger/templates/print/' . $paper['view']), 'Missing view file for ' . $type . '/' . $format . '.');
        }
    }
    assert_true(isset($templates['settlement']['formats']['a4'], $templates['settlement']['formats']['80mm']), 'The settlement receipt lacks A4 or 80 mm.');
});

test('an omitted format defaults to A4 and an unknown type or format is refused', function (): void {
    assert_same(PL_PRINT_DEFAULT_FORMAT, pl_print_template('settlement')['format']);
    assert_same('a4', pl_print_template('settlement', '')['format']);
    assert_same('80mm', pl_print_template('settlement', '80mm')['format']);
    // 'invoice' and 'statement' became registered types in 1.2 M3; an unknown type, a
    // differently-cased known type and an empty type are still refused rather than guessed.
    foreach (['unknown', 'Settlement', 'Invoice', 'statements', ''] as $type) {
        assert_throws(fn() => pl_print_template($type), DomainException::class, 'cannot be printed');
    }
    foreach (['a3', 'letter', '58mm', 'A4', 'pdf'] as $format) {
        assert_throws(fn() => pl_print_template('settlement', $format), DomainException::class, 'paper formats');
    }
});

test('the settlement receipt reads the posted allocations without changing anything', function (): void {
    $f = print_fixture();
    $before = (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']);
    $receipt = pl_settlement_receipt($f['actor_id'], $f['company_id'], $f['book_id'], $f['journal_id']);
    assert_same('receivable', $receipt['direction']);
    assert_same(2, count($receipt['allocations']));
    assert_same('20.0000', $receipt['allocated_fc']);
    assert_same('Sample settlement party', (string) $receipt['party']['legal_name']);
    assert_true($receipt['bank'] !== null, 'The receipt does not identify its cash or bank account.');
    assert_same(false, $receipt['reversed']);
    assert_same(false, $receipt['is_reversal']);
    assert_same('PL-' . str_pad((string) $f['journal_id'], 8, '0', STR_PAD_LEFT), pl_print_settlement_reference($receipt));
    assert_same($before, (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE book_id=%i', $f['book_id']));
});

test('printing follows the record screen: owner, accountant and viewer read; a stranger and another company do not', function (): void {
    $f = print_fixture();
    foreach (['owner' => $f['actor_id'], 'accountant' => $f['members']['accountant'], 'viewer' => $f['members']['viewer']] as $role => $readerId) {
        $receipt = pl_settlement_receipt($readerId, $f['company_id'], $f['book_id'], $f['journal_id']);
        assert_same('20.0000', $receipt['allocated_fc'], 'A ' . $role . ' could not read the receipt the record screen shows.');
    }
    assert_throws(fn() => pl_settlement_receipt($f['stranger_id'], $f['company_id'], $f['book_id'], $f['journal_id']), DomainException::class, 'do not have access');
    // The same id in another company's scope, and this company's id read from another company.
    assert_throws(fn() => pl_settlement_receipt($f['other']['actor_id'], $f['other']['company_id'], $f['other']['book_id'], $f['journal_id']), DomainException::class, 'not available');
    assert_throws(fn() => pl_settlement_receipt($f['actor_id'], $f['other']['company_id'], $f['other']['book_id'], $f['journal_id']), DomainException::class);
});

test('only a settlement journal prints as a payment receipt', function (): void {
    $f = print_fixture();
    $other = pl_post_journal($f['actor_id'], $f['company_id'], $f['book_id'], array_replace(ledger_payload($f), ['currency' => 'PKR']));
    assert_throws(fn() => pl_settlement_receipt($f['actor_id'], $f['company_id'], $f['book_id'], (int) $other['id']), DomainException::class, 'not a customer receipt');
    assert_throws(fn() => pl_settlement_receipt($f['actor_id'], $f['company_id'], $f['book_id'], 0), DomainException::class);
});

$printRouter = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/public/index.php');
$printFrame = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/templates/print/frame.php');
$printStyles = (string) file_get_contents(dirname(__DIR__) . '/www/phpledger/public/assets/app.css');
$printSource = (string) file_get_contents(dirname(__DIR__) . '/resources/ui/app.css');

test('the print route is one read-only GET route driven by the registry', function () use ($printRouter): void {
    assert_true(str_contains($printRouter, "^/print/([a-z0-9-]{1,40})/([0-9]{1,18})$"), 'The /print/<type>/<id> route pattern is missing.');
    assert_true(str_contains($printRouter, 'pl_web_print($actorId, $company,'), 'The print route does not use the session company context.');
    assert_true(str_contains($printRouter, "\$printRequest !== null ? \$method !== 'GET'"), 'The print route accepts a method other than GET.');
    assert_true(!preg_match('~pl_print|pl_web_print~', substr($printRouter, 0, (int) strpos($printRouter, '$company = pl_web_context'))), 'Printing is reachable before the company context is resolved.');
});

test('print output carries no application shell', function () use ($printFrame): void {
    $markup = $printFrame;
    foreach (glob(dirname(__DIR__) . '/www/phpledger/templates/print/*.php') ?: [] as $view) {
        $markup .= (string) file_get_contents($view);
    }
    foreach (['layout.php', 'shell.php', 'context-strips.php', 'shell-sidebar', 'shell-topbar', 'shell-main', 'skip-link', 'crumbs', 'app.js'] as $shell) {
        assert_true(!str_contains($markup, $shell), 'Print output still carries the application shell part "' . $shell . '".');
    }
    assert_true(!str_contains($markup, '<form'), 'A print document must not carry a form.');
    assert_true(str_contains($printFrame, 'print-sheet paper-'), 'The print frame applies no paper class.');
});

test('print styles ship in the compiled stylesheet and keep tokens and logical properties', function () use ($printStyles, $printSource): void {
    foreach (['.print-sheet', '.paper-a4', '.paper-80mm', '.print-letterhead', '.print-controls'] as $rule) {
        assert_true(str_contains($printStyles, $rule), 'Compiled app.css is missing ' . $rule . '; rebuild it with npm run build:css.');
    }
    assert_true(str_contains($printStyles, '80mm'), 'The compiled 80 mm paper width is missing.');
    $block = substr($printSource, (int) strpos($printSource, 'Document printing (1.2 M2)'));
    assert_true(str_contains($block, '@media print'), 'The print block has no @media print rules.');
    assert_true(!preg_match('~[^-a-z](margin|padding)-(left|right)\s*:~', $block), 'Print CSS uses physical inline margins or padding.');
    assert_true(!preg_match('~[^-a-z](left|right)\s*:~', $block), 'Print CSS uses physical inline offsets.');
    assert_true(!preg_match('~text-align\s*:\s*(left|right)~', $block), 'Print CSS uses physical text alignment.');
    assert_true(!preg_match('~:\s*#[0-9A-Fa-f]{3,8}\b~', $block), 'Print CSS hard-codes a colour instead of using a design token.');
});
