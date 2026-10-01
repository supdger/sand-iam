<?php

declare(strict_types=1);

// behavior-test-gate: static-rule
$root = dirname(__DIR__, 3);
require_once $root . '/tools/check-existing-schema.php';
require_once $root . '/tools/package-payload-policy.php';
sandIamCheckExistingSchema($root);
echo "[PASS] current declaration matches package and exact published ledger\n";
if (!in_array('existing-schema.json', sandIamPayloadFiles($root, true), true)) {
    throw new RuntimeException('Declaration omitted from authoritative payload');
}
echo "[PASS] declaration included by shared release payload policy\n";
$temp = sys_get_temp_dir() . '/iam-schema-contract-' . bin2hex(random_bytes(5));
mkdir($temp, 0700);
$files = ['existing-schema.json', 'info.ini', 'install.sql', 'plugin/sand-iam/info.ini',
    'plugin/sand-iam/install.sql', 'plugin/sand-iam/migrations/042_permission_menu_hierarchy.pgsql',
    'lifecycle/existing-schema-embedded-073.json', 'lifecycle/update-073-or-075-to-076-preflight.pgsql'];
try {
    foreach ($files as $path) {
        if (!is_dir(dirname($temp . '/' . $path))) mkdir(dirname($temp . '/' . $path), 0700, true);
        copy($root . '/' . $path, $temp . '/' . $path);
    }
    $original = file_get_contents($temp . '/existing-schema.json');
    foreach (['version' => '0.7.3', 'install_sql_sha256' => str_repeat('0', 64), 'ledger_rows' => 42,
        'ledger_sha256' => str_repeat('0', 64), 'catalog_schema_sha256' => str_repeat('0', 64)] as $field => $value) {
        $manifest = json_decode($original, true, 16, JSON_THROW_ON_ERROR);
        $manifest[$field] = $value;
        file_put_contents($temp . '/existing-schema.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $rejected = false;
        try { sandIamCheckExistingSchema($temp); } catch (RuntimeException) { $rejected = true; }
        if (!$rejected) throw new RuntimeException('Accepted stale/mutated ' . $field);
        echo "[PASS] rejected stale/mutated {$field}\n";
    }
} finally {
    foreach ($files as $path) unlink($temp . '/' . $path);
    foreach (['plugin/sand-iam/migrations', 'plugin/sand-iam', 'plugin', 'lifecycle', ''] as $directory) {
        rmdir($temp . ($directory === '' ? '' : '/' . $directory));
    }
}
