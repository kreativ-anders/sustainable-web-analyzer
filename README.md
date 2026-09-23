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

Public API, no keys: the same limits for every caller, counted per IP and day, plus caps per analyzed
host and for the endpoint as a whole. Cached results are free and count against nothing. All values
with comments in [`config.default.php`](config.default.php).

* `429` and `Retry-After` once a limit is reached, `X-RateLimit-*` on successful responses
* `rate_limit_exempt_ips` skips every limit – your own office, so testing does not count
* `enabled => false` (or `SWA_ENABLED=false`) closes the endpoint with `503`
* Targets are public `https` hosts on port 443 only ([`UrlGuard`](src/UrlGuard.php)); ipinfo lookups
  are budgeted per analysis and per day

Exempt IPs are matched against the TCP source address, so they cannot single out the form on
kreativ-anders.de: a `fetch()` there comes from the visitor. Every visitor gets their own daily
budget instead, and `allowed_origins` only decides who receives CORS headers.
