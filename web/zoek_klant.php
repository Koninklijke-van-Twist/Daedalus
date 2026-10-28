<?php
/**
 * Zoek Klant: heeft de (bellende) klant een onderhoudscontract?
 * Zoekt server-side in de per-bedrijf cache uit lib/zoek_klant.php.
 */

/**
 * Includes en requires
 */
require __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/lib/zoek_klant.php';

/**
 * Variabelen
 */
$companies = ['Koninklijke van Twist', 'Hunter van Twist', 'KVT Gas'];
if (odata_mimir_enabled()) {
    try {
        $companies = odata_mimir_list_companies(null);
    } catch (Throwable $ignored) {
        // hardcoded lijst blijft staan
    }
}
$company = trim((string) ($_GET['company'] ?? ''));
if (!in_array($company, $companies, true)) {
    $company = (string) ($companies[0] ?? '');
}
$query = trim((string) ($_GET['q'] ?? ''));
$ajax = trim((string) ($_GET['ajax'] ?? ''));
$today = date('Y-m-d');

$stateLabels = [
    'actief' => ['Actief', 'ok'],
    'toekomstig' => ['Toekomstig', 'neutral'],
    'open' => ['Nog niet vrijgegeven (Open)', 'warn'],
    'verlopen' => ['Verlopen', 'unknown'],
];

/**
 * Functies
 */
function zk_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function zk_date(string $date): string
{
    if ($date === '' || $date === '0001-01-01') {
        return '…';
    }
    $ts = strtotime($date);
    return $ts ? date('d-m-Y', $ts) : $date;
}

function zk_render_results(?array $cache, string $query, string $today, array $stateLabels): string
{
    if ($cache === null || $query === '') {
        return '';
    }
    $results = zk_search($cache, $query, $today);
    ob_start();
    if ($results === []) {
        echo '<div class="zk-message warn">Geen onderhoudscontract gevonden voor "' . zk_h($query) . '"</div>';
    } else {
        $active = count(array_filter($results, static fn($c) => $c['state'] === 'actief'));
        if ($active === 0) {
            echo '<div class="zk-message warn">Geen actief onderhoudscontract gevonden voor "' . zk_h($query) . '". Niet-actieve contracten:</div>';
        } else {
            echo '<div class="zk-message ok">' . $active . ' actief onderhoudscontract' . ($active === 1 ? '' : 'en') . ' gevonden</div>';
        }
        foreach (array_slice($results, 0, 50) as $contract) {
            [$label, $class] = $stateLabels[$contract['state']];
            ?>
            <article class="card zk-result zk-<?= zk_h($contract['state']) ?>">
                <div class="zk-result-head">
                    <span class="badge <?= $class ?>"><?= zk_h($label) ?></span>
                    <span class="zk-period"><?= zk_h(zk_date($contract['start']) . ' t/m ' . zk_date($contract['end'])) ?></span>
                </div>
                <p class="zk-title"><?= zk_h($contract['no']) ?><?= $contract['description'] !== '' ? ' · ' . zk_h($contract['description']) : '' ?></p>
                <p class="zk-customer"><?= zk_h($contract['customer_no']) ?> · <?= zk_h($contract['customer_name']) ?></p>
                <?php if ($contract['address'] !== ''): ?>
                    <p class="zk-muted"><?= zk_h($contract['address']) ?></p>
                <?php endif; ?>
                <?php if ($contract['locations'] !== []): ?>
                    <ul class="zk-locations">
                        <?php foreach ($contract['locations'] as $location): ?>
                            <li>
                                <strong><?= zk_h($location['code']) ?></strong>
                                <?= zk_h($location['name'] !== '' ? $location['name'] : $location['description']) ?>
                                <?php if ($location['address'] !== ''): ?>
                                    <span class="zk-muted">· <?= zk_h($location['address']) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
            <?php
        }
        if (count($results) > 50) {
            echo '<p class="zk-muted">' . (count($results) - 50) . ' resultaten meer; verfijn de zoekopdracht.</p>';
        }
    }
    return (string) ob_get_clean();
}

/**
 * Page load
 */
if ($ajax === 'build') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $built = zk_build_cache($company);
        echo json_encode(['success' => true, 'contracts' => count($built['contracts'])]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Contracten konden niet worden geladen.']);
    }
    exit;
}

$cache = zk_load_cache($company);

if ($ajax === 'results') {
    header('Content-Type: text/html; charset=utf-8');
    echo zk_render_results($cache, $query, $today, $stateLabels);
    exit;
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="theme-color" content="#ffffff" />
    <title>Zoek Klant - Daedalus</title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" href="favicon.ico">
    <link rel="manifest" href="site.webmanifest">
    <style>
        :root {
            color-scheme: light;
            --bg: #f2f5f9;
            --card: #ffffff;
            --text: #152233;
            --muted: #5f7287;
            --border: #dbe4ee;
            --primary: #0f5bb7;
            --ok: #1d8a4c;
            --warn: #ad6f1a;
            --neutral: #637588;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding-bottom: 40px;
        }

        .page { width: min(100%, 760px); margin: 0 auto; padding: 12px; }
        .logo { display: block; width: 168px; max-width: 70%; margin: 0 auto 10px; height: auto; }
        .back { display: inline-block; padding: 8px 0; margin-bottom: 4px; color: var(--primary); font-weight: 600; text-decoration: none; font-size: .95rem; }
        .title { margin: 0 0 10px; font-size: 1.2rem; }

        .toolbar { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 10px; margin-bottom: 10px; display: grid; gap: 8px; }
        .field label { display: block; font-size: .78rem; color: var(--muted); margin-bottom: 4px; }
        .field select, .field input {
            width: 100%; border: 1px solid var(--border); border-radius: 10px; padding: 12px;
            font-size: 1rem; background: #fff; color: var(--text); min-height: 48px;
        }
        .search-row { display: flex; gap: 8px; }
        .search-row input { flex: 1 1 auto; }
        .search-button {
            border: 1px solid var(--primary); background: var(--primary); color: #fff; border-radius: 10px;
            min-height: 48px; padding: 0 16px; font-size: 1rem; font-weight: 600; cursor: pointer;
        }

        .card { display: block; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 12px; margin-bottom: 10px; }
        .badge { border-radius: 999px; padding: 3px 9px; font-size: .78rem; font-weight: 600; white-space: nowrap; border: 1px solid transparent; }
        .badge.ok { color: var(--ok); border-color: #bce4cb; background: #eef9f2; }
        .badge.warn { color: var(--warn); border-color: #ead8b7; background: #fbf5ea; }
        .badge.neutral { color: var(--neutral); border-color: #d7e0e8; background: #f7f9fb; }
        .badge.unknown { color: #8e2a2a; border-color: #f2cdcd; background: #fff3f3; }

        .zk-message { border-radius: 12px; padding: 12px; margin-bottom: 10px; font-weight: 600; border: 1px solid var(--border); background: var(--card); }
        .zk-message.ok { color: var(--ok); border-color: #bce4cb; background: #eef9f2; }
        .zk-message.warn { color: var(--warn); border-color: #ead8b7; background: #fbf5ea; }
        .zk-result.zk-actief { border-left: 4px solid var(--ok); }
        .zk-result-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
        .zk-period { font-size: .85rem; color: var(--muted); }
        .zk-title { margin: 8px 0 2px; font-weight: 700; font-size: 1.02rem; overflow-wrap: anywhere; }
        .zk-customer { margin: 0 0 2px; overflow-wrap: anywhere; }
        .zk-muted { color: var(--muted); font-size: .85rem; margin: 0; }
        .zk-locations { margin: 8px 0 0; padding-left: 18px; font-size: .9rem; line-height: 1.4; }
        .zk-hint, .zk-footer { color: var(--muted); font-size: .8rem; text-align: center; margin-top: 12px; }
        .zk-loading { text-align: center; }
    </style>
</head>

<body>
    <main class="page">
        <img src="logo-website.png" alt="KVT" class="logo" />
        <a href="index.php?company=<?= rawurlencode($company) ?>" class="back">← Terug naar overzicht</a>
        <h1 class="title">Zoek Klant</h1>

        <form class="toolbar" method="get" action="zoek_klant.php" id="zk-form">
            <div class="field">
                <label for="company">Bedrijf</label>
                <select id="company" name="company" onchange="this.form.submit()">
                    <?php foreach ($companies as $companyOption): ?>
                        <option value="<?= zk_h($companyOption) ?>" <?= $companyOption === $company ? 'selected' : '' ?>><?= zk_h($companyOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="q">Klantnaam, klantnummer, locatie, adres of contract</label>
                <div class="search-row">
                    <input id="q" name="q" type="search" value="<?= zk_h($query) ?>" placeholder="Bijv. Attero of 10398"
                        autocomplete="off" autocapitalize="off" enterkeyhint="search" <?= $cache !== null ? 'autofocus' : '' ?> />
                    <button type="submit" class="search-button">Zoek</button>
                </div>
            </div>
        </form>

        <?php if ($cache === null): ?>
            <div class="card zk-loading" id="zk-loading">
                <p><strong>Bezig met laden van onderhoudscontracten…</strong></p>
                <p class="zk-muted">Dit gebeurt eenmalig en kan een minuut duren.</p>
            </div>
        <?php endif; ?>

        <section id="zk-results" aria-live="polite"><?= zk_render_results($cache, $query, $today, $stateLabels) ?></section>

        <?php if ($cache !== null): ?>
            <?php if ($query === ''): ?>
                <p class="zk-hint" id="zk-hint">Typ minimaal 2 tekens. Alle woorden moeten voorkomen.</p>
            <?php endif; ?>
            <p class="zk-footer"><?= count($cache['contracts']) ?> contracten · gegevens van <?= zk_h(date('d-m-Y H:i', strtotime((string) $cache['built_at']) ?: time())) ?></p>
        <?php endif; ?>
    </main>
    <script>
        (function () {
            var form = document.getElementById('zk-form');
            var input = document.getElementById('q');
            var results = document.getElementById('zk-results');
            var company = document.getElementById('company').value;

            if (document.getElementById('zk-loading')) {
                fetch('zoek_klant.php?ajax=build&company=' + encodeURIComponent(company))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) { throw new Error(); }
                        window.location.reload();
                    })
                    .catch(function () {
                        document.getElementById('zk-loading').innerHTML = '<p><strong>Contracten konden niet worden geladen.</strong></p><p class="zk-muted">Probeer het later opnieuw.</p>';
                    });
                return;
            }

            var timer = null;
            var seq = 0;
            function search() {
                var q = input.value.trim();
                var hint = document.getElementById('zk-hint');
                if (hint) { hint.hidden = q.length >= 2; }
                var url = 'zoek_klant.php?company=' + encodeURIComponent(company) + '&q=' + encodeURIComponent(q);
                history.replaceState(null, '', url);
                if (q.length < 2) { results.innerHTML = ''; return; }
                var mine = ++seq;
                fetch(url + '&ajax=results')
                    .then(function (r) { return r.text(); })
                    .then(function (html) { if (mine === seq) { results.innerHTML = html; } });
            }
            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(search, 300);
            });
            form.addEventListener('submit', function (e) {
                if (document.activeElement === document.getElementById('company')) { return; }
                e.preventDefault();
                clearTimeout(timer);
                search();
                input.blur();
            });
        })();
    </script>
</body>

</html>
