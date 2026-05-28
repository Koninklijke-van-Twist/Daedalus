<?php
/**
 * Includes en requires
 */
require_once __DIR__ . '/logincheck.php';
define('DAEDALUS_EMAIL_NOTIFICATIONS_LIB_ONLY', true);
require_once __DIR__ . '/api_send_email_notifications.php';

/**
 * Variabelen
 */
$companies = [
    'Koninklijke van Twist',
    'Hunter van Twist',
    'KVT Gas',
];

$sessionUserEmail = normalize_email((string) ($_SESSION['user']['email'] ?? ''));
$sessionUserName = trim((string) ($_SESSION['user']['name'] ?? ''));
$errorMessage = '';
$successMessage = '';
$previewCount = 0;

$requestedCompany = trim((string) ($_GET['company'] ?? $_POST['company'] ?? ''));
$company = in_array($requestedCompany, $companies, true) ? $requestedCompany : $companies[0];

$today = new DateTimeImmutable('today');
$dateFromValue = trim((string) ($_GET['date_from'] ?? $_POST['date_from'] ?? $today->format('Y-m-d')));
$selectedResourceNo = trim((string) ($_GET['resource_no'] ?? $_POST['resource_no'] ?? ''));

$serviceResources = [];

/**
 * Functies
 */
function parse_date_ymd_or_null(string $value): ?DateTimeImmutable
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
    if (!($parsed instanceof DateTimeImmutable)) {
        return null;
    }

    if ($parsed->format('Y-m-d') !== $trimmed) {
        return null;
    }

    return $parsed;
}

/**
 * Page load
 */
try {
    $serviceResources = fetch_service_resources($environment, $company, $auth);

    if ($selectedResourceNo === '' && !empty($serviceResources)) {
        $selectedResourceNo = trim((string) ($serviceResources[0]['No'] ?? ''));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($sessionUserEmail === '') {
            throw new RuntimeException('Geen ingelogde gebruiker met e-mailadres gevonden.');
        }

        $dateFrom = parse_date_ymd_or_null($dateFromValue);
        if (!($dateFrom instanceof DateTimeImmutable)) {
            throw new RuntimeException('Ongeldige datum. Gebruik een datum in formaat JJJJ-MM-DD.');
        }

        if ($selectedResourceNo === '') {
            throw new RuntimeException('Selecteer een servicemonteur.');
        }

        $resourceRow = fetch_service_resource_row_by_no($environment, $company, $selectedResourceNo, $auth);
        if (empty($resourceRow)) {
            throw new RuntimeException('De geselecteerde servicemonteur is niet gevonden of is niet actief.');
        }

        $resourceName = trim((string) ($resourceRow['Name'] ?? ''));
        $workOrders = fetch_workorders_for_resource_by_date(
            $environment,
            $company,
            $selectedResourceNo,
            $dateFrom->format('Y-m-d'),
            true,
            $auth
        );

        $previewCount = count($workOrders);
        if ($previewCount <= 0) {
            $successMessage = 'Geen werkorders gevonden vanaf de gekozen datum. Er is geen testmail verzonden.';
        } else {
            $workOrderNos = array_map(static fn(array $workOrder): string => trim((string) ($workOrder['No'] ?? '')), $workOrders);
            $materialSummary = fetch_workorder_material_summary_for_workorders($environment, $company, $workOrderNos, $auth);
            $webfleetLabels = fetch_webfleet_status_labels_for_workorders($environment, $company, $workOrderNos, $auth);

            $emailHtml = build_email_html(
                $company,
                $selectedResourceNo,
                $resourceName,
                $workOrders,
                is_array($materialSummary['counts'] ?? null) ? $materialSummary['counts'] : [],
                is_array($materialSummary['labels'] ?? null) ? $materialSummary['labels'] : [],
                $webfleetLabels
            );

            $subjectPrefix = trim((string) ($reportMail['subject_prefix'] ?? 'Daedalus'));
            $subject = $subjectPrefix . ' TEST - ' . $previewCount . ' werkorder' . ($previewCount === 1 ? '' : 's') . ' vanaf ' . $dateFrom->format('Y-m-d');

            smtp_send_html_mail(
                $reportMail,
                $sessionUserEmail,
                $sessionUserName,
                $subject,
                $emailHtml
            );

            $successMessage = 'Testmail verzonden naar ' . $sessionUserEmail . ' met ' . $previewCount . ' werkorder' . ($previewCount === 1 ? '' : 's') . '.';
        }
    }
} catch (Throwable $throwable) {
    $errorMessage = $throwable->getMessage();
}

?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="theme-color" content="#ffffff" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <title>Test e-mailnotificatie</title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
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
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .page {
            width: min(100%, 760px);
            margin: 0 auto;
            padding: 12px;
        }

        .logo {
            display: block;
            width: 168px;
            max-width: 70%;
            margin: 0 auto 10px;
            height: auto;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
        }

        .title {
            margin: 0 0 8px;
            font-size: 1.2rem;
        }

        .subtitle {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: .92rem;
        }

        .alert {
            border: 1px solid #f2cdcd;
            background: #fff3f3;
            color: #8e2a2a;
            border-radius: 10px;
            padding: 10px;
            margin: 0 0 10px;
            font-size: .9rem;
        }

        .success {
            border: 1px solid #bce4cb;
            background: #eef9f2;
            color: #1d8a4c;
            border-radius: 10px;
            padding: 10px;
            margin: 0 0 10px;
            font-size: .9rem;
        }

        .field {
            margin-bottom: 10px;
        }

        .field label {
            display: block;
            font-size: .78rem;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .field select,
        .field input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            font-size: .95rem;
            background: #fff;
            color: var(--text);
        }

        .button-row {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .button {
            border: 1px solid var(--primary);
            background: var(--primary);
            color: #fff;
            border-radius: 10px;
            padding: 9px 12px;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
        }

        .button.secondary {
            border-color: var(--border);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .meta {
            margin-top: 10px;
            color: var(--muted);
            font-size: .86rem;
        }
    </style>
</head>

<body>
    <main class="page">
        <img src="logo-website.png" alt="KVT" class="logo" />

        <section class="card">
            <h1 class="title">Test e-mailnotificatie</h1>
            <p class="subtitle">Stuurt een testmail naar de ingelogde gebruiker met werkorders van een gekozen monteur
                vanaf een datum.</p>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>
                <div class="success"><?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>

            <form method="post" action="test_email_notification.php">
                <div class="field">
                    <label for="company">Bedrijf</label>
                    <select id="company" name="company" onchange="this.form.submit()">
                        <?php foreach ($companies as $companyOption): ?>
                            <option value="<?= htmlspecialchars($companyOption) ?>" <?= $companyOption === $company ? 'selected' : '' ?>>
                                <?= htmlspecialchars($companyOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="resource_no">Servicemonteur</label>
                    <select id="resource_no" name="resource_no" required>
                        <?php foreach ($serviceResources as $serviceResource): ?>
                            <?php $resourceNo = trim((string) ($serviceResource['No'] ?? '')); ?>
                            <?php $resourceName = trim((string) ($serviceResource['Name'] ?? '')); ?>
                            <?php if ($resourceNo === '') {
                                continue;
                            } ?>
                            <option value="<?= htmlspecialchars($resourceNo) ?>" <?= $resourceNo === $selectedResourceNo ? 'selected' : '' ?>>
                                <?= htmlspecialchars($resourceName !== '' ? ($resourceName . ' (' . $resourceNo . ')') : $resourceNo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="date_from">Vanaf datum</label>
                    <input id="date_from" name="date_from" type="date" value="<?= htmlspecialchars($dateFromValue) ?>"
                        required />
                </div>

                <div class="button-row">
                    <a class="button secondary" href="index.php?company=<?= rawurlencode($company) ?>">Terug</a>
                    <button class="button" type="submit">Verstuur testmail</button>
                </div>
            </form>

            <div class="meta">
                Ingelogde gebruiker:
                <?= htmlspecialchars($sessionUserEmail !== '' ? $sessionUserEmail : 'onbekend') ?><br />
                Laatste resultaat: <?= htmlspecialchars((string) $previewCount) ?> gevonden
                werkorder<?= $previewCount === 1 ? '' : 's' ?>.
            </div>
        </section>
    </main>
</body>

</html>