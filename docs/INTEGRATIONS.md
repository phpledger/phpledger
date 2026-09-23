# Authorized accounting reads (0.2.1-preview)

Recipe revision **2, 15 September 2026**. The owner approved a combined **0.2.1-preview** with the existing accounting core, read integrations, server-side tables and four multi-year demos. Compatibility is recorded per tested client. Untested or inaccessible clients remain pending and do not hold this preview; financial/access, installation/recovery and demo isolation checks remain mandatory. Codex remote HTTP and STDIO require actual client acceptance. A protocol harness is not application compatibility proof. Publication and final client results are recorded separately.

## Configure an installation

Keep the existing PHP 8.2+ / MySQL 8.4 / MeekroDB installation, identity system and public document root. Production dependencies are pinned in `composer.lock`, including `mcp/sdk` **0.8.1** and `league/oauth2-server` **9.4.1**. PHP needs BCMath, PDO MySQL, mbstring, curl, OpenSSL, fileinfo and sessions. There is no second database connection or external authentication provider.

1. Back up the source, database, private configuration and existing private keys. Apply the versioned migration through `php www/phpledger/install/migrate.php`. `011_read_connections` adds seven integration tables; the complete chain has thirteen receipts and retains 35 accounting guards; `012_demo_history_periods` adjusts two demo period triggers before new sample assignment. It changes no posted accounting rows.
2. Set `PL_PUBLIC_URL` to the exact HTTPS application URL, without a trailing slash: for example `https://ledger.example.com` or `https://phpledger.com/demo`. The resource/audience is that URL plus `/mcp`. Do not derive it from incoming headers.
3. Set `PL_OAUTH_KEY_DIRECTORY` to a private directory outside the public root, or use `www/phpledger/storage/oauth`. Run `php tools/setup-oauth.php` once as the operating-system account that owns this private storage. It refuses an existing directory, generates a 3072-bit RSA key pair and an encryption key, and prints no key material. All three files must be readable by the PHP process, directory mode 0700 and file mode 0600 on Linux. Back them up securely with the installation; do not regenerate them during upgrades or hourly demo resets.
4. Forward Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id, Mcp-Method and Mcp-Name through the HTTPS proxy. Disable response caching on API, MCP and OAuth routes. Do not record authorization headers, request bodies or financial result bodies in access/debug logs. Keep the application SDK logger disabled.
5. Set `PL_ALLOWED_ORIGINS` only for approved direct browser callers: comma-separated exact HTTPS origins, with no paths or wildcards. The approved hosted client origin is `https://llm.bixisoft.com`. Server-to-server clients need no Origin entry. CORS controls browser access; every financial request still requires a credential.
6. Sign in to an authorized company and open **Connections**. Create a separately named personal token for each private agent or job, or let an OAuth client open the existing sign-in/consent screen. The screen grants one selected company/book. The underlying service supports up to twenty explicit authorized pairs and never a wildcard. Owners may revoke connections including their company; revocation ends that connection's entire grant.

Local Docker development mounts private storage read-only into the web container. Initialize a new volume directory using a separate one-shot container, then grant its directory/files to the web process UID (33 in the supplied Apache image). Run the CLI with the same `PL_OAUTH_KEY_DIRECTORY`; mount only that private volume writable for setup. Do not make the web mount writable or copy keys into an image. `compose.demo.yaml` uses its own `demo_private` volume, separate from ordinary local storage. HTTP is accepted only for loopback development/test, or an explicitly enabled local demo (`PL_DEMO_LOCAL_HTTP=1`); remote deployments require HTTPS.

### Discovery with an application path

The application serves `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server` relative to its application base. For an issuer at `https://host.example/demo`, the outer proxy must also route the standard root-level paths `/.well-known/oauth-authorization-server/demo` and `/.well-known/oauth-protected-resource/demo/mcp` to this same application, preserving the original Host. Configure **exact** locations for the selected application, not a wildcard that routes other applications' issuers here. The protected resource's WWW-Authenticate challenge supplies the application-relative metadata URL. Test both discovery paths before hosted OAuth acceptance.

### Credential lifecycle

| Credential | Maximum lifetime | Binding and revocation |
|---|---|---|
| Personal token | 30 days | Shown once; only SHA-256 of 256 random bits stored. Actor, client and explicit company/books. |
| Authorization code | 5 minutes | PKCE S256, exact redirect, client and resource; one redemption. |
| OAuth access token | 15 minutes | Signed JWT with issuer, exact resource audience, actor/client/connection and read scope; durable revocation checked on every request. |
| OAuth refresh grant | 30 days from consent | Rotation is atomic. Reuse revokes the family. Refresh cannot extend the connection boundary. Dropping offline_access stops refresh issuance. |
| Demo connection | Next hourly reset | Also bound to visitor and random generation. Reused numeric IDs cannot restore old access. |

The earlier client-registration expiry also caps a grant. Public DCR clients use authorization code + S256 and `token_endpoint_auth_method=none`. CIMD clients use a public HTTPS metadata document whose `client_id` exactly equals its URL. Registration expires after 90 days; renewing expired CIMD metadata revokes old grants. A revoked registration cannot be revived by refetching metadata.

OAuth endpoints are `/oauth/authorize`, `/oauth/token`, `/oauth/register`, `/oauth/revoke`. Authorization and token requests require the exact `resource` URL. Request `ledger.read` and optionally `offline_access`. A standard revocation POST supplies `client_id`, `token` and optionally `token_type_hint` as form data; no client secret is configured for these public clients. Authentic access or refresh tokens revoke the whole connection. Unknown/already revoked tokens return 200; a token belonging to another client is refused. Personal tokens are revoked in Connections. These behaviors follow [RFC 7009](https://www.rfc-editor.org/rfc/rfc7009.html) and refresh replay guidance in [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html).

Every read rechecks active user, current company membership, book, client, expiry, revocation and demo generation. Browser sessions are not constructed for machine access. A 401 response tells the client to reconnect. During the short demo maintenance lock or refresh boundary, 503 includes a retry instruction; after reset, start a fresh sample and consent again. The demo banner shows time remaining. A shared chat deployment must use each user's separate OAuth grant.

## Business interface

Use Streamable HTTP at `<application URL>/mcp`. The hosted target is `https://phpledger.com/demo/mcp` **when 0.2.1 is published**. Do not append `/sse` or infer the transport from a suffix. Legacy SSE is not supplied. The PHP STDIO bridge forwards to this HTTPS endpoint and needs no database credentials.

| MCP tool | GET API path | Required selection beyond company_id/book_id |
|---|---|---|
| ledger_companies | /api/v1/companies | No scope arguments; lists only granted pairs |
| ledger_capabilities | /api/v1/capabilities | None |
| ledger_accounts | /api/v1/accounts | None |
| ledger_transactions | /api/v1/transactions | Optional inclusive from/to, status, kind, search |
| ledger_general_journals | /api/v1/general-journals | None |
| ledger_journal | /api/v1/journal | journal_id |
| ledger_source_detail | /api/v1/source-detail | source_id, source_type: transaction or general_journal |
| ledger_trial_balance | /api/v1/trial-balance | as_of |
| ledger_profit_loss | /api/v1/profit-loss | from, to |
| ledger_balance_sheet | /api/v1/balance-sheet | as_of |
| ledger_account_statement | /api/v1/account-statement | account_id, from, as_of |

Schemas come from `pl_read_catalog()` and are self-contained objects rejecting unknown arguments. Positive integer IDs are bounded to JavaScript's exact range; dates are validated calendar dates in `YYYY-MM-DD`. Reports use the same accounting functions as browser reports. Drafts never affect posted report totals. Unsupported write tools, SQL execution, arbitrary HTTP and generic administration are absent.

Amounts remain decimal strings with four places; never parse them with binary floating point to recompute accounting totals. Business dates are inclusive. Event instants use UTC ISO 8601 with `Z`. Source IDs/references and journal links remain available for drill-down. Report lines are nested collections (for example `data.accounts.rows`); each collection carries its own pagination. List operations retain descriptive collection names such as `documents` and `movements`, with `data.pagination`.

`page` starts at 1; `page_size` is 25 (default), 50 or 100. Pagination metadata is `{page,page_size,total,pages,next_page}`. Empty collections have page/pages 1 and no next page; requests past the final page clamp to it. For reports with several named collections, follow each collection's metadata; do not append a shorter collection's repeated final page. Totals always describe the complete scoped report, not the visible page. Canonical account running balances are computed in date/journal/line order before pagination, search and presentation sorting.

MCP returns the same result in `structuredContent` and a compact JSON text content block. A client whose model cannot see structured results can use the text. No resources, prompts or proprietary UI extensions are required. Separately fetch `/api/v1/openapi.json` for OpenAPI 3.1 workflow integration; using that fallback does not pass native MCP acceptance.

### Protocol versions and limits

- **2025-11-25:** initialize, save Mcp-Session-Id, send notifications/initialized, then tools/list and tools/call. Send MCP-Protocol-Version on subsequent requests. Sessions are bound to a connection, last at most thirty idle minutes, and cannot outlive the grant.
- **2026-07-28:** per-request protocol, with server/discover and tools/call; use `params._meta` keys `io.modelcontextprotocol/protocolVersion` and `io.modelcontextprotocol/clientCapabilities`. HTTP also requires Mcp-Method and Mcp-Name for tool calls. The pinned SDK checks header/body agreement. This is a separate tested protocol path, not an assumption about a client's version.
- Requests: 64 KiB body, 8 KiB query/Authorization, search 160 characters, pages bounded to 100,000. Tool data: 240,000 bytes; duplicated MCP response: 512 KiB. Ten active MCP sessions and thirty active connections per actor are bounded. The STDIO bridge accepts one newline-delimited JSON-RPC message at a time and finite JSON/SSE responses.
- Limits per minute: 300 requests per source, 120 per connection, 20 registrations globally / 5 per source, 20 metadata fetches globally / 5 per source. 429 includes Retry-After. CIMD is limited to HTTPS/443, two/four second connection/total timeouts and 32 KiB JSON; redirects/proxies/private/special addresses are denied and a validated public DNS address is pinned for the request.
- Integration audit rows contain actor/connection, fixed action/outcome and UTC time. No credentials or financial result bodies are logged. Rate keys are hashes. Ordinary installations collect expired session/rate rows in bounded batches; the restricted demo uses updates and its scheduled reset. Durable connection/token/audit history remains until operator-managed retention or the demo reset.

## Versioned client recipes

The files under [`resources/integrations/0.2.1`](../resources/integrations/0.2.1/) contain no credentials. Replace example URLs and IDs deliberately. Store bearer credentials in the client's private secret settings/environment. Never export a populated owner token as a shared team configuration.

### Codex — recipe 1

Local CLI inventory reports **codex-cli 0.154.0**; actual server acceptance is still open. Use `codex-remote.toml` for remote HTTP with `bearer_token_env_var`, or `codex-stdio.toml` with the PHP bridge and inherited `PL_MCP_URL`/`PL_MCP_TOKEN`. For OAuth, omit the bearer variable, retain the HTTPS URL and run `codex mcp login phpledger` after adding the reviewed entry. Read the trial balance, then an account's movements, following all pages. Current setup reference: [Codex MCP](https://learn.chatgpt.com/docs/extend/mcp?surface=cli).

### Claude — recipe 1

In a Claude account with custom remote connectors, add the exact HTTPS MCP URL and use OAuth. Complete PHP Ledger's company/book consent. Ask for P&L and Balance Sheet for the selected dates, then inspect the sources. Record the Claude app/build and plan/connector surface actually used. Remote-connector instructions: [Claude custom connectors](https://support.claude.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp).

### ChatGPT — recipe 1

Use the account's supported custom connection/plugin setup, choose the HTTPS MCP URL and OAuth, and consent to the selected sample company/book. Product names and availability vary by account; record the exact supported UI and build. Ask for reports and trace one figure to its source. [Official custom plugin connection guide](https://developers.openai.com/plugins/deploy/connect-chatgpt). An authenticated custom connection UI was not available for this acceptance run.

### n8n — recipe 1

Import `n8n-manual-report.json`, which is inactive, has a disabled MCP node and no credential IDs. It uses the native standalone MCP Client node schema **1.1**, `serverTransport=httpStreamable`, bearer auth and an explicit tool. Configure its HTTPS endpoint, selected IDs and dedicated bearer credential, then enable only the MCP node for a manual execution. The following Code node formats exact report strings and has no send action or model call. For OAuth, choose MCP OAuth2 credentials, authorize through PHP Ledger and verify the exact resource and scopes. [Credential documentation](https://docs.n8n.io/integrations/builtin/cluster-nodes/sub-nodes/n8n-nodes-langchain.toolmcp) and [native node source](https://github.com/n8n-io/n8n/blob/master/packages/%40n8n/nodes-langchain/nodes/mcp/McpClient/McpClient.node.ts) informed the recipe; import/execution against an installed n8n version remains open.

### OpenClaw — recipe 1

Use `openclaw-oauth.json`, which explicitly sets `transport: "streamable-http"` and `auth: "oauth"`; then `openclaw mcp login phpledger`. For a private bearer job, replace the auth setting through its private credential configuration. Do not rely on omitted transport: the documented default is SSE. Restrict the connection to a private agent session and demonstrate denial of an ungranted company. [Transport reference](https://docs.openclaw.ai/cli/mcp/transports).

### Hermes Agent (Nous Research) — recipe 1

Merge the reviewed `hermes-oauth.yaml` entry into `mcp_servers` in the user's Hermes configuration; run `hermes mcp login phpledger`. A separate `hermes-stdio.yaml` uses the bundled PHP bridge with scoped credentials inherited privately. An HTTP bearer recipe may use Hermes's documented environment substitution in the Authorization header. Discover tools, read reports and follow pagination in an actual session. [Hermes MCP reference](https://hermes-agent.nousresearch.com/docs/user-guide/features/mcp/).

### Open WebUI — recipe 1

Requires native MCP support (documented from 0.6.31); record the tested build. An administrator adds **MCP (Streamable HTTP)** under external tool-server integrations and limits access to the intended users. Choose per-user OAuth 2.1/DCR and send the resource parameter; use `ledger.read offline_access`. Each of two users must separately consent to their own PHP Ledger company. Discovery's green check alone does not list tools or execute a report. Verify isolation with actual tool calls. [Open WebUI instructions](https://docs.openwebui.com/features/extensibility/mcp/).

### llm.bixisoft.com — recipe 1, deployment-specific gate

A fresh public `/props` read returned **b8790-be76dd0bb** on 15 September 2026, the separately identified llama.cpp WebUI. This is not Open WebUI. In its actual UI, select authenticated Streamable HTTP explicitly if offered, use the approved `https://llm.bixisoft.com` origin and a visitor-scoped credential, and complete tool discovery plus financial reads. Do not guess transport from the URL suffix or upgrade/replace the hosted application as part of this task. If its current UI cannot send the required transport/authentication, record the precise unsupported behavior and keep this gate open.

## Client acceptance matrix

| Client | Observed client version | Acceptance status |
|---|---|---|
| Codex (remote and STDIO) | CLI 0.154.0 | Verified: live tool discovery, all four reports, exact browser parity, pagination/source detail, scope denial, revocation, hourly expiry/reset and new-generation reconnect |
| Claude (OAuth HTTP) | Unavailable | Open: authenticated client UI unavailable |
| ChatGPT (OAuth HTTP) | Unavailable | Open: authenticated custom connection UI unavailable |
| n8n (native HTTP) | No installed version verified; recipe node 1.1 | Open: import/native execution and OAuth recipe test |
| OpenClaw | No installed version verified | Open: private agent session |
| Hermes Agent (HTTP and STDIO) | No installed version verified | Open: discovery/report/pagination session |
| Open WebUI | No deployed version verified | Open: two-user OAuth isolation |
| llm.bixisoft.com | llama.cpp b8790-be76dd0bb; gemma-4-fast | Actual UI reads verified: all four reports, pagination/source, cross-company denial and expiry. Full compatibility gate remains partial: dedicated revocation and exhaustive browser parity checks pending |

For **each** client, record endpoint, explicit transport, authentication, build, actor's authorized pairs, discovered tools, TB/P&L/Balance Sheet/account statement, pagination, source detail and exact browser comparison. Verify cross-company and write denial, expiry, revocation and the next real demo reset. Separate successful tool execution from model interpretation. Use concurrent private clients, malformed arguments and origin/reconnect checks. Credentials and result bodies never belong in published receipts.

## Browser tables

Transactions, general journals, account movements and bank rows progressively enhance with locally bundled **DataTables 3.0.4**, with 25/50/100-row pages. `/tables` is a session-authenticated, company/book-scoped GET route. Search/sort are bounded and allowlisted; total/filtered counts are scoped. Ledger window balances precede filtering/paging. Existing CSV exports retain complete scoped results and their established limits. Without JavaScript or after an enhancement error, the original server-rendered view remains usable. Connection navigation scrolls within the header at narrow widths.

Local browser evidence covers desktop 1440, tablet 768 and mobile 390, including OAuth consent, token display/revocation and bank drill-down. Technical checks do not establish independent accounting review, WCAG certification or observed participant usability. See the [staged receipt](repository/sprint-05/DELIVERY-0.1.5-0.2.1.md).

## Native-client corrections found during release verification

The STDIO bridge preserves empty JSON objects in capabilities and schemas; decoding them as associative PHP arrays changed `{}` into `[]` and caused Codex to reject initialization. The demo now queues concurrent admissions for at most two seconds while preserving the same maintenance lock. Busy bootstrap responses retain CORS and Retry-After headers for approved origins. These corrections have targeted regression coverage; the actual client matrix is updated from execution receipts.

## 0.2.1 live acceptance

The [acceptance receipt](repository/sprint-05/PREVIEW-0.2.1-ACCEPTANCE.json) records final source `a8ee38a37616cc245102404bd47b6afea9373a23`. Codex 0.154.0 completed the same four-report journey through remote HTTPS and the packaged STDIO bridge with dedicated visitor credentials. All report totals matched the browser and API exactly. The real 19:00 UTC reset on 15 September completed at 19:00:13; old native credentials were denied and both transports read a fresh generation. Two independent users also passed browser OAuth consent, scope isolation, rotation and replay rejection.

The actual BixiSoft UI discovered 11 tools and executed financial reads. Its model mislabelled the expected scope denial as a failed check; the server correctly denied the request. Its remaining matrix items are explicitly partial. No other named client is represented as tested by these results.

## Local receipt/expense party identity extension

The existing source and transaction-list read DTOs expose nullable `party_id` in addition to `counterparty`. The ID identifies a party in the same company; the name remains a document snapshot, including in correction revisions. Legacy sources remain null and are never linked by a name guess. Party identity alone creates no AR/AP recognition or allocation. No public financial-write endpoint is introduced. The additive `050_document_parties` migration retains old immutable source rows and extends the effective-document view for current correction snapshots. This extension is locally implemented and awaits the combined validation recorded in the implementation receipt.

Internal callers using the central posting service must also respect the account's explicit physical-cash or bank classification. A bank account has a zero floor unless an agreed overdraft facility and positive fixed-precision limit are recorded. A facility is denominated in the account's designated currency, or the book currency when no account currency is designated; it does not provide an allowance for other currency balances. Changing exchange rates must not permit a payment that exceeds the recorded funds and facility in the payment currency. These checks use posted accounting records and do not confirm cleared balances, holds, available funds, or acceptance by a banking provider. Existing historical deficits are retained; a new posting may improve them but cannot worsen a deficit beyond the permitted floor. Exact posting retries continue to resolve the original result before applying the current balance policy.


The existing account read DTO also exposes `money_kind` (nullable `physical`/`bank`), nullable designated `currency`, boolean `overdraft_enabled`, exact decimal-string `overdraft_limit`, and resolved `facility_currency`. These are scoped accounting configuration fields. They do not report a provider's available credit, cleared balance or a loan drawdown. A disabled facility has a zero limit; account updates and audit remain on the existing service/browser permission path.
