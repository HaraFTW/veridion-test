<?php

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
    exit('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function progress(PDO $pdo): array 
{
    $progress = [];
    $sql = "
        SELECT COUNT(DISTINCT input_row_key) AS cnt
        FROM presales_data_sample
        WHERE verified = 0
    ";
    $stmt = $pdo->query($sql);
    $progress['remaining'] = $stmt->fetchColumn();

    $sql = "
        SELECT COUNT(DISTINCT input_row_key) AS cnt
        FROM presales_data_sample
        WHERE verified = 1
    ";
    $stmt = $pdo->query($sql);
    $progress['verified'] = $stmt->fetchColumn();    
    return $progress;   
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getNextUnverifiedInputRowKey(PDO $pdo): ?string
{
    $sql = "
        SELECT input_row_key
        FROM presales_data_sample
        WHERE verified = 0
        ORDER BY input_row_key
        LIMIT 1
    ";

    $stmt = $pdo->query($sql);
    $value = $stmt->fetchColumn();

    return $value === false ? null : (string) $value;
}

function getCandidateRows(PDO $pdo, string $inputRowKey): array
{
    $sql = "
        SELECT
            input_row_key,
            input_company_name,
            input_main_country,
            input_main_city,
            veridion_id,
            company_name,
            main_country,
            main_city,
            website_domain,
            website_url,
            linkedin_url,
            (
                CASE
                    WHEN (
                        LOWER(company_name) LIKE CONCAT('%', LOWER(input_company_name), '%')
                        OR LOWER(input_company_name) LIKE CONCAT('%', LOWER(company_name), '%')
                    ) THEN 40
                    ELSE 0
                END
                +
                CASE
                    WHEN LOWER(TRIM(input_main_country_code)) = LOWER(TRIM(main_country_code)) THEN 30
                    ELSE 0
                END
                +
                CASE
                    WHEN LOWER(TRIM(input_main_city)) = LOWER(TRIM(main_city)) THEN 20
                    ELSE 0
                END
                +
                CASE
                    WHEN website_url IS NOT NULL AND website_url <> '' THEN 5
                    ELSE 0
                END
                +
                CASE
                    WHEN linkedin_url IS NOT NULL AND linkedin_url <> '' THEN 5
                    ELSE 0
                END
            ) AS match_score,
            main_business_category,
            main_industry,
            last_updated_at
        FROM presales_data_sample
        WHERE input_row_key = :input_row_key
        ORDER BY match_score DESC, company_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'input_row_key' => $inputRowKey,
    ]);

    return $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| FORM SUBMIT
|--------------------------------------------------------------------------
*/

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputRowKey = trim($_POST['input_row_key'] ?? '');
    $inputCompanyName = trim($_POST['input_company_name'] ?? '');
    $selectedVeridionId = trim($_POST['selected_veridion_id'] ?? '');
    $selectedCompanyName = trim($_POST['selected_company_name'] ?? '');
    $decisionStatus = trim($_POST['decision_status'] ?? '');
    $confidenceLevel = trim($_POST['confidence_level'] ?? '');
    $reviewerNotes = trim($_POST['reviewer_notes'] ?? '');

    $allowedDecisionStatuses = ['matched', 'unmatched'];
    $allowedConfidenceLevels = ['high', 'medium', 'low'];

    if ($inputRowKey === '') {
        $error = 'Missing input_row_key.';
    } elseif ($inputCompanyName === '') {
        $error = 'Missing input_company_name.';
    } elseif (! in_array($decisionStatus, $allowedDecisionStatuses, true)) {
        $error = 'Invalid decision_status.';
    } elseif (! in_array($confidenceLevel, $allowedConfidenceLevels, true)) {
        $error = 'Invalid confidence_level.';
    } elseif ($decisionStatus === 'matched' && ($selectedVeridionId === '' || $selectedCompanyName === '')) {
        $error = 'For a matched decision, you must select a candidate row.';
    } elseif ($decisionStatus === 'unmatched') {
        // For unmatched, force matched fields to null/empty.
        $selectedVeridionId = '';
        $selectedCompanyName = '';
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $insertSql = "
                INSERT INTO veridion_poc_results
                (
                    input_row_key,
                    input_company_name,
                    selected_veridion_id,
                    selected_company_name,
                    decision_status,
                    confidence_level,
                    reviewer_notes
                )
                VALUES
                (
                    :input_row_key,
                    :input_company_name,
                    :selected_veridion_id,
                    :selected_company_name,
                    :decision_status,
                    :confidence_level,
                    :reviewer_notes
                )
            ";

            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                'input_row_key' => $inputRowKey,
                'input_company_name' => $inputCompanyName,
                'selected_veridion_id' => $selectedVeridionId !== '' ? $selectedVeridionId : null,
                'selected_company_name' => $selectedCompanyName !== '' ? $selectedCompanyName : null,
                'decision_status' => $decisionStatus,
                'confidence_level' => $confidenceLevel,
                'reviewer_notes' => $reviewerNotes !== '' ? $reviewerNotes : null,
            ]);

            $updateSql = "
                UPDATE presales_data_sample
                SET verified = 1
                WHERE input_row_key = :input_row_key
            ";

            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                'input_row_key' => $inputRowKey,
            ]);

            $pdo->commit();

            header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = 'Save failed: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = 'Saved successfully.';
}

/*
|--------------------------------------------------------------------------
| LOAD CURRENT GROUP
|--------------------------------------------------------------------------
*/

$currentInputRowKey = getNextUnverifiedInputRowKey($pdo);
$rows = [];

if ($currentInputRowKey !== null) {
    $rows = getCandidateRows($pdo, $currentInputRowKey);
}

$inputCompanyName = $rows[0]['input_company_name'] ?? '';
$inputMainCountry = $rows[0]['input_main_country'] ?? '';
$inputMainCity = $rows[0]['input_main_city'] ?? '';
$progress = progress($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veridion POC Review</title>
    <style>
        :root {
            --bg: #111827;
            --panel: #1f2937;
            --panel-2: #111827;
            --border: #374151;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --accent: #3b82f6;
            --accent-2: #1d4ed8;
            --success-bg: #052e16;
            --success-border: #166534;
            --success-text: #bbf7d0;
            --error-bg: #3f1111;
            --error-border: #7f1d1d;
            --error-text: #fecaca;
            --selected: #1e3a8a;
            --selected-border: #60a5fa;
            --input-bg: #0f172a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }

        h1, h2, h3 {
            margin-top: 0;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .message {
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
            border: 1px solid transparent;
        }

        .message.success {
            background: var(--success-bg);
            border-color: var(--success-border);
            color: var(--success-text);
        }

        .message.error {
            background: var(--error-bg);
            border-color: var(--error-border);
            color: var(--error-text);
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(220px, 1fr));
            gap: 12px;
        }

        .meta-box {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 12px;
        }

        .meta-label {
            display: block;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        th, td {
            border: 1px solid var(--border);
            padding: 10px;
            vertical-align: top;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #0b1220;
            color: #cbd5e1;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        tr.candidate-row {
            background: #18212f;
            cursor: pointer;
        }

        tr.candidate-row:hover {
            background: #223047;
        }

        tr.candidate-row.selected {
            background: var(--selected);
            outline: 2px solid var(--selected-border);
            outline-offset: -2px;
        }

        .small {
            font-size: 12px;
            color: var(--muted);
        }

        .score {
            font-weight: bold;
            font-size: 16px;
        }

        .score.high {
            color: #86efac;
        }

        .score.medium {
            color: #fde68a;
        }

        .score.low {
            color: #fca5a5;
        }

        form .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(280px, 1fr));
            gap: 16px;
        }

        .field {
            margin-bottom: 14px;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            color: #cbd5e1;
        }

        .field input[type="text"],
        .field textarea {
            width: 100%;
            background: var(--input-bg);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 14px;
        }

        .field input[readonly] {
            opacity: 0.95;
        }

        .field textarea {
            min-height: 120px;
            resize: vertical;
        }

        .radio-group {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            padding-top: 4px;
        }

        .radio-group label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 0;
            cursor: pointer;
        }

        .actions {
            margin-top: 18px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        button,
        .button-secondary {
            appearance: none;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--accent);
            color: white;
            padding: 10px 16px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }

        button:hover,
        .button-secondary:hover {
            background: var(--accent-2);
        }

        .button-secondary {
            background: #334155;
        }

        .button-secondary:hover {
            background: #475569;
        }

        .unmatched-box {
            margin-top: 12px;
            padding: 10px 12px;
            background: #0b1220;
            border: 1px solid var(--border);
            border-radius: 6px;
        }

        .empty-state {
            padding: 30px;
            text-align: center;
            color: var(--muted);
        }

        a {
            color: #93c5fd;
        }

        @media (max-width: 900px) {
            .meta-grid,
            form .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Veridion POC Review</h1>

    <?php if ($message !== ''): ?>
        <div class="message success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($currentInputRowKey === null || count($rows) === 0): ?>
        <div class="panel empty-state">
            <h2>All done</h2>
            <p>No more unverified rows were found.</p>
        </div>
    <?php else: ?>
        <div class="panel">
            <h2>Current Input Group</h2>
            <div class="meta-grid">
                <div class="meta-box">
                    <span class="meta-label">Input Row Key</span>
                    <div><?= h($currentInputRowKey) ?></div>
                </div>
                <div class="meta-box">
                    <span class="meta-label">Input Company</span>
                    <div><?= h($inputCompanyName) ?></div>
                </div>
                <div class="meta-box">
                    <span class="meta-label">Location</span>
                    <div><?= h($inputMainCity) ?>, <?= h($inputMainCountry) ?></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2>Candidate Matches</h2>

            <div class="unmatched-box">
                <label>
                    <input type="radio" name="selected_candidate_ui" value="" checked>
                    No row selected / unmatched
                </label>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Select</th>
                        <th>Match Score</th>
                        <th>Veridion ID</th>
                        <th>Company Name</th>
                        <th>Main Country</th>
                        <th>Main City</th>
                        <th>Website Domain</th>
                        <th>Website URL</th>
                        <th>LinkedIn</th>
                        <th>Business Category</th>
                        <th>Industry</th>
                        <th>Last Updated</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $index => $row): ?>
                        <?php
                        $score = (int) ($row['match_score'] ?? 0);
                        $scoreClass = 'low';

                        if ($score >= 70) {
                            $scoreClass = 'high';
                        } elseif ($score >= 40) {
                            $scoreClass = 'medium';
                        }
                        ?>
                        <tr
                            class="candidate-row"
                            data-veridion-id="<?= h($row['veridion_id']) ?>"
                            data-company-name="<?= h($row['company_name']) ?>"
                            data-radio-id="candidate_<?= $index ?>"
                        >
                            <td>
                                <input
                                    type="radio"
                                    name="selected_candidate_ui"
                                    id="candidate_<?= $index ?>"
                                    value="<?= h($row['veridion_id']) ?>"
                                    data-company-name="<?= h($row['company_name']) ?>"
                                    data-veridion-id="<?= h($row['veridion_id']) ?>"
                                >
                            </td>
                            <td><span class="score <?= h($scoreClass) ?>"><?= h((string) $score) ?></span></td>
                            <td><?= h($row['veridion_id']) ?></td>
                            <td><?= h($row['company_name']) ?></td>
                            <td><?= h($row['main_country']) ?></td>
                            <td><?= h($row['main_city']) ?></td>
                            <td><?= h($row['website_domain']) ?></td>
                            <td>
                                <?php if (! empty($row['website_url'])): ?>
                                    <a href="<?= h($row['website_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($row['website_url']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (! empty($row['linkedin_url'])): ?>
                                    <a href="<?= h($row['linkedin_url']) ?>" target="_blank" rel="noopener noreferrer">LinkedIn</a>
                                <?php endif; ?>
                            </td>
                            <td><?= h($row['main_business_category']) ?></td>
                            <td><?= h($row['main_industry']) ?></td>
                            <td><?= h($row['last_updated_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <h2>Review Form</h2>

            <form method="post" action="">
                <div class="form-grid">
                    <div>
                        <div class="field">
                            <label for="input_row_key">input_row_key</label>
                            <input
                                type="text"
                                id="input_row_key"
                                name="input_row_key"
                                value="<?= h($currentInputRowKey) ?>"
                                readonly
                            >
                        </div>

                        <div class="field">
                            <label for="input_company_name">input_company_name</label>
                            <input
                                type="text"
                                id="input_company_name"
                                name="input_company_name"
                                value="<?= h($inputCompanyName) ?>"
                                readonly
                            >
                        </div>

                        <div class="field">
                            <label for="selected_veridion_id">selected_veridion_id</label>
                            <input
                                type="text"
                                id="selected_veridion_id"
                                name="selected_veridion_id"
                                value=""
                                readonly
                            >
                        </div>

                        <div class="field">
                            <label for="selected_company_name">selected_company_name</label>
                            <input
                                type="text"
                                id="selected_company_name"
                                name="selected_company_name"
                                value=""
                                readonly
                            >
                        </div>
                    </div>

                    <div>
                        <div class="field">
                            <label>decision_status</label>
                            <div class="radio-group">
                                <label>
                                    <input type="radio" name="decision_status" value="matched">
                                    matched
                                </label>
                                <label>
                                    <input type="radio" name="decision_status" value="unmatched" checked>
                                    unmatched
                                </label>
                            </div>
                        </div>

                        <div class="field">
                            <label>confidence_level</label>
                            <div class="radio-group">
                                <label>
                                    <input type="radio" name="confidence_level" value="high" checked>
                                    high
                                </label>
                                <label>
                                    <input type="radio" name="confidence_level" value="medium">
                                    medium
                                </label>
                                <label>
                                    <input type="radio" name="confidence_level" value="low">
                                    low
                                </label>
                            </div>
                        </div>

                        <div class="field">
                            <label for="reviewer_notes">reviewer_notes</label>
                            <textarea id="reviewer_notes" name="reviewer_notes"></textarea>
                        </div>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit">Save and mark verified</button>
                    <a class="button-secondary" href="<?= h($_SERVER['PHP_SELF']) ?>">Refresh</a>
                </div>
            </form>
        </div>
    <?php endif; ?>
    <p>Remaining: <?=$progress['remaining']; ?></p>
    <p>Verified: <?=$progress['verified']; ?></p>
</div>

<script>
    (function () {
        const candidateRadios = document.querySelectorAll('input[name="selected_candidate_ui"]');
        const candidateRows = document.querySelectorAll('.candidate-row');
        const selectedVeridionIdInput = document.getElementById('selected_veridion_id');
        const selectedCompanyNameInput = document.getElementById('selected_company_name');
        const decisionStatusRadios = document.querySelectorAll('input[name="decision_status"]');

        function getCheckedDecisionStatus() {
            let value = '';

            decisionStatusRadios.forEach(function (radio) {
                if (radio.checked) {
                    value = radio.value;
                }
            });

            return value;
        }

        function setDecisionStatus(value) {
            decisionStatusRadios.forEach(function (radio) {
                radio.checked = (radio.value === value);
            });
        }

        function clearRowHighlights() {
            candidateRows.forEach(function (row) {
                row.classList.remove('selected');
            });
        }

        function syncSelectionUi() {
            clearRowHighlights();

            let checkedRadio = null;

            candidateRadios.forEach(function (radio) {
                if (radio.checked) {
                    checkedRadio = radio;
                }
            });

            if (!checkedRadio || checkedRadio.value === '') {
                selectedVeridionIdInput.value = '';
                selectedCompanyNameInput.value = '';
                setDecisionStatus('unmatched');
                return;
            }

            const row = checkedRadio.closest('tr');

            if (row) {
                row.classList.add('selected');
            }

            selectedVeridionIdInput.value = checkedRadio.dataset.veridionId || '';
            selectedCompanyNameInput.value = checkedRadio.dataset.companyName || '';

            if (getCheckedDecisionStatus() !== 'matched') {
                setDecisionStatus('matched');
            }
        }

        candidateRadios.forEach(function (radio) {
            radio.addEventListener('change', syncSelectionUi);
        });

        candidateRows.forEach(function (row) {
            row.addEventListener('click', function (event) {
                const clickedInsideLink = event.target.closest('a');
                const clickedRadio = event.target.closest('input[type="radio"]');

                if (clickedInsideLink) {
                    return;
                }

                const radioId = row.dataset.radioId;

                if (radioId && !clickedRadio) {
                    const radio = document.getElementById(radioId);

                    if (radio) {
                        radio.checked = true;
                        syncSelectionUi();
                    }
                }
            });
        });

        decisionStatusRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.value === 'unmatched' && radio.checked) {
                    const unmatchedRadio = document.querySelector('input[name="selected_candidate_ui"][value=""]');

                    if (unmatchedRadio) {
                        unmatchedRadio.checked = true;
                    }

                    syncSelectionUi();
                }
            });
        });

        syncSelectionUi();
    })();
</script>
</body>
</html>