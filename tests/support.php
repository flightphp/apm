<?php

declare(strict_types=1);

use flight\apm\presenter\SqlitePresenter;
use flight\database\PdoWrapper;

function apm_migrate_sqlite(PDO $pdo): void
{
    $files = glob(__DIR__ . '/../src/apm/migration/sqlite/dest/*.sql');
    sort($files);
    foreach ($files as $file) {
        $pdo->exec(file_get_contents($file));
    }
}

function apm_test_presenter(): array
{
    $pdo = new PdoWrapper('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    apm_migrate_sqlite($pdo);
    $presenter = new SqlitePresenter($pdo, ['apm' => []]);
    return [$pdo, $presenter];
}
