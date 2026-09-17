# Sustainable Web Analyzer

A standalone PHP endpoint that measures how sustainable a web page is. It gives the page weight, requests, hosts and their countries, green hosting, cookies, "div-ification" and the CO2 emissions per visit.

It replaces the `web-analyse` Kirby plugin of [kreativ-anders.de](https://kreativ-anders.de/web-analyse) and returns the same JSON, plus a few additional fields.

- Vanilla PHP 8.2+, no Composer packages, only the `curl` and `dom` extensions
- Parallel crawling with `curl_multi`; resource sizes come from `HEAD` requests (`Content-Length`), and a full download is only the fallback
- SSRF protection, rate limiting, CORS allowlist, results cached for a week
- CO2 calculation ported 1:1 from [CO2.js](https://github.com/thegreenwebfoundation/co2.js) 0.19.0 (Sustainable Web Design Model v4, the library's default), verified against the JavaScript library

## Quick start

```sh
cp config.example.php config.php          # optional
php -S 127.0.0.1:8080 -t public           # development server

curl -H 'Origin: https://kreativ-anders.de' 'http://127.0.0.1:8080/?url=kreativ-anders.de'
bin/analyze https://kreativ-anders.de     # CLI, skips origin check, rate limit and result cache
php tests/run.php                         # unit tests (add --network [url] for a live run)
```

## API

`GET /?url=<url>`

The URL may omit the scheme. `http://` is upgraded to `https://`. Only port 443 is allowed.

### Response `200`

```json
{
  "meta": {
    "url": "https://kreativ-anders.de/",
    "time": 0,
    "last": "17.09.2026",
    "final_url": "https://kreativ-anders.de/",
    "duration_ms": 368,
    "skipped": 0,
    "sizes": { "content_length": 11, "download": 0 },
    "truncated": false,
    "cached": false,
    "version": "1.0.0"
  },
  "requests": {
    "https://kreativ-anders.de/": 11858,
    "https://kreativ-anders.de/assets/css/main.min.css": 88362
  },
  "bytes": 217543,
  "types": { "css": 2, "js": 2, "png": 4, "gif": 3 },
  "hosts": { "kreativ-anders.de": "DE", "api.pirsch.io": "DE" },
  "green": true,
  "cookies": false,
  "divification": false,
  "co2": {
    "model": "swd",
    "model_version": 4,
    "co2js_version": "0.19.0",
    "per_visit": 0.02632922929,
    "per_byte": 0.02632922929,
    "segments": { "operational": 0.014937807638, "embodied": 0.011391421652 },
    "rating": "A+",
    "unit": "g"
  }
}
```

| Field | Meaning |
|---|---|
| `requests` | URL → bytes of the page and every `script[src]`, `link[href]` and `img[src]` (links with a non-downloading `rel` such as `preconnect` or `icon` are ignored) |
| `bytes` | Sum of `requests`. Sizes are uncompressed: no `Accept-Encoding` is sent. The page itself is always downloaded; sub-resources use the `Content-Length` of a `HEAD` response |
| `types` | File extension → count, for sub-resources (`""` = no extension) |
| `hosts` | Host → ISO country code of the server (ipinfo.io), or `null` if unknown |
| `green` | Hosted with renewable energy according to The Green Web Foundation |
| `cookies` | The page sets cookies, or loads a known consent manager |
| `divification` | More than one `<div>` is nested at least four levels deep |
| `co2.per_visit` | Grams of CO2e per visit, same as `new co2({model: "swd", version: 4}).perVisit(bytes, green)` |
| `co2.per_byte` | Grams of CO2e for transferring `bytes`, same as `perByte(bytes, green)`. It can differ from `per_visit` in the last floating-point digits, as in CO2.js |
| `co2.segments` | `per_visit` split into `operational` (electricity) and `embodied` (manufacturing of data centers, networks and devices). The embodied part is a fixed per-GB average in SWDM v4, not the site's real hardware. Green hosting is applied to the operational part, so both always add up to `per_visit` |
| `co2.rating` | SWDM v4 grade `A+`…`F` of `per_visit`, same as CO2.js `rating: true` (`null` for 0 bytes) |
| `meta.time` | Analysis duration in whole seconds (the field the plugin had) |
| `meta.duration_ms` | Analysis duration in milliseconds |
| `meta.final_url` | Page URL after redirects |
| `meta.skipped` | Resources that could not be fetched (unreachable, blocked, time limit) |
| `meta.sizes` | How sub-resource sizes were measured: `content_length` (HEAD) or `download` (fallback, or `head_requests => false`) |
| `meta.truncated` | `max_resources` or `max_execution_time` was hit |
| `meta.cached` | Response was served from the result cache |

### Errors

Errors return `{"error": "<German message>"}` with `Cache-Control: no-store`.

| Status | When |
|---|---|
| 400 | `url` missing or invalid |
| 403 | Request is neither from an allowed origin nor same-origin |
| 405 | Method other than GET, HEAD or OPTIONS |
| 422 | Host is internal or non-public, port is not 443, or the domain does not exist |
| 429 | Rate limit reached (see `Retry-After`) |
| 500 | Unexpected error (details go to the PHP error log) |
| 502 | The page could not be fetched or answered with HTTP ≥ 400 |
| 503 | Maintenance mode (`enabled => false`) |

## Using it from kreativ-anders.de

In `assets/js/templates/web-analyse.raw.js` only the fetch URL has to change:

```js
function createFetchURL(url) {
  const remote = new URL('https://api.kreativ-anders.de/web-analyse/'); // wherever public/ is served
  remote.searchParams.set('url', url);
  return remote;
}
```

- The `X-Requested-With` header is no longer needed. Removing it saves a CORS preflight request, but it is still accepted.
- The CO2 value can be read from `result.co2.per_visit` instead of loading CO2.js 0.13.2 from unpkg: `printCO2Result(result.co2.per_visit.toFixed(3))`. SWDM v4 gives roughly 45 % lower values than v3, so the thresholds of the opinion text in `printCO2Result()` should be revisited, or replaced by `result.co2.rating`.
- `hosts` can now contain `null` for hosts whose country is unknown, so `printHostResults()` should handle it.
- Afterwards `site/plugins/web-analyse` (templates `web-analyse.json.php`, the route and the helper functions) and `site/plugins/ipinfo` can be removed. The HTML template, form and PNG template belong to the website and must be moved to `site/templates` / `site/snippets` if the page should stay.

## Configuration

Settings are resolved in this order: defaults in [`src/Config.php`](src/Config.php), then `config.php`, then environment variables. The most important ones:

| Key | Default | |
|---|---|---|
| `allowed_origins` | kreativ-anders.de (+ www) | CORS allowlist (`SWA_ALLOWED_ORIGINS`) |
| `require_origin` | `true` | Reject calls that are neither from an allowed origin nor same-origin |
| `ipinfo_token` | `''` | ipinfo.io token, sent as a Bearer header over HTTPS (`SWA_IPINFO_TOKEN`) |
| `rate_limit_per_ip` / `rate_limit_global` / `rate_limit_window` | 10 / 300 / 600 s | Only uncached analyses count |
| `client_ip_header` | `null` | e.g. `HTTP_X_REAL_IP`, only behind a trusted reverse proxy |
| `cache_dir` | `var/cache` | Results, geo lookups, locks, rate-limit counters (`SWA_CACHE_DIR`) |
| `cache_ttl` / `geo_cache_ttl` | 1 week / 2 weeks | |
| `max_execution_time` | 20 s | Hard limit for one analysis |
| `max_resources` | 150 | Sub-resources per page |
| `concurrency` | 10 | Parallel transfers |
| `head_requests` | `true` | Measure sub-resources via `HEAD` `Content-Length`; `false` downloads everything |
| `enabled` / `debug` | `true` / `false` | Maintenance mode / show error details (`SWA_ENABLED`, `SWA_DEBUG`) |

## Deployment

Serve `public/` as the document root. Everything else (`src/`, `var/`, `config.php`) must not be web-accessible. The PHP user needs write access to `var/` (or `cache_dir`).

**nginx + PHP-FPM**

```nginx
server {
    server_name api.kreativ-anders.de;
    root /var/www/sustainable-web-analyzer/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```

**Apache:** point the vhost at `public/`. If the project root has to be the document root (e.g. shared hosting), the included `.htaccess` routes every request to `public/index.php`.

Use PHP-FPM (not `php -S`) in production. The response is sent with `fastcgi_finish_request()` before cache housekeeping runs.

## How it works

1. **Validate:** normalize the URL, return the cached result if one exists, otherwise count the request against the rate limit and take a per-URL lock. Simultaneous requests for the same URL wait for the first analysis instead of repeating it.
2. **Phase 1, in parallel:** fetch the page HTML and query the Green Web Foundation.
3. **Parse:** extract resources with DOMDocument (`<base href>` and relative paths are resolved per RFC 3986) and detect div-ification and consent managers.
4. **Phase 2, in parallel:** send a `HEAD` request to every sub-resource and look up the country of every host IP (ipinfo.io, cached per IP).
5. **Phase 3, in parallel:** download only the sub-resources whose `HEAD` response was unusable (no or zero `Content-Length`, e.g. chunked transfer; a non-2xx status; `HEAD` not supported; an error). Bodies are streamed and only counted, never buffered. On zeit.de, spiegel.de, github.com and wikipedia.org this avoided 94–100 % of the downloads, and the totals matched a full download to within a few bytes.
6. **Finish:** calculate CO2, cache the result for a week (errors are never cached) and respond.

### Security

- **SSRF:** a URL is only fetched if it uses `https` on port 443 with no credentials. Every IP its host resolves to must be globally routable (no private, loopback, link-local, CGNAT or cloud-metadata ranges, and no IPv4-mapped/NAT64/6to4 tricks). The vetted IP is pinned with `CURLOPT_RESOLVE`, which blocks DNS rebinding. Redirects are followed manually and each hop is checked again. Proxy environment variables are ignored.
- **Resource limits:** there is a hard time limit per analysis, timeouts per request, and caps on the number of resources, the size of each transfer and the HTML kept in memory.
- **Abuse:** CORS origin allowlist, per-IP limits (IPv6 counted per /64) and a global rate limit, and per-URL locking.
- **Output:** `nosniff`, `default-src 'none'` CSP, `no-referrer`, `Vary: Origin`. Internal error details are only included in debug mode.
- The ipinfo token is sent in a header over HTTPS. The plugin sent it over plain HTTP in the query string.

## Differences from the Kirby plugin

- **Redirects are followed.** For example, `zeit.de` now measures the real page instead of the redirect response.
- **URL fixes:** relative URLs (`../`, paths relative to subpages, `<base href>`) are resolved correctly. `data:` URIs are skipped and `http://` resources are upgraded instead of producing broken URLs.
- **Duplicates:** a resource referenced several times is fetched and counted once, so `bytes` always equals the sum of `requests`.
- **Error handling:** one host without geo data no longer fails the whole analysis (its country is `null`), and failed analyses are never cached.
- **Extensions** in `types` are lower-case.
- **`rel` checks:** the ignore list is compared against the tokens of the `rel` attribute (so `rel="shortcut icon"` is ignored). Other attributes are not checked, so `alt="icon"` no longer hides an image.
- **Consent managers:** more are detected (Cookiebot, OneTrust, consentmanager, Complianz, CookieYes, iubenda, …).
- **Cache key:** now includes the query string (`?page_id=2` is a different page).
- **Status codes:** errors use proper HTTP status codes; the plugin always returned 200.
- **Downloads:** sub-resources are measured with `HEAD` requests instead of being downloaded.
- **CO2:** calculated on the server with SWDM v4 (CO2.js 0.19.0). The website used SWDM v3 (CO2.js 0.13.2) in the browser.
- **Server countries:** CDN-hosted sites may report different countries than before, because anycast/GeoDNS answers depend on where the analyzer runs.
