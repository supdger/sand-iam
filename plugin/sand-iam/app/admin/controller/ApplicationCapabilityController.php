<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\service\ApplicationCapabilityService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\app\cache\UserAuthCache;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * B3 "一次聚合授权": two-click application capability directory. The UI only
 * ever sees Chinese capability names; environment, managed workload client,
 * audience, grants, and audit stay inside SandIAM.
 */
final class ApplicationCapabilityController extends BaseController
{
    #[Permission('应用功能授权查看', 'sand_iam:application_capability:index')]
    public function index(Request $request, string $application_id): Response
    {
        $data = (new ApplicationCapabilityService())->catalog(
            $this->applicationId($application_id),
            $this->access(),
            fn (): bool => $this->canSave(),
        );
        return $this->success($data);
    }

    #[Permission('应用功能授权管理', 'sand_iam:application_capability:save')]
    public function save(Request $request, string $application_id): Response
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 200 || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1) {
            throw new ApiException('SAND_IAM_CAPABILITY_IDEMPOTENCY_KEY_INVALID: 缺少或非法的 Idempotency-Key 请求头', 400);
        }
        $result = (new ApplicationCapabilityService())->save(
            $this->applicationId($application_id),
            $request->post(),
            $idempotencyKey,
            $this->adminId(),
            RequestId::fromRequest($request),
            $this->access(),
            fn (): bool => $this->canSave(),
        );
        $payload = $result['result'] + ['idempotent_replay' => $result['replayed']];
        if (($payload['status'] ?? '') === 'failed') {
            return json([
                'code' => 422,
                'message' => (string) ($payload['message'] ?? '授权失败'),
                'data' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)->withStatus(422);
        }
        // 信封 message 与业务 payload 一样使用中文；重放如实标注返回的是
        // 既有结果，成功/部分完成直接沿用 payload 自带的中文 message。
        return $this->success($payload, $result['replayed'] ? '请求已处理，返回既有结果' : (string) ($payload['message'] ?? '处理完成'));
    }

    /**
     * Route params arrive as strings; a bare (int) cast would silently
     * truncate '1abc' to 1. Require the full decimal id form instead — 18
     * digits keeps the value inside signed 64-bit range — and treat anything
     * else as a missing application.
     */
    private function applicationId(string $raw): int
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $raw) !== 1) {
            throw new ApiException('SAND_IAM_CAPABILITY_APPLICATION_INVALID: 应用编号不正确', 404);
        }
        return (int) $raw;
    }

    private function access(): AdminOrganizationAccess
    {
        $token = request()->header('check_admin', []);
        return new AdminOrganizationAccess(
            is_array($token) ? (int) ($token['id'] ?? 0) : 0,
            is_array($token) ? $token : null,
        );
    }

    private function adminId(): int
    {
        $token = request()->header('check_admin', []);
        return is_array($token) ? (int) ($token['id'] ?? 0) : 0;
    }

    /**
     * Secondary permission probe mirroring host CheckAuth: super admins pass,
     * others must hold the save slug in their cached auth list. The service
     * re-evaluates it inside the write lock.
     */
    private function canSave(): bool
    {
        $token = request()->header('check_admin', []);
        $adminId = is_array($token) ? (int) ($token['id'] ?? 0) : 0;
        if ($adminId === 1 || (int) ($token['is_super'] ?? 0) === 1) {
            return true;
        }
        return in_array('sand_iam:application_capability:save', UserAuthCache::getUserAuth($adminId), true);
    }
}
