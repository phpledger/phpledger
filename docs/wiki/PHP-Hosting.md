# PHP and hosting compatibility

**Installing for the first time?** Follow the [[XAMPP/WAMP|Install-on-XAMPP-or-WAMP]] or [[shared-hosting|Install-on-Shared-Hosting]] walkthrough. This page provides the technical compatibility reference.

**Minimum PHP 8.2; PHP 8.3 recommended for deployment.** Use a current security patch and the same version/extensions for web requests, the browser installer and command-line installation. The current Docker default is 8.3.33; CI covers 8.2/8.3/8.4 without raising the minimum.

1.1.0 installs like WordPress: upload the `phpledger` folder anywhere inside the website and open it; the browser installer starts by itself, without a setup key when the database is on the same server. Apache or LiteSpeed (most shared hosting) is needed for this layout because it relies on `.htaccess`; pointing the document root at `www/phpledger/public` works on any server. CLI installation (`preflight.php`, `migrate.php`, `create-admin.php`) remains available. The PHP **zip** extension is needed for automatic updates.

Plesk and cPanel document 8.2/8.3 availability. Hostinger names 8.3 as its new-site default; its 8.2 availability wording is inconsistent and should be checked in the actual plan. SiteGround's published 2024 rollout provides evidence of 8.2 availability. These checks are not a market-share survey or completed provider installations. See the [dated research, sources and runtime evidence](https://github.com/phpledger/phpledger/blob/master/docs/strategy/HOSTING-PHP-COMPATIBILITY.md).

MySQL 8.0.19+ (8.4 recommended) or MariaDB 10.4+ with InnoDB, the required extensions and HTTPS remain requirements; plain `http://localhost` is accepted only on your own computer. The test battery passed on MySQL 8.4 and MariaDB 10.4, 10.6, 10.11 and 11.4; MySQL 8.0.19 or newer is supported; 8.4 is recommended. A PHP selector in a hosting panel does not by itself prove compatibility.

The downloadable ZIP includes compatible production dependencies. Follow its INSTALL.md and UPGRADE.md and run preflight. Composer development resolution targets PHP 8.2 so a newer development runtime cannot silently raise the package floor.
