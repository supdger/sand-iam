<?php

declare(strict_types=1);

/**
 * Offline package declaration gate. A pass never proves a database can attach.
 * The structure fingerprint is the read-only captured embedded073 baseline:
 * revision042 changes system menus and ledger rows, not sand_iam structures.
 */
function sandIamCheckExistingSchema(string $root): void
{
    $manifest = json_decode((string) file_get_contents($root . '/existing-schema.json'), true, 16, JSON_THROW_ON_ERROR);
    $legacy = json_decode((string) file_get_contents($root . '/lifecycle/existing-schema-embedded-073.json'), true, 16, JSON_THROW_ON_ERROR);
    $info = parse_ini_file($root . '/info.ini');
    $pluginInfo = parse_ini_file($root . '/plugin/sand-iam/info.ini');
    if ($manifest['app'] !== 'sand-iam' || $manifest['format'] !== 1
        || $manifest['version'] !== ($info['version'] ?? null)
        || $manifest['version'] !== ($pluginInfo['version'] ?? null)
        || $manifest['install_sql_sha256'] !== hash_file('sha256', $root . '/install.sql')
        || $manifest['install_sql_sha256'] !== hash_file('sha256', $root . '/plugin/sand-iam/install.sql')) {
        throw new RuntimeException('existing-schema package identity or SQL digest mismatch');
    }
    $preflightFile = ($info['version'] ?? '') === '0.8.0'
        ? '/lifecycle/update-073-or-075-or-076-to-080-preflight.pgsql'
        : '/lifecycle/update-073-or-075-to-076-preflight.pgsql';
    $preflight = (string) file_get_contents($root . $preflightFile);
    preg_match_all("/\\('([0-9]{3}_[^']+\\.pgsql)', (\\d+), '([a-f0-9]{64})', '([^']+)'\\)/", $preflight, $matches, PREG_SET_ORDER);
    $rows = [];
    foreach ($matches as $match) {
        if (isset($rows[$match[1]])) throw new RuntimeException('Duplicate expected migration');
        $rows[$match[1]] = implode('|', array_slice($match, 1)) . "\n";
    }
    ksort($rows, SORT_STRING);
    if (count($rows) !== 43 || $manifest['ledger_rows'] !== count($rows)
        || $manifest['ledger_table'] !== 'sand_iam_schema_migration'
        || $manifest['ledger_sha256'] !== hash('sha256', implode('', $rows))) {
        throw new RuntimeException('existing-schema published073 ledger mismatch');
    }
    $without042 = $rows;
    unset($without042['042_permission_menu_hierarchy.pgsql']);
    if (count($without042) !== 42 || $legacy['version'] !== '0.7.3' || $legacy['ledger_rows'] !== 42
        || $legacy['ledger_sha256'] !== hash('sha256', implode('', $without042))) {
        throw new RuntimeException('embedded073 ledger is not the exact published073 predecessor');
    }
    $migration = (string) file_get_contents($root . '/plugin/sand-iam/migrations/042_permission_menu_hierarchy.pgsql');
    if (preg_match('/\b(?:CREATE|ALTER|DROP)\s+(?:TABLE|INDEX|SEQUENCE|VIEW|TRIGGER|FUNCTION)\b/i', $migration) === 1) {
        throw new RuntimeException('revision042 changed structure; recapture reviewed catalog fingerprint');
    }
    foreach (['table_count', 'table_names_sha256', 'catalog_schema_sha256'] as $field) {
        if ($manifest[$field] !== $legacy[$field]) throw new RuntimeException('Structural baseline changed without capture');
    }
    if ($manifest['table_count'] !== 86
        || $manifest['table_names_sha256'] !== '5feaa153988f0b1e05a04e6c91192c8ef19d9ee8fb7b4bf51aa8350bd369ea88'
        || $manifest['catalog_schema_sha256'] !== '3ba37e63adace3e7950cdd1f435010216d351929c27662a24ae55eedadc6821e') {
        throw new RuntimeException('Reviewed read-only structure fingerprint mismatch');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    sandIamCheckExistingSchema(dirname(__DIR__));
    echo "[PASS] package version / SQL / 43-row ledger / structural baseline; database attach remains separate\n";
}
