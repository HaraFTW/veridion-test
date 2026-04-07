<?php

declare(strict_types = 1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/*
|--------------------------------------------------------------------------
| DB CONFIG
|--------------------------------------------------------------------------
*/

$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbName = 'veridion';
$dbUser = 'root';
$dbPass = '';

/*
|--------------------------------------------------------------------------
| DB CONNECT
|--------------------------------------------------------------------------
*/

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    exit('DB connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fetchChartData(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);

    return $stmt->fetchAll();
}

function getMaxValue(array $rows, string $valueKey): int
{
    $max = 0;

    foreach ($rows as $row) {
        $value = (int) ($row[$valueKey] ?? 0);

        if ($value > $max) {
            $max = $value;
        }
    }

    return $max > 0 ? $max : 1;
}

function getBarClass(string $label): string
{
    $normalized = strtolower(trim($label));

    return match ($normalized) {
        'matched' => 'bar-green',
        'unmatched' => 'bar-red',
        'high' => 'bar-green',
        'medium' => 'bar-yellow',
        'low' => 'bar-red',
        'script' => 'bar-blue',
        'other' => 'bar-gray',
        default => 'bar-blue',
    };
}

function renderBarChart(array $rows, string $labelKey, string $valueKey): string
{
    if (count($rows) === 0) {
        return '<div class="empty-chart">No data available.</div>';
    }

    $maxValue = getMaxValue($rows, $valueKey);

    $total = 0;
    foreach ($rows as $row) {
        $total += (int) ($row[$valueKey] ?? 0);
    }

    if ($total === 0) {
        $total = 1;
    }

    $html = '<div class="chart">';

    foreach ($rows as $row) {
        $label = (string) ($row[$labelKey] ?? '');
        $value = (int) ($row[$valueKey] ?? 0);

        $barPercent = ($value / $maxValue) * 100;
        $percentage = ($value / $total) * 100;

        $barClass = getBarClass($label);

        $html .= '
            <div class="chart-row">
                <div class="chart-label">' . h($label) . '</div>
                <div class="chart-bar-wrap">
                    <div class="chart-bar ' . h($barClass) . '" style="width: ' . h(number_format($barPercent, 2, '.', '')) . '%;"></div>
                </div>
                <div class="chart-value">' . h((string) $value) . ' <span class="small">(' . h(number_format($percentage, 1)) . '%)</span></div>
            </div>
        ';
    }

    $html .= '</div>';

    return $html;
}

/*
|--------------------------------------------------------------------------
| QUERIES
|--------------------------------------------------------------------------
*/

$decisionStatusRows = fetchChartData($pdo, '
    SELECT decision_status, COUNT(id) AS cnt
    FROM veridion_poc_results
    GROUP BY decision_status
');

$matchedConfidenceRows = fetchChartData($pdo, '
    SELECT confidence_level, COUNT(id) AS cnt
    FROM veridion_poc_results
    WHERE decision_status = "matched"
    GROUP BY confidence_level
');

$scriptMatchedConfidenceRows = fetchChartData($pdo, '
    SELECT confidence_level, COUNT(id) AS cnt
    FROM veridion_poc_results
    WHERE decision_status = "matched"
      AND reviewer_notes IN ("script", "bulk")
    GROUP BY confidence_level
');

$notesGroupRows = fetchChartData($pdo, '
    SELECT
        CASE
            WHEN reviewer_notes IN ("script", "bulk") THEN "script"
            ELSE "other"
        END AS notes_group,
        COUNT(*) AS total_rows
    FROM veridion_poc_results
    GROUP BY
        CASE
            WHEN reviewer_notes IN ("script", "bulk") THEN "script"
            ELSE "other"
        END
');

/*
|--------------------------------------------------------------------------
| TOTALS
|--------------------------------------------------------------------------
*/

$totalReviewed = (int) $pdo->query('SELECT COUNT(id) FROM veridion_poc_results')->fetchColumn();

$matchedCount = (int) $pdo->query('
    SELECT COUNT(id)
    FROM veridion_poc_results
    WHERE decision_status = "matched"
')->fetchColumn();

$unmatchedCount = (int) $pdo->query('
    SELECT COUNT(id)
    FROM veridion_poc_results
    WHERE decision_status = "unmatched"
')->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veridion POC Dashboard</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --panel-2: #1f2937;
            --border: #374151;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --green: #22c55e;
            --yellow: #f59e0b;
            --red: #ef4444;
            --blue: #3b82f6;
            --gray: #6b7280;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 24px;
        }

        h1 {
            margin: 0 0 24px;
            font-size: 28px;
        }

        h2 {
            margin: 0 0 16px;
            font-size: 20px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(320px, 1fr));
            gap: 20px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
        }

        .chart {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .chart-row {
            display: grid;
            grid-template-columns: 140px 1fr 60px;
            gap: 12px;
            align-items: center;
        }

        .chart-label {
            color: var(--text);
            font-size: 14px;
            text-transform: capitalize;
        }

        .chart-bar-wrap {
            width: 100%;
            height: 22px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 999px;
            overflow: hidden;
        }

        .chart-bar {
            height: 100%;
            border-radius: 999px;
        }

        .chart-value {
            text-align: right;
            font-weight: bold;
            font-size: 14px;
        }

        .bar-green {
            background: var(--green);
        }

        .bar-yellow {
            background: var(--yellow);
        }

        .bar-red {
            background: var(--red);
        }

        .bar-blue {
            background: var(--blue);
        }

        .bar-gray {
            background: var(--gray);
        }

        .empty-chart {
            color: var(--muted);
            padding: 8px 0;
        }

        .footer-note {
            margin-top: 24px;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 900px) {
            .stats,
            .grid {
                grid-template-columns: 1fr;
            }

            .chart-row {
                grid-template-columns: 110px 1fr 50px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Veridion POC Dashboard</h1>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-label">Total Reviewed</div>
            <div class="stat-value"><?= h((string) $totalReviewed) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Matched</div>
            <div class="stat-value"><?= h((string) $matchedCount) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Unmatched</div>
            <div class="stat-value"><?= h((string) $unmatchedCount) ?></div>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Decision Status</h2>
            <?= renderBarChart($decisionStatusRows, 'decision_status', 'cnt') ?>
        </div>

        <div class="card">
            <h2>Confidence Level for Matched Rows</h2>
            <?= renderBarChart($matchedConfidenceRows, 'confidence_level', 'cnt') ?>
        </div>

        <div class="card">
            <h2>Confidence Level for Script-Matched and Bulk-Matched Rows</h2>
            <?= renderBarChart($scriptMatchedConfidenceRows, 'confidence_level', 'cnt') ?>
        </div>

        <div class="card">
            <h2>Script vs Other</h2>
            <?= renderBarChart($notesGroupRows, 'notes_group', 'total_rows') ?>
        </div>
    </div>

    <div class="footer-note">
        Data source: <code>veridion_poc_results</code>
    </div>
</div>
</body>
</html>