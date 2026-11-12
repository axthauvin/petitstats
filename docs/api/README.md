# Stats API

Show your audience on your own website, for example on a sponsor page: visitors, pageviews, bounce rate and a day-by-day chart, with the same numbers as your dashboard.

Your server asks Petitstats for the numbers, then shows them. The token stays on your server and never reaches your visitors' browsers.

## Create a token

1. Open **Settings → Tracking** and select your website.
2. Under **Stats API**, name the token after what will use it, such as "Sponsor page", and click **Create token**.
3. Copy the token. It is shown only once.

A token reads one website only and can never change anything. Revoke it in the same place when you no longer need it: it stops working right away.

## Get the summary

```bash
curl -H "Authorization: Bearer pss_your_token" \
  https://analytics.example.com/api/v1/sites/your_site/summary
```

Replace `analytics.example.com` with your Petitstats address, and `your_site` with your website ID, shown in **Websites**.

By default, you get the last 30 full days: from 30 days ago to yesterday, in UTC. Today is left out because it is not over yet. To choose the days, add `from` and `to` (both included, 90 days at most):

```text
/api/v1/sites/your_site/summary?from=2026-10-01&to=2026-10-31
```

The period never starts before your first visit: for a website tracked for 10 days, you get 10 days, not 20 empty days followed by 10.

## The response

```json
{
  "site_id": "your_site",
  "from": "2026-10-13",
  "to": "2026-11-11",
  "visitors": 728,
  "visits": 731,
  "pageviews": 5169,
  "pages_per_visit": 7.1,
  "avg_duration_seconds": 160,
  "bounce_rate": 0.26,
  "daily": [
    { "date": "2026-10-13", "visitors": 52, "pageviews": 371 },
    { "date": "2026-10-14", "visitors": 0, "pageviews": 0 }
  ],
  "generated_at": "2026-11-12T09:15:00Z"
}
```

| Field                  | Meaning                                                                           |
| ---------------------- | --------------------------------------------------------------------------------- |
| `visitors`             | Unique visitors over the whole period (not the sum of the days)                   |
| `visits`               | Visits. A visit ends after 30 minutes without activity                            |
| `pageviews`            | Pages viewed                                                                      |
| `pages_per_visit`      | Pageviews divided by visits, rounded to one decimal                               |
| `avg_duration_seconds` | Average visit length, in whole seconds                                            |
| `bounce_rate`          | Share of visits with a single action, from 0 to 1 (`0.26` means 26%)              |
| `daily`                | One entry per day, with `0` on days without visits. Empty before your first visit |

Bots are left out, like everywhere else in Petitstats.

## Errors

Errors come with a message in `error`, such as `{"error": "This token can only read its own website."}`.

| Code | Why                                                                   |
| ---- | --------------------------------------------------------------------- |
| 400  | The period is invalid: a wrong or missing date, or over 90 days       |
| 401  | No token, or a wrong or revoked one                                   |
| 403  | The token belongs to another website                                  |
| 404  | No website has this ID                                                |
| 429  | Over 60 requests in a minute: wait the seconds given in `Retry-After` |

## Good to know

- The numbers change slowly, so keep the response for a few minutes on your server rather than calling Petitstats on every page view. Responses say so with `Cache-Control: private, max-age=300`.
- Only call the API from your server. Anyone who sees the token in a page could read your statistics with it.
- On Nginx and Caddy, add the rewrite rule of the [web server configuration](../operations/README.md#web-server-configuration). Apache and LiteSpeed need nothing.
