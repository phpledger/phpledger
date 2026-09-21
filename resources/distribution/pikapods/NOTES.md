# PikaPods — process notes

## What PikaPods' own documentation says about adding an application

Checked live, 21 September 2026: `docs.pikapods.com/faq/apps`.

- On how new apps are added, verbatim: *"New apps are regularly being added.
  If your favorite app isn't available yet, you can suggest or vote for it on
  our [feedback](https://feedback.pikapods.com/) page."* There is **no open
  manifest format or repository that accepts a submitted package** — PikaPods
  staff package each application themselves after a request, matching issue
  #101's existing assessment: "A container image on a single HTTPS port; they
  package it" / "Request or vote on their feedback page" / "Informal staff
  testing."
- Eligibility bar, verbatim from the same page: an app should
  - "Be a web application and use one HTTPS port only"
  - "Have an official (or semi-official) Docker image available"
  - "Be actively developed and security issues are addressed"
  - "Have a license that allows self-hosting"
  - "Not use large amounts of CPU or bandwidth by design (e.g. video encoding)"
  - "Not have a potential for abuse or impact on other pods (e.g. proxies or
    VPNs)"
  - **"Not compete with the author's own paid hosting service, unless
    alternative hosting options are actively promoted"**

**Conclusion: confirmed.** PikaPods does not publish an open packaging schema;
the deliverable here is a dossier plus a working reference compose file for
the feedback-page request, not a PR against a manifest repository.

## The single-HTTPS-port requirement and this compose file

"Use one HTTPS port only" is PikaPods' rule about the application's
**public-facing** surface — PikaPods' own edge terminates TLS and proxies to
one port per pod. It is not, on its own wording, a ban on a private backing
service inside the pod: PikaPods' existing catalogue already includes
multi-container-shaped applications that need a database (Discourse, Moodle,
phpBB, per their own docs and GitHub org), so a `web` + `db` compose is not
obviously disqualifying.

That said, **this is inferred, not confirmed for PHP Ledger specifically** —
PikaPods' own docs do not show the internal compose shape they use for those
apps, only that the apps exist on the platform. `resources/distribution/pikapods/compose.yaml`
is offered as the reference deployment (app on 8080 internally, MySQL 8.4
alongside it, exactly mirroring `compose.production.yaml`), with the
possibility flagged plainly in `SUBMISSION.md`'s outreach material that
PikaPods staff may instead choose to run the database as a separate managed
service, or require SQLite/a single-container variant, per their own internal
conventions. Whoever sends the request should ask PikaPods directly whether
the two-service compose is acceptable as-is or whether they expect an
external database — this file does not assume an answer either way.

## The competing-hosting exclusion — resolved

Recorded already in `AGENT_MESSAGES.MD` and issue #101: **the owner confirmed
on 21 September 2026 that PikaPods' competing-hosting exclusion does not apply
to PHP Ledger**, because PHP Ledger is self-hosted software and the project
offers no hosted product of its own — there is nothing for a PikaPods listing
to compete with. This was previously flagged against decision C7 in
`CHANNEL-UNBLOCKING-2026-09-21.md` and has since been corrected there. No
further owner input is needed on this point before a request is sent.

## What is still unconfirmed

- The exact revenue-share percentage for PikaPods (commonly cited as up to
  ~20% in secondary sources; not itself the subject of this dossier's claims,
  since `SUBMISSION.md` makes no revenue-share claim).
- Whether PikaPods would accept the bundled MySQL service as-is or require a
  different database shape (see above) — an open question to raise in the
  feedback-page request itself, not something resolved by this dossier.
