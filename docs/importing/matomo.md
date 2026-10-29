# Import from Matomo

Petitstats imports every visit your Matomo still keeps, with its pages, events, outlinks, downloads, site searches, goals and ecommerce orders. Each one shows up in your dashboard like the data Petitstats collects itself, so your charts continue where Matomo stopped.

Matomo, Matomo Cloud and older Piwik installations all work the same way.

## 1. Export your visits from Matomo

1. In Matomo, open **Visitors → Visits Log**.
2. In the date selector, choose **Date range** and pick the whole period you want to keep, for example from the day you installed Matomo until today.
3. Below the list of visits, click the export icon (**Export this dataset**).
4. Choose **CSV** or **JSON**, set the export limit to **all rows**, then click **Export**.

Your browser downloads a file with all the visits of the period. Keep it as it is, there is nothing to change in it.

> Export the **Visits Log** report, not **Visits Overview**. Only the Visits Log holds each visit with its pages and events. The other reports are totals, which Petitstats cannot turn back into visits.

### If your website has a lot of traffic

A very large export can take a long time or fail to download. In that case, export one year or one month at a time by changing the date range, then put all the files in a single ZIP and import that ZIP. A visit that appears in two files is only imported once.

### Exporting with the Matomo API

You can get the same export without the interface by opening this address in your browser, after replacing the parts in capitals.

```text
https://YOUR-MATOMO/index.php?module=API&method=Live.getLastVisitsDetails&idSite=SITE_ID&period=range&date=2010-01-01,today&filter_limit=-1&format=JSON&token_auth=YOUR_TOKEN
```

- `YOUR-MATOMO` is the address of your Matomo.
- `SITE_ID` is the number of your website in Matomo, shown under **Administration → Websites → Manage**.
- `YOUR_TOKEN` is an authentication token, created under **Administration → Personal → Security → Auth tokens**.

Save the page as a `.json` file. You can also replace `format=JSON` with `format=CSV`, both give the same result once imported. To export one year at a time, change the dates, for example `date=2025-01-01,2025-12-31`.

## 2. Import the file into Petitstats

1. In Petitstats, click **Import Data** and choose **Matomo**.
2. Upload your export. Petitstats shows how many visits it found, the period they cover and the website they come from.
3. Create a new website for this data, or add it to one you already track. For a new website, the name and domain come from your Matomo, so you only need to check them.
4. If your export goes back further than the website keeps its visits, choose whether to keep them longer (see [Keeping all your history](README.md#keeping-all-your-history)).
5. Click **Import data**. A new website also gets its tracking tag, ready to replace the Matomo one on your pages.

You can import a newer export of the same website later, for example to catch up on the visits Matomo recorded while you were switching. Visits that are already in Petitstats are not added twice.

## What you find in Petitstats

| In Matomo | In Petitstats |
| --- | --- |
| Pageviews | Pageviews, with their title, referrer and campaign |
| Events | Custom events named after the event action, with the category, name and value as details |
| Outlinks | `outbound_click` events, with the address of the link |
| Downloads | `file_download` events, with the file name |
| Site searches | `site_search` events, with the keyword and the number of results |
| Goals | Custom events named after the goal, with its revenue |
| Ecommerce orders | `purchase` events, with the order number, revenue and products |
| Abandoned carts | `abandoned_cart` events, with the revenue and products |
| Forms, media, content and errors | `form_interaction`, `media_play`, `content_impression` and `js_error` events |
| Custom variables and custom dimensions | Details of each pageview and event |

Visitors keep their browser, operating system, device, screen size, language, country, region and city. When Matomo knows a visitor across several visits, Petitstats recognizes them as the same visitor too. A User ID set in Matomo is never copied as is, so no email address or account name ends up in Petitstats.

## If something goes wrong

**Petitstats finds no visits in the file.** The file is probably another Matomo report. Export the **Visits Log** again, as described above.

**The oldest visits are missing.** Matomo may delete its oldest visits on its own, depending on **Administration → Privacy → Anonymize data → Delete old raw data**. Petitstats can only import the visits that are still in Matomo. Check also the history choice you made during the import, as visits older than the website keeps are left out.

**The upload is refused because the file is too large.** Split the export by year, as explained in [If your website has a lot of traffic](#if-your-website-has-a-lot-of-traffic), raise the upload limit as explained in [Operations](../operations/README.md#large-imports), or use the command line below.

## From the command line

If you can open a terminal on your server, run this command from the Petitstats folder.

```bash
php bin/import.php --provider matomo --file matomo-visits.csv --site-id my_site
```

Use `--zip` instead of `--file` for a ZIP of several exports, and add `--retention-days 760` to keep up to about 25 months of visits. Run `php bin/import.php --help` to see every option.
