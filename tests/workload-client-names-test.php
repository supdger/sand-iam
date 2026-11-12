<?php

declare(strict_types=1);

/**
 * 服务授权和调用凭证的列表行要带上服务调用身份名称。
 *
 * Usage: php tests/workload-client-names-test.php
 */

require_once dirname(__DIR__) . '/plugin/sand-iam/app/admin/support/WorkloadClientNames.php';

use plugin\SandIam\app\admin\support\WorkloadClientNames;

$passed = 0;
$failed = 0;

function ok(bool $condition, string $name): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
}

$rows = WorkloadClientNames::apply(
    [
        ['id' => 1, 'workload_client_id' => 4],
        ['id' => 2, 'workload_client_id' => 9],
        ['id' => 3, 'workload_client_id' => 0],
        'skip',
    ],
    [4 => '托管调用身份', 9 => '']
);

ok(is_array($rows[0]) && $rows[0]['workload_client_name'] === '托管调用身份', 'known client keeps its name');
ok(is_array($rows[1]) && $rows[1]['workload_client_name'] === null, 'blank name stays empty');
ok(is_array($rows[2]) && $rows[2]['workload_client_name'] === null, 'missing client id stays empty');
ok($rows[3] === 'skip', 'non-row is left untouched');

echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
