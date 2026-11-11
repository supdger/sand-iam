<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

/** 用户组没有删除，只能停用。 */
final class IdentityGroupPolicy
{
    public static function deleteRefusal(): string
    {
        return '用户组不能删除，请改为停用';
    }
}
