"""Country nomenclature for legal forms — the proposed contract for resources/locale/legal-forms.json.

Each country lists the forms in the words its own registrar uses. Every entry names the canonical
family PHP Ledger already knows (pl_legal_forms(): sole_proprietor, partnership, llp, llc,
single_member_company, private_limited, public_limited, corporation, non_profit, other), so the
share ledger, partner ratio and owner-equity behaviour keep working, and adds the plain-language
guidance shown under the picker. The identifier labels rename the invoice fields per country.
Nothing here activates a tax rule; it is wording and guidance only.
"""
from html import escape as e

FAMILIES = {
    'sole_proprietor': ('you alone', 'drawings; what you put in is your capital'),
    'partnership': ('partners with a profit-sharing ratio', 'partner drawings and the year-end profit split'),
    'llp': ('members (partners) with limited liability', 'partner drawings by the agreed ratio'),
    'llc': ('members', 'member distributions'),
    'single_member_company': ('one shareholder; directors run it', 'salary or dividends, tracked in the share ledger'),
    'private_limited': ('shareholders; directors run it', 'salary or dividends, tracked in the share ledger'),
    'public_limited': ('shareholders; a board runs it', 'dividends, tracked in the share ledger'),
    'corporation': ('shareholders; a board runs it', 'salary or dividends, tracked in the share ledger'),
    'non_profit': ('members or trustees, nobody owns it', 'nothing: surplus stays in the organisation'),
    'other': ('set once you choose a form', 'set once you choose a form'),
}

COUNTRIES = {
    'PK': {
        'default': 'pvt_ltd', 'currency': 'PKR', 'fiscal_year_end': '06-30',
        'name': 'Pakistan', 'registrar': 'SECP',
        'labels': {'reg_number': 'SECP registration number (CUIN)', 'reg_authority': 'Registered with', 'tax1': 'NTN', 'tax2': 'STRN'},
        'placeholders': {'reg_number': '0123456', 'tax1': '1234567-8', 'tax2': '12-34-5678-901-23'},
        'forms': [
            ('sole_proprietor', 'sole_proprietor', 'Sole proprietorship', 'One owner and no separate company. Registered with FBR for an NTN; nothing is filed with SECP.', 'FBR', 'NTN, and STRN if registered for sales tax'),
            ('partnership', 'partnership', 'Partnership firm (AOP)', '2 to 20 partners under the Partnership Act 1932; taxed as an association of persons.', 'the Registrar of Firms', 'the firm\'s NTN, and STRN if registered'),
            ('llp', 'llp', 'Limited liability partnership (LLP)', 'Partners with limited liability under the LLP Act 2017.', 'SECP', 'LLP number, NTN'),
            ('smc', 'single_member_company', 'Single member company — (SMC-Private) Limited', 'A private company with one shareholder under the Companies Act 2017.', 'SECP', 'CUIN, NTN, STRN'),
            ('pvt_ltd', 'private_limited', 'Private limited company — (Private) Limited', '2 to 50 shareholders; shares are never offered to the public. The usual company.', 'SECP', 'CUIN, NTN, STRN'),
            ('public_ltd', 'public_limited', 'Public limited company — Limited (listed or unlisted)', 'Shares may be offered to the public; a listed company also answers to the PSX.', 'SECP', 'CUIN, NTN, STRN'),
            ('section_42', 'non_profit', 'Section 42 company, trust or society', 'Not for profit. Section 42 companies are licensed by SECP; trusts and societies by the provincial registrar.', 'SECP or the provincial registrar', 'NTN'),
            ('other', 'other', 'Other or not sure', 'Pick the closest form; your registration papers or your accountant will say. It can be changed later in Company profile.', 'your registrar', 'the numbers on your registration'),
        ],
    },
    'GB': {
        'default': 'ltd', 'currency': 'GBP', 'fiscal_year_end': '12-31',
        'name': 'United Kingdom', 'registrar': 'Companies House',
        'labels': {'reg_number': 'Company number', 'reg_authority': 'Registered with', 'tax1': 'UTR', 'tax2': 'VAT registration number'},
        'placeholders': {'reg_number': '12345678', 'tax1': '1234567890', 'tax2': 'GB123456789'},
        'forms': [
            ('sole_trader', 'sole_proprietor', 'Sole trader', 'One person trading. Registered with HMRC for Self Assessment, not at Companies House.', 'HMRC', 'UTR, and VAT number if registered'),
            ('partnership', 'partnership', 'Partnership', 'Two or more people trading together; the partnership and each partner register with HMRC.', 'HMRC', 'the partnership UTR, and VAT number if registered'),
            ('llp', 'llp', 'Limited liability partnership (LLP)', 'Members with limited liability; incorporated at Companies House.', 'Companies House', 'company number, UTR, VAT number'),
            ('ltd', 'private_limited', 'Private company limited by shares (Ltd)', 'The usual small company: shareholders own it, directors run it. One shareholder is fine.', 'Companies House', 'company number, UTR, VAT number'),
            ('plc', 'public_limited', 'Public limited company (PLC)', 'Shares may be offered to the public; at least £50,000 of share capital.', 'Companies House', 'company number, UTR, VAT number'),
            ('cic', 'private_limited', 'Community interest company limited by shares (CIC)', 'A limited company for a social purpose: shareholders, an asset lock and a dividend cap. The CIC Regulator sits alongside Companies House.', 'Companies House and the CIC Regulator', 'company number, UTR, VAT number if registered'),
            ('clg', 'non_profit', 'Company limited by guarantee, CIC limited by guarantee, or charity (CIO)', 'No shareholders; members guarantee a nominal sum. CICs and charities have their own regulator too.', 'Companies House or the Charity Commission', 'company or charity number, VAT number if registered'),
            ('other', 'other', 'Other or not sure', 'Pick the closest form; your registration papers or your accountant will say. It can be changed later in Company profile.', 'your registrar', 'the numbers on your registration'),
        ],
    },
    'US': {
        'default': 'llc', 'currency': 'USD', 'fiscal_year_end': '12-31',
        'name': 'United States', 'registrar': 'the Secretary of State of the state of formation',
        'labels': {'reg_number': 'State entity number', 'reg_authority': 'State of formation', 'tax1': 'EIN', 'tax2': 'State sales tax permit'},
        'placeholders': {'reg_number': 'e.g. 20261234567', 'tax1': '12-3456789', 'tax2': ''},
        'forms': [
            ('sole_proprietorship', 'sole_proprietor', 'Sole proprietorship', 'You and the business are one for tax. A DBA name may be filed with the state or county.', 'no registrar; the IRS issues an EIN if you need one', 'EIN or SSN'),
            ('general_partnership', 'partnership', 'General partnership', 'Two or more owners. The partnership files a return; the partners pay the tax.', 'the state, if it requires it', 'EIN'),
            ('llp', 'llp', 'Limited liability partnership (LLP)', 'A partnership registered with the state whose partners have limited liability; common for professional firms.', 'the Secretary of State', 'state entity number, EIN'),
            ('llc', 'llc', 'Limited liability company (LLC)', 'The usual small-business form; owners are members. Taxed as a sole proprietorship, partnership or corporation by election.', 'the Secretary of State', 'state entity number, EIN'),
            ('corporation', 'corporation', 'Corporation (C corp or S corp)', 'Shareholders own it and a board runs it. S corp is a tax election, not a different registration.', 'the Secretary of State', 'state entity number, EIN'),
            ('nonprofit', 'non_profit', 'Nonprofit corporation (501(c)(3))', 'A state nonprofit corporation; the IRS tax exemption is applied for separately.', 'the Secretary of State', 'state entity number, EIN'),
            ('other', 'other', 'Other or not sure', 'Pick the closest form; your formation papers or your CPA will say. It can be changed later in Company profile.', 'your state', 'the numbers on your registration'),
        ],
    },
    'AE': {
        'default': 'llc', 'currency': 'AED', 'fiscal_year_end': '12-31',
        'name': 'United Arab Emirates', 'registrar': 'the economic department of the emirate (in Dubai, the Department of Economy and Tourism) or the free zone authority',
        'labels': {'reg_number': 'Trade licence number', 'reg_authority': 'Licensing authority', 'tax1': 'TRN (FTA)', 'tax2': ''},
        'placeholders': {'reg_number': 'e.g. 1234567', 'tax1': '100123456700003', 'tax2': ''},
        'forms': [
            ('sole_establishment', 'sole_proprietor', 'Sole establishment', 'Owned by one person who is fully liable; licensed by the economic department of the emirate.', 'the economic department of your emirate (DET in Dubai)', 'trade licence number, and TRN if registered'),
            ('civil_company', 'partnership', 'Civil company (professional partnership)', 'Partners practising a profession together, with unlimited liability.', 'the economic department of your emirate (DET in Dubai)', 'trade licence number, TRN'),
            ('llc', 'llc', 'Limited liability company (LLC, mainland)', 'The usual mainland company; one or more shareholders with limited liability.', 'the economic department of your emirate (DET in Dubai)', 'trade licence number, TRN'),
            ('fze', 'single_member_company', 'Free zone establishment (FZE)', 'A free zone company with a single shareholder.', 'the free zone authority (DMCC, JAFZA, DIFC, ADGM…)', 'licence number, TRN'),
            ('fzco', 'llc', 'Free zone company (FZCO or FZ-LLC)', 'A free zone company with one or more shareholders; the name depends on the free zone.', 'the free zone authority', 'licence number, TRN'),
            ('prjsc', 'private_limited', 'Private joint stock company (PrJSC)', 'Shares held privately; two or more founders and higher capital than an LLC.', 'the Ministry of Economy and the economic department of the emirate', 'licence number, TRN'),
            ('pjsc', 'public_limited', 'Public joint stock company (PJSC)', 'Shares offered to the public and usually listed.', 'the Securities and Commodities Authority', 'licence number, TRN'),
            ('association', 'non_profit', 'Association or non-profit', 'Licensed by the Ministry of Community Development or the emirate; no owners.', 'the Ministry of Community Development', 'licence number'),
            ('other', 'other', 'Other, branch of a foreign company, or not sure', 'Pick the closest form; your licence will say. It can be changed later in Company profile.', 'your licensing authority', 'the numbers on your licence'),
        ],
    },
    'IN': {
        'default': 'pvt_ltd', 'currency': 'INR', 'fiscal_year_end': '03-31',
        'name': 'India', 'registrar': 'the Ministry of Corporate Affairs (Registrar of Companies)',
        'labels': {'reg_number': 'CIN / LLPIN', 'reg_authority': 'Registered with', 'tax1': 'PAN', 'tax2': 'GSTIN'},
        'placeholders': {'reg_number': 'U12345MH2026PTC123456', 'tax1': 'ABCDE1234F', 'tax2': '27ABCDE1234F1Z5'},
        'forms': [
            ('sole_proprietorship', 'sole_proprietor', 'Sole proprietorship', 'No separate registration; the owner\'s PAN is the business PAN. GST and Udyam registration as needed.', 'no registrar', 'PAN, and GSTIN if registered'),
            ('partnership_firm', 'partnership', 'Partnership firm', 'Under the Indian Partnership Act 1932; registered with the state Registrar of Firms.', 'the state Registrar of Firms', 'the firm\'s PAN, GSTIN'),
            ('llp', 'llp', 'Limited liability partnership (LLP)', 'Under the LLP Act 2008; incorporated with the MCA.', 'the MCA', 'LLPIN, PAN, GSTIN'),
            ('opc', 'single_member_company', 'One person company (OPC)', 'A private company with a single member under the Companies Act 2013.', 'the MCA', 'CIN, PAN, GSTIN'),
            ('pvt_ltd', 'private_limited', 'Private limited company (Pvt Ltd)', '2 to 200 members; the usual company for a startup or family business.', 'the MCA', 'CIN, PAN, GSTIN'),
            ('public_ltd', 'public_limited', 'Public limited company (Ltd)', 'Shares may be offered to the public; seven or more members.', 'the MCA', 'CIN, PAN, GSTIN'),
            ('section_8', 'non_profit', 'Section 8 company, trust or society', 'Not for profit. Section 8 companies are licensed by the MCA; trusts and societies by the state.', 'the MCA or the state registrar', 'PAN, and GSTIN if registered'),
            ('other', 'other', 'Other or not sure', 'Pick the closest form; your registration papers or your CA will say. It can be changed later in Company profile.', 'your registrar', 'the numbers on your registration'),
        ],
    },
    'SG': {
        'default': 'pte_ltd', 'currency': 'SGD', 'fiscal_year_end': '12-31',
        'name': 'Singapore', 'registrar': 'ACRA',
        'labels': {'reg_number': 'UEN', 'reg_authority': 'Registered with', 'tax1': 'GST registration number', 'tax2': ''},
        'placeholders': {'reg_number': '202612345K', 'tax1': '202612345K', 'tax2': ''},
        'forms': [
            ('sole_proprietorship', 'sole_proprietor', 'Sole proprietorship', 'One owner registered with ACRA; the registration is renewed for one or three years.', 'ACRA', 'UEN, and GST number if registered'),
            ('partnership', 'partnership', 'Partnership or limited partnership (LP)', 'A general partnership has 2 to 20 partners; a limited partnership has no cap. Both register with ACRA.', 'ACRA', 'UEN, and GST number if registered'),
            ('llp', 'llp', 'Limited liability partnership (LLP)', 'Partners with limited liability; a separate legal entity.', 'ACRA', 'UEN, GST number'),
            ('pte_ltd', 'private_limited', 'Private company limited by shares (Pte. Ltd.)', 'The usual company, up to 50 shareholders. With 20 or fewer, none corporate, it is an exempt private company.', 'ACRA', 'UEN, GST number'),
            ('ltd', 'public_limited', 'Public company limited by shares (Ltd.)', 'Shares may be offered to the public.', 'ACRA', 'UEN, GST number'),
            ('clg', 'non_profit', 'Company limited by guarantee, society or charity', 'Not for profit; a charity registers with the Commissioner of Charities as well.', 'ACRA or the Registry of Societies', 'UEN'),
            ('other', 'other', 'Other or not sure', 'Pick the closest form; your ACRA profile will say. It can be changed later in Company profile.', 'ACRA', 'UEN'),
        ],
    },
    'EE': {
        'default': 'ou', 'currency': 'EUR', 'fiscal_year_end': '12-31',
        'name': 'Estonia', 'registrar': 'the Estonian Business Register',
        'labels': {'reg_number': 'Registry code', 'reg_authority': 'Registered with', 'tax1': 'VAT number (KMKR)', 'tax2': ''},
        'placeholders': {'reg_number': '12345678', 'tax1': 'EE123456789', 'tax2': ''},
        'forms': [
            ('fie', 'sole_proprietor', 'Sole proprietor (FIE)', 'Füüsilisest isikust ettevõtja: entered in the Business Register, the owner is fully liable.', 'the Business Register', 'registry code, and VAT number if registered'),
            ('entrepreneur_account', 'sole_proprietor', 'Entrepreneur account (ettevõtluskonto)', 'A simplified scheme through a bank account: no legal entity, no VAT registration, tax withheld by the bank.', 'the Tax and Customs Board, through the bank', 'personal identification code'),
            ('partnership', 'partnership', 'General or limited partnership (TÜ / UÜ)', 'Partners trading together; in a limited partnership some partners have limited liability.', 'the Business Register', 'registry code, VAT number'),
            ('ou', 'private_limited', 'Private limited company (OÜ)', 'The usual company, also for e-residents; one or more shareholders, share capital from one cent.', 'the Business Register', 'registry code, VAT number'),
            ('as', 'public_limited', 'Public limited company (AS)', 'Shares may be offered to the public; share capital from EUR 25,000.', 'the Business Register', 'registry code, VAT number'),
            ('mtu', 'non_profit', 'Non-profit association (MTÜ) or foundation (SA)', 'No owners; members or a board.', 'the Business Register', 'registry code'),
            ('other', 'other', 'Other or not sure', 'Pick the closest form; your registry card will say. It can be changed later in Company profile.', 'the Business Register', 'registry code'),
        ],
    },
    'ZZ': {
        'default': '', 'currency': 'USD', 'fiscal_year_end': '12-31',
        'name': 'Any other country', 'registrar': 'your company registrar',
        'labels': {'reg_number': 'Registration number', 'reg_authority': 'Registered with', 'tax1': 'Tax number', 'tax2': 'VAT or sales tax number'},
        'placeholders': {'reg_number': '', 'tax1': '', 'tax2': ''},
        'forms': [
            ('sole_proprietor', 'sole_proprietor', 'Sole proprietor', 'One owner and no separate company.', 'your tax authority', 'your tax number'),
            ('partnership', 'partnership', 'Partnership', 'Two or more owners sharing profit by an agreed ratio.', 'your registrar', 'your tax number'),
            ('llp', 'llp', 'Limited liability partnership', 'Partners with limited liability.', 'your registrar', 'your registration and tax numbers'),
            ('llc', 'llc', 'Limited liability company (LLC)', 'Owners are members with limited liability.', 'your registrar', 'your registration and tax numbers'),
            ('single_member_company', 'single_member_company', 'Company with a single shareholder', 'A private company owned by one person.', 'your registrar', 'your registration and tax numbers'),
            ('private_limited', 'private_limited', 'Private limited company', 'Shares held privately; the usual company.', 'your registrar', 'your registration and tax numbers'),
            ('public_limited', 'public_limited', 'Public limited company', 'Shares may be offered to the public.', 'your registrar', 'your registration and tax numbers'),
            ('corporation', 'corporation', 'Corporation', 'Shareholders own it, a board runs it.', 'your registrar', 'your registration and tax numbers'),
            ('non_profit', 'non_profit', 'Non-profit or association', 'Nobody owns it; surplus stays in the organisation.', 'your registrar', 'your registration number'),
            ('other', 'other', 'Other or not sure', 'Pick the closest form; it can be changed later in Company profile.', 'your registrar', 'the numbers on your registration'),
        ],
    },
}


def guide_text(country, key):
    """The sentence shown under the picker: what the form is, who registers it, what it means for the books."""
    c = COUNTRIES[country]
    for k, family, name, short, registrar, numbers in c['forms']:
        if k == key:
            owners, profit = FAMILIES[family]
            return f'{short} Registered with {registrar}. Owners: {owners}. Money out as {profit}. On invoices: {numbers}.'
    return ''


def options_html(country, current):
    return ''.join(f'<option value="{e(k)}"{" selected" if k == current else ""}>{e(name)}</option>' for k, _, name, *_ in COUNTRIES[country]['forms'])


def as_json():
    """What mock.js needs to swap the picker, the guidance and the invoice labels when the country changes."""
    import json
    out = {}
    for code, c in COUNTRIES.items():
        out[code] = {'name': c['name'], 'registrar': c['registrar'], 'default': c['default'], 'currency': c['currency'], 'fiscal_year_end': c['fiscal_year_end'], 'labels': c['labels'], 'placeholders': c['placeholders'],
                     'forms': [{'key': k, 'family': f, 'name': n, 'guide': guide_text(code, k)} for k, f, n, *_ in c['forms']]}
    return json.dumps(out, ensure_ascii=False)
