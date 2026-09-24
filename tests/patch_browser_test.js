/* Local 1.4.1 acceptance: requires an explicit isolated sample login and company. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.PL_PATCH_URL || 'http://127.0.0.1:18441';
const email = process.env.PL_PATCH_EMAIL;
const company = process.env.PL_PATCH_COMPANY;
if (!/^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(base) || !/^starter-browser-[a-f0-9]+@example\.test$/.test(email || '') || !/^\d+$/.test(company || '')) throw new Error('Explicit local sample fixture required.');
const output = path.resolve('.cache/validation');
fs.mkdirSync(output, { recursive: true });
const evidence = { base, company: Number(company), checks: [], screens: [], errors: [] };
function check(name, pass, details) { evidence.checks.push({ name, pass: !!pass, ...(details === undefined ? {} : { details }) }); }
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        page.on('pageerror', error => evidence.errors.push(error.message));
        page.setDefaultTimeout(15000);
        await page.goto(base + '/login');
        await page.locator('[name=email]').fill(email);
        await page.locator('[name=password]').fill(process.env.PL_PATCH_PASSWORD || 'Sample-browser-only-2026!');
        await page.getByRole('button', { name: 'Sign in', exact: true }).click();
        await page.goto(base + '/companies');
        const select = page.locator('form').filter({ has: page.locator('input[name=company_id][value="' + company + '"]') });
        if (await select.count() !== 1) throw new Error('Authorized sample company unavailable.');
        await select.getByRole('button').click();
        for (const width of [1440, 768, 320, 360, 390]) {
            await page.setViewportSize({ width, height: 900 });
            for (const [route, heading] of [['/employees', 'Employees'], ['/ar?new=1', 'New Invoice'], ['/payroll', 'Payroll'], ['/recurring', 'Recurring'], ['/receipts/new', 'Receipt'], ['/expenses/new', 'Expense']]) {
                const response = await page.goto(base + route);
                await page.locator('h1').first().waitFor();
                await page.waitForFunction(() => [...document.querySelectorAll('input[type=date]:not(:disabled):not([readonly])')].length === 0);
                const state = await page.evaluate(() => {
                    const required = [...document.querySelectorAll('input[required]:not([type=hidden]), select[required], textarea[required]')].filter(el => el.getClientRects().length);
                    const missing = required.filter(el => ![...(el.labels || [])].some(label => label.querySelector('.field-required'))).map(el => el.name || el.id);
                    return { heading: document.querySelector('h1')?.textContent.trim(), width: innerWidth, scroll: document.documentElement.scrollWidth,
                        dateFields: document.querySelectorAll('.pl-date-display').length, requiredCount: required.length, missingRequiredLabels: missing,
                        activeNav: [...document.querySelectorAll('.nav-item[aria-current=page]')].map(a => a.textContent.trim()),
                        scope: [...document.querySelectorAll('input[name=company_id]')].map(el => el.value) };
                });
                evidence.screens.push({ route, width, status: response.status(), ...state });
                check(route + ' HTTP ' + width, response.status() === 200);
                check(route + ' heading ' + width, state.heading.toLowerCase().includes(heading.toLowerCase()), state.heading);
                check(route + ' page width ' + width, state.scroll <= width + 1, { scroll: state.scroll, width });
                check(route + ' required labels ' + width, state.missingRequiredLabels.length === 0, state.missingRequiredLabels);
                check(route + ' fixture scope ' + width, state.scope.length > 0 && state.scope.every(id => id === company));
                if (route.startsWith('/receipts') || route.startsWith('/expenses')) check(route + ' selected sidebar ' + width, state.activeNav.length === 1 && state.activeNav[0] === (route.startsWith('/receipts') ? 'Receipts' : 'Expenses'), state.activeNav);
                if (state.scroll > width + 1 || state.missingRequiredLabels.length) await page.screenshot({ path: path.join(output, 'patch-browser-' + route.split('?')[0].replaceAll('/', '-') + '-' + width + '.png'), fullPage: true });
            }
            for (const label of ['Receipt', 'Expense']) {
                await page.goto(base + '/home');
                await page.getByRole('navigation', { name: 'Quick actions', exact: true }).getByRole('link', { name: label, exact: true }).click();
                check('Home ' + label + ' quick action ' + width, (await page.locator('h1').first().textContent()).toLowerCase().includes(label.toLowerCase()) && page.url().endsWith('/' + label.toLowerCase() + 's/new'));
            }
        }
        await page.goto(base + '/employees');
        const hire = page.locator('#employee-hire-date + .pl-date-display');
        await hire.fill('29/02/2024');
        check('typed date submits ISO', await page.locator('#employee-hire-date').inputValue() === '2024-02-29');
        await hire.press('Alt+ArrowDown');
        await page.keyboard.press('ArrowRight'); await page.keyboard.press('Enter');
        check('keyboard date selection crosses month', await page.locator('#employee-hire-date').inputValue() === '2024-03-01');
        check('keyboard focus returns to visible field', await hire.evaluate(el => el === document.activeElement));
        await page.locator('#employee-name').fill('Sample rejected patch recovery');
        await page.locator('#employee-reason').fill('Local patch acceptance; invalid hire date must not save.');
        await hire.fill('31/02/2024');
        check('invalid edit clears ISO', await page.locator('#employee-hire-date').inputValue() === '');
        await page.locator('#employee-form form').evaluate(form => { form.noValidate = true; });
        await page.locator('#employee-form').getByRole('button', { name: 'Add employee', exact: true }).click();
        check('server rejects invalid employee date', await page.locator('[data-form-error]').count() > 0);
        check('server raw date recovery restores exact text', await page.locator('#employee-hire-date + .pl-date-display').inputValue() === '31/02/2024');
        check('server recovery retains cleared canonical date', await page.locator('#employee-hire-date').inputValue() === '');
        check('server recovery preserves name', await page.locator('#employee-name').inputValue() === 'Sample rejected patch recovery');
        check('rejected employee remains a create form', await page.locator('#employee-form input[name=id]').count() === 0);
        await page.screenshot({ path: path.join(output, 'patch-browser-employee-recovery.png'), fullPage: true });
        const noJs = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState(), viewport: { width: 390, height: 900 } });
        const native = await noJs.newPage();
        for (const route of ['/employees', '/ar?new=1', '/payroll', '/recurring']) {
            const response = await native.goto(base + route);
            check(route + ' noJS HTTP', response.status() === 200);
            check(route + ' noJS native dates', await native.locator('input[type=date]').count() > 0 && await native.locator('.pl-date-display').count() === 0);
            check(route + ' noJS required inputs', await native.locator('input[required],select[required]').count() > 0);
        }
        check('no JavaScript runtime exceptions', evidence.errors.length === 0, evidence.errors);
    } catch (error) { evidence.errors.push(error.stack); check('acceptance runner completed', false, error.message); }
    finally {
        evidence.passed = evidence.checks.filter(item => item.pass).length;
        evidence.failed = evidence.checks.filter(item => !item.pass).length;
        fs.writeFileSync(path.join(output, 'patch-browser-results.json'), JSON.stringify(evidence, null, 2) + '\n');
        console.log(JSON.stringify({ passed: evidence.passed, failed: evidence.failed, errors: evidence.errors, findings: evidence.checks.filter(item => !item.pass) }, null, 2));
        await browser.close();
        process.exitCode = evidence.failed ? 1 : 0;
    }
})();
