import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {loadSampleCompanies, sampleCompanyPages} from './sample-company-pages.mjs';

const root = path.dirname(fileURLToPath(import.meta.url));
const packs = loadSampleCompanies(root);
const pages = sampleCompanyPages(root);

test('eleven versioned companies produce one index and eleven distinct profile routes', () => {
  assert.equal(packs.length, 11);
  assert.equal(pages.length, 12);
  assert.equal(new Set(pages.map(page => page.path)).size, 12);
  for (const pack of packs) {
    const page = pages.find(page => page.path === `/sample-companies/${pack.id}/`);
    assert.ok(page);
    assert.ok(page.content.includes(pack.learning_story.logo.path));
    assert.ok(page.content.includes('fictional'));
    assert.ok(page.content.includes(`sample pack ${pack.version}`));
    assert.ok(!/href="[^"]*(?:transaction|journal)[^"]*[?&]id=\d/.test(page.content));
    assert.ok(page.description.length >= 50 && page.description.length <= 160, pack.id);
    if ((pack.validation_issues ?? []).length) {
      assert.ok(page.content.includes('A practice event remains unposted'));
      for (const issue of pack.validation_issues) { if (issue.shortfall !== undefined) assert.ok(page.content.includes(String(issue.shortfall))); }
    }
  }
});

test('every Cedar chapter reference agrees with its recorded source and keeps practice separate', () => {
  const cedar = packs.find(pack => pack.id === 'service-agency');
  const history = cedar.learning_story.chapters.filter(chapter => chapter.period === 'history');
  assert.equal(history.length, 24);
  assert.equal(new Set(history.map(chapter => chapter.month)).size, 24);
  assert.equal(history[0].month, '2024-01');
  assert.equal(history.at(-1).month, '2025-12');
  assert.equal(cedar.learning_story.chapters.filter(chapter => chapter.period === 'practice').length, 1);
  for (const chapter of history) {
    assert.ok(chapter.events.length > 0, chapter.id);
    for (const event of chapter.events) {
      const source = cedar.events.find(source => source.key === event.key);
      assert.ok(source, event.key);
      for (const field of ['reference', 'date', 'kind', 'description', 'amount']) assert.equal(event[field], source[field], `${event.key}/${field}`);
      assert.ok(source.date.startsWith(chapter.month));
    }
  }
  for (const pack of packs.filter(pack => pack.id !== 'service-agency')) {
    assert.equal(pack.learning_story.status, 'profile_only');
    assert.equal(pack.learning_story.chapters.length, 0);
    assert.ok(pages.find(page => page.path === `/sample-companies/${pack.id}/`).content.includes('Monthly journey in preparation'));
  }
});

test('company marks are distinct accessible local vectors with no external references or scripts', () => {
  const marks = packs.map(pack => fs.readFileSync(path.resolve(root, '../phpledger/public', pack.learning_story.logo.path.slice(1)), 'utf8'));
  assert.equal(new Set(marks).size, 11);
  for (const mark of marks) {
    assert.match(mark, /viewBox="0 0 96 96"/);
    assert.match(mark, /<title id="title">[^<]+<\/title>/);
    assert.ok(!/<script|href=|<image|onload=/i.test(mark));
  }
});
