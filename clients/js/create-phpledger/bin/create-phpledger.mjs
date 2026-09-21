#!/usr/bin/env node
// create-phpledger: scaffolds a Docker Compose deployment of PHP Ledger
// (docs/CONTAINER.md). It installs nothing itself - it writes files into the
// target directory and prints the command to start them. No dependencies: only
// Node built-ins, so `npm create phpledger@latest` never resolves a supply chain.
import { randomBytes } from "node:crypto";
import { createInterface } from "node:readline/promises";
import { fileURLToPath, pathToFileURL } from "node:url";
import { dirname, join, resolve } from "node:path";
import { readFile, mkdir, writeFile, chmod, readdir } from "node:fs/promises";
import { existsSync } from "node:fs";

const HERE = dirname(fileURLToPath(import.meta.url));
const PACKAGE_ROOT = resolve(HERE, "..");

// The public feed compose.production.yaml's upgrade docs point at. Overridable
// only for the test suite (tests/create-phpledger-test.py): PL_FEED_URL is not a
// documented end-user variable and must not appear in generated output.
const DEFAULT_FEED_URL = "https://phpledger.com/releases/index.json";
const FEED_TIMEOUT_MS = 10_000;

function parseArgs(argv) {
  const options = { yes: false, force: false, targetArg: null };
  for (const arg of argv) {
    if (arg === "--yes" || arg === "-y") options.yes = true;
    else if (arg === "--force") options.force = true;
    else if (!arg.startsWith("-") && options.targetArg === null) options.targetArg = arg;
    else throw new Error(`Unrecognized argument: ${arg}`);
  }
  return options;
}

// PL_FEED_URL is read directly as a filesystem path when it isn't an http(s) URL
// (or is a file: URL). That is what lets the test suite stub the release feed
// with a plain JSON fixture on disk, with no local server and no network call.
async function readFeed(feedUrl) {
  if (feedUrl.startsWith("http://") || feedUrl.startsWith("https://")) {
    const response = await fetch(feedUrl, { signal: AbortSignal.timeout(FEED_TIMEOUT_MS) });
    if (!response.ok) throw new Error(`HTTP ${response.status} from ${feedUrl}`);
    return response.json();
  }
  const path = feedUrl.startsWith("file://") ? fileURLToPath(feedUrl) : feedUrl;
  return JSON.parse(await readFile(path, "utf8"));
}

async function resolveVersion(feedUrl) {
  try {
    const feed = await readFeed(feedUrl);
    const version = feed?.channels?.stable?.version;
    if (typeof version !== "string" || version.length === 0) {
      throw new Error("channels.stable.version is missing from the feed");
    }
    return version;
  } catch (error) {
    process.stderr.write(
      `create-phpledger: could not resolve the current stable release from ${feedUrl} (${error.message}); ` +
        `falling back to the "latest" image tag.\n`,
    );
    return "latest";
  }
}

// Strong enough for a compose .env: 24 random bytes, base64url-encoded (32
// characters, no shell-hostile symbols), generated fresh per invocation.
function generateSecret() {
  return randomBytes(24).toString("base64url");
}

async function isNonEmptyDirectory(path) {
  if (!existsSync(path)) return false;
  const entries = await readdir(path);
  return entries.length > 0;
}

async function ask(rl, question, fallback) {
  const suffix = fallback ? ` [${fallback}]` : "";
  const answer = (await rl.question(`${question}${suffix}: `)).trim();
  return answer.length > 0 ? answer : fallback;
}

async function collectAnswers(options) {
  const defaults = {
    targetDir: options.targetArg || "phpledger",
    publicUrl: "http://127.0.0.1:8080",
    webPort: "8080",
    adminEmail: "",
    adminName: "",
    adminUsername: "",
  };

  if (options.yes) {
    // Non-interactive: take the defaults (or the CLI argument) and skip the
    // administrator fields entirely, leaving env-based install unconfigured so
    // setup finishes in the browser at /install (docs/CONTAINER.md).
    return defaults;
  }

  const rl = createInterface({ input: process.stdin, output: process.stdout });
  try {
    const targetDir = await ask(rl, "Target directory", defaults.targetDir);
    const publicUrl = await ask(rl, "Public URL", defaults.publicUrl);
    const webPort = await ask(rl, "Web port", defaults.webPort);
    const adminEmail = await ask(rl, "Administrator email (leave blank to finish setup in the browser)", "");
    let adminName = "";
    let adminUsername = "";
    if (adminEmail) {
      adminName = await ask(rl, "Administrator name", "");
      adminUsername = await ask(rl, "Administrator username (optional)", "");
    }
    return { targetDir, publicUrl, webPort, adminEmail, adminName, adminUsername };
  } finally {
    rl.close();
  }
}

function renderEnv({ dbPassword, dbRootPassword, publicUrl, webPort, adminEmail, adminName, adminUsername, adminPassword }) {
  const lines = [
    "# Written by create-phpledger. Never commit this file - it holds the database",
    "# and administrator passwords for this install (see .gitignore).",
    `PL_PUBLIC_URL=${publicUrl}`,
    `PL_WEB_PORT=${webPort}`,
    `PL_DB_PASSWORD=${dbPassword}`,
    `PL_DB_ROOT_PASSWORD=${dbRootPassword}`,
  ];
  if (adminEmail) {
    lines.push(
      "",
      "# Installs the administrator account from the environment on first start",
      "# (docs/CONTAINER.md). Remove these three and leave PL_ADMIN_PASSWORD unset",
      "# to finish setup in the browser at /install instead.",
      `PL_ADMIN_EMAIL=${adminEmail}`,
      `PL_ADMIN_NAME=${adminName}`,
      `PL_ADMIN_PASSWORD=${adminPassword}`,
    );
    if (adminUsername) lines.push(`PL_ADMIN_USERNAME=${adminUsername}`);
  } else {
    lines.push(
      "",
      "# No administrator email was given, so setup finishes in the browser at",
      "# /install instead. This generated password is unused until you set",
      "# PL_ADMIN_EMAIL and PL_ADMIN_NAME here too (docs/CONTAINER.md).",
      `#PL_ADMIN_EMAIL=`,
      `#PL_ADMIN_NAME=`,
      `PL_ADMIN_PASSWORD=${adminPassword}`,
    );
  }
  lines.push(
    "",
    "# Set to 1 for one restart after pulling a newer image tag, then back to 0",
    "# (or leave it set - a no-op once nothing is pending). See README.md.",
    "#PL_AUTO_MIGRATE=1",
  );
  return lines.join("\n") + "\n";
}

function renderReadme({ version, publicUrl, webPort }) {
  return `# PHP Ledger (Docker Compose)

Generated by \`create-phpledger\`, pinned to release ${version}.

## Start

\`\`\`
docker compose up -d
\`\`\`

Then open ${publicUrl} (container port ${webPort}). \`/health\` answers as soon as
the web server and its database connection are up, whether or not installation
itself has finished.

## Administrator password

\`.env\` holds \`PL_DB_PASSWORD\`, \`PL_DB_ROOT_PASSWORD\` and \`PL_ADMIN_PASSWORD\`.
Keep it out of version control (already listed in \`.gitignore\`) and out of logs,
screenshots and chat. If \`PL_ADMIN_EMAIL\` is not set in \`.env\`, no account was
created from the environment - finish setup in the browser at
\`${publicUrl}/install\` using the database host \`db\` and \`PL_DB_PASSWORD\`.

## Upgrade (docs/CONTAINER.md)

1. Back up the \`phpledger_data\` and \`phpledger_private\` volumes.
2. Pull the new tag: \`docker compose pull web\` (edit \`PL_VERSION\` in
   \`compose.yaml\` first, or pass \`-e PL_VERSION=<version>\`).
3. Restart with migrations applied once: set \`PL_AUTO_MIGRATE=1\` for this run
   (uncomment it in \`.env\`, or \`docker compose run -e PL_AUTO_MIGRATE=1\`), then
   \`docker compose up -d web\`.
4. Afterwards \`PL_AUTO_MIGRATE\` can stay set (a no-op once nothing is pending)
   or be turned back off until the next upgrade - either is safe.

This installation never replaces its own files in place; see docs/CONTAINER.md
in the PHP Ledger repository for the full explanation and the environment
variable reference.
`;
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  const feedUrl = process.env.PL_FEED_URL || DEFAULT_FEED_URL;

  const answers = await collectAnswers(options);
  const targetDir = resolve(process.cwd(), answers.targetDir);

  if ((await isNonEmptyDirectory(targetDir)) && !options.force) {
    process.stderr.write(
      `create-phpledger: "${answers.targetDir}" already exists and is not empty. ` +
        `Pass --force to write into it anyway.\n`,
    );
    process.exitCode = 1;
    return;
  }

  const version = await resolveVersion(feedUrl);

  const dbPassword = generateSecret();
  const dbRootPassword = generateSecret();
  const adminPassword = generateSecret();

  const composeTemplate = await readFile(join(PACKAGE_ROOT, "templates", "compose.yaml.tmpl"), "utf8");
  const compose = composeTemplate.replace("__PL_VERSION__", version);

  await mkdir(targetDir, { recursive: true });
  await writeFile(join(targetDir, "compose.yaml"), compose, "utf8");
  await writeFile(
    join(targetDir, ".env"),
    renderEnv({
      dbPassword,
      dbRootPassword,
      publicUrl: answers.publicUrl,
      webPort: answers.webPort,
      adminEmail: answers.adminEmail,
      adminName: answers.adminName,
      adminUsername: answers.adminUsername,
      adminPassword,
    }),
    "utf8",
  );
  await chmod(join(targetDir, ".env"), 0o600);
  await writeFile(join(targetDir, ".gitignore"), ".env\n", "utf8");
  await writeFile(join(targetDir, "README.md"), renderReadme({ version, publicUrl: answers.publicUrl, webPort: answers.webPort }), "utf8");

  process.stdout.write(`\nWritten to ${targetDir}\n\n`);
  process.stdout.write(`  cd ${answers.targetDir} && docker compose up -d\n\n`);
  process.stdout.write(`Then open ${answers.publicUrl}\n\n`);
  process.stdout.write(
    `WARNING: .env holds the database and administrator passwords for this install. ` +
      `Do not commit it (it is already in .gitignore) and do not share it.\n`,
  );
}

// Only run when this file is the program entry point, not when a test imports
// it as a module by path.
if (import.meta.url === pathToFileURL(process.argv[1] ?? "").href) {
  main().catch((error) => {
    process.stderr.write(`create-phpledger: ${error.message}\n`);
    process.exitCode = 1;
  });
}
