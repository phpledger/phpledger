<?php
declare(strict_types=1);

/*
 * Urdu (ur) interface catalogue — DRAFT, UNREVIEWED.
 *
 * ---------------------------------------------------------------------------------------------
 * UNREVIEWED. No named human reviewer has passed this wording. It is a machine-written first
 * draft, produced with the 1.2 M11 work so that the right-to-left screens, the font, the digit
 * grouping and the fallback behaviour could be built and tested against real Urdu text rather
 * than against a placeholder. A reviewer's sign-off is a separate release gate (decision B3), and
 * until it happens no language is "supported": pl_locale_review_state('ur') answers 'draft', the
 * language switch says so on screen, and resources/lang/README.md records the same thing.
 * ---------------------------------------------------------------------------------------------
 *
 * WHAT A REVIEWER SHOULD KNOW BEFORE CHANGING ANYTHING
 *
 * - The key is the English source string and must never be edited. Editing a key does not rename
 *   a translation, it silently orphans it: the screen falls back to English and nothing fails.
 * - What is NOT here falls back to English per key, by design. This draft deliberately covers the
 *   vocabulary a person meets on every screen — navigation, buttons, column headings, statuses,
 *   form labels, months — and leaves the long explanatory sentences in English rather than
 *   guessing at them. A half-translated screen in plain English is honest; a confidently wrong
 *   Urdu sentence about a posted journal is not.
 * - Accounting terms are the ones Pakistani commercial practice actually uses, which means the
 *   established loanwords (ڈیبٹ, کریڈٹ, انوائس, بیلنس شیٹ) rather than purist coinages a
 *   bookkeeper would not recognise. Where a traditional word is still current it is used
 *   (کھاتہ for account, میزان for balance in a report title).
 * - Figures, account codes and dates are NOT translated here and must not be: pl_money(),
 *   pl_date_label() and pl_ltr() write them, in Latin digits, isolated so the bidirectional
 *   algorithm cannot reorder them. Eastern Arabic-Indic digits are deliberately not used — a
 *   figure a person has to check against a Pakistani bank statement must be written the way the
 *   statement writes it.
 * - A plural value is an array keyed by the CLDR forms Urdu uses, which are exactly one and
 *   other (pl_i18n_plural_table()). Any other key in that array is dropped by the loader.
 * - Region wording goes in ur-PK.php, which is loaded on top of this file and states only what it
 *   changes. There is none today.
 *
 * See resources/lang/README.md for the file format and docs/DEVELOPMENT.md, "How to add a
 * translatable string", for the call sites.
 */

return [
    // ---------------------------------------------------------------- product and shell chrome
    'Skip to content' => 'مواد پر جائیں',
    'Main navigation' => 'مرکزی نیویگیشن',
    'Workspace' => 'ورک اسپیس',
    'Breadcrumb' => 'صفحاتی راستہ',
    'User menu' => 'صارف مینو',
    'Close navigation' => 'نیویگیشن بند کریں',
    'Toggle navigation' => 'نیویگیشن دکھائیں یا چھپائیں',
    'Dismiss notification' => 'اطلاع ہٹائیں',
    'Business logo' => 'کاروباری لوگو',
    'PHP Ledger home' => 'PHP Ledger کا مرکزی صفحہ',
    'PHP Ledger businesses' => 'PHP Ledger کے کاروبار',
    'Search or jump to…' => 'تلاش کریں یا براہِ راست جائیں…',
    'New' => 'نیا',
    'Quick create' => 'فوری اندراج',
    'Sample' => 'نمونہ',
    'Sample guide' => 'نمونہ رہنما',
    'Your businesses' => 'آپ کے کاروبار',
    'Add a business' => 'کاروبار شامل کریں',
    'Business setup' => 'کاروبار کی ترتیب',
    'Switch business' => 'کاروبار تبدیل کریں',
    'Your profile' => 'آپ کا پروفائل',
    'Sign out' => 'سائن آؤٹ',
    'Sign in' => 'سائن ان',
    'Leave demo' => 'ڈیمو سے نکلیں',
    'Current business: {business}. Switch business' => 'موجودہ کاروبار: {business}۔ کاروبار تبدیل کریں',

    // ---------------------------------------------------------------- the language switch itself
    'Language' => 'زبان',
    'Change language' => 'زبان تبدیل کریں',
    'Choose a language from the list.' => 'فہرست میں سے ایک زبان منتخب کریں۔',
    '{language} (draft translation)' => '{language} (مسودہ ترجمہ)',
    'This translation is an unreviewed draft. Anything not yet translated is shown in English.'
        => 'یہ ترجمہ ایک غیر نظرثانی شدہ مسودہ ہے۔ جو کچھ ابھی ترجمہ نہیں ہوا وہ انگریزی میں دکھایا جاتا ہے۔',

    // ---------------------------------------------------------------- navigation groups and items
    'Daily work' => 'روزمرہ کام',
    'Sales' => 'فروخت',
    'Purchases' => 'خریداری',
    'Inventory' => 'انوینٹری',
    'Banking' => 'بینکاری',
    'Reports' => 'رپورٹس',
    'Setup' => 'ترتیبات',
    'Home' => 'ہوم',
    'Help' => 'مدد',
    'Receipts & expenses' => 'وصولیاں اور اخراجات',
    'Point of sale' => 'پوائنٹ آف سیل',
    'Journals' => 'جرنل',
    'Invoices' => 'انوائسز',
    'Customers' => 'گاہک',
    'Bills' => 'بل',
    'Purchase orders' => 'خریداری کے آرڈر',
    'Suppliers' => 'سپلائرز',
    'Products & stock' => 'پروڈکٹس اور اسٹاک',
    'Stock issues & returns' => 'اسٹاک اجرا اور واپسی',
    'Van settlement' => 'وین سیٹلمنٹ',
    'Stock by location' => 'مقام کے لحاظ سے اسٹاک',
    'Bank reconciliation' => 'بینک مطابقت',
    'All reports' => 'تمام رپورٹس',
    'Profit & loss' => 'نفع و نقصان',
    'Receivables & payables ageing' => 'واجبات اور مطالبات کی عمر',
    'Balance sheet' => 'بیلنس شیٹ',
    'Trial balance' => 'آزمائشی میزان',
    'Account statement' => 'کھاتے کا گوشوارہ',
    'Cash forecast' => 'نقدی کی پیش گوئی',
    'Chart of accounts' => 'کھاتوں کا چارٹ',
    'Owner and partners' => 'مالک اور شراکت دار',
    'Tax codes' => 'ٹیکس کوڈز',
    'Opening balances' => 'ابتدائی بیلنس',
    'Opening documents' => 'ابتدائی دستاویزات',
    'Periods' => 'ادوار',
    'Document numbering' => 'دستاویزی نمبر شماری',
    'Accounting policies' => 'اکاؤنٹنگ پالیسیاں',
    'Company profile' => 'کمپنی پروفائل',
    'Modules' => 'ماڈیولز',
    'Users' => 'صارفین',
    'Roles' => 'کردار',
    'Cost visibility' => 'لاگت کی نمائش',
    'Connections & API' => 'کنکشنز اور API',

    // ---------------------------------------------------------------- quick create
    'Expense' => 'خرچ',
    'Receipt' => 'رسید',
    'Invoice' => 'انوائس',
    'Bill' => 'بل',
    'Journal entry' => 'جرنل اندراج',
    'Purchase order' => 'خریداری کا آرڈر',
    'Customer or supplier' => 'گاہک یا سپلائر',
    'Product' => 'پروڈکٹ',

    // ---------------------------------------------------------------- roles
    'Owner' => 'مالک',
    'Accountant' => 'اکاؤنٹنٹ',
    'Viewer' => 'ناظر',

    // ---------------------------------------------------------------- column headings and labels
    'Account' => 'کھاتہ',
    'Accounts' => 'کھاتے',
    'Action' => 'عمل',
    'Actions' => 'اعمال',
    'Amount' => 'رقم',
    'Balance' => 'بیلنس',
    'Category' => 'زمرہ',
    'Classification' => 'درجہ بندی',
    'Code' => 'کوڈ',
    'Credit' => 'کریڈٹ',
    'Currency' => 'کرنسی',
    'Customer' => 'گاہک',
    'Date' => 'تاریخ',
    'Dated' => 'مورخہ',
    'Debit' => 'ڈیبٹ',
    'Description' => 'تفصیل',
    'Details' => 'تفصیلات',
    'Difference' => 'فرق',
    'Direction' => 'سمت',
    'Document' => 'دستاویز',
    'Document date' => 'دستاویز کی تاریخ',
    'Document lines' => 'دستاویزی سطریں',
    'Driver' => 'ڈرائیور',
    'Due' => 'واجب الادا',
    'Due date' => 'واجب الادا تاریخ',
    'Email' => 'ای میل',
    'From' => 'از',
    'History' => 'تاریخچہ',
    'Item' => 'شے',
    'Journal' => 'جرنل',
    'Line discounts' => 'سطری رعایت',
    'Lines' => 'سطریں',
    'Name' => 'نام',
    'Number' => 'نمبر',
    'Party' => 'فریق',
    'Phone' => 'فون',
    'Purpose' => 'مقصد',
    'Quantity' => 'مقدار',
    'Reason' => 'وجہ',
    'Reason for this change' => 'اس تبدیلی کی وجہ',
    'Reference' => 'حوالہ',
    'Role' => 'کردار',
    'Status' => 'حیثیت',
    'Subtotal' => 'ذیلی میزان',
    'Tax' => 'ٹیکس',
    'Terms' => 'شرائط',
    'To' => 'بنام',
    'Total' => 'کل',
    'Type' => 'قسم',
    'Unit' => 'اکائی',
    'Unit price' => 'فی اکائی قیمت',
    'Vehicle' => 'گاڑی',
    'Vendor' => 'سپلائر',
    'As of' => 'بتاریخ',
    'Base currency' => 'بنیادی کرنسی',
    'Cash or bank account' => 'نقد یا بینک کھاتہ',
    'Cash received' => 'وصول شدہ نقدی',
    'Books and controls' => 'کھاتے اور نگرانی',
    'Goods entering' => 'آنے والا مال',
    'Goods leaving' => 'جانے والا مال',
    'Area / route' => 'علاقہ / روٹ',
    'All locations' => 'تمام مقامات',
    'Accounting periods' => 'محاسباتی ادوار',
    'Balance receivable' => 'قابلِ وصول بیلنس',
    'Stock documents' => 'اسٹاک دستاویزات',
    'Posting preview' => 'اندراج کا پیش منظر',
    'Open statement' => 'گوشوارہ کھولیں',
    'Amount ({currency})' => 'رقم ({currency})',
    'Credit ({currency})' => 'کریڈٹ ({currency})',
    'Debit ({currency})' => 'ڈیبٹ ({currency})',
    'Total ({currency})' => 'کل ({currency})',
    '{currency} balance' => '{currency} بیلنس',

    // ---------------------------------------------------------------- statuses and short answers
    'Active' => 'فعال',
    'Inactive' => 'غیر فعال',
    'All' => 'تمام',
    'Yes' => 'ہاں',
    'No' => 'نہیں',
    'Balanced' => 'متوازن',
    'Needs balancing' => 'توازن درکار',
    'Returnable' => 'قابلِ واپسی',

    // ---------------------------------------------------------------- common actions
    'Cancel' => 'منسوخ کریں',
    'Add line' => 'سطر شامل کریں',
    'Back to list' => 'فہرست پر واپس',
    'Back to transactions' => 'لین دین پر واپس',
    'Back to the record' => 'ریکارڈ پر واپس',
    'Back to account statement' => 'کھاتے کے گوشوارے پر واپس',
    'Back to ageing report' => 'عمر کی رپورٹ پر واپس',
    'Back to {target}' => '{target} پر واپس',
    'Edit draft' => 'مسودے میں ترمیم',
    'Filter' => 'فلٹر',
    'Post journal' => 'جرنل پوسٹ کریں',
    'Print formats' => 'پرنٹ کی شکلیں',
    'Reload the latest saved version' => 'تازہ ترین محفوظ نسخہ دوبارہ لوڈ کریں',

    // ---------------------------------------------------------------- months, for pl_date_label()
    // Urdu has no settled three-letter abbreviation for a month, which is why pl_date_format_pattern()
    // gives Urdu the full-name pattern 'd F Y'. The short English forms are translated too, because
    // a module or a print template may still ask for one.
    'January' => 'جنوری',   'Jan' => 'جنوری',
    'February' => 'فروری',  'Feb' => 'فروری',
    'March' => 'مارچ',      'Mar' => 'مارچ',
    'April' => 'اپریل',     'Apr' => 'اپریل',
    'May' => 'مئی',
    'June' => 'جون',        'Jun' => 'جون',
    'July' => 'جولائی',     'Jul' => 'جولائی',
    'August' => 'اگست',     'Aug' => 'اگست',
    'September' => 'ستمبر', 'Sep' => 'ستمبر',
    'October' => 'اکتوبر',  'Oct' => 'اکتوبر',
    'November' => 'نومبر',  'Nov' => 'نومبر',
    'December' => 'دسمبر',  'Dec' => 'دسمبر',

    // ---------------------------------------------------------------- counted strings
    // Urdu uses the CLDR forms one and other, and only those two. See pl_i18n_plural_table().
    '{count} item' => ['one' => '{count} شے', 'other' => '{count} اشیا'],
    '{quantity} unit' => ['one' => '{quantity} اکائی', 'other' => '{quantity} اکائیاں'],
    '{units} unit' => ['one' => '{units} اکائی', 'other' => '{units} اکائیاں'],
];
