<?php

declare(strict_types=1);

/**
 * 用户组删除：已停用、无成员且无下级组才可删。
 * 断言后端策略文案、删除链路（事务 + 软删 + 角色授权撤销）、
 * 页面常显删除入口的三种置灰提示、「删除后不能恢复」确认框样式，
 * 以及业务拒绝不再弹「服务器内部错误」全局提示（统一交给页面横幅呈现）。
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

// 策略：启用中拒绝，白话原因带「先停用」语义和稳定错误码。
$refusal = IdentityGroupPolicy::deleteRefusal(1, 0, 0);
ok($refusal !== null && str_contains($refusal, '先停用'), 'enabled group refusal says disable first');
ok($refusal !== null && str_contains($refusal, 'SAND_IAM_IDENTITY_GROUP_NOT_DELETABLE'), 'enabled refusal carries a stable code');

// 策略：有成员拒绝，白话原因带「先移出成员」语义。
$refusal = IdentityGroupPolicy::deleteRefusal(2, 3, 0);
ok($refusal !== null && str_contains($refusal, '先移出成员'), 'member refusal says remove members first');
ok($refusal !== null && str_contains($refusal, 'SAND_IAM_IDENTITY_GROUP_HAS_MEMBERS'), 'member refusal carries a stable code');

// 策略：还有下级组拒绝，避免删除后出现悬空上级；报文用产品定稿那句。
$refusal = IdentityGroupPolicy::deleteRefusal(2, 0, 2);
ok($refusal !== null && str_contains($refusal, '组里还有子组，先删掉子组或把它移到别的组下，再删除'), 'child refusal uses the product wording verbatim');
ok($refusal !== null && str_contains($refusal, 'SAND_IAM_IDENTITY_GROUP_HAS_CHILDREN'), 'child refusal carries a stable code');

// 策略：启用中与有成员同时成立时按「先停用」报，与页面悬停优先级一致。
$refusal = IdentityGroupPolicy::deleteRefusal(1, 5, 1);
ok($refusal !== null && str_contains($refusal, '先停用'), 'status refusal wins over members and children');

// 策略：停用且无成员、无下级允许删除。
ok(IdentityGroupPolicy::deleteRefusal(2, 0, 0) === null, 'disabled empty group is deletable');

$root = dirname(__DIR__);
$route = (string) file_get_contents($root . '/plugin/sand-iam/config/route.php');
$controller = (string) file_get_contents($root . '/plugin/sand-iam/app/admin/controller/IdentityGroupController.php');
$service = (string) file_get_contents($root . '/plugin/sand-iam/app/service/IdentityGroupService.php');
$contract = (string) file_get_contents($root . '/sandadmin-artd/src/views/plugin/sand-iam/api/identityLifecycleContracts.ts');
$errors = (string) file_get_contents($root . '/sandadmin-artd/src/views/plugin/sand-iam/api/errors.ts');
$page = (string) file_get_contents($root . '/sandadmin-artd/src/views/plugin/sand-iam/identity-group/index.vue');

ok(str_contains($route, "'/identity-group/delete'"), 'delete route is registered');
ok(str_contains($controller, 'IdentityGroupService())->delete('), 'controller delegates delete to the service');
ok(str_contains($controller, "'sand_iam:identity_group:disable'"), 'delete reuses the disable permission code');
ok(str_contains($controller, "'member_count'") && str_contains($controller, "'child_count'"), 'list payload carries member and child counts');

ok(str_contains($service, 'IdentityGroupPolicy::deleteRefusal'), 'service enforces the refusal policy');
ok(str_contains($service, 'Db::startTrans()') && str_contains($service, 'Db::commit()'), 'delete runs inside a transaction');
ok(str_contains($service, '$group->delete()'), 'service soft-deletes the group row');
ok(str_contains($service, 'IdentityGroupRole::'), 'service revokes remaining group role bindings');
ok(str_contains($service, "'identity_group.delete'"), 'delete writes an audit entry');

ok(str_contains($contract, 'groupDeleteRefusal'), 'contract exposes the shared refusal helper');
ok(str_contains($contract, 'child_count'), 'contract parses the child count');
ok(str_contains($contract, '先停用才能删除'), 'contract refusal says disable first');
ok(str_contains($contract, '先移出成员'), 'contract refusal says remove members first');
ok(str_contains($contract, '组里还有子组，先删掉子组或把它移到别的组下，再删除'), 'contract refusal uses the product wording verbatim');

ok(str_contains($errors, 'SAND_IAM_IDENTITY_GROUP_NOT_DELETABLE'), 'error catalog translates the new refusal code');
// 拒删报文带「CODE: 中文原因」时，详情原样显示后端那句，与悬停文案同一句。
ok(str_contains($errors, 'SAND_IAM_IDENTITY_GROUP_HAS_CHILDREN') && str_contains($errors, 'lastIndexOf(marker)'), 'error detail reuses the backend refusal sentence');

ok(str_contains($page, 'identity-group/delete'), 'page posts to the delete endpoint');
ok(str_contains($page, 'ElTooltip') && str_contains($page, 'groupDeleteRefusal'), 'page disables delete with a hover reason');
ok(str_contains($page, '删除后不能恢复'), 'confirm dialog warns deletion cannot be undone');
ok(str_contains($page, '删除「${row.name}」？'), 'confirm dialog title names the group');
ok(str_contains($page, "confirmButtonText: '删除'") && str_contains($page, "cancelButtonText: '再想想'"), 'confirm buttons match the shared dialog style');
ok(str_contains($page, "confirmButtonClass: 'el-button--danger'"), 'confirm button is rendered as danger');
ok(str_contains($page, '已删除'), 'delete success refreshes with a named toast');

// 业务拒绝返回真实 HTTP 4xx，共享请求层的全局提示会把 409 弹成「服务器内部错误」；
// 本目录 api 封装默认关掉它，错误统一交给页面用 describeSandIamError 呈现。
$write = (string) file_get_contents($root . '/sandadmin-artd/src/views/plugin/sand-iam/api/write.ts');
$list = (string) file_get_contents($root . '/sandadmin-artd/src/views/plugin/sand-iam/api/resource.ts');
ok(substr_count($write, 'showErrorMessage = false') >= 5, 'write api defaults suppress the global error toast');
ok(str_contains($write, 'showErrorMessage: false'), 'form uploads suppress the global error toast');
ok(substr_count($list, 'showErrorMessage = false') >= 1, 'list api default suppresses the global error toast');
ok(substr_count($list, 'showErrorMessage: false') >= 1, 'grant candidate reads suppress the global error toast');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
