# Import from Umami

Petitstats imports the pageviews and custom events of your Umami website, with the data attached to each event. Each one shows up in your dashboard like the data Petitstats collects itself, so your charts continue where Umami stopped.

## 1. Export your data from Umami

1. In Umami, open **Settings → Data access → Data**.
2. In the **Export** section, click **Export**. If you have multiple websites, choose the one you want to export, and the period of visits to include. The export can take a few minutes, depending on how many visits you have, and you will receive an email when it is ready.
3. Download the ZIP file Umami prepares. Keep it as it is, there is nothing to change in it.

The ZIP holds a `website_event.csv` file with your pageviews and events, and an `event_data.csv` file with the details of your custom events.

## 2. Import the file into Petitstats

1. In Petitstats, click **Import Data** and choose **Umami**.
2. Upload the ZIP file. Petitstats shows how many visits it found, the period they cover and the website they come from.
3. Create a new website for this data, or add it to one you already track. For a new website, the name and domain are filled in from the export, so you only need to check them.
4. If your export goes back further than the website keeps its visits, choose whether to keep them longer (see [Keeping all your history](README.md#keeping-all-your-history)).
5. Click **Import data**. A new website also gets its tracking tag, ready to replace the Umami one on your pages.

You can import a newer export of the same website later, for example to catch up on the visits Umami recorded while you were switching. Visits that are already in Petitstats are not added twice.

## What you find in Petitstats

| In Umami      | In Petitstats                                                   |
| ------------- | --------------------------------------------------------------- |
| Pageviews     | Pageviews, with their title, referrer and campaign              |
| Custom events | Custom events with the same name                                |
| Event data    | Details of each custom event, as numbers, text or yes/no values |

Visitors keep their browser, operating system, device, screen size, language, country, region and city.

## If something goes wrong

**Petitstats cannot find `website_event.csv`.** The ZIP does not come from the Umami export, or its files were renamed. Export your website again, or check the file names if you created them from the database.

**The oldest visits are missing.** Check the history choice you made during the import, as visits older than the website keeps are left out.

**The upload is refused because the file is too large.** Raise the upload limit as explained in [Operations](../operations/README.md#large-imports), or use the command line below.

## From the command line

If you can open a terminal on your server, run this command from the Petitstats folder.

```bash
php bin/import.php --zip umami-export.zip --site-id my_site
```

Add `--retention-days 760` to keep up to about 25 months of visits. Run `php bin/import.php --help` to see every option.
