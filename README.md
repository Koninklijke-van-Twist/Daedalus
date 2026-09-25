# Daedalus

## Mímir (optional)

Set in `web/auth.php` (not in git):

```php
$mimirApi  = 'mimir_…';
// optional:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

With `$mimirApi` set, `$auth_list`, `$environment`, `$baseUrl` and `$auth` are unused for Business Central — OData fetches and company discovery (`odata_mimir_list_companies`) go through Mímir. User company preference (GET → saved preference → first in list) is unchanged. The local odata file-cache widget remains available for the non-Mímir path. Without `$mimirApi` the existing BC path and hardcoded company list are unchanged.

