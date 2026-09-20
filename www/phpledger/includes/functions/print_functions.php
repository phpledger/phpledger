<?php
declare(strict_types=1);

/*
 * Document printing (1.2 M2).
 *
 * One registry keyed by document type and paper format decides what /print/<type>/<id>
 * can render. A new document type is added here and as a view file under
 * templates/print; the route itself never changes. Printing is read-only: a loader
 * reads through an existing scoped accounting service, so the company/book scope and
 * the role rule of the record screen are enforced once, in that service.
 */

/** Paper format used when the request does not name one. */
const PL_PRINT_DEFAULT_FORMAT = 'a4';

/**
 * Available (type, format) pairs.
 *
 * Each type carries the human label shown on the print itself, the read-only loader
 * that returns its data, and the record screen a reader returns to. Each format
 * carries its own human label, its view file under templates/print and the paper
 * class the print frame applies.
 *
 * @return array<string, array{label: string, loader: callable-string, reference: callable-string, record: string, formats: array<string, array{label: string, view: string, paper: string}>}>
 */
function pl_print_templates(): array
{
    return [
        'settlement' => [
            'label' => 'Payment receipt',
            'loader' => 'pl_settlement_receipt',
            'reference' => 'pl_print_settlement_reference',
            'record' => '/journals/detail',
            'formats' => [
                'a4' => ['label' => 'A4 page', 'view' => 'settlement-a4.php', 'paper' => 'a4'],
                '80mm' => ['label' => '80 mm roll', 'view' => 'settlement-80mm.php', 'paper' => '80mm'],
            ],
        ],
    ];
}

/**
 * Resolve one registered template. An unknown type or format is refused, never guessed.
 *
 * @return array{type: string, format: string, label: string, format_label: string, view: string, paper: string, loader: callable-string, reference: callable-string, record: string}
 */
function pl_print_template(string $type, string $format = ''): array
{
    $templates = pl_print_templates();
    if (!isset($templates[$type])) {
        throw new DomainException('That document type cannot be printed.');
    }
    $document = $templates[$type];
    $format = $format === '' ? PL_PRINT_DEFAULT_FORMAT : $format;
    if (!isset($document['formats'][$format])) {
        throw new DomainException('Choose one of the available paper formats for this document.');
    }
    $paper = $document['formats'][$format];
    return [
        'type' => $type,
        'format' => $format,
        'label' => $document['label'],
        'format_label' => $paper['label'],
        'view' => $paper['view'],
        'paper' => $paper['paper'],
        'loader' => $document['loader'],
        'reference' => $document['reference'],
        'record' => $document['record'],
    ];
}

/** The printed reference of a settlement receipt is its posted journal reference. */
function pl_print_settlement_reference(array $data): string
{
    return (string) $data['journal']['reference'];
}

/**
 * The other formats of the same document, for the on-screen format links.
 *
 * @return array<int, array{format: string, label: string, url: string}>
 */
function pl_print_formats(string $type, int $id): array
{
    $links = [];
    foreach (pl_print_templates()[$type]['formats'] ?? [] as $format => $paper) {
        $links[] = ['format' => $format, 'label' => $paper['label'], 'url' => pl_url('/print/' . $type . '/' . $id, ['format' => $format])];
    }
    return $links;
}

/**
 * Company identity for the letterhead. Only fields the schema already has are used:
 * the optional installation logo (migration 033) and the company's own name, book and
 * functional currency. There is no company address or tax-registration column, so the
 * letterhead states none; party addresses and registrations come from the party record.
 *
 * @return array{name: string, book: string, currency: string, logo: array{url: string, width: int, height: int}|null}
 */
function pl_print_letterhead(array $company): array
{
    $logo = pl_logo_current();
    return [
        'name' => (string) $company['name'],
        'book' => (string) ($company['book_name'] ?? ''),
        'currency' => (string) $company['currency'],
        'logo' => $logo === null ? null : ['url' => pl_logo_url($logo), 'width' => $logo['width'], 'height' => $logo['height']],
    ];
}

/**
 * Readable lines from one party detail list (addresses or registrations). The stored
 * rows are free-form maps, so every text value is shown in stored order rather than
 * assuming field names this application does not define.
 *
 * @param mixed $rows
 * @return array<int, string>
 */
function pl_print_party_lines(mixed $rows, string $role = ''): array
{
    if (!is_array($rows)) {
        return [];
    }
    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row) || ($role !== '' && ($row['role'] ?? null) !== $role)) {
            continue;
        }
        $parts = [];
        foreach ($row as $key => $value) {
            if ($key !== 'role' && is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }
        if ($parts !== []) {
            $lines[] = implode(' · ', $parts);
        }
    }
    return $lines;
}

/**
 * GET /print/<type>/<id>[?format=...] — the browser adapter.
 *
 * The company and book come from the signed-in session context, exactly as on the
 * record screen, so an id belonging to another company is not found in this scope and
 * the loader refuses it. The role rule is the loader's, which is the record screen's.
 * This route reads only: it accepts no POST, posts nothing and writes nothing.
 */
function pl_web_print(int $actorId, array $company, string $type, int $id, string $format): never
{
    $template = pl_print_template($type, $format);
    if ($id < 1) {
        throw new DomainException('Choose a document to print.');
    }
    $loader = $template['loader'];
    $data = $loader($actorId, (int) $company['id'], (int) $company['book_id'], $id);
    $reference = $template['reference'];
    pl_print_render($template, [
        'template' => $template,
        'company' => $company,
        'letterhead' => pl_print_letterhead($company),
        'formats' => pl_print_formats($type, $id),
        'recordId' => $id,
        'reference' => $reference($data),
        'title' => $template['label'] . ' ' . $reference($data),
        'document' => $data,
    ]);
}

/** Render one print view without the application shell and stop. */
function pl_print_render(array $template, array $data): never
{
    $view = PL_APP . '/templates/print/' . $template['view'];
    if (!is_file($view)) {
        throw new LogicException('Unknown print template.');
    }
    extract($data, EXTR_SKIP);
    require PL_APP . '/templates/print/frame.php';
    exit;
}
