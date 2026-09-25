# Install on a Windows computer with XAMPP or Wampserver

This route is for **your own computer**. For a public website, use the [shared-hosting guide](https://github.com/phpledger/phpledger/wiki/Install-on-Shared-Hosting). XAMPP and Wampserver provide the software PHP Ledger needs. Choose a version with PHP 8.2+ and MariaDB 10.4+ or MySQL 8.4. MySQL 8.0 is unsupported.

## 1. Install XAMPP or Wampserver

Install [XAMPP](https://www.apachefriends.org/download.html) into `C:\xampp`, or [Wampserver](https://wampserver.aviatechno.net/) into `C:\wamp64`. In XAMPP Control Panel, click **Start** beside **Apache** and **MySQL** (which may be MariaDB). In Wampserver's tray menu, select a supported **PHP** version and **MariaDB** version, then start both services; avoid an installed MySQL 8.0 service. The Wampserver download page lists included versions. Open `http://localhost/`; the welcome page should appear. On either stack, open its **phpMyAdmin** link and read **Server version** to confirm the running database version. The PHP version appears on the stack's welcome page or Wampserver's **PHP → Version** menu; the PHP Ledger installer also checks it.

## 2. Download PHP Ledger

Open the [latest PHP Ledger release](https://github.com/phpledger/phpledger/releases/latest). Under **Assets**, download `phpledger-VERSION.zip`, where `VERSION` is the release number shown there. Avoid GitHub's automatically generated **Source code** archives. Extract the ZIP once. You should see one `phpledger` folder containing `index.php`, `.htaccess`, `www`, `vendor`, and `resources`; if you see another `phpledger` folder inside it, move the inner folder instead.

## 3. Put the folder in the right place

For XAMPP the result is `C:\xampp\htdocs\phpledger\index.php`; for Wampserver it is `C:\wamp64\www\phpledger\index.php`. Do not rename the folder after setup. Open `http://localhost/phpledger/`. PHP Ledger should show **Install PHP Ledger** and server checks. Click **Start setup** when the checks pass. A local HTTP warning is expected; keep this computer-only installation off the public internet.

## 4. Fill in the setup screen

The default local values are below. If you changed the database account or port, enter its real values. On Wampserver, phpMyAdmin shows the connected server and port; use the port for your selected MariaDB service (it may differ from MySQL's port). PHP Ledger can create the database on this computer. Click **Check database**. Expect **Database connected.** and an empty-database review.

| Box on the screen | What to enter for a fresh XAMPP/Wampserver setup |
| --- | --- |
| This site's address | `http://localhost/phpledger` |
| Database host | `localhost` |
| Port | `3306`, or the port shown by your stack |
| Table prefix | Leave `pl_` |
| TLS CA certificate path | Leave empty for a local database |
| Database name | `phpledger` |
| Database user | `root` only on an untouched local stack |
| Database password | Empty only if that local account has no password |

## 5. Let setup finish

Click **Install database**. Keep the tab open while **Preparing your database** advances; if it pauses, click **Continue installation**. At **Database checks**, click **Save private configuration**. You should reach **Create your sign-in account**.

## 6. Create your account

Enter your name, username, email, and a password of at least 6 characters; type it again (the eye shows what you typed; **Generate a password** fills both boxes). A logo is optional. Click **Finish and create your business**.

**Finished:** the page says **Your installation is complete.** You are signed in. Click **Set up your first business** when ready. Choose **New business** if starting from scratch or **Bring past records** if you already have books, then **Continue**. Business setup can be finished later. Keep the installer's private maintenance key with your private backups for recovery or updates; it is separate from your sign-in password.

## If you get stuck

| What you see | What to do |
| --- | --- |
| `localhost` does not open | Start Apache in your chosen stack. If another program uses its web port, resolve that conflict in the stack's control panel. |
| PHP Ledger does not open, but the stack's welcome page does | Check the exact `phpledger\index.php` path in step 3; remove an accidental extra folder level. |
| A red PHP or database version requirement | In Wampserver's tray menu, choose supported PHP and MariaDB versions; with XAMPP, install a supported stack release. Restart services, then click **Check again**. |
| **Check database** cannot connect | Start MySQL/MariaDB and use the actual port, username, and password from your local stack. |
| A named PHP extension is missing | Wampserver: open the tray menu's **PHP → PHP extensions** and enable the named extension, then restart services. XAMPP: in Control Panel click **Config** beside Apache, open **PHP (php.ini)**, enable the matching `extension=` line if supplied, save, and restart Apache. If the extension is absent from that PHP build (BCMath may be built in or absent), choose a stack/PHP build that includes it. Click **Check again**. |

For detailed host requirements and recovery, read the [package installation reference](https://github.com/phpledger/phpledger/blob/master/resources/release/INSTALL.md). XAMPP's [Windows FAQ](https://www.apachefriends.org/faq_windows) explains its control panel and `htdocs` folder. Return to [Getting Started](https://github.com/phpledger/phpledger/wiki/Getting-Started).
