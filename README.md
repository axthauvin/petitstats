<div align="center">
  <img src="docs/assets/banner.png" alt="Petitstats logo" width="100%" />

  <p>
    <a href="#quick-start">Quick Start</a> &bull;
    <a href="#production-installation">Installation</a> &bull;
    <a href="#local-development">Development</a> &bull;
    <a href="#documentation">Documentation</a> &bull;
    <a href="#testing">Testing</a> &bull;
    <a href="#license">License</a>
  </p>
</div>

Petitstats is a lightweight, privacy-friendly web analytics tool written in PHP. Most analytics tools need Node.js, Docker or a separate database server. **Petitstats doesn't**.

> Petitstats was called Minilytics until October 2026. Existing installs update as usual from the dashboard and keep their data, snippets and settings: see [Coming from Minilytics](docs/operations/README.md#coming-from-minilytics).

- **Runs on a cheap Apache hosting plan, no VPS needed.** It stores data in SQLite by default, and MySQL and MariaDB are also supported. Installation takes [three steps](#production-installation). Just upload the archive and you are done.
- **Bring your history with you.** You can import your [Umami and Matomo](docs/importing/README.md) data from the dashboard or the command line. Importers for Google Analytics, Plausible and Simple Analytics are planned.

![Screenshot of the Petitstats dashboard](docs/assets/dashboard.png)

> This is what the dashboard looks like. It is not because its lightweight that it can not be beautiful and functional

---

## Quick Start

### Requirements

- PHP 8.1 or later with the `sqlite3` and `pdo_sqlite` extensions enabled
- Apache or LiteSpeed work out of the box; for Nginx or Caddy, apply the [web server configuration](docs/operations/README.md#web-server-configuration)
- For MySQL or MariaDB: the `pdo_mysql` PHP extension and a database/user with `CREATE`, `ALTER`, `INDEX`, `SELECT`, `INSERT`, `UPDATE` and `DELETE` permissions
- A writable data directory outside the web root (default: `petitstats-data/` next to the web root, or set `PETITSTATS_DATA_DIR`)
- Outbound HTTPS access and `zlib` support (to download the local MaxMind GeoLite2 City geolocation database)

_Note: The `zip` extension is only needed if you import historical data from external services._

### Production Installation

**Petitstats is easy to install**. Download the latest production archive from [GitHub Releases](https://github.com/axthauvin/petitstats/releases/latest) and extract it into your website root directory:

```bash
curl -LO https://github.com/axthauvin/petitstats/releases/latest/download/petitstats.tar.gz
tar -xzf petitstats.tar.gz && rm petitstats.tar.gz
```

Thats it!

Once its installed, navigate to `https://your-domain.com/dashboard/` to create the initial administrator account.

### How do we store analytics data?

SQLite is the default (because it's lightweight and doesn't require a separated server), but you can change it any time !
To use a managed database, open **Settings → Database**, select MySQL or MariaDB, enter the host, port, database name, username and password, then use **Test connection** before saving. The test can create the named database when it is missing if the database user has the `CREATE` permission. **Existing SQLite analytics are not copied automatically**.

> Data is stored outside the web root so redeploying or re-extracting the archive can never overwrite or expose it.

### Local Development

Clone the repository, install the PHP dependencies with [Composer](https://getcomposer.org/) and start the development server:

```bash
cd app
composer install
composer serve # or php -S localhost:8080 -t app scripts/dev-router.php
```

Open [http://localhost:8080/dashboard/](http://localhost:8080/dashboard/) for the dashboard and [http://localhost:8080/](http://localhost:8080/) for the landing page. The development router (`scripts/dev-router.php`) overlays `landing/` on top of `app/`. Without Composer, run `php -S localhost:8080 -t app scripts/dev-router.php` from the repository root.

Release archives already bundle `vendor/`, so Composer is only needed when running from a clone of the repository.

Want to contribute? Read the [contributing guide](CONTRIBUTING.md) for the coding standard, checks and commit convention.

---

## Documentation

- [Tracking and custom events](docs/tracking/README.md)
- [Privacy and data protection](docs/privacy/README.md)
- [Operations, backups and storage](docs/operations/README.md)
- [Importing data from other services](docs/importing/README.md)
- [AI assistants (MCP)](docs/mcp/README.md)

---

## Testing

Node.js is used only to run unit and smoke tests during development. **Node.js is not required to run Petitstats in production.**

```bash
node --test app/tests/*.test.js
```

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
