# Users and roles

PHP Ledger 1.2 gives every business real user accounts, roles and permissions. This page is the
short version: what the three built-in roles do, how to add someone, and what an administrator can
and cannot do to an account.

## The three built-in roles

Every installation ships with three roles. They cannot be edited or deleted, so "Owner" means the
same thing in March as it did in January, and an audit trail stays readable.

| Role | Can |
|---|---|
| **Owner** | Everything: record and correct accounting, and change the settings that govern the books — modules, numbering, periods, opening balances, tax settings, accounting policies, roles and people. Sees cost. |
| **Accountant** | Record, edit, post and correct documents and journals. Does not change the settings that govern the books, and does not see cost. |
| **Viewer** | Read records and reports. Records nothing. |

Roles are held **per business**. Somebody can be the Owner of one business and a Viewer of another
on the same installation.

## Your own roles

If the three do not fit, **Setup › Roles › New role** builds one from the same list of permissions
the built-in roles use. A common example: a supervisor who may approve a van settlement but should
not see purchase cost, change modules or reopen a period. Before 1.2 that person had to be an Owner
or nothing.

A role that a module owns a permission for — a plugin's permission, from 1.3 onward — keeps that
permission listed while the module is switched off. It simply does not apply until the module is
switched back on, and then it applies again exactly as configured. Nothing is lost.

## Adding someone

**Setup › Users › Invite someone.** Enter their email address and choose the role they will hold in
this business.

PHP Ledger does not send email yet. The screen shows a **one-time link**, once. Copy it and send it
to them however you normally would. They open it, choose their own name and password, and are ready
to sign in. You never see or choose their password.

An invitation lasts seven days, can be revoked before it is used, and works only once.

## If someone forgets their password

**Setup › Users › (person) › Force a password reset.** Their old password stops working
immediately, every browser they are signed in to is signed out, and you get a one-time link to pass
on. They choose the new password themselves.

You can change only **your own** password, from **Your profile**. No administrator can set another
person's password.

## Where you are signed in

**Your profile › Where you are signed in** lists every browser currently signed in as you, with
when it started and when it was last used. Ending one signs it out immediately, wherever it is.
"Sign out everywhere else" keeps the browser you are using and ends the rest.

A session ends by itself after 30 minutes without use, and after 12 hours in any case.

## Suspending, removing and anonymising

Nobody who has posted anything is ever deleted. The books answer "who recorded this", and an
account that vanished would take that answer with it. There are three lesser actions instead:

- **Suspend** — they cannot sign in. Reversible at any time; nothing else changes.
- **Remove access to this business** — they stop being able to open this business. Their account
  survives, and so does their access to any other business they work in.
- **Anonymise** — the personal details on the account are replaced and the account is suspended.
  Every journal, document and audit row keeps pointing at the same account. **This cannot be
  undone**, and it is the right answer to an erasure request that an accounting trail can honour.

Every one of these records who did it, when, and the reason they gave.

## Who sees cost

Purchase cost and margin are a trade secret in many businesses, so they are their own permission:
**See cost and margin**. By default only the Owner holds it.

On top of that, **Setup › Cost visibility** turns cost and margin columns on or off **per report**.
Both must say yes for a number to appear: the person holds the permission, and the report is set to
show it. Turning a report off hides cost on the screen *and* in the machine-readable read, so an
API client cannot read what the screen hides.

Two reports are covered today: **Stock and value by location**, and **Stock issues, returns and
gate passes**. On a stock document only the carrying value is withheld — the quantities, the
movements and the document number are always shown, because a storeman has to be able to read the
document he is handed. The van settlement's cost totals are not covered yet.

## Administering the installation

One permission belongs to the whole installation rather than to one business: **Administer this
installation**. It is held by a person, not through a role, because the owner of one business must
not automatically gain rights over another. The owner of the first business created on an
installation holds it; they can give it to someone else from **Setup › Users**.

From 1.2's next milestone this is the permission that installs and activates packages.

## What is not here yet

- **PHP Ledger does not send email.** Invitations, password resets and email-address confirmations
  all show a one-time link on screen for you to pass on. Delivering them by email is planned.
- **There is no self-service "I forgot my password" link** on the sign-in page. Ask an
  administrator for a reset.
- **Two-factor authentication is not available.**
- **Roles cannot be copied between businesses.** Build the same role again in each one.
- **A person cannot be invited to several businesses at once.** Invite them per business.
