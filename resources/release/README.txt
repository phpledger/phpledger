PHP Ledger {{VERSION}}
Self-hosted double-entry accounting for PHP hosting.
https://phpledger.com/


WHAT YOU NEED
- Web hosting with PHP 8.2 or newer (8.3 recommended) and these PHP extensions:
  bcmath, pdo_mysql, mbstring, curl, openssl and fileinfo.
  Most hosting panels list them under "Select PHP Version" or "PHP extensions".
- MySQL 8.0.19 or newer (8.4 recommended), or MariaDB 10.4 or newer.
- An Apache or LiteSpeed web server, which most shared hosting uses.
  XAMPP on your own computer works too.


INSTALL IN FIVE STEPS
1. Unzip this file. You get a folder named "phpledger". Rename it now if you
   want a different address, for example "accounts". Do not rename it after
   installing.
2. Upload the folder into your website, for example public_html/accounts.
   On your own computer, put it in C:\xampp\htdocs\ (Windows XAMPP).
3. Decide which database to use.
   - cPanel and most panels: MySQL Databases. Create an empty database, create
     a user with a password, then add the user to the database with all
     privileges.
   - On your own computer (XAMPP, Laragon, MAMP): nothing to prepare. The
     installer accepts the built-in "root" account with an empty password and
     creates the database for you.
4. Open the folder's address in your browser straight away, for example
   https://example.com/accounts or http://localhost/accounts.
   The installer starts by itself.
5. Enter the database details. Then choose your username, email address and
   password (you can sign in with either the username or the email), and
   optionally upload your logo. Finally, set up your first business.

The installer runs only until it finishes; afterwards the address shows the
sign-in page. Run it right after uploading so nobody else can start it first.


HTTPS
PHP Ledger installs and runs over plain http://, so you can try it before a
certificate is in place. It warns you on every screen while you do: without a
certificate your sign-in details and your accounting data travel unencrypted,
and Connections (the API, MCP and app integrations) stay unavailable. Before
you keep real books, turn on SSL (AutoSSL or Let's Encrypt) in your hosting
panel, which most hosts include free, and open the site again with https://.


THE MOST SECURE SETUP
If your host lets you choose a (sub)domain's document root, for example a
subdomain in cPanel or your own server, point it at this folder:
    phpledger/www/phpledger/public
Only that folder is then reachable from the web, and the .htaccess rules in
the main folder are not needed.


BACK UP
Keep regular copies of your database and of these private items:
    www/phpledger/includes/config.local.php
    www/phpledger/storage/
They hold your database settings and private keys; never share them.


UPGRADING
Do not unzip a new version over an installed copy. Follow the upgrade guide:
https://github.com/phpledger/phpledger/blob/master/resources/release/UPGRADE.md


HELP AND LICENCE
Full installation guide (databases, permissions, command-line setup):
https://github.com/phpledger/phpledger/blob/master/resources/release/INSTALL.md
Self-hosting guides: https://phpledger.com/self-hosting/
Source code and support: https://github.com/phpledger/phpledger
PHP Ledger is free software under the GNU Affero General Public License,
version 3 or later (see LICENSE). Bundled third-party components and their
licences are listed in the licenses folder.
