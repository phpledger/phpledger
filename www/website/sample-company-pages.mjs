import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const esc = (value) => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const paragraph = (value) => `<p>${esc(value)}</p>`;
const list = (items) => `<ul>${items.map(item => `<li>${esc(item)}</li>`).join('')}</ul>`;
const profileUrl = id => `/sample-companies/${id}/`;
const logo = (pack, loading = 'lazy') => `<img class="sample-company-logo" src="${esc(pack.learning_story.logo.path)}" width="96" height="96" alt="${esc(pack.name)} fictional company logo" loading="${loading}">`;
const status = pack => pack.learning_story.status === 'complete_history' ? '24 historical chapters · 2026 practice' : 'Company profile · Monthly journey in preparation';

/** The website and installed guide share the signed/versioned sample story source. */
export function loadSampleCompanies(root) {
  const repo = path.resolve(root, '../..');
  const directory = path.join(repo, 'resources/demo-packs');
  const catalog = JSON.parse(fs.readFileSync(path.join(directory, 'catalog.json'), 'utf8'));
  return catalog.map(entry => {
    if (!/^[a-z0-9-]+$/.test(entry.id) || !/^[a-z0-9.-]+\.json$/.test(entry.file)) throw new Error('Invalid sample company identity');
    const raw = fs.readFileSync(path.join(directory, entry.file));
    if (crypto.createHash('sha256').update(raw).digest('hex') !== entry.sha256) throw new Error(`Sample pack digest mismatch: ${entry.id}`);
    const pack = JSON.parse(raw);
    const story = pack.learning_story;
    if (!story || !story.fictional || story.version !== pack.version || pack.id !== entry.id || pack.name !== entry.name) throw new Error(`Sample story version mismatch: ${entry.id}`);
    if (story.logo.path !== `/assets/sample-companies/${pack.id}.svg`) throw new Error(`Invalid sample logo path: ${entry.id}`);
    const keys = new Set(pack.events.map(event => event.key));
    for (const chapter of story.chapters) {
      if (!/^[a-z0-9-]+$/.test(chapter.id)) throw new Error(`Invalid chapter: ${entry.id}`);
      for (const event of chapter.events) if (!keys.has(event.key)) throw new Error(`Missing story source: ${entry.id}/${event.key}`);
    }
    return pack;
  });
}

function chapterHtml(chapter) {
  const events = chapter.events.length ? `<details class="sample-event-list"><summary>Recorded sources (${chapter.events.length})</summary><ul>${chapter.events.map(event => `<li><span class="sample-event-date">${esc(event.date)}</span> <strong>${esc(event.reference)}</strong> — ${esc(event.description)}${event.amount ? ` <span class="sample-event-amount">${esc(event.amount)} base-currency units</span>` : ''}</li>`).join('')}</ul></details>` : '';
  return `<section class="sample-chapter" id="${esc(chapter.id)}"><p class="eyebrow">${chapter.period === 'history' ? 'Recorded history' : 'Separate practice period'}</p><h3>${esc(chapter.title)}</h3>${paragraph(chapter.happened)}<dl><dt>Business decision</dt><dd>${esc(chapter.decision)}</dd><dt>Accounting treatment</dt><dd>${esc(chapter.accounting)}</dd><dt>What to inspect</dt><dd>${esc(chapter.inspect)}</dd></dl>${events}</section>`;
}

export function sampleCompanyPages(root) {
  const packs = loadSampleCompanies(root);
  const page = (url, title, description, content, breadcrumb) => ({
    path: url, nav: 'learn', title: `${title} | PHP Ledger`, description, breadcrumb,
    jsonld: ['organization', 'webpage', 'breadcrumb'], lastmod: '2026-09-23', toc: false, content,
  });
  const index = page('/sample-companies/', 'Meet the fictional sample companies',
    'Meet eleven fictional businesses, follow their bookkeeping choices, and explore Cedar Studio’s recorded monthly history and separate practice year.',
    `<article class="container section sample-companies"><div class="sample-intro"><p class="eyebrow">Stories behind the books</p><h1>Follow the stories behind eleven small businesses</h1><p class="lead">Meet the people, understand the decision, then follow the money through the accounts.</p><p>Eleven fictional companies make bookkeeping concrete. Start with Cedar Studio’s 2024–2025 monthly history, then try the separate 2026 practice year in your own sample company.</p><p class="sample-note">Company origins and characters are fictional background. Only recorded events affect the books. These are bookkeeping examples, not complete industry systems.</p></div><div class="sample-company-grid">${packs.map(pack => `<section class="sample-company-card">${logo(pack)}<p class="eyebrow">${esc(pack.business)}</p><h2><a href="${profileUrl(pack.id)}">${esc(pack.name)}</a></h2>${paragraph(pack.learning_story.origin)}<p class="sample-status">${esc(status(pack))}</p><a class="sample-read" href="${profileUrl(pack.id)}">Meet ${esc(pack.name)} <span aria-hidden="true">→</span></a></section>`).join('')}</div><section class="sample-next"><h2>Read, inspect, then practise</h2><p>Read a chapter here, open the demo, and choose the matching sample company. Its Sample guide resolves recorded sources inside your own books. Use the matching month to compare the entry with its reports.</p><p><a class="button" href="/demo/">Open the demo</a> <a href="/learn/">Learn the accounting concepts</a></p></section></article>`, 'Sample companies');
  return [index, ...packs.map(pack => {
    const story = pack.learning_story;
    const historical = story.chapters.filter(chapter => chapter.period === 'history');
    const practice = story.chapters.filter(chapter => chapter.period === 'practice');
    const staged = (pack.validation_issues ?? []).map(issue => `<section class="sample-note"><h2>A practice event remains unposted</h2><p>${esc(issue.date)} · ${esc(issue.event_id)} is staged for review.</p>${issue.required !== undefined ? `<p>The proposed outflow is ${esc(issue.required)} and the recorded available balance is ${esc(issue.available)}, a shortfall of ${esc(issue.shortfall)} base-currency units.</p>` : ''}${issue.overdraft_limit !== undefined ? `<p>No agreed overdraft facility is recorded. The permitted bank overdraft is ${esc(issue.overdraft_limit)}.</p>` : ''}${paragraph(issue.reason)}<p>Inspect the source evidence before choosing a correction. No funding or credit facility has been invented to make this practice event pass.</p></section>`).join('');
    const journey = historical.length ? `<section class="sample-journey"><h2>Two years, one month at a time</h2><p>These chapters describe recorded sample history. Use your company’s Sample guide to open each recorded entry and the matching dated reports.</p><nav class="sample-months" aria-label="Historical chapters">${historical.map(chapter => `<a href="#${esc(chapter.id)}">${esc(chapter.month)}</a>`).join('')}</nav>${historical.map(chapterHtml).join('')}</section><section class="sample-practice"><h2>2026: your separate practice year</h2>${practice.map(chapterHtml).join('')}</section>` : `<section class="sample-note"><h2>Monthly journey in preparation</h2>${paragraph(story.journey_note)}<p>The existing scenario below is available to inspect. It is not a completed month-by-month story.</p></section>`;
    return page(profileUrl(pack.id), `${pack.name}: a fictional company story`,
      `Meet ${pack.name}, explore its fictional ${pack.business.toLowerCase()} story, and connect its bookkeeping decisions to recorded sample activity.`,
      `<article class="container section sample-company-profile"><p><a href="/sample-companies/">← All sample companies</a></p><header class="sample-profile-header">${logo(pack, 'eager')}<div><p class="eyebrow">${esc(pack.business)}</p><h1>${esc(pack.name)}</h1><p class="sample-status">${esc(status(pack))}</p></div></header><section class="sample-origin"><h2>How it began</h2>${paragraph(story.origin)}<div class="sample-characters">${story.characters.map(character => `<p><strong>${esc(character.name)}</strong><br><span>${esc(character.role)}</span></p>`).join('')}</div><p class="sample-note">${esc(story.notice)}</p></section><section><h2>${esc(pack.scenario.title)}</h2>${paragraph(pack.scenario.goal)}${list(pack.scenario.steps)}<h3>What this example does and does not show</h3>${list(pack.scenario.checks)}</section>${staged}${journey}<section class="sample-next"><h2>Follow the story in your own books</h2><p>This story accompanies sample pack ${esc(pack.version)}. If your installed sample predates it, its guide explains which chapters require the newer pack. Keep an existing real company’s chart and history intact.</p><p>Open the demo, choose ${esc(pack.name)}, then open Sample guide. Your guide opens the matching entries and reports.</p><p><a class="button" href="/demo/">Open the demo</a> <a href="/learn/">Read the bookkeeping lessons</a></p><p>${esc(pack.capability_note)}</p></section></article>`, pack.name);
  })];
}

export function copySampleCompanyLogos(root, publicDir) {
  const source = path.resolve(root, '../phpledger/public/assets/sample-companies');
  const destination = path.join(publicDir, 'assets/sample-companies');
  fs.mkdirSync(destination, {recursive: true});
  for (const pack of loadSampleCompanies(root)) fs.copyFileSync(path.join(source, `${pack.id}.svg`), path.join(destination, `${pack.id}.svg`));
}
