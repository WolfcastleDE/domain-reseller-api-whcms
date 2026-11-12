# Domain Reseller API – WHMCS Registrar Module

A WHMCS registrar module for the [Domain Reseller API](https://domain-reseller-api.de) by Wolfscastle.
Register, transfer, renew and manage domains directly from WHMCS – including contact handles,
nameservers, glue records, transfer lock, WHOIS privacy, managed DNS and TLD price synchronisation.

API documentation: <https://domain-reseller-api.de/dokumentation>

## Features

| Area | What the module does |
| --- | --- |
| **Ordering** | Registration for the booked term (TLD minimum terms such as 2 years for `.ai` are respected), transfers with auth code, availability check (bulk), domain suggestions, premium domains (when enabled in WHMCS) |
| **Contacts** | Creates registrant/admin/tech handles from the order (`person` or `organization`, street and house number are split automatically), shows and updates the WHOIS contacts; roles that share a handle get their own contact when they differ; a person ⇄ organisation switch of the registrant is an owner change and only performed when allowed in the settings |
| **.it** | The nic.it RegCode is taken from the `.it` additional field *Tax ID* and sent as contact field `pin` |
| **Renewals** | Paid renewals via `POST /domains/{domain}/renew` for the WHMCS term; domains in the redemption period are restored. The API's own auto-renewal is disabled (WHMCS is the billing master) unless *Keep API Auto-Renew* is enabled |
| **Nameservers** | Get/save nameservers, register/modify/delete child nameservers (glue records) |
| **Transfer lock** | Lock/unlock where the TLD supports it |
| **ID protection** | WHOIS privacy for OpenProvider TLDs (natural persons), otherwise the WHOIS protection flag of the registrant contact |
| **Auth codes** | Retrieve auth codes (`.de`/`.eu` from the registry, other TLDs from the stored code), reset (`.de`) |
| **DNS** | DNS record management on the managed DNS (A, AAAA, CNAME, MX, TXT, SRV, …; WHMCS *URL* records become *Redirect*) |
| **Sync** | Expiry date and status sync, transfer completion sync |
| **TLD pricing** | *Registrar TLD Sync* imports net EUR prices incl. setup fees, minimum/maximum terms and redemption fees |
| **Admin actions** | Restore domain, cancel deletion, reset auth code, enable/disable managed DNS |

## Requirements

- WHMCS 8.x with PHP 8.1 or newer (`curl`, `json`, `mbstring`)
- An account at [domain-reseller-api.de](https://domain-reseller-api.de) with an API key (scopes
  `domains`, `contacts`, `dns` and `pricing`)

## Installation

1. Download `domainresellerapi-<version>.zip` from the
   [latest GitHub release](../../releases/latest).
2. Extract the ZIP into `<whmcs>/modules/registrars/`, so that the module ends up in
   `<whmcs>/modules/registrars/domainresellerapi/`.
3. In WHMCS go to **System Settings → Domain Registrars**, activate **Domain Reseller API** and
   configure it:

   | Setting | Description |
   | --- | --- |
   | API Key | Your `drapi_…` API key |
   | API URL | `https://domain-reseller-api.de` (only change when instructed) |
   | Sandbox Mode | Test without real registrations or charges (sends `x-sandbox: true`) |
   | Keep API Auto-Renew | Leave the API's own auto-renewal enabled (not recommended, risk of double renewals) |
   | Allow Owner Changes | Perform contact changes that need an owner change (may be charged) |
   | API Timeout | Request timeout in seconds (5–120) |

4. Optional: import TLDs and prices via **Utilities → Registrar TLD Sync**.

## Development

```bash
docker build -t drapi-test-whmcs .
docker run --rm drapi-test-whmcs vendor/bin/phpunit
```

Releases are built by GitHub Actions when a tag `v*` is pushed.

## Deutsch

WHMCS-Registrar-Modul für die [Domain Reseller API](https://domain-reseller-api.de) von Wolfscastle:
Domains direkt aus WHMCS registrieren, transferieren, verlängern und verwalten (Kontakte, Nameserver,
Glue-Records, Transfer-Lock, WHOIS-Privacy, Managed DNS, TLD-Preissync).

**Installation:** `domainresellerapi-<version>.zip` aus dem neuesten GitHub-Release herunterladen und
nach `<whmcs>/modules/registrars/` entpacken (Ergebnis: `modules/registrars/domainresellerapi/`).
Anschließend unter **System Settings → Domain Registrars** das Modul **Domain Reseller API**
aktivieren, den API-Key eintragen und für Tests den **Sandbox Mode** einschalten. Preise und TLDs
lassen sich über **Utilities → Registrar TLD Sync** importieren.

Verlängerungen steuert WHMCS (bei bezahlter Rechnung); die eigene Auto-Verlängerung der API wird
deshalb vom Modul abgeschaltet. Dokumentation der API: <https://domain-reseller-api.de/dokumentation>

## License

[MIT](LICENSE) © Wolfscastle
