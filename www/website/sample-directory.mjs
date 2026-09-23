/** Static sample directory: data-only manifests, pages and unsigned signing inputs. */
import fs from 'node:fs';
import path from 'node:path';
const escape = (s) => String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export function sampleDirectory(source) {
  const value = JSON.parse(fs.readFileSync(source, 'utf8'));
  if (value.schema !== 1 || !Array.isArray(value.packages) || value.packages.length > 200) throw new Error('Invalid sample directory schema');
  const seen = new Set();
  for (const entry of value.packages) {
    if (entry.type !== 'sample' || !/^sample-[a-z][a-z0-9-]{0,52}$/.test(entry.slug) || !/^\d+\.\d+\.\d+$/.test(entry.version) || entry.licence !== 'CC0-1.0' || seen.has(entry.slug)) throw new Error('Invalid sample directory identity');
    if (entry.manifest.slug !== entry.slug || entry.manifest.version !== entry.version || entry.inventory.slug !== entry.slug || !/^[a-f0-9]{64}$/.test(entry.inventory.archive_sha256)) throw new Error('Directory payload does not match its package');
    seen.add(entry.slug);
  }
  return value;
}
export function sampleDirectoryPages(source) {
  const directory = sampleDirectory(source);
  const make = (route, title, description, content) => ({path: route, title, description, nav: '', breadcrumb: title, ogImage: '/assets/og/download.png', ogImageAlt: 'PHP Ledger software download', ogType: 'website', bodyClass: 'page-directory', jsonld: ['organization','breadcrumb'], budgetEager: 700000, budgetTotal: 1000000, lastmod: '2026-09-23', id: `directory:${route}`, file: source, slug: route.slice(1,-1), outFile: `${route.slice(1)}index.html`, content, noindex: false});
  const cards = directory.packages.map((e) => `<article><h2><a href="/directory/${escape(e.slug)}/">${escape(e.name)}</a></h2><p>${escape(e.description)}</p><p>${escape(e.version)} · ${escape(e.licence)} · data only</p></article>`).join('\n');
  const pages = [make('/directory/', 'Sample company directory', 'Optional fictional sample companies for practice or zero-balance onboarding. Download data-only CC0 packages separately from PHP Ledger.', `<section class="section"><div class="container"><h1>Sample company directory</h1><p>Keep your PHP Ledger core installation small. Add a fictional sample to practise, or copy its structure into a new business without its transactions.</p><p>These packages contain JSON data only. The application checks file digests and requirements before installation; official downloads also require publisher-signed metadata. Samples never install plugins silently.</p><div class="card-grid">${cards}</div><p><a href="/directory/index.json">Machine-readable directory</a> · <a href="/demo/">Try the shared demonstration</a></p><h2>Submit a package</h2><p>Use CC0 for sample data, include only fictional identities, declare exact module and plugin requirements, separate structure from history, and demonstrate reconciliation through existing posting services. Submit a pull request to the project for review. No executable files, secrets, real personal data or silent dependency installation are accepted.</p></div></section>`)];
  for (const e of directory.packages) {
    const url = `https://github.com/phpledger/${e.slug}/releases/download/v${e.version}/${e.slug}-${e.version}.zip`;
    const requirements = Object.entries(e.requires).map(([k,v]) => `<li>${escape(k)} ${escape(v)}</li>`).join('');
    pages.push(make(`/directory/${e.slug}/`, `${e.name} sample company`, `Practise with ${e.name}, a fictional CC0 sample. Import its history into a sample business or its structure with zero balances.`, `<section class="section"><div class="container"><p><a href="/directory/">All sample companies</a></p><h1>${escape(e.name)}</h1><p>${escape(e.description)}</p><p>Version ${escape(e.version)} · ${escape(e.author)} · CC0-1.0 · JSON data only</p><h2>What you can do</h2><p>Practise with fictional transaction history in a separate sample business, or import its declared structure into a new business with zero balances. This sample does not claim industry, payroll, tax or statutory compliance.</p><h2>Requirements</h2><ul>${requirements}</ul><h2>Install</h2><p>In PHP Ledger, open Packages, refresh the directory and choose Verify and install. Opening Packages does not contact the directory. For an offline owner upload, download the ZIP and upload it on Packages.</p><p><a href="${url}">Download sample ZIP</a> · <a href="/directory/${e.slug}/${e.version}/package.json">Package manifest</a></p><p>SHA-256: <code>${escape(e.inventory.archive_sha256)}</code></p><h2>Data and removal</h2><p>All records are fictional. Removing the package stops future selection but preserves businesses already created from it and their accounting history.</p></div></section>`));
  }
  return pages;
}
export function writeSampleDirectory(output, source) {
  const directory = sampleDirectory(source);
  const write = (name, value) => { const file = path.join(output,'directory',name); fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,JSON.stringify(value,null,2)+'\n'); };
  write('index.json', {schema:1,packages:directory.packages.map(({manifest,inventory,...entry})=>entry)});
  for (const entry of directory.packages) { write(`${entry.slug}/${entry.version}/package.json`,entry.manifest);write(`${entry.slug}/${entry.version}/sample-metadata.json`,entry.inventory); }
}
