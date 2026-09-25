# Install on shared hosting

You need your hosting-panel sign-in and your website address. These steps add PHP Ledger in its own **phpledger** folder on your website. Check that you do not already have a folder with that name; an existing PHP Ledger installation needs the [upgrade guide](https://github.com/phpledger/phpledger/blob/master/resources/release/UPGRADE.md).

If you are unsure whether your hosting supports PHP Ledger, send your host the short request at the end of this page before uploading.

## 1. Open your website's files

Turn on SSL/HTTPS. With **cPanel**, open **File Manager → public_html** (or your domain's empty web folder). Click **Settings → Show Hidden Files (dotfiles) → Save**. With **Plesk**, open **Websites & Domains → your domain → File Manager → httpdocs**. Check that the extracted package later contains `.htaccess`; Plesk has no cPanel **Show Hidden Files** step. This example uses `https://example.com/phpledger/`; substitute your domain.

## 2. Create a database for PHP Ledger

With **cPanel**, open **Database Wizard** (**MySQL Database Wizard** in older versions), create an empty database and user with a strong password, then assign **ALL PRIVILEGES**. Save the full names, including cPanel prefixes. With **Plesk**, open **Websites & Domains → Databases → Add Database**, select MySQL/MariaDB, name the empty database, check **Create a database user**, and set its username and strong password. In **Databases → User Management**, open that user and confirm it can create and change database structures (including triggers and views); ask the host if these rights are unavailable. Save the displayed database name, user, host, port, and password. Use one dedicated user for this installation.

## 3. Upload PHP Ledger

From the [latest release](https://github.com/phpledger/phpledger/releases/latest), download its `phpledger-VERSION.zip` file, where `VERSION` is the number shown on the release. Avoid the **Source code** and **media kit** downloads. In the website folder from step 1, **cPanel:** click **Upload**, select the ZIP, then **Extract**. **Plesk:** click **Upload File**, select the ZIP, click its row, then **Extract Files → OK**. Open the new `phpledger` folder: `index.php`, `.htaccess`, `www`, `vendor`, and `resources` should be inside. Correct any extra folder level. Remove the ZIP after extraction.

## 4. Open PHP Ledger

Visit `https://example.com/phpledger/`. The **Install PHP Ledger** screen should show server checks. Click **Start setup**. If a private-folder warning appears, open **this check link** in a new tab. It must say **Not Found** or **Forbidden**. If it shows private content, stop: ask the host to enable Apache/LiteSpeed `.htaccess` rules or set the document root to `www/phpledger/public`.

## 5. Fill in the setup screen

Enter the values below and click **Check database**. Expect **Database connected.** and an empty-database review. If the database is on another server, the page requests a **Setup code**. In File Manager, open the private `www/phpledger/storage/installation/setup-code.txt` path shown there and paste its contents. Keep that code private.

| Box on the screen | What to enter |
| --- | --- |
| This site's address | Your exact `https://example.com/phpledger` address |
| Database host | Provider's hostname, often `localhost` |
| Port | Provider's port, commonly `3306` |
| Table prefix | Leave `pl_` for this separate database |
| TLS CA certificate path | Leave empty for a local database; ask the host if it requires database TLS |
| Database name | Full name from step 2, including a prefix if shown |
| Database user | Full username from step 2, including a prefix if shown |
| Database password | That user's password |

## 6. Finish setup

Click **Install database** and keep the tab open. Click **Continue installation** if asked. At **Database checks**, click **Save private configuration**. If saving fails, expand **My host does not allow PHP to write this file** and follow its instructions; delete the downloaded copy afterward.

At **Create your sign-in account**, enter your name, username, email, and a password of at least 6 characters (the eye shows what you typed; **Generate a password** fills both boxes). A logo is optional. Click **Finish and create your business**.

**Finished:** the page says **Your installation is complete.** You are signed in. Click **Set up your first business** when ready. Choose **New business** if starting from scratch or **Bring past records** if you already have books, then **Continue**. Keep the installer's private maintenance key with your private backups for recovery or updates; it is separate from your sign-in password.

## If you get stuck

| What you see | What to do |
| --- | --- |
| Red PHP or database requirement | Ask the host for the named extension or supported version, then click **Check again**. |
| **Check database** fails | Compare the full names, password, host, port, and user's database privileges in your panel. |
| Private-folder warning or missing `.htaccess` | Check extracted hidden files; ask the host to enable `.htaccess` or use the public document root above. |
| **Save private configuration** fails | Use the installer’s download/upload option at its exact private path. |

<details><summary>What to ask hosting support if checks stay red</summary>

“Can my account run PHP 8.2+ with BCMath, PDO MySQL, mbstring, cURL, OpenSSL, fileinfo, and sessions; MariaDB 10.4+ or MySQL 8.4; Apache/LiteSpeed `.htaccess` routing or document root `www/phpledger/public`; and database migrations creating tables, triggers, and views? What are my database host and port?”

</details>

More detail: [package reference](https://github.com/phpledger/phpledger/blob/master/resources/release/INSTALL.md), cPanel [Database Wizard](https://docs.cpanel.net/cpanel/databases/mysql-database-wizard/) and [File Manager](https://docs.cpanel.net/cpanel/files/file-manager/110/), Plesk [file upload](https://docs.plesk.com/en-US/obsidian/quick-start-guide/plesk-tutorial.74376/) and [database users](https://docs.plesk.com/en-US/obsidian/customer-guide/website-databases/managing-database-user-accounts.69539/). Return to [Getting Started](https://github.com/phpledger/phpledger/wiki/Getting-Started).
