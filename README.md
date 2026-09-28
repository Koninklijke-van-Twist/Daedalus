# Daedalus

## Mímir (optional)

Set in `web/auth.php` (not in git):

```php
$mimirApi  = 'mimir_…';
// optional:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

With `$mimirApi` set, OData fetches and company discovery (`odata_mimir_list_companies`) try Mímir first. If that call fails (connection/timeout, non-2xx, invalid JSON, or a Mímir error payload), Daedalus fetches the same data on the legacy Business Central path (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, local odata file cache) and skips Mímir for the rest of that PHP request. Keep those BC credentials in `auth.php` next to `$mimirApi`; if they are absent the original Mímir error is raised. User company preference (GET → saved preference → first in list) is unchanged. Without `$mimirApi` the existing BC path and hardcoded company list are unchanged.

