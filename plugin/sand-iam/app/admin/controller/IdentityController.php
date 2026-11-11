<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\service\IdentityLifecycleService;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class IdentityController extends ApplicationResourceController
{
    protected string $modelClass = Identity::class;
    protected array $writeFields = ['application_id', 'code', 'display_name', 'status'];
    protected array $requiredFields = ['application_id', 'code', 'display_name'];
    protected string $resourceType = 'identity';
    protected ?string $keywordField = 'display_name';
    #[Permission('SandIAM 应用用户身份列表', 'sand_iam:identity:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 应用用户身份读取', 'sand_iam:identity:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 应用用户身份保存', 'sand_iam:identity:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 应用用户身份更新', 'sand_iam:identity:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 应用用户身份停用', 'sand_iam:identity:disable')]
    public function disable(Request $request): Response
    {
        if ((int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) !== 1) return parent::disable($request);
        $identity = $this->find($request);
        (new IdentityLifecycleService())->disable((int) $identity->id, (int) $identity->application_id, $this->actor($request), $this->requestId($request));
        return $this->success('应用用户已停用，现有登录和认证方式均已撤销');
    }
    #[Permission('SandIAM 应用用户删除', 'sand_iam:identity:delete')]
    public function delete(Request $request): Response
    {
        $this->enabled(); $identity = $this->find($request);
        (new IdentityLifecycleService())->delete((int) $identity->id, (int) $identity->application_id, $this->actor($request), $this->requestId($request));
        return $this->success('应用用户已进入删除保留期，现有登录和授权关系均已撤销');
    }
    #[Permission('SandIAM 应用用户启用', 'sand_iam:identity:enable')]
    public function enable(Request $request): Response
    {
        $identity = $this->find($request);
        if ((int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) !== 1) {
            // 生命周期开关关闭时走通用启用：仅允许 disabled → active 的双轨写回，
            // 与 disablePayload 的同步写保持对称，停用的用户可以重新启用。
            if ((string) ($identity->lifecycle_state ?? '') !== 'disabled') {
                throw new ApiException('SAND_IAM_IDENTITY_NOT_ENABLEABLE', 409);
            }
            $identity->save(['status' => 1, 'lifecycle_state' => 'active']);
            $this->audit('enable', (int) $identity->id, $request);
            return $this->success('已启用');
        }
        (new IdentityLifecycleService())->enable((int) $identity->id, (int) $identity->application_id, $this->actor($request), $this->requestId($request));
        return $this->success('应用用户已启用；停用前的会话不会恢复');
    }
    #[Permission('SandIAM 应用用户恢复', 'sand_iam:identity:restore')]
    public function restore(Request $request): Response
    {
        $this->enabled(); $identity = $this->find($request);
        (new IdentityLifecycleService())->restore((int) $identity->id, (int) $identity->application_id, $this->actor($request), $this->requestId($request));
        return $this->success('应用用户已恢复；旧会话、旧令牌和已移除的授权关系不会恢复');
    }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['application_id']) && !Application::where('id', (int) $payload['application_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属应用不存在或已停用', 400); }
    protected function formatIndexPage(array $page): array
    {
        $page['lifecycle_enabled'] = (int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) === 1;
        return $page;
    }
    /**
     * 生命周期开关关闭时走通用停用，但仍同步 lifecycle_state，
     * 与 delete/enable 的生命周期门禁对齐；否则列表按 lifecycle_state
     * 渲染会继续显示「正常」，出现提示停用成功却还能再停用的分叉。
     */
    protected function disablePayload(object $model): array
    {
        if ((string) ($model->lifecycle_state ?? '') === 'deleted') throw new ApiException('SAND_IAM_IDENTITY_NOT_DISABLEABLE: 该用户已删除，不能停用', 409);
        return ['status' => 2, 'lifecycle_state' => 'disabled'];
    }
    /**
     * status 与 lifecycle_state 双轨同步：直接改状态时同步生命周期，
     * deleted 记录不被普通编辑复活。
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function normalizePayload(array $payload, ?object $existing = null): array
    {
        $currentState = $existing !== null ? (string) ($existing->lifecycle_state ?? '') : '';
        if (array_key_exists('status', $payload) && $currentState !== 'deleted') {
            $payload['lifecycle_state'] = (int) $payload['status'] === 1 ? 'active' : 'disabled';
        }
        return $payload;
    }
    private function enabled(): void { if ((int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) !== 1) throw new ApiException('SAND_IAM_IDENTITY_LIFECYCLE_UNAVAILABLE', 503); }
    private function actor(Request $request): string { $token = $request->header('check_admin', []); return (string) (is_array($token) ? ($token['id'] ?? 0) : 0); }
    private function requestId(Request $request): string { return substr((string) $request->header('X-Request-Id', ''), 0, 96); }
}
