#!/usr/bin/env node
/**
 * build.mjs: static site generator for www/website (Node 18+, no dependencies).
 *
 * Reads   src/site.json                     facts used by every page (name, URLs, release, nav, ...)
 *         src/pages/*.html                  JSON front matter in a leading HTML comment, then the <main> markup
 *         src/partials/{head,header,footer,dialog}.html
 *         src/icons/*.svg                   inlined by {{icon:name}}
 *         src/css/*.css                     concatenated in sorted order
 *         src/js/site.js
 *         src/static/**                     copied verbatim into public/
 * Writes  public/<path>index.html (or public/404.html), public/assets/site.css, public/assets/site.js,
 *         public/sitemap.xml, public/news/feed.xml (only when article pages exist),
 *         public/releases/index.json (release feed read by installations; see releases-feed.mjs),
 *         public/<indexNowKey>.txt (only when site.indexNowKey is set)
 *
 * Tokens  {{site.a.b}} (HTML-escaped), {{{site.a.b}}} (raw), {{page.key}} / {{{page.key}}} (front matter),
 *         {{nav}} (main navigation with aria-current), {{icon:name}} (inline SVG),
 *         {{cssHref}}, {{jsHref}}, {{canonical}}, {{{robotsMeta}}}, {{{seoMeta}}}, {{{jsonld}}}
 *
 * Usage   node build.mjs            build
 *         node build.mjs --check    build, then run check.mjs; exits non-zero when the checker finds errors
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { writeReleaseFeed } from './releases-feed.mjs';
import { sampleDirectoryPages, writeSampleDirectory } from './sample-directory.mjs';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.join(ROOT, 'src');
const PUBLIC = path.join(ROOT, 'public');

const PAGE_KEYS = new Set(['path', 'slug', 'nav', 'title', 'description', 'ogImage', 'ogImageAlt', 'ogType', 'bodyClass', 'jsonld', 'breadcrumb', 'faq', 'article', 'howto', 'itemlist', 'noindex', 'budgetEager', 'budgetTotal', 'headExtra', 'datePublished', 'lastmod', 'toc']);
const GRAPH_PARTS = ['organization', 'website', 'software', 'webpage', 'person', 'breadcrumb', 'faq', 'article', 'howto', 'itemlist'];
const TEXT_EXTENSIONS = new Set(['.txt', '.html', '.xml', '.json', '.css', '.js', '.mjs', '.svg', '.md', '.webmanifest']);
const FORBIDDEN_JSONLD_KEYS = /"(aggregateRating|review|reviews|userInteractionCount|interactionStatistic)"\s*:/;

class BuildError extends Error {}
const fail = (message) => { throw new BuildError(message); };
const notes = [];
const note = (message) => notes.push(message);

const readText = (file) => fs.readFileSync(file, 'utf8').replace(/\r\n?/g, '\n');
function writeText(file, text) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, text.replace(/\r\n?/g, '\n'), 'utf8');
}
// Attribute values in the templates are always double-quoted, so apostrophes stay readable.
const escapeHtml = (value) => String(value)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const shortHash = (content) => crypto.createHash('sha256').update(content).digest('hex').slice(0, 10);
const publicFile = (sitePath) => path.join(PUBLIC, ...sitePath.split('/').filter(Boolean));

/* ---------- site facts ---------- */

function loadSite() {
  const site = JSON.parse(readText(path.join(SRC, 'site.json')));
  for (const key of ['name', 'alternateName', 'baseUrl', 'entity', 'locale', 'founder', 'address', 'repo', 'wiki', 'demo', 'social', 'release', 'requirements', 'nav', 'logo', 'screenshot', 'ogDefault']) {
    if (site[key] === undefined) fail(`site.json: missing "${key}"`);
  }
  if (!/^https?:\/\/[^/]+$/.test(site.baseUrl)) fail('site.json: baseUrl must be an origin without a trailing slash');
  if (!Array.isArray(site.nav) || site.nav.some((entry) => !Array.isArray(entry) || entry.length !== 3)) fail('site.json: nav must be an array of [key, label, href]');
  if (site.indexNowKey && !/^[A-Za-z0-9-]{8,128}$/.test(site.indexNowKey)) fail('site.json: indexNowKey must be 8-128 characters of a-z, A-Z, 0-9 or -');
  return site;
}
const site = loadSite();
const absolute = (sitePath) => (/^[a-z][a-z0-9+.-]*:/i.test(sitePath) ? sitePath : site.baseUrl + sitePath);

/* ---------- pages ---------- */

function parsePage(file, id) {
  const raw = readText(file);
  const match = raw.match(/^\s*<!--\s*(\{[\s\S]*?\})\s*-->[ \t]*\n?/);
  if (!match) fail(`${id}: the file must start with an HTML comment holding JSON front matter`);
  let meta;
  try { meta = JSON.parse(match[1]); } catch (error) { fail(`${id}: front matter is not valid JSON (${error.message})`); }
  for (const key of Object.keys(meta)) if (!PAGE_KEYS.has(key)) fail(`${id}: unknown front matter key "${key}"`);
  for (const key of ['path', 'nav', 'title', 'description']) {
    if (typeof meta[key] !== 'string' || !meta[key].trim()) fail(`${id}: front matter "${key}" must be a non-empty string`);
  }
  if (!meta.path.startsWith('/')) fail(`${id}: path must start with "/"`);
  if (!(meta.path.endsWith('/') || meta.path.endsWith('.html'))) fail(`${id}: path must end with "/" or ".html"`);
  if (/[^A-Za-z0-9\-._~/]/.test(meta.path)) fail(`${id}: path may only use letters, digits, - . _ ~ and /`);
  meta.ogType = meta.ogType || 'website';
  if (!['website', 'article'].includes(meta.ogType)) fail(`${id}: ogType must be "website" or "article"`);
  if (meta.jsonld !== undefined && (!Array.isArray(meta.jsonld) || meta.jsonld.some((part) => !GRAPH_PARTS.includes(part)))) {
    fail(`${id}: jsonld must be an array drawn from ${GRAPH_PARTS.join(', ')}`);
  }
  if (meta.faq !== undefined && (!Array.isArray(meta.faq) || !meta.faq.length || meta.faq.some((item) => !item || typeof item.q !== 'string' || typeof item.a !== 'string'))) {
    fail(`${id}: faq must be a non-empty array of {q, a}`);
  }
  if (meta.article !== undefined) {
    for (const key of ['headline', 'datePublished', 'dateModified', 'section']) {
      if (typeof meta.article[key] !== 'string' || !meta.article[key]) fail(`${id}: article.${key} must be a non-empty string`);
    }
    for (const key of ['datePublished', 'dateModified']) {
      if (Number.isNaN(Date.parse(meta.article[key]))) fail(`${id}: article.${key} is not a valid date`);
    }
  }
  // Educational pages can use the Open Graph article type without becoming
  // release BlogPostings or entries in the release-news RSS feed.
  if ((meta.jsonld || []).includes('faq') && !meta.faq) fail(`${id}: jsonld "faq" needs a "faq" array`);
  if ((meta.jsonld || []).includes('article') && !meta.article) fail(`${id}: jsonld "article" needs an "article" object`);
  if (meta.howto !== undefined && (!meta.howto || typeof meta.howto.name !== 'string' || !meta.howto.name.trim()
      || !Array.isArray(meta.howto.step) || !meta.howto.step.length
      || meta.howto.step.some((step) => !step || typeof step.name !== 'string' || !step.name.trim()
        || typeof step.text !== 'string' || !step.text.trim() || (step.url !== undefined && !/^#[A-Za-z][A-Za-z0-9_-]*$/.test(step.url))))) fail(`${id}: howto needs a name and non-empty steps with name, text and optional local anchor`);
  if ((meta.jsonld || []).includes('howto') && !meta.howto) fail(`${id}: jsonld "howto" needs a howto object`);
  if (meta.itemlist !== undefined && (!Array.isArray(meta.itemlist) || !meta.itemlist.length
      || meta.itemlist.some((item) => !item || item['@type'] !== 'ListItem' || !Number.isInteger(item.position) || item.position < 1
        || typeof item.name !== 'string' || !item.name || typeof item.url !== 'string' || !/^https:\/\//.test(item.url)))) fail(`${id}: itemlist needs ListItems with positive position, name and HTTPS URL`);
  if ((meta.jsonld || []).includes('itemlist') && !meta.itemlist) fail(`${id}: jsonld "itemlist" needs an itemlist array`);
  for (const key of ['budgetEager', 'budgetTotal']) {
    if (meta[key] !== undefined && !(Number.isInteger(meta[key]) && meta[key] > 0)) fail(`${id}: ${key} must be a positive integer`);
  }
  if (typeof meta.lastmod !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(meta.lastmod)
      || Number.isNaN(Date.parse(meta.lastmod)) || new Date(meta.lastmod).toISOString().slice(0, 10) !== meta.lastmod) fail(`${id}: lastmod is required and must be a valid YYYY-MM-DD date`);
  if (meta.datePublished !== undefined && (typeof meta.datePublished !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(meta.datePublished)
      || Number.isNaN(Date.parse(meta.datePublished)) || new Date(meta.datePublished).toISOString().slice(0, 10) !== meta.datePublished
      || meta.datePublished > meta.lastmod)) fail(`${id}: datePublished must be a valid YYYY-MM-DD date no later than lastmod`);
  if (meta.headExtra !== undefined && typeof meta.headExtra !== 'string') fail(`${id}: headExtra must be a string`);
  const outFile = meta.path === '/' ? 'index.html' : meta.path.endsWith('/') ? `${meta.path.slice(1)}index.html` : meta.path.slice(1);
  const slug = meta.slug || (meta.path === '/' ? 'index' : meta.path.replace(/^\/|\/$/g, '').replace(/\.html$/, ''));
  const content = raw.slice(match[0].length).replace(/\s+$/, '');
  const lastmod = meta.lastmod;
  return { ...meta, id, file, slug, outFile, content, lastmod, noindex: Boolean(meta.noindex) };
}

function loadPages() {
  const dir = path.join(SRC, 'pages');
  const names = fs.readdirSync(dir).filter((name) => name.endsWith('.html')).sort();
  if (!names.length) fail('src/pages has no .html files');
  const pages = [...names.map((name) => parsePage(path.join(dir, name), `src/pages/${name}`)), ...sampleDirectoryPages(path.join(SRC, 'directory.json'))];
  const byPath = new Map();
  for (const page of pages) {
    if (byPath.has(page.path)) fail(`${page.id}: path ${page.path} is also used by ${byPath.get(page.path).id}`);
    byPath.set(page.path, page);
  }
  return { pages, byPath };
}

/* ---------- templates ---------- */

const partials = Object.fromEntries(['head', 'header', 'footer', 'dialog'].map((name) => {
  const file = path.join(SRC, 'partials', `${name}.html`);
  if (!fs.existsSync(file)) fail(`missing partial src/partials/${name}.html`);
  return [name, readText(file).replace(/\s+$/, '')];
}));

const iconCache = new Map();
function icon(name) {
  if (!/^[a-z0-9-]+$/.test(name)) fail(`invalid icon name "${name}"`);
  if (!iconCache.has(name)) {
    const file = path.join(SRC, 'icons', `${name}.svg`);
    if (!fs.existsSync(file)) fail(`icon not found: src/icons/${name}.svg`);
    let svg = readText(file).replace(/<!--[\s\S]*?-->/g, '').replace(/<\?xml[^>]*>/g, '').trim();
    svg = svg.replace(/\s*\n\s*/g, ' ').replace(/\s+>/g, '>').replace(/\s+\/>/g, '/>').replace(/>\s+</g, '><');
    if (!svg.startsWith('<svg')) fail(`src/icons/${name}.svg does not start with <svg>`);
    if (/<script|\bon[a-z]+=/i.test(svg)) fail(`src/icons/${name}.svg contains script`);
    svg = svg.replace(/^<svg\b/, '<svg aria-hidden="true" focusable="false"');
    iconCache.set(name, svg);
  }
  return iconCache.get(name);
}

const getPath = (object, dotted) => dotted.split('.').reduce((value, key) => (value == null ? undefined : value[key]), object);

function render(template, context, where) {
  return template.replace(/\{\{\{\s*([^{}]+?)\s*\}\}\}|\{\{\s*([^{}]+?)\s*\}\}/g, (token, rawExpr, escapedExpr) => {
    const expr = rawExpr ?? escapedExpr;
    const raw = rawExpr !== undefined;
    if (expr.startsWith('icon:')) return icon(expr.slice(5).trim());
    if (expr === 'nav') return context.nav;
    let value;
    if (expr.startsWith('site.')) {
      value = getPath(site, expr.slice(5));
      if (value === undefined) fail(`${where}: unknown token ${token}`);
    } else if (expr.startsWith('page.')) {
      value = getPath(context.page, expr.slice(5));
      if (value === undefined) value = '';
    } else if (Object.hasOwn(context.tokens, expr)) {
      value = context.tokens[expr];
    } else {
      fail(`${where}: unknown token ${token}`);
    }
    if (value !== null && typeof value === 'object') fail(`${where}: token ${token} resolves to an object, not text`);
    return raw ? String(value) : escapeHtml(value);
  });
}

const navHtml = (page) => site.nav
  .map(([key, label, href]) => `<a href="${escapeHtml(href)}"${key === page.nav && page.path === href ? ' aria-current="page"' : ''}>${escapeHtml(label)}</a>`)
  .join('');

/* ---------- images ---------- */

function pngSize(file) {
  try {
    const fd = fs.openSync(file, 'r');
    const header = Buffer.alloc(24);
    const read = fs.readSync(fd, header, 0, 24, 0);
    fs.closeSync(fd);
    if (read < 24 || header.toString('latin1', 1, 4) !== 'PNG') return null;
    return { width: header.readUInt32BE(16), height: header.readUInt32BE(20) };
  } catch {
    return null;
  }
}

/* ---------- head ---------- */

function seoMeta(page, canonical, ogImage, dims, hasFeed) {
  const meta = (attr, key, value) => `<meta ${attr}="${key}" content="${escapeHtml(value)}">`;
  const alt = page.ogImageAlt || `${site.name} logo with a capture of the application showing a fictional sample business`;
  const image = absolute(ogImage);
  const lines = [
    meta('property', 'og:site_name', site.name),
    meta('property', 'og:type', page.ogType),
    meta('property', 'og:locale', site.locale),
    meta('property', 'og:title', page.title),
    meta('property', 'og:description', page.description),
    meta('property', 'og:url', canonical),
    meta('property', 'og:image', image),
    meta('property', 'og:image:width', String(dims.width)),
    meta('property', 'og:image:height', String(dims.height)),
    meta('property', 'og:image:alt', alt),
  ];
  if (page.article) {
    lines.push(
      meta('property', 'article:published_time', page.article.datePublished),
      meta('property', 'article:modified_time', page.article.dateModified),
      meta('property', 'article:section', page.article.section),
    );
  }
  lines.push(
    meta('name', 'twitter:card', 'summary_large_image'),
    meta('name', 'twitter:title', page.title),
    meta('name', 'twitter:description', page.description),
    meta('name', 'twitter:image', image),
    meta('name', 'twitter:image:alt', alt),
  );
  if (site.twitterHandle) lines.push(meta('name', 'twitter:site', site.twitterHandle.startsWith('@') ? site.twitterHandle : `@${site.twitterHandle}`));
  if (site.verification?.google) lines.push(meta('name', 'google-site-verification', site.verification.google));
  if (site.verification?.bing) lines.push(meta('name', 'msvalidate.01', site.verification.bing));
  if (hasFeed) lines.push(`<link rel="alternate" type="application/rss+xml" title="${escapeHtml(site.name)} news" href="/news/feed.xml">`);
  return lines.join('\n');
}

/* ---------- JSON-LD ---------- */

const ids = {
  organization: `${site.baseUrl}/#organization`,
  website: `${site.baseUrl}/#website`,
  software: `${site.baseUrl}/#software`,
  person: `${site.baseUrl}/#maintainer`,
};

function compact(value) {
  if (Array.isArray(value)) {
    const items = value.map(compact).filter((item) => item !== undefined);
    return items.length ? items : undefined;
  }
  if (value !== null && typeof value === 'object') {
    const out = {};
    for (const [key, item] of Object.entries(value)) {
      const kept = compact(item);
      if (kept !== undefined) out[key] = kept;
    }
    return Object.keys(out).length ? out : undefined;
  }
  if (value === '' || value === null || value === undefined) return undefined;
  return value;
}

function logoImage() {
  const dims = pngSize(publicFile(site.logo.path));
  if (!dims) {
    note(`logo ${site.logo.path} is not in public/ yet; JSON-LD uses the dimensions from site.json`);
    return { '@type': 'ImageObject', url: absolute(site.logo.path), width: site.logo.width, height: site.logo.height };
  }
  if (dims.width !== site.logo.width || dims.height !== site.logo.height) {
    note(`logo ${site.logo.path} is ${dims.width}x${dims.height} but site.json says ${site.logo.width}x${site.logo.height}; using the file`);
  }
  return { '@type': 'ImageObject', url: absolute(site.logo.path), width: dims.width, height: dims.height };
}

const builders = {
  organization: () => ({
    '@type': 'Organization',
    '@id': ids.organization,
    name: site.name,
    alternateName: site.alternateName,
    url: `${site.baseUrl}/`,
    logo: logoImage(),
    address: { '@type': 'PostalAddress', ...site.address },
    founder: { '@type': 'Person', name: site.founder.name, ...(site.founder.url ? { url: site.founder.url, sameAs: [site.founder.url] } : {}) },
    sameAs: [site.repo, ...Object.values(site.social || {}).filter(Boolean)],
  }),
  website: () => ({
    '@type': 'WebSite',
    '@id': ids.website,
    url: `${site.baseUrl}/`,
    name: site.name,
    alternateName: site.alternateName,
    description: site.entity,
    inLanguage: 'en',
    publisher: { '@id': ids.organization },
  }),
  software: () => ({
    '@type': 'SoftwareApplication',
    '@id': ids.software,
    name: site.name,
    alternateName: site.alternateName,
    description: site.entity,
    applicationCategory: ['BusinessApplication', 'FinanceApplication'],
    operatingSystem: 'Self-hosted web application; PHP 8.2 or newer and MySQL 8.4',
    softwareRequirements: site.requirements,
    softwareVersion: site.release.version,
    datePublished: site.release.date,
    releaseNotes: site.release.url,
    downloadUrl: site.release.zipUrl,
    license: site.release.license,
    isAccessibleForFree: true,
    offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
    softwareHelp: { '@type': 'CreativeWork', url: site.wiki },
    screenshot: absolute(site.screenshot),
    url: `${site.baseUrl}/`,
    sameAs: [site.repo],
    author: { '@id': ids.organization },
    publisher: { '@id': ids.organization },
    inLanguage: 'en',
  }),
  person: () => ({
    '@type': 'Person', '@id': ids.person, name: site.founder.name, ...(site.founder.url ? { url: site.founder.url, sameAs: [site.founder.url] } : {}),
    jobTitle: 'PHP Ledger project maintainer',
    worksFor: { '@id': ids.organization }, knowsAbout: ['double-entry bookkeeping', 'PHP application architecture', 'self-hosted software'],
  }),
  webpage: (page, byPath) => ({
    '@type': 'WebPage', '@id': `${absolute(page.path)}#webpage`, url: absolute(page.path), name: page.title,
    description: page.description, inLanguage: 'en', isPartOf: { '@id': ids.website },
    datePublished: page.datePublished || page.article?.datePublished, dateModified: page.lastmod, author: { '@id': ids.person }, publisher: { '@id': ids.organization },
    primaryImageOfPage: { '@type': 'ImageObject', url: absolute(page.ogImage || site.ogDefault) },
    breadcrumb: { '@id': `${absolute(page.path)}#breadcrumb` },
    speakable: { '@type': 'SpeakableSpecification', cssSelector: /class="[^"]*\bsummary\b/.test(page.content) ? ['h1', '.summary'] : ['h1', '.lead'] },
  }),
  breadcrumb: (page, byPath) => {
    const crumbs = [{ name: 'Home', item: `${site.baseUrl}/` }];
    const segments = page.path.split('/').filter(Boolean);
    if (segments.length >= 2) {
      const parentPath = `/${segments.slice(0, -1).join('/')}/`;
      const parent = byPath.get(parentPath);
      if (!parent) fail(`${page.id}: breadcrumb needs a parent page at ${parentPath}`);
      crumbs.push({ name: parent.breadcrumb || parent.title, item: absolute(parentPath) });
    }
    if (page.path !== '/') crumbs.push({ name: page.breadcrumb || page.title, item: absolute(page.path) });
    return {
      '@type': 'BreadcrumbList',
      '@id': `${absolute(page.path)}#breadcrumb`,
      itemListElement: crumbs.map((crumb, index) => ({ '@type': 'ListItem', position: index + 1, name: crumb.name, item: crumb.item })),
    };
  },
  faq: (page) => ({
    '@type': 'FAQPage',
    '@id': `${absolute(page.path)}#faq`,
    mainEntity: page.faq.map(({ q, a }) => ({ '@type': 'Question', name: q, acceptedAnswer: { '@type': 'Answer', text: a } })),
  }),
  howto: (page) => ({
    '@type': 'HowTo', '@id': `${absolute(page.path)}#howto`, name: page.howto.name, totalTime: page.howto.totalTime,
    supply: page.howto.supply, tool: page.howto.tool,
    step: page.howto.step.map((step, index) => ({ '@type': 'HowToStep', position: index + 1, ...step, url: `${absolute(page.path)}${step.url || ''}` })),
  }),
  itemlist: (page) => ({ '@type': 'ItemList', '@id': `${absolute(page.path)}#itemlist`, itemListElement: page.itemlist }),
  article: (page) => ({
    '@type': page.path.startsWith('/news/') ? 'BlogPosting' : 'Article',
    '@id': `${absolute(page.path)}#article`,
    headline: page.article.headline,
    description: page.description,
    datePublished: page.article.datePublished,
    dateModified: page.article.dateModified,
    author: { '@id': ids.person },
    publisher: { '@id': ids.organization },
    image: absolute(page.ogImage || site.ogDefault),
    mainEntityOfPage: absolute(page.path),
    url: absolute(page.path),
    articleSection: page.article.section,
    inLanguage: 'en',
  }),
};

function jsonLd(page, byPath) {
  const parts = new Set(page.jsonld || []);
  parts.add('webpage');
  parts.add('website');
  parts.add('breadcrumb');
  if (parts.has('website') || parts.has('software') || parts.has('article') || parts.has('webpage') || parts.has('person')) parts.add('organization');
  if (parts.has('webpage') || parts.has('article')) parts.add('person');
  const graph = GRAPH_PARTS.filter((part) => parts.has(part)).map((part) => builders[part](page, byPath));
  if (!graph.length) return '';
  const json = JSON.stringify(compact({ '@context': 'https://schema.org', '@graph': graph }), null, 2).replace(/<\//g, '<\\/');
  if (FORBIDDEN_JSONLD_KEYS.test(json)) fail(`${page.id}: JSON-LD must not carry ratings, reviews or interaction counts`);
  return `<script type="application/ld+json">\n${json}\n</script>`;
}

/* ---------- page assembly ---------- */

function addContents(html, page) {
  if (page.toc === false || page.noindex || ['/404.html', '/privacy/', '/terms/'].includes(page.path)) return html;
  const headings = [...html.matchAll(/<h2\b[^>]*>[\s\S]*?<\/h2>/gi)];
  if (headings.length < 4) return html;
  const used = new Set([...html.matchAll(/\bid="([^"]+)"/g)].map((match) => match[1]));
  const items = [];
  const includeH3 = html.replace(/<[^>]+>/g, ' ').split(/\s+/).length > 1500;
  html = html.replace(/<h([23])\b([^>]*)>([\s\S]*?)<\/h\1>/gi, (all, level, attributes, content) => {
    if (level === '3' && !includeH3) return all;
    const text = content.replace(/<[^>]+>/g, '').trim();
    let id = attributes.match(/\bid="([^"]+)"/)?.[1];
    if (!id) {
      const slug = text.toLowerCase().replace(/&[^;]+;/g, '-').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'section';
      id = slug;
      for (let suffix = 2; used.has(id); suffix++) id = `${slug}-${suffix}`;
      used.add(id);
      attributes += ` id="${id}"`;
    }
    items.push(`<li${level === '3' ? ' class="toc-subsection"' : ''}><a href="#${escapeHtml(id)}">${text}</a></li>`);
    return `<h${level}${attributes}>${content}</h${level}>`;
  });
  const toc = `<nav class="page-toc" aria-label="On this page"><p><strong>On this page</strong></p><ol>${items.join('')}</ol></nav>`;
  // Authored summary blocks are simple section/aside/div elements; retain their complete contents.
  const summary = /<(section|aside|div|p)\b[^>]*class="[^"]*\b(?:summary|callout)\b[^"]*"[^>]*>[\s\S]*?<\/\1>/i;
  if (summary.test(html)) return html.replace(summary, (block) => `${block}\n${toc}`);
  if (/<article\b/i.test(html)) return html.replace(/<h2\b/i, `${toc}\n<h2`);
  const h1 = html.indexOf('</h1>');
  const firstSectionEnd = html.indexOf('</section>', h1);
  if (h1 >= 0 && firstSectionEnd >= 0) {
    const end = firstSectionEnd + '</section>'.length;
    return `${html.slice(0, end)}\n<div class="container">${toc}</div>\n${html.slice(end)}`;
  }
  return `${toc}\n${html}`;
}

function buildPage(page, byPath, assets) {
  const canonical = absolute(page.path);
  const ogImage = page.ogImage || site.ogDefault;
  let dims = pngSize(publicFile(ogImage));
  if (!dims) {
    note(`${page.id}: OG image ${ogImage} is missing or not a PNG; og:image dimensions default to 1200x630`);
    dims = { width: 1200, height: 630 };
  }
  const tokens = {
    canonical,
    cssHref: assets.cssHref,
    jsHref: assets.jsHref,
    robotsMeta: page.noindex ? '<meta name="robots" content="noindex">' : '<meta name="robots" content="max-image-preview:large">',
    seoMeta: seoMeta(page, canonical, ogImage, dims, assets.hasFeed),
    jsonld: jsonLd(page, byPath),
  };
  const context = { page, nav: navHtml(page), tokens };
  const head = render(partials.head, context, 'src/partials/head.html').split('\n').filter((line) => line.trim()).join('\n');
  const header = render(partials.header, context, 'src/partials/header.html');
  const footer = render(partials.footer, context, 'src/partials/footer.html');
  const dialog = render(partials.dialog, context, 'src/partials/dialog.html');
  const main = addContents(render(page.content, context, page.id), page);
  const bodyClass = page.bodyClass ? ` class="${escapeHtml(page.bodyClass)}"` : '';
  return [
    '<!doctype html>',
    '<html lang="en">',
    '<head>',
    head,
    '</head>',
    `<body${bodyClass}>`,
    '<a class="skip-link" href="#main">Skip to content</a>',
    header,
    '<main id="main">',
    main,
    '</main>',
    footer,
    dialog,
    `<script src="${assets.jsHref}" defer></script>`,
    '</body>',
    '</html>',
    '',
  ].join('\n');
}

/* ---------- sitemap, feed, static ---------- */

function sitemapXml(pages) {
  const listed = pages
    .filter((page) => !page.noindex && page.path !== '/404.html' && !page.path.startsWith(site.demo))
    .sort((a, b) => (a.path === '/' ? -1 : b.path === '/' ? 1 : a.path.localeCompare(b.path)));
  const entries = listed.map((page) => `  <url>\n    <loc>${escapeHtml(absolute(page.path))}</loc>\n    <lastmod>${page.lastmod}</lastmod>\n  </url>`);
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${entries.join('\n')}\n</urlset>\n`;
}

function feedXml(articles) {
  const items = [...articles].sort((a, b) => Date.parse(b.article.datePublished) - Date.parse(a.article.datePublished));
  const item = (page) => [
    '<item>',
    `<title>${escapeHtml(page.article.headline)}</title>`,
    `<link>${escapeHtml(absolute(page.path))}</link>`,
    `<guid isPermaLink="true">${escapeHtml(absolute(page.path))}</guid>`,
    `<pubDate>${new Date(page.article.datePublished).toUTCString()}</pubDate>`,
    `<description>${escapeHtml(page.description)}</description>`,
    '</item>',
  ].join('\n');
  return [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
    '<channel>',
    `<title>${escapeHtml(site.name)} news</title>`,
    `<link>${site.baseUrl}/news/</link>`,
    `<atom:link href="${site.baseUrl}/news/feed.xml" rel="self" type="application/rss+xml"/>`,
    `<description>${escapeHtml(site.entity)}</description>`,
    '<language>en-gb</language>',
    ...items.map(item),
    '</channel>',
    '</rss>',
    '',
  ].join('\n');
}

function copyStatic(reserved) {
  const staticDir = path.join(SRC, 'static');
  if (!fs.existsSync(staticDir)) return [];
  const copied = [];
  const walk = (dir, rel) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const relPath = rel ? `${rel}/${entry.name}` : entry.name;
      if (entry.isDirectory()) { walk(path.join(dir, entry.name), relPath); continue; }
      if (reserved.has(relPath)) fail(`src/static/${relPath} collides with a generated file`);
      const from = path.join(dir, entry.name);
      const to = path.join(PUBLIC, ...relPath.split('/'));
      if (TEXT_EXTENSIONS.has(path.extname(entry.name).toLowerCase())) writeText(to, readText(from));
      else { fs.mkdirSync(path.dirname(to), { recursive: true }); fs.copyFileSync(from, to); }
      copied.push(relPath);
    }
  };
  walk(staticDir, '');
  return copied;
}

function writeDiscovery(pages) {
  const listed = pages.filter((page) => !page.noindex).sort((a, b) => a.path.localeCompare(b.path));
  const licence = 'New project-owned code: AGPL-3.0-or-later; commercial licensing available. Pre-adoption 0.1.0 through 0.1.5 previews retain MIT; dependencies and archived code keep their own terms.';
  const facts = {
    name: site.name, description: site.entity, version: site.release.version, releaseDate: site.release.date,
    license: licence, requirements: site.requirements, repository: site.repo,
    release: site.release.url, packageSha256: site.release.sha256,
    demo: { url: absolute(site.demo), warning: 'Fictional sample data only; private sample companies reset hourly.' },
    capabilities: site.capabilities, notYet: site.limitations,
  };
  if (!Array.isArray(facts.capabilities) || !Array.isArray(facts.notYet)) fail('site.json needs capabilities and limitations arrays for generated discovery');
  const faqs = listed.flatMap((page) => (page.faq || []).map(({ q, a }) => ({ question: q, answer: a, source: absolute(page.path) })));
  writeText(path.join(PUBLIC, 'ai', 'summary.json'), `${JSON.stringify(facts, null, 2)}\n`);
  writeText(path.join(PUBLIC, 'ai', 'faq.json'), `${JSON.stringify(faqs, null, 2)}\n`);
  writeText(path.join(PUBLIC, '.well-known', 'ai.txt'), `${site.name}\n\n${site.entity}\n\nCurrent stable release: ${site.release.version} (${site.release.date}).\n${licence}\n\nRead ${site.baseUrl}/llms.txt and ${site.baseUrl}/ai/summary.json.\nContact: ${site.repo}/discussions (public); security reports: ${site.repo}/blob/master/SECURITY.md. The public demo holds fictional sample data and resets hourly.\nThe static website has no analytics; the separate application has a documented optional country lookup.\n`);
  const links = listed.map((page) => `- [${page.breadcrumb || page.title}](${absolute(page.path)}): ${page.description}`);
  writeText(path.join(PUBLIC, 'llms.txt'), `# ${site.name}\n\n> ${site.entity}\n\nCurrent stable release: ${site.release.version}. ${site.requirements}.\n\n${licence}\n\n## Implemented scope\n\n${site.capabilities.map((item) => `- ${item}`).join('\n')}\n\n## Outside the current workflows\n\n${site.limitations.map((item) => `- ${item}`).join('\n')}\n\n## Documentation and pages\n\n${links.join('\n')}\n\nThe public demo contains fictional records only. Technical validation is not professional accounting acceptance.\n`);
}

/* ---------- main ---------- */

function build() {
  const { pages, byPath } = loadPages();

  const cssDir = path.join(SRC, 'css');
  const cssNames = fs.readdirSync(cssDir).filter((name) => name.endsWith('.css')).sort();
  if (!cssNames.length) fail('src/css has no .css files');
  const css = `${cssNames.map((name) => readText(path.join(cssDir, name)).replace(/\s+$/, '')).join('\n')}\n`;
  const js = `${readText(path.join(SRC, 'js', 'site.js')).replace(/\s+$/, '')}\n`;
  const cssHash = shortHash(css);
  const jsHash = shortHash(js);
  writeText(path.join(PUBLIC, 'assets', 'site.css'), css);
  writeText(path.join(PUBLIC, 'assets', 'site.js'), js);

  const articles = pages.filter((page) => page.article && page.path.startsWith('/news/'));
  const assets = { cssHref: `/assets/site.css?v=${cssHash}`, jsHref: `/assets/site.js?v=${jsHash}`, hasFeed: articles.length > 0 };
  const reserved = new Set([...pages.map((page) => page.outFile), 'sitemap.xml', 'assets/site.css', 'assets/site.js', 'news/feed.xml', 'llms.txt', 'ai/summary.json', 'ai/faq.json', '.well-known/ai.txt', 'releases/index.json']);
  const staticFiles = copyStatic(reserved);
  writeDiscovery(pages);

  const written = [];
  for (const page of pages) {
    const html = buildPage(page, byPath, assets);
    writeText(path.join(PUBLIC, ...page.outFile.split('/')), html);
    written.push({ path: page.path, file: page.outFile, bytes: Buffer.byteLength(html) });
  }
  const sitemap = sitemapXml(pages);
  writeText(path.join(PUBLIC, 'sitemap.xml'), sitemap);
  if (assets.hasFeed) writeText(path.join(PUBLIC, 'news', 'feed.xml'), feedXml(articles));
  if (site.indexNowKey) writeText(path.join(PUBLIC, `${site.indexNowKey}.txt`), site.indexNowKey);
  writeSampleDirectory(PUBLIC, path.join(SRC, 'directory.json'));
  writeReleaseFeed(PUBLIC, ROOT);

  const sitemapCount = (sitemap.match(/<loc>/g) || []).length;
  console.log(`build: ${written.length} page(s), css v=${cssHash} (${css.length} bytes from ${cssNames.join(', ')}), js v=${jsHash} (${js.length} bytes)`);
  for (const entry of written) console.log(`  ${entry.path.padEnd(14)} -> public/${entry.file} (${entry.bytes} bytes)`);
  console.log(`  sitemap.xml: ${sitemapCount} URL(s); news/feed.xml: ${assets.hasFeed ? `${articles.length} item(s)` : 'not written (no article pages)'}`);
  console.log(`  static: ${staticFiles.length ? staticFiles.join(', ') : 'none'}${site.indexNowKey ? `, ${site.indexNowKey}.txt` : ''}`);
  for (const message of notes) console.log(`  note: ${message}`);
}

try {
  build();
} catch (error) {
  if (error instanceof BuildError) {
    console.error(`build error: ${error.message}`);
    process.exit(1);
  }
  throw error;
}

if (process.argv.includes('--check')) {
  const result = spawnSync(process.execPath, [path.join(ROOT, 'check.mjs')], { stdio: 'inherit' });
  process.exit(result.status ?? 1);
}
