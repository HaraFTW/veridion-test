<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbName = 'veridion';
$dbUser = 'root';
$dbPass = '';
$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    exit('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}

function getUnverified(PDO $pdo)
{
    $sql = "
        SELECT DISTINCT input_row_key, input_company_name, input_main_country_code, input_main_country, input_main_region, input_main_city
        FROM presales_data_sample
        WHERE verified = 0
        ORDER BY input_row_key
    ";

    $stmt = $pdo->query($sql);
    $value = $stmt->fetchAll();

    return $value;
}

function getCandidates(PDO $pdo, string $inputRowKey) {
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

$matchesCount = 0;
$matches = [];
$unverified = getUnverified($pdo);
if(!empty($unverified)) {
	foreach ($unverified as $u) {
		$candidates = getCandidates($pdo, $u['input_row_key']);
		foreach ($candidates as $c) {
			if($c['match_score'] >= 70) {
				$matchesCount++;
				$matches[] = [$u, $c];
			}
		}
	}
}
// var_dump($matchesCount);
echo '<pre>';
print_r($matches);
exit;

$checked = [];
$checked[] = $matches[0];
$checked[] = $matches[1];
$checked[] = $matches[4];
$checked[] = $matches[8];
$checked[] = $matches[9];


foreach ($checked as $match) {
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
            :veridion_id,
            :company_name,
            'matched',
			'medium',
			'bulk'
        )
    ";

    $insertStmt = $pdo->prepare($insertSql);
    $insertStmt->execute([
        'input_row_key' => $match[1]['input_row_key'] ?? null,
        'input_company_name' => $match[1]['input_company_name'] ?? null,
        'veridion_id' => $match[1]['veridion_id'] ?? null,
        'company_name' => $match[1]['company_name'] ?? null,
    ]);

    $updateSql = "
        UPDATE presales_data_sample
        SET verified = 1
        WHERE input_row_key = :input_row_key
    ";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        'input_row_key' => $match[1]['input_row_key'],
    ]);

    $pdo->commit();
}