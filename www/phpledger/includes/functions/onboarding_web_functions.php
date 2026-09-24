<?php
declare(strict_types=1);

/**
 * The "onboard a business" wizard (owner decision B50, 1.2.1 M10).
 *
 * Five stages, named after what onboarding actually decides — Start, Business, Source, Review,
 * Ready — laid out in the same Workbench language as the installer that runs just before it.
 * The installer's six compartments are its own: a database step, a schema build and server
 * checks have no counterpart here, and padding this wizard out to six slots would copy the
 * installer's shape without its content (`docs/design/1.2-2026-09/onboarding-and-packages`,
 * question 1).
 *
 * The Source stage is the new one and the reason for the redesign. Until now a new business
 * could only start from PHP Ledger's neutral chart; a sample company was a separate, isolated
 * thing you looked at. It now offers three starting points, and the middle one is what the
 * owner asked for: one sample company's **structure** — its chart, the modules it needs, its
 * numbering, its policies, its customers, suppliers and products — with none of its
 * transactions and every balance at zero.
 *
 * Nothing is created until the Review stage is confirmed. Every stage keeps its answers in the
 * session so leaving and coming back loses nothing, and a failed stage returns to itself with
 * what was typed.
 */

/** The tray, in order. The keys are what `?stage=` accepts. */
function pl_onboarding_stages(): array
{
    return ['start' => 'Start', 'business' => 'Business', 'source' => 'Source', 'review' => 'Review', 'ready' => 'Ready'];
}

/** @return array<string, mixed> the draft this session is building, empty before the first stage */
function pl_onboarding_draft(): array
{
    $draft = $_SESSION['onboarding'] ?? null;
    return is_array($draft) && is_array($draft['input'] ?? null) ? $draft : ['input' => [], 'request_key' => ''];
}

function pl_onboarding_store(array $input): void
{
    $_SESSION['onboarding'] = ['input' => $input,
        'request_key' => (string) (pl_onboarding_draft()['request_key'] ?: bin2hex(random_bytes(24)))];
}

/**
 * Which sources this starting point may choose between.
 *
 * `full` carries a sample's fictional history, so it only ever creates a separate sample
 * company. Past records bring their own chart through the opening cutover, so that path starts
 * blank and the Source stage says why rather than hiding itself (question 2).
 *
 * @return list<string>
 */
function pl_onboarding_sources(string $startMode): array
{
    return match ($startMode) {
        'sample' => ['skeleton', 'full'],
        'existing' => ['blank'],
        default => ['blank', 'skeleton'],
    };
}

/** The stage a draft is allowed to be on, so a bookmarked later stage cannot skip a decision. */
function pl_onboarding_reached(array $input): string
{
    if (!isset($input['start_mode'])) { return 'start'; }
    if (!isset($input['name'])) { return 'business'; }
    if (!isset($input['source'])) { return 'source'; }
    return 'review';
}

/**
 * Read one stage's answers, or throw with the sentence the person needs to fix it.
 *
 * @param array<string, mixed> $input the draft so far
 * @return array<string, mixed> the draft with this stage's answers in it
 */
function pl_onboarding_read_stage(string $stage, array $post, array $input): array
{
    if ($stage === 'start') {
        $startMode = pl_web_text($post, 'start_mode');
        if (!in_array($startMode, ['fresh', 'existing', 'sample'], true)) {
            throw new DomainException(pl_t('Choose whether you are starting a new business, bringing past records, or exploring a sample company.'));
        }
        if ($startMode === 'sample') { pl_require_sample_companies_allowed(); }
        if (($input['start_mode'] ?? '') !== $startMode) {
            // A different starting point allows a different set of sources, so a source chosen
            // under the old answer is dropped rather than carried into a stage that forbids it.
            unset($input['source'], $input['sample_pack'], $input['chart_choice']);
        }
        $input['start_mode'] = $startMode;
        return $input;
    }
    if ($stage === 'business') {
        $input['name'] = pl_web_text($post, 'name');
        $input['currency'] = pl_web_text($post, 'currency');
        $input['start_date'] = pl_web_text($post, 'start_date');
        $input['entity_type'] = pl_web_text($post, 'entity_type', 'other');
        pl_ledger_text($input['name'], 'Business name', 160);
        if (!isset(pl_base_currency_options()[$input['currency']])) {
            throw new DomainException(pl_t('Choose one of the supported base currencies.'));
        }
        pl_ledger_date($input['start_date']);
        if (!array_key_exists($input['entity_type'], pl_setup_entity_type_options())) {
            throw new DomainException(pl_t('Choose the type of business or organisation you are setting up.'));
        }
        $choice = pl_web_text($post, 'fiscal_year_end_choice');
        $input['fiscal_year_end_choice'] = $choice;
        $input['fiscal_year_end_custom'] = pl_web_text($post, 'fiscal_year_end_custom');
        if ($choice === 'custom') {
            $input['fiscal_year_end'] = $input['fiscal_year_end_custom'];
        } elseif (array_key_exists($choice, pl_fiscal_year_end_options())) {
            $input['fiscal_year_end'] = $choice;
        } else {
            throw new DomainException(pl_t('Choose a listed year-end option or enter a custom year end.'));
        }
        pl_ledger_date('2001-' . $input['fiscal_year_end']);
        return $input;
    }
    $source = pl_web_text($post, 'source');
    if (!in_array($source, pl_onboarding_sources((string) ($input['start_mode'] ?? 'fresh')), true)) {
        throw new DomainException(pl_t('Choose how much starting structure this business begins with.'));
    }
    $input['source'] = $source;
    unset($input['sample_pack']);
    if ($source !== 'blank') {
        $sampleId = pl_web_text($post, 'sample_pack');
        if (!in_array($sampleId, pl_sample_structure_ids(), true)) {
            throw new DomainException(pl_t('Choose which sample company this business starts from.'));
        }
        if ($source === 'skeleton' && pl_sample_structure_read($sampleId) === null) {
            throw new DomainException(pl_t('This sample company does not publish a structure yet, so it cannot start a business. Choose another, or bring in the full sample.'));
        }
        $input['sample_pack'] = $sampleId;
    }
    $input['chart_choice'] = ($input['start_mode'] ?? '') === 'existing' ? pl_web_text($post, 'chart_choice', 'neutral') : 'neutral';
    if (!in_array($input['chart_choice'], ['neutral', 'bring_own'], true)) {
        throw new DomainException(pl_t('Choose a chart starting point.'));
    }
    $input['zero_balances_confirmed'] = pl_web_text($post, 'zero_balances_confirmed') === '1';
    if (($input['start_mode'] ?? '') === 'fresh' && !$input['zero_balances_confirmed']) {
        throw new DomainException(pl_t('Confirm that this business starts with no prior balances, or go back and choose Bring past records.'));
    }
    return $input;
}

/**
 * Turn the draft into the payload `pl_setup_company()` accepts.
 *
 * A full sample replays a pinned history, so its book has to begin where that history begins
 * and the Review stage says so. A skeleton replays nothing, so it keeps the dates that were
 * chosen on the Business stage.
 *
 * @return array<string, mixed>
 */
function pl_onboarding_payload(array $input, string $templateDigest): array
{
    $payload = [
        'name' => $input['name'] ?? '', 'currency' => $input['currency'] ?? '',
        'start_date' => $input['start_date'] ?? '', 'fiscal_year_end' => $input['fiscal_year_end'] ?? '12-31',
        'start_mode' => $input['start_mode'] ?? 'fresh', 'chart_choice' => $input['chart_choice'] ?? 'neutral',
        'source' => $input['source'] ?? 'blank', 'template_digest' => $templateDigest,
        'zero_balances_confirmed' => ($input['zero_balances_confirmed'] ?? false) === true,
    ];
    if (isset($input['sample_pack'])) {
        $payload['sample_pack'] = $input['sample_pack'];
        if ($payload['source'] === 'full') {
            $pack = pl_demo_sample((string) $input['sample_pack']);
            $payload['start_date'] = (string) $pack['start_date'];
            $payload['fiscal_year_end'] = '12-31';
        }
    }
    return $payload;
}

/**
 * Everything the Review stage shows about the chosen source, read once so the summary, the
 * chart preview and the contents list cannot describe it differently.
 *
 * It reaches the screen as `sourceView`, never `view`: pl_render() extracts a route's data with
 * EXTR_SKIP while already holding `$view` as the template's own name, so a data key called
 * `view` is dropped without a word and the screen reads a string where it expects an array.
 *
 * @return array<string, mixed>
 */
function pl_onboarding_source_view(array $input): array
{
    $source = $input['source'] ?? 'blank';
    $view = ['source' => $source, 'sample' => null, 'structure' => null, 'summary' => [], 'accounts' => []];
    if (!isset($input['sample_pack'])) {
        return $view;
    }
    $sample = pl_demo_sample((string) $input['sample_pack']);
    $view['sample'] = ['id' => (string) $sample['id'], 'name' => (string) $sample['name'],
        'business' => (string) ($sample['business'] ?? ''), 'start_date' => (string) $sample['start_date'],
        'journals' => (int) ($sample['journal_count'] ?? 0), 'drafts' => (int) ($sample['draft_count'] ?? 0)];
    if ($source === 'skeleton') {
        $structure = pl_sample_structure_read((string) $input['sample_pack']);
        if ($structure !== null) {
            $view['structure'] = $structure;
            $view['summary'] = pl_sample_structure_summary($structure);
            $view['accounts'] = $structure['accounts'];
        }
    }
    return $view;
}

/**
 * The plain facts the Ready stage lists, in the installer's own manifest style: what exists
 * now, stated once each, with no congratulation.
 *
 * @return list<string>
 */
/** Current scoped totals, including resumed setup after later activity. */
function pl_onboarding_book_counts(array $company): array
{
    return [
        'journals' => (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_journals WHERE company_id=%i AND book_id=%i', (int) $company['id'], (int) $company['book_id']),
        'drafts' => (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_documents WHERE company_id=%i AND book_id=%i AND status='draft'", (int) $company['id'], (int) $company['book_id'])
            + (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_ar_documents WHERE company_id=%i AND book_id=%i AND status='draft'", (int) $company['id'], (int) $company['book_id'])
            + (int) DB::queryFirstField("SELECT COUNT(*) FROM pl_general_drafts WHERE company_id=%i AND book_id=%i AND status='draft'", (int) $company['id'], (int) $company['book_id']),
    ];
}

function pl_onboarding_manifest(array $company, ?array $receipt, array $packages): array
{
    $lines = [];
    $accounts = count(array_filter($company['accounts'], static fn (array $row): bool => $row['level'] === 'account'));
    if ($receipt !== null) {
        $created = $receipt['created'];
        $lines[] = pl_t('{total} accounts, {brought} of them brought in from the sample structure', [
            'total' => $accounts, 'brought' => (int) $created['accounts']]);
        if ($created['modules'] !== []) {
            $lines[] = pl_t('Modules active: {modules}', ['modules' => implode(', ', array_map('pl_module_label', $created['modules']))]);
        }
        if ((int) $created['parties'] > 0 || (int) $created['products'] > 0) {
            $lines[] = pl_t('{parties} customer and supplier records and {products} products, imported at zero', [
                'parties' => (int) $created['parties'], 'products' => (int) $created['products']]);
        }
        if ($created['number_series'] !== []) {
            $lines[] = pl_tn('Numbering ready for {count} document type', 'Numbering ready for {count} document types',
                count($created['number_series']), ['count' => count($created['number_series'])]);
        }
    } else {
        $lines[] = pl_tn('{count} account created', '{count} accounts created', $accounts, ['count' => $accounts]);
    }
    foreach ($packages as $slug => $outcome) {
        $lines[] = match ($outcome) {
            'activated', 'already_active' => pl_t('Package {slug} is active', ['slug' => $slug]),
            'not_installed' => pl_t('Package {slug} is not installed on this server; install it in Admin > Packages', ['slug' => $slug]),
            'refused' => pl_t('Package {slug} could not be activated; Admin > Packages says why', ['slug' => $slug]),
            default => pl_t('Package {slug} is named by this sample but this release has no package runtime', ['slug' => $slug]),
        };
    }
    $lines[] = pl_t('Financial year ends {date}', ['date' => pl_fiscal_year_end_label((string) $company['fiscal_year_end'])]);
    $counts = pl_onboarding_book_counts($company);
    $lines[] = $counts['journals'] > 0 || $counts['drafts'] > 0
        ? pl_t('{journals} posted journals and {drafts} open drafts are recorded in these books.', $counts)
        : ($company['setup_status'] === 'opening_required'
            ? pl_t('Opening balances and unpaid documents are still to be brought over')
            : pl_t('Zero transactions: these books start empty'));
    return $lines;
}

/** A module's own manifest name, so a screen never invents a label for one. */
function pl_module_label(string $id): string
{
    return (string) (pl_module_registry()[$id]['name'] ?? $id);
}

/** Render one stage, or handle one submission. */
function pl_web_onboarding(int $actorId, array $user, string $method): never
{
    $template = pl_starter_template();
    if ($method === 'POST') {
        $action = pl_web_text($_POST, 'action');
        $draft = pl_onboarding_draft();
        if ($action === 'next') {
            $stage = pl_web_text($_POST, 'stage');
            if (!in_array($stage, ['start', 'business', 'source'], true)) {
                throw new DomainException('Choose a valid setup stage.');
            }
            try {
                pl_onboarding_store(pl_onboarding_read_stage($stage, $_POST, $draft['input']));
                $order = array_keys(pl_onboarding_stages());
                pl_redirect('/onboarding?stage=' . $order[(int) array_search($stage, $order, true) + 1]);
            } catch (DomainException $error) {
                pl_form_failure('/onboarding?stage=' . $stage, $_POST, $error->getMessage());
            }
        }
        if ($action === 'preview') {
            // The single-request path an existing caller may still post. It lands on Review,
            // which is the only place a business is ever created from.
            try {
                pl_onboarding_store(pl_onboarding_preview_input($_POST, (string) $template['digest']));
                pl_redirect('/onboarding?stage=review');
            } catch (DomainException $error) {
                pl_form_failure('/onboarding?stage=business', $_POST, $error->getMessage());
            }
        }
        if ($action === 'confirm') {
            if ($draft['request_key'] === '') {
                pl_notice(pl_t('Please review your business details again before confirming.'));
                pl_redirect('/onboarding');
            }
            try {
                $payload = pl_onboarding_payload($draft['input'], (string) $template['digest']);
                $created = pl_setup_company($actorId, $payload, $draft['request_key']);
                $companyId = (int) $created['id'];
                $bookId = (int) $created['book_id'];
                // Packages are activated after the company's own transaction has committed: a
                // package runs its own migrations, and schema changes do not roll back.
                $structure = ($payload['source'] ?? 'blank') === 'skeleton'
                    ? pl_sample_structure_read((string) $payload['sample_pack']) : null;
                $packages = $structure === null ? [] : pl_sample_structure_activate_packages(
                    $actorId, $structure['packages'],
                    'Required by the ' . $structure['id'] . ' sample structure this business was created from.',
                    'skeleton:' . $companyId . ':package:'
                );
                $_SESSION['company_id'] = $companyId;
                $_SESSION['onboarding_done'] = ['company_id' => $companyId, 'packages' => $packages];
                unset($_SESSION['onboarding']);
                pl_redirect('/onboarding?stage=ready');
            } catch (DomainException $error) {
                pl_form_failure('/onboarding?stage=review', [], $error->getMessage());
            }
        }
        throw new DomainException('Choose a valid setup action.');
    }

    $stages = pl_onboarding_stages();
    $requested = pl_web_text($_GET, 'stage');
    // `?step=N` is what 1.2 and earlier linked to. It keeps working and lands on the stage that
    // now asks the same question.
    $legacy = ['1' => 'start', '2' => 'business', '3' => 'business', '4' => 'source', '5' => 'review', 'preview' => 'review'];
    $stage = isset($stages[$requested]) ? $requested : ($legacy[pl_web_text($_GET, 'step')] ?? 'start');

    if ($stage === 'ready') {
        $done = $_SESSION['onboarding_done'] ?? null;
        if (!is_array($done)) { pl_redirect('/onboarding'); }
        $company = pl_company_context($actorId, (int) $done['company_id']);
        $receipt = pl_company_sample_import($actorId, (int) $company['id'], (int) $company['book_id']);
        pl_render('onboarding', ['title' => pl_t('Your business is ready'), 'user' => $user, 'stage' => 'ready',
            'stages' => $stages, 'company' => null, 'created' => $company, 'receipt' => $receipt,
            'manifest' => pl_onboarding_manifest($company, $receipt, is_array($done['packages'] ?? null) ? $done['packages'] : []),
            'form' => ['input' => [], 'message' => ''], 'input' => [], 'template' => $template,
            'sources' => [], 'sourceView' => []]);
    }

    $form = pl_form_state('/onboarding?stage=' . $stage);
    $draft = pl_onboarding_draft();
    $input = $form['input'] !== [] ? $form['input'] + $draft['input'] : $draft['input'];
    $reached = pl_onboarding_reached($draft['input']);
    $order = array_keys($stages);
    if ((int) array_search($stage, $order, true) > (int) array_search($reached, $order, true)) {
        pl_redirect('/onboarding?stage=' . $reached);
    }
    pl_render('onboarding', ['title' => pl_t('Set up a business'), 'user' => $user, 'stage' => $stage,
        'stages' => $stages, 'company' => null, 'created' => null, 'receipt' => null, 'manifest' => [],
        'form' => $form, 'input' => $input, 'template' => $template,
        'sources' => $stage === 'source' ? pl_sample_skeleton_sources() : [],
        'sourceView' => $stage === 'review' ? pl_onboarding_source_view($input) : []]);
}
