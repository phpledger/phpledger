# Elestio — process notes

## What Elestio's own documentation says about adding an application

Checked live, 21 September 2026: elest.io's homepage and `/about` page, and a
web search over Elestio's own blog and about pages (`docs.elest.io` returned
no separate public catalogue-submission page distinct from `/about`).

- Elestio describes itself as "fully managed DevOps" for a curated catalogue
  of "400+ open-source tools ready to deploy." **No page found publishes an
  open manifest format, a schema, or a repository that accepts pull requests**
  for new catalogue entries — unlike CasaOS, CapRover or Coolify (see
  `resources/distribution/README.md`).
- `/about` states plainly: *"We share revenue with open-source authors
  participating in our program"* — confirming the revenue-share model exists,
  but the page does not publish an exact percentage or the mechanics of
  joining. Third-party coverage (search results, not Elestio's own site)
  repeats a commonly cited figure of roughly 20%; that figure is **not**
  confirmed from an Elestio-owned page and is not asserted as fact in
  `SUBMISSION.md`.
- No page found describes a public submission form, issue tracker, or
  packaging repository. The only path identifiable from their own site is
  contacting Elestio directly (support/Discord/outreach), consistent with
  issue #101's existing assessment: **"No public specification; they build
  and manage the deployment" / "Informal outreach."**

**Conclusion: confirmed.** Elestio does not publish an open packaging schema.
The deliverable here is a dossier plus a working reference compose file for
that outreach conversation, not a PR against a manifest repository.

## Eligibility bars

None found published for authors beyond the general shape of "open-source,
self-hostable software" implied by their catalogue and revenue-share framing.
No bar comparable to PikaPods' competing-hosting exclusion was found on any
Elestio-owned page.

## What is still unconfirmed

- The exact revenue-share percentage (commonly cited as ~20% in secondary
  sources, not on an Elestio-owned page).
- Whether Elestio has a private author-facing submission form gated behind an
  account, separate from public marketing pages — not reachable without
  contacting them, which is out of scope for this task (no outreach was
  performed; nothing was submitted).
