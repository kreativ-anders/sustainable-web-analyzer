# Sustainable Web Analyzer

Vanilla PHP endpoint (8.2+, 8.5 recommended; `curl`, `dom`) that measures page weight, hosts, green hosting and CO2 per visit of a web page.

Docs: [`docs/index.html`](docs/index.html) · API: [`docs/api.html`](docs/api.html) · Spec: [`docs/openapi.json`](docs/openapi.json) · Deployment: [`nginx.conf.example`](nginx.conf.example) · Local Wharf: [`nginx.wharf.conf.example`](nginx.wharf.conf.example)

```sh
php -S 127.0.0.1:8080 -t docs index.php    # docs at /, API at /api/?url=…
bin/analyze https://kreativ-anders.de
php tests/run.php
bin/update-grid-intensity                   # refresh data/grid-intensity.php (needs intl), also runs every 6 months via GitHub Actions
```

## Abuse protection

One public API, the same limits for everyone, no keys and nothing to trust. Every value is
configurable in `config.php`; the defaults are in [`config.default.php`](config.default.php).

| Per IP and day | |
|---|---|
| `rate_limit_per_ip` | **10** uncached analyses |
| `rate_limit_requests_per_ip` | 200 requests in total, cached ones included |

| For everyone together | |
|---|---|
| `rate_limit_per_target` | 50 uncached analyses of one target host per day |
| `rate_limit_global` | 1000 uncached analyses per day |
| `max_concurrent_analyses` | 6 at the same time, further ones get `503` |

**Cached results are free.** They count against nothing, so re-checking a site never costs a caller
anything. Only an analysis that actually leaves the server is counted.

Successful responses carry `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`, so
a frontend can show "noch 7 von 10 Analysen heute" instead of surprising people with an error. The
window is rolling per IP: it resets 24 hours after that IP's first analysis, not at midnight. When
a limit is reached the answer is `429` with `Retry-After` and a message that says when to come back.

Beyond the counting, an analysis may only ever target `https` on port 443, never credentials, never
an IP address, and every resolved address must be globally routable ([`UrlGuard`](src/UrlGuard.php)).
Country lookups are budgeted separately (`max_geo_lookups` per analysis, `geo_lookups_per_day` in
total) so abuse cannot burn through the monthly ipinfo quota; a host beyond the budget keeps `null`
as its country and the page is still measured.

`rate_limit_exempt_ips` lists IPs and CIDRs that skip every limit — put your own office in there so
testing does not count against you. It is matched against the TCP source address, which cannot be
forged, so it covers what *you* send, not what a visitor's browser sends.

To close the endpoint, set `enabled => false` in `config.php` (or `SWA_ENABLED=false`): every
request answers `503` until you set it back.

### Why the frontend needs nothing

The form on kreativ-anders.de calls the API straight from the browser. The only thing it needs is
`allowed_origins`, which decides who gets CORS headers — it grants no privileges, and nothing else
in the frontend or its vhost has to change.

Each visitor is their own IP, so each gets their own ten analyses a day. That is generous for a
check-my-site form and only bites where many people share one address, such as an office or a
mobile network. If that ever shows up, raise `rate_limit_per_ip`; it is one number in one file.

Whitelisting the server's IP would not help here: a `fetch()` in a visitor's browser comes from the
*visitor's* address, never from the server, so an IP whitelist can never single out your own form.
