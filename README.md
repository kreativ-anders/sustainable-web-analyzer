# Sustainable Web Analyzer

Vanilla PHP endpoint (8.2+, 8.5 recommended; `curl`, `dom`) that measures page weight, hosts, green hosting and CO2 per visit of a web page.

Docs: [`docs/index.html`](docs/index.html) · API: [`docs/api.html`](docs/api.html) · Spec: [`docs/openapi.json`](docs/openapi.json) · Deployment: [`nginx.conf.example`](nginx.conf.example) · Local Wharf: [`nginx.wharf.conf.example`](nginx.wharf.conf.example)

```sh
php -S 127.0.0.1:8080 -t docs index.php    # docs at /, API at /api/?url=…
bin/analyze https://kreativ-anders.de
php tests/run.php
bin/update-grid-intensity                   # refresh data/grid-intensity.php (needs intl), also runs every 6 months via GitHub Actions
```
