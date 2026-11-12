<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

/** 已停用、无成员且无下级组的用户组才能删除。 */
final class IdentityGroupPolicy
{
    /**
     * 返回带稳定错误码的白话拒绝原因，null 表示可以删除。
     * 判定顺序即页面置灰提示的优先级：先停用，再移成员，最后处理下级组。
     */
    public static function deleteRefusal(int $status, int $memberCount, int $childCount): ?string
    {
        if ($status !== 2) {
            return 'SAND_IAM_IDENTITY_GROUP_NOT_DELETABLE: 用户组还在启用中，先停用才能删除';
        }
        if ($memberCount > 0) {
            return 'SAND_IAM_IDENTITY_GROUP_HAS_MEMBERS: 组里还有成员，先移出成员再删除';
        }
        if ($childCount > 0) {
            return 'SAND_IAM_IDENTITY_GROUP_HAS_CHILDREN: 还有下级用户组，先删除下级组或改挂别处再删除';
        }
        return null;
    }
}
