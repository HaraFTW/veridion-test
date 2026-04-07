<?php

$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbName = 'veridion';
$dbUser = 'root';
$dbPass = '';
$db = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
try {
    $pdo = new PDO($db, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    exit("DB connection failed: " . $e->getMessage() . "\n");
}

$tableName = 'presales_data_sample';

$csvFile = 'presales_data_sample.csv';

$handle = fopen($csvFile, 'r');
$originalHeaders = fgetcsv($handle);

// CREATE TABLE //
foreach ($originalHeaders as $header) {
    $name = trim($header);
    $headers[] = $name;
}

$pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");

$columnsSql = [];
$columnsSql[] = "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY";
foreach ($headers as $header) {
    $columnsSql[] = "`{$header}` LONGTEXT NULL";
}

$createTableSql = "
    CREATE TABLE `{$tableName}` (
        " . implode(",\n        ", $columnsSql) . "
    ) ENGINE = InnoDB
      DEFAULT CHARSET = utf8mb4
      COLLATE = utf8mb4_unicode_ci
";

$pdo->exec($createTableSql);
// CREATE TABLE //

// INSERT ROWS //
$placeholders = implode(', ', array_fill(0, count($headers), '?'));
$insertColumns = '`' . implode('`, `', $headers) . '`';

$insertSql = "
    INSERT INTO `{$tableName}` ({$insertColumns})
    VALUES ({$placeholders})
";

$stmt = $pdo->prepare($insertSql);

$rowCount = 0;
$pdo->beginTransaction();

try {
    while (($row = fgetcsv($handle)) !== false) {
        // Ensure row has same number of columns as header
        $row = array_pad($row, count($headers), null);

        if (count($row) > count($headers)) {
            $row = array_slice($row, 0, count($headers));
        }

        $stmt->execute($row);
        $rowCount++;

        // Commit in batches of 500 rows
        if ($rowCount % 500 === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fclose($handle);
    exit("Error on row {$rowCount}: " . $e->getMessage() . "\n");
}

fclose($handle);
// INSERT ROWS //