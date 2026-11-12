<?php

declare(strict_types=1);

/**
 * 用户组不能删除：接口拒绝；验收口径要求页面不摆出删不掉的删除按钮，
 * 行内操作只保留停用，因此页面断言改为「无删除入口、有停用入口」。
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
ok(!str_contains($page, '删除用户组') && !str_contains($page, '/identity-group/delete'), 'page has no group delete entry');
ok(str_contains($page, '停用'), 'page offers disable instead of delete');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
