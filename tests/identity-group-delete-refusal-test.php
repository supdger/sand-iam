<?php

declare(strict_types=1);

/**
 * 用户组不能删除：接口拒绝，页面按钮置灰并写明原因。
 *
 * Usage: php tests/identity-group-delete-refusal-test.php
 */

require_once dirname(__DIR__) . '/plugin/sand-iam/app/admin/support/IdentityGroupPolicy.php';

use plugin\SandIam\app\admin\support\IdentityGroupPolicy;

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

$reason = '用户组不能删除，请改为停用';
ok(IdentityGroupPolicy::deleteRefusal() === $reason, 'refusal text is fixed');

$root = dirname(__DIR__);
$route = (string) file_get_contents($root . '/plugin/sand-iam/config/route.php');
$controller = (string) file_get_contents($root . '/plugin/sand-iam/app/admin/controller/IdentityGroupController.php');
$page = (string) file_get_contents($root . '/sandadmin-artd/src/views/plugin/sand-iam/identity-group/index.vue');
ok(str_contains($route, "'/identity-group/delete'"), 'delete route is registered');
ok(str_contains($controller, 'IdentityGroupPolicy::deleteRefusal()'), 'controller uses the refusal');
ok(str_contains($page, $reason), 'page shows the refusal on a disabled button');
ok(str_contains($page, 'disabled title="' . $reason . '"'), 'the delete button stays disabled');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
