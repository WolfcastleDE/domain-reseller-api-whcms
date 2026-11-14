# End-to-end tests in a real WHMCS

Runs the registrar module inside a real WHMCS installation against the Domain Reseller API
backend in sandbox mode, drives WHMCS through its admin/client UI (Playwright) and its internal
API (`localAPI`), and checks every result at the API.

Last run: WHMCS 9.1.1 on PHP 8.3.36 with ionCube Loader 15.5.1, MySQL 8.0 (all tests passing).

## Requirements

- Docker with Compose v2, `curl`, `python3`, `zip`, `gh` (only for `MODULE=v…`)
- A WHMCS license key. A **development license** is enough, but it may only be used for testing
  and the instance must not be publicly reachable: every port is bound to `127.0.0.1`.
- A checkout of the Domain Reseller API (`BACKEND_PATH`, mounted read-only)

## Run

```bash
export WHMCS_LICENSE_KEY=Dev-…            # never commit it; it only ends up in the container volume
export BACKEND_PATH=~/Projects/domain-reseller-api
./e2e/run.sh            # download + verify WHMCS, install, configure, run all tests, take screenshots
./e2e/run.sh test       # run the tests again against the running environment
./e2e/run.sh reinstall  # rebuild the module ZIP from this checkout and reinstall it (then: test)
./e2e/run.sh shots      # marketplace screenshots -> e2e/artifacts/marketplace-*.png
./e2e/run.sh down       # remove containers and volumes
```

`MODULE=v0.9.1 ./e2e/run.sh` installs a published release ZIP instead of the local build
(`EXPECT_CONFIG_VALIDATE=0` is needed for releases before the connection test existed).

WHMCS: <http://127.0.0.1:18081/admin> (`admin` / `e2e-password-123`), client
`kunde@e2e.test` / `e2e-password-123`. API: <http://127.0.0.1:13001/swagger>.

## How it works

| Piece | Purpose |
| --- | --- |
| `fetch-whmcs.sh` | Gets the current WHMCS package from the public download API (`api1.whmcs.com/download/latest`), verifies its SHA-256 and extracts it to `.cache/` |
| `whmcs/Dockerfile` | PHP 8.3 + Apache + ionCube Loader + the extensions of the [WHMCS 9.1 system requirements](https://docs.whmcs.com/9-1/installation-guide/system-requirements/) (WHMCS itself is mounted, not baked in) |
| `whmcs-entrypoint.sh` | First start: WHMCS CLI installer (`install/bin/installer.php -i -n -c`), then installs the module ZIP (release layout) and the capture hook |
| `docker-compose.yml` | MySQL, the backend (`oven/bun`) and WHMCS. WHMCS shares the backend's network namespace, so the module talks to `http://localhost:3000` |
| `api-entrypoint.sh`, `e2e-backend-setup.ts` | Migrate + seed the backend, create the sandbox reseller and API key, add `.ai` (2-year minimum, per-term prices) and `.it` |
| `scripts/e2e-capture-hook.php` | Test-only WHMCS hook: uses `ApiClient::$factory` to log every module call's parameters and every API request to `/shared/drapi-e2e.jsonl` (API key masked) |
| `scripts/*.php` | Lifecycle tests through WHMCS (`localAPI`, `RegCallFunction`, cron tasks) |
| `ui/*.cjs` | Playwright tests of the admin area and the Twenty-One client area, screenshots |

### License binding

On first use WHMCS binds the license to the domain (`127.0.0.1`), the server IP as WHMCS sees
it (the container address) and the directory (`/var/www/html`). The compose file pins the
address to `172.26.0.3` (`E2E_SUBNET_PREFIX`) so the license stays valid when the environment is
recreated. If WHMCS reports an invalid license after changing these, reissue the license in the
WHMCS client area.

### What is covered

| Script | Checks |
| --- | --- |
| `ui/license.cjs` | EULA, license accepted |
| `ui/registrar.cjs` | Domain Registrars: activate, settings form, connection test (wrong key rejected, masked key re-save), save |
| `ui/tldsync.cjs` | Registrar TLD Sync: cost prices (`.ai` 2-year totals, transfer, redemption), min term, import |
| `ui/lookup.cjs` | Lookup provider = module, suggestion TLDs, spotlight TLDs |
| `scripts/register.php` | Orders `.com` (ID protection, nameservers), `.ai` (2 years), `.it` (Tax ID → `pin`), `.de`; module params and API payloads |
| `scripts/manage.php` | Nameservers, lock, EPP code, WHOIS contacts, ID protection, glue records, DNS, renewal (API + paid invoice), `DomainStatusSync`, custom buttons |
| `scripts/transfer.php` | Transfer with auth code, `DomainTransferSync` (pending, completed, failed) |
| `scripts/redemption.php` | Expiry sync, restore by renewing in the redemption period, Restore/Cancel Deletion buttons, RequestDelete |
| `scripts/contacts.php` | Role contacts, owner change refused/allowed by *Allow Owner Changes* |
| `ui/client.cjs` | Client area: nameservers, contacts (UTF-8), private nameservers, DNS, EPP code |
| `ui/admin-domain.cjs` | Admin domain page: registrar commands, custom buttons, save without lock change |
| `ui/search.cjs` | Cart search: availability, taken domain, IDN, suggestions, spotlight bulk check |

### Sandbox limitations

The backend sandbox has no registry, so some checks simulate the registry outcome in the
backend's test database (transfer completed/failed, expiry/redemption). The sandbox always
answers `GET /lock` with `supported: false` (WHMCS shows "Unknown") and lists no glue records.
The backend's own hourly crons run too (e.g. the BDOM price sync replaces seed prices with the
public price list).
