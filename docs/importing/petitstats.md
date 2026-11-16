# Export and move your data

Petitstats lets you download your analytics at any time, and bring them back on another server or after switching from SQLite to MySQL or MariaDB.

## Export

1. Go to **Settings → Database**, card **Export and move your data**.
2. Choose a website, or **All websites**, and a format:
   - **Petitstats archive**: to move your websites or back them up. Petitstats reads it back, with each website's settings and tracking tag.
   - **CSV**: one file for the visits and events, one for the blocked requests and one for the funnels, to open in a spreadsheet.
   - **JSON**: the same data, for a script.
3. Click **Download**. You get a ZIP file.

Your database password and the secret Petitstats uses to make visitors anonymous are never part of an export.

## Move to another server

1. On the old server, download a **Petitstats archive** of **All websites**.
2. Install Petitstats on the new server.
3. In **Settings → Database**, click **Restore an archive…** and choose the file.

Every website comes back with its settings and tracking key, so the tags already on your pages keep working as soon as they point to the new server. A website that already exists keeps its settings and only gets the visits it is missing, so restoring the same archive twice changes nothing.

To import a single website into a new website or one you already track, use **Import Data** and choose **Petitstats** instead.

## Switch from SQLite to MySQL or MariaDB

Changing the database connector does not move the analytics already collected. To bring them along:

1. Download a **Petitstats archive** of **All websites**.
2. Save the new connector, as explained in [Using MySQL or MariaDB](../operations/README.md#using-mysql-or-mariadb).
3. Click **Restore an archive…** and choose the archive.

It works the same way to go back from MySQL to SQLite.

## From the command line

Large archives may exceed your server's upload limit. Run these commands in the folder where Petitstats is installed instead.

```bash
php bin/export.php --output petitstats.zip
```

```bash
php bin/import.php --provider petitstats --zip petitstats.zip
```

`bin/export.php` takes `--site <id>` to export a single website, and `--format csv` or `--format json` for the other formats. Add `--site-id <id>` to `bin/import.php` to import the website of an archive into another website.
