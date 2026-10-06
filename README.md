# Daedalus

## Mímir (optional)

Set in `web/auth.php` (not in git):

```php
$mimirApi  = 'mimir_…';
// optional:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

With `$mimirApi` set, OData fetches and company discovery (`odata_mimir_list_companies`) try Mímir first. If that call fails (connection/timeout, non-2xx, invalid JSON, or a Mímir error payload), Daedalus fetches the same data on the legacy Business Central path (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, local odata file cache) and skips Mímir for the rest of that PHP request. Keep those BC credentials in `auth.php` next to `$mimirApi`; if they are absent the original Mímir error is raised. User company preference (GET → saved preference → first in list) is unchanged. Without `$mimirApi` the existing BC path and hardcoded company list are unchanged.

## Daily briefing / attention notes (optional, Copilot MVP)

Two parts, both off until configured in `web/auth.php`:

1. **`GET web/api/daily_briefing.php`**: read-only JSON for an external flow (Copilot / Power Automate). For each service engineer it lists the day's work orders plus a short history of earlier service reports for the same object, taken from SharePoint.
2. **Daily overview mail** (`api_send_email_notifications.php`): when SharePoint has a file `<briefingNotesFolder>/<YYYY-MM-DD>/<WO-nr>.md`, its content shows up as **Aandachtspunten** under that work order. If there is no file, no configuration, an error or a timeout, the mail goes out exactly as before.

### Configuration (`web/auth.php`, never committed)

Daedalus **reuses Clio's Entra app**. Copy the `$sharepointSettings` block from Clio's `auth.php` unchanged, using the same keys.

Daedalus uses these keys:
- `tenant_id`, `client_id`, `client_secret`
- optional: `token_scope`, `token_url`, `verify_ssl`, `ca_bundle`

Like in Clio, a missing key falls back to the matching environment variable (`SHAREPOINT_TENANT_ID`, …).

Clio's `site_id`, `drive_id`, `list_id`, `upload_folder`, `status_field` and `access_token` belong to Clio's transcript site. Daedalus ignores them, so the block can be copied as is. The KVTAlgemeen site is configured separately:

```php
// Same Entra app as Clio (copy from Clio's auth.php)
$sharepointSettings = [
    'tenant_id'     => '…',
    'client_id'     => '…',
    'client_secret' => '…',
    'token_scope'   => 'https://graph.microsoft.com/.default', // optional
    // 'site_id', 'drive_id', 'list_id', … from Clio may stay; ignored here
];

// Daedalus-specific
$briefingApiKeys      = ['copilot-flow-…'];   // one long random key per client (bin2hex(random_bytes(32)))
$briefingSiteHostname = 'kvtnl.sharepoint.com'; // default
$briefingSitePath     = '/sites/KVTAlgemeen';   // default
$briefingDriveName    = '';   // empty = the site's default library ("Gedeelde documenten"), recommended
$briefingDriveId      = '';   // optional fixed drive ID: skips the site lookup (needed if the app only has Files.*)
$briefingNotesFolder  = 'General/Daedalus/Aandachtspunten';

// Optional
$briefingIndexPath          = 'General/servicerapporten.xlsx';
$briefingTimeBudgetSeconds  = 40;   // SharePoint time budget per endpoint call
$briefingNotesBudgetSeconds = 20;   // SharePoint time budget per daily-mail run (5 s per request)
$briefingPdftotextPath      = '/usr/bin/pdftotext'; // PDF fallback; auto-detected when omitted
$briefingCompanies          = ['Koninklijke van Twist', 'Hunter van Twist', 'KVT Gas'];
```

KVTAlgemeen has a second, empty library whose display name is "Gedeelde Documenten". Leave `$briefingDriveName` empty, or set it to `'Gedeelde documenten'`: the name is matched against the library URL first (`…/Gedeelde%20documenten`), so you still get the real library.

**Permissions.** Daedalus only reads, but what it can read depends on the permissions of Clio's app:
- **`Sites.Read.All` or `Sites.ReadWrite.All`** (application permission). Clio documents `Sites.ReadWrite.All` or `Files.ReadWrite.All`, so this is the likely case:
  - KVTAlgemeen is already covered and nothing has to change.
- **Only `Files.Read.All` or `Files.ReadWrite.All`**:
  - The site lookup (`GET /sites/{host}:{path}`) needs `Sites.*` permissions and will fail.
  - Set `$briefingDriveId` to the drive ID of the KVTAlgemeen library "Gedeelde documenten". The current ID is `b!pIXr9wIzhEqTtnu2zdSFSshQtbeBpypEi1bmliwFU4MgmCNWzHvlTa8WxBnyLRKd`.
  - Daedalus then skips the site lookup and only does drive operations, which the `Files.*` permissions cover.
- **Only `Sites.Selected`**: an admin has to add a read grant on KVTAlgemeen:
  1. `GET https://graph.microsoft.com/v1.0/sites/kvtnl.sharepoint.com:/sites/KVTAlgemeen` and take the `id`.
  2. `POST https://graph.microsoft.com/v1.0/sites/{site-id}/permissions` with body `{"roles":["read"],"grantedToIdentities":[{"application":{"id":"<client_id>","displayName":"Clio"}}]}`. Run this as an admin, for example in Graph Explorer with `Sites.FullControl.All`.

You can check which case applies in Entra under App registrations → (Clio app) → API permissions. If the token has no `roles` at all, Daedalus reports *"Graph-token bevat geen rechten (roles)"*, the same check Clio does.

### Endpoint

```
GET /daedalus/api/daily_briefing.php?date=2026-10-07
X-API-Key: <one of $briefingApiKeys>
```

| Parameter | Default | |
|---|---|---|
| `date` | today (Europe/Amsterdam) | `YYYY-MM-DD` |
| `scope` | `subscribers` | `subscribers` = engineers who enabled the daily overview mail in Daedalus (the same people the daily mail goes to); `all` = every resource with a work order that day |
| `company` | all companies in `$briefingCompanies` | one company name |
| `history` | `5` | number of earlier reports per work order (1–10) |

Responses:
- `401` when the key is missing or wrong, or when no keys are configured
- `405` for anything but GET
- `400` for invalid parameters
- `502` when Business Central can't be reached at all:
  - with `scope=all`: for every requested company
  - with `scope=subscribers`: for every engineer

A partial Business Central failure still returns `200`:
- with `scope=subscribers`: an `error` field on each affected resource
- with `scope=all`: a top-level `errors` list

Work orders come from the `AppWerkorders` page only, never from `Werkorders`. Cancelled work orders are skipped.

`history` holds only the text from the named ranges `Opmerkingen` and `Storingsdiagnose` in the `.xlsm` service report. If there is no xlsm, the text comes from the Opmerkingen/Advies sections of the PDF, but only when `pdftotext` is available. Names of known engineers, e-mail addresses, phone numbers and signature/name lines are removed (best effort). Report headers and customer details are never read. `has_notes` is `false` when every text is empty or trivial (`-`, `geen`, `n.v.t.`, …).

SharePoint failures never produce a 500. Each work order gets a `history_status` instead:
- `ok`, `partial`, `no_reports`, `no_component`, `not_configured`, `error`, `budget_exceeded`

Each history entry has a `status`:
- `ok`, `not_found`, `folder_not_found`, `pdf_unavailable`, `error`, `budget_exceeded`

Example (fictitious data):

```json
{
  "ok": true,
  "date": "2026-10-07",
  "date_label": "7 oktober 2026",
  "timezone": "Europe/Amsterdam",
  "generated_at": "2026-10-07T05:30:12+02:00",
  "scope": "subscribers",
  "notes_path": "General/Daedalus/Aandachtspunten/2026-10-07/<WO-nr>.md",
  "sharepoint": { "configured": true, "index_status": "ok", "index_message": null, "pdf_fallback": true },
  "resources": [
    {
      "resource_no": "9001",
      "name": "Jan Voorbeeld",
      "email": "j.voorbeeld@example.com",
      "company": "Koninklijke van Twist",
      "workorders": [
        {
          "no": "WO2699001",
          "task_code": "OH",
          "task": "Onderhoud 500 uur",
          "status": "Gepland",
          "start_date": "2026-10-07",
          "start_time": "08:00",
          "end_time": "12:00",
          "main_entity_description": "Voorbeeld BV Zwolle",
          "component_no": "10009999",
          "component_description": "Noodstroomaggregaat 250 kVA",
          "link": "https://sleutels.kvt.nl/daedalus/index.php?company=Koninklijke%20van%20Twist&person=9001&workorder=WO2699001",
          "history_status": "ok",
          "history_message": null,
          "has_notes": true,
          "history": [
            {
              "date": "2026-04-14",
              "date_label": "14 april 2026",
              "workorder_no": "WO2698123",
              "source": "xlsm",
              "opmerkingen": "Accu's vertonen slijtage, advies vervangen bij volgend onderhoud.",
              "storingsdiagnose": "",
              "has_notes": true,
              "status": "ok"
            }
          ]
        }
      ]
    }
  ]
}
```

### Contract for the flow (attention notes)

The flow writes one Markdown file for each work order, for the day of the overview mail:

```
<library "Gedeelde documenten">/General/Daedalus/Aandachtspunten/2026-10-07/WO2699001.md
```

- File name: the work order number plus `.md`. Matching is case-insensitive.
- Maximum size: 64 KB.
- Supported Markdown: headings (`#`), bullets (`-`/`*`/`1.`), `**bold**` and `[text](https://…)` links (http(s) only). Everything else is escaped and shown as plain text; raw HTML is never rendered.
- The daily-mail run lists the day folder once, with a 5 s timeout per request and a total budget of `$briefingNotesBudgetSeconds`. On any problem the notes are left out and the mail is sent unchanged.
- The flow needs write access to that folder using its own (user/flow) permissions. The Daedalus app itself only reads.

### Caching

Cache files live under `web/cache/briefing/`:
- the Graph token, until it expires
- the site and drive IDs
- the report index (eTag-checked every 30 min)
- folder listings
- extracted report text (keyed by item id + eTag)

The directory is gitignored and excluded from FTP deploy. Every file starts with a PHP guard (`http_response_code(404); exit;`), and the directory also gets a `.htaccess` deny.

### Tests

```
php tests/briefing_test.php
php tests/mimir_fallback_test.php
```

Graph and Business Central are mocked in the tests, so they make no live calls. The xlsm fixtures are generated and contain no real customer data.
