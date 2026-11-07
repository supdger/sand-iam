<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\admin\support\AccessMatrixInput;
use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\model\ServiceGrant;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\sandadmin\exception\ApiException;
use support\Log;
use think\facade\Db;

/**
 * SandIAM B3 "一次聚合授权": the two-click application capability directory.
 *
 * GET lists the visible capability catalog with the current grant state; POST
 * replaces the whole desired set for that catalog inside one serializable
 * transaction guarded by pg_advisory_xact_lock and an opaque revision. The
 * managed binding is one workload client (code `managed`) per environment;
 * disabled rows are never resurrected and no credential material is issued,
 * returned, or persisted here.
 */
final class ApplicationCapabilityService
{
    private const POLICY_VERSION = 'application-capability.v1';
    private const OPERATION = 'application_capability.save';
    private const MANAGED_CLIENT_CODE = 'managed';
    private const MANAGED_CLIENT_NAME = '托管调用身份';
    private const FILTER_LOG_TTL_SECONDS = 3600;
    private const MAX_ACTION_NAME = 128;

    /** @var array<string, int> */
    private static array $filterLogAt = [];

    /**
     * @param callable():bool $canSave save-permission probe evaluated by the caller
     * @return array<string,mixed>
     */
    public function catalog(int $applicationId, AdminOrganizationAccess $access, callable $canSave): array
    {
        [$application, $organization] = $this->requireApplication($applicationId, $access);
        $snapshot = $this->snapshot((int) $application->id, (int) $organization->id);
        $binding = $this->resolveBinding((int) $application->id, false);
        $items = [];
        foreach ($snapshot['catalog'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'description' => $item['description'],
                'group_name' => $item['group_name'],
                'granted' => $this->isGranted($item, $binding),
            ];
        }
        return [
            'application' => ['id' => (int) $application->id, 'name' => (string) $application->name],
            'revision' => $snapshot['revision'],
            'can_modify' => (bool) $canSave(),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param callable():bool $canSave re-evaluated inside the write lock
     * @return array{result:array<string,mixed>,replayed:bool}
     */
    public function save(int $applicationId, array $input, string $idempotencyKey, int $adminId, string $requestId, AdminOrganizationAccess $access, callable $canSave): array
    {
        if (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 200 || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1) {
            throw new ApiException('SAND_IAM_CAPABILITY_IDEMPOTENCY_KEY_INVALID: 缺少或非法的 Idempotency-Key 请求头', 400);
        }
        [$application, $organization] = $this->requireApplication($applicationId, $access);
        if (!$canSave()) {
            throw new ApiException('SAND_IAM_CAPABILITY_PERMISSION_DENIED: 当前账号没有管理应用功能授权的权限', 403);
        }
        [$desiredIds, $expectedRevision] = $this->normalizeInput($input);

        $actorRef = $adminId . ':' . (int) $organization->id . ':' . (int) $application->id;
        $receiptId = 'cap_' . substr(hash('sha256', 'sand-iam-capability:' . $idempotencyKey), 0, 56);
        // Contract fingerprint: normalized capability ids + expected revision +
        // directory/policy version. Catalog content is deliberately excluded so
        // a metadata refresh cannot turn a legitimate replay into a conflict.
        $fingerprint = IdempotencyService::fingerprint([
            'capability_ids' => $desiredIds,
            'expected_revision' => $expectedRevision,
            'policy' => self::POLICY_VERSION,
        ]);
        $operations = new IdempotencyService();
        $completedReplay = $operations->waitForCompletedReplay('admin', $actorRef, self::OPERATION, $receiptId, $fingerprint);
        if ($completedReplay !== null) {
            return ['result' => $this->stripReplayKeys($completedReplay['result']), 'replayed' => true];
        }

        $previousIsolation = $this->beginSerializableTransaction();
        try {
            [$left, $right] = OnboardingService::advisoryKey((int) $organization->id, (int) $application->id);
            Db::query('SELECT pg_advisory_xact_lock(?, ?)', [$left, $right]);
            $result = $operations->execute('admin', $actorRef, self::OPERATION, $receiptId, $fingerprint, 'application', function () use ($applicationId, $organization, $application, $desiredIds, $expectedRevision, $adminId, $requestId, $receiptId, $access, $canSave): array {
                return $this->applyInsideTransaction(
                    (int) $applicationId,
                    (int) $organization->id,
                    (int) $application->id,
                    $desiredIds,
                    $expectedRevision,
                    $adminId,
                    $requestId,
                    $receiptId,
                    $access,
                    $canSave,
                );
            }, 0, false);
            Db::commit();
            return ['result' => $this->stripReplayKeys($result['result']), 'replayed' => $result['replayed']];
        } catch (ApiException $exception) {
            Db::rollback();
            $completedReplay = $operations->waitForCompletedReplay('admin', $actorRef, self::OPERATION, $receiptId, $fingerprint, 20);
            if ($completedReplay !== null) {
                return ['result' => $this->stripReplayKeys($completedReplay['result']), 'replayed' => true];
            }
            if (str_starts_with($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED')) {
                $replay = $operations->replay('admin', $actorRef, self::OPERATION, $receiptId, $fingerprint);
                return ['result' => $this->stripReplayKeys($replay['result']), 'replayed' => $replay['replayed']];
            }
            throw $exception;
        } catch (\Throwable $exception) {
            Db::rollback();
            $completedReplay = $operations->waitForCompletedReplay('admin', $actorRef, self::OPERATION, $receiptId, $fingerprint, 20);
            if ($completedReplay !== null) {
                return ['result' => $this->stripReplayKeys($completedReplay['result']), 'replayed' => true];
            }
            if ($this->isSerializationFailure($exception)) {
                throw new ApiException('SAND_IAM_CAPABILITY_REVISION_CONFLICT: 并发授权变更导致修订失效，请刷新后重试', 409);
            }
            throw $exception;
        } finally {
            $this->restoreTransactionIsolation($previousIsolation);
        }
    }

    /** @return array{resource_id:int,result:array<string,mixed>} */
    private function applyInsideTransaction(int $applicationId, int $organizationId, int $appId, array $desiredIds, string $expectedRevision, int $adminId, string $requestId, string $auditScope, AdminOrganizationAccess $access, callable $canSave): array
    {
        // Audit request ids derive from the client request id AND the
        // idempotency receipt: two saves sharing one X-Request-Id under
        // different Idempotency-Key values must not collide on the audit
        // (request_id, action, event_key) uniqueness key, while a same-key
        // replay returns the stored result before reaching this code path
        // and therefore writes no duplicate audit. Failed attempts roll back
        // with the transaction, so a retried execution cannot double-write.
        $requestId .= "\0" . $auditScope;
        // Re-verify scope and permission inside the write lock; the pre-checks
        // outside the transaction are only a fast path.
        $application = Application::where('id', $appId)->where('organization_id', $organizationId)->where('status', 1)->lock(true)->find();
        $organization = Organization::where('id', $organizationId)->where('status', 1)->lock(true)->find();
        if ($application === null || $organization === null) {
            throw new ApiException('SAND_IAM_CAPABILITY_APPLICATION_MISSING: 应用不存在或已停用', 404);
        }
        if (!$access->isSuperAdmin() && !in_array($appId, $access->applicationIds(), true)) {
            throw new ApiException('SAND_IAM_APPLICATION_ACCESS_DENIED: 当前账号未获授该接入应用的管理范围', 403);
        }
        if (!$canSave()) {
            throw new ApiException('SAND_IAM_CAPABILITY_PERMISSION_DENIED: 当前账号没有管理应用功能授权的权限', 403);
        }

        $snapshot = $this->snapshot($appId, $organizationId);
        if (!hash_equals($snapshot['revision'], $expectedRevision)) {
            throw new ApiException('SAND_IAM_CAPABILITY_REVISION_STALE: 授权目录已变化，请刷新后重试', 409);
        }

        $binding = $this->resolveBinding($appId, true);
        $catalog = $snapshot['catalog'];
        $byId = [];
        foreach ($catalog as $item) {
            $byId[$item['id']] = $item;
        }
        // Strict input validation: every submitted id must be a visible,
        // manageable catalog entry; duplicates and unknown ids reject the call.
        $submitted = [];
        foreach ($desiredIds as $rawId) {
            if (preg_match('/^[1-9][0-9]{0,18}$/D', (string) $rawId) !== 1 || !isset($byId[(string) $rawId])) {
                throw new ApiException('SAND_IAM_CAPABILITY_UNKNOWN: 提交的功能不在可管理目录中，请刷新后重试', 422);
            }
            $id = (string) $rawId;
            if (isset($submitted[$id])) {
                throw new ApiException('SAND_IAM_CAPABILITY_DUPLICATE: 功能列表包含重复项', 422);
            }
            $submitted[$id] = true;
        }

        $items = [];
        $changed = 0;
        $failed = 0;
        $bindingConflict = $binding['conflict'];
        // Deterministic processing order keeps multi-service audience claims stable.
        usort($catalog, static fn (array $a, array $b): int => $a['action_id'] <=> $b['action_id']);
        foreach ($catalog as $item) {
            $id = $item['id'];
            $desired = isset($submitted[$id]);
            $current = $this->isGranted($item, $binding);
            if ($bindingConflict !== null) {
                $items[] = $this->itemResult($item, $desired ? 'grant' : 'revoke', false, $bindingConflict);
                $failed++;
                continue;
            }
            if ($desired === $current) {
                $items[] = $this->itemResult($item, 'unchanged', true, '');
                continue;
            }
            $operation = $desired ? 'grant' : 'revoke';
            $outcome = $desired
                ? $this->applyGrant($item, $binding, $appId, $organizationId, $adminId, $requestId)
                : $this->applyRevoke($item, $binding, $appId, $organizationId, $adminId, $requestId);
            if ($outcome['ok']) {
                $changed++;
                $items[] = $this->itemResult($item, $operation, true, '');
            } else {
                $failed++;
                $items[] = $this->itemResult($item, $operation, false, $outcome['message']);
            }
        }

        $status = $failed === 0 ? 'success' : ($changed > 0 ? 'partial' : 'failed');
        $message = match ($status) {
            'success' => '已按所选功能完成授权',
            'partial' => '部分功能授权未完成，请查看明细',
            default => '本次授权未生效，请查看明细',
        };
        $postSnapshot = $this->snapshot($appId, $organizationId);
        $grantedCount = 0;
        $postBinding = $this->resolveBinding($appId, false);
        foreach ($postSnapshot['catalog'] as $postItem) {
            if ($this->isGranted($postItem, $postBinding)) {
                $grantedCount++;
            }
        }
        $writer = new AuditWriter();
        $writer->write('admin', (string) $adminId, $organizationId, $appId, 'application_capability.save', 'application', $appId, $failed === 0 ? 'succeeded' : 'failed', $this->childRequestId($requestId, 'summary'), [
            'status' => $status,
            'desired_count' => count($submitted),
            'changed_count' => $changed,
            'failed_count' => $failed,
            'granted_count' => $grantedCount,
        ]);
        return [
            'resource_id' => $appId,
            'result' => [
                'application_id' => $appId,
                'revision' => $postSnapshot['revision'],
                'status' => $status,
                'message' => $message,
                'items' => $items,
                'granted_count' => $grantedCount,
            ],
        ];
    }

    /** @return array{0:list<string>,1:string} */
    private function normalizeInput(array $input): array
    {
        $rawIds = $input['capability_ids'] ?? null;
        $expected = $input['expected_revision'] ?? null;
        if (!is_array($rawIds) || !array_is_list($rawIds)
            || !is_string($expected) || trim($expected) === '' || mb_strlen($expected) > 128) {
            throw new ApiException('SAND_IAM_CAPABILITY_INPUT_INVALID: 请求体必须包含 capability_ids 列表与 expected_revision', 422);
        }
        $ids = [];
        foreach ($rawIds as $raw) {
            if (!is_string($raw) && !is_int($raw)) {
                throw new ApiException('SAND_IAM_CAPABILITY_INPUT_INVALID: capability_ids 只能包含功能编号', 422);
            }
            $id = trim((string) $raw);
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1) {
                throw new ApiException('SAND_IAM_CAPABILITY_INPUT_INVALID: 功能编号格式不正确', 422);
            }
            $ids[] = $id;
        }
        sort($ids, SORT_STRING);
        return [$ids, trim($expected)];
    }

    /**
     * @return array{catalog:list<array<string,mixed>>,catalog_fingerprint:string,revision:string}
     */
    private function snapshot(int $applicationId, int $organizationId): array
    {
        $catalog = $this->visibleCatalog();
        $catalogRows = [];
        foreach ($catalog as $item) {
            $catalogRows[] = [
                $item['action_id'],
                $item['name'],
                $item['description'],
                $item['group_name'],
                $item['service_code'],
                $item['service_audience'],
            ];
        }
        usort($catalogRows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $catalogFingerprint = hash('sha256', $this->canonical([
            'policy' => self::POLICY_VERSION,
            'catalog' => $catalogRows,
        ]));

        $environments = Environment::where('application_id', $applicationId)->order('id')->select()->toArray();
        $environmentIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $environments));
        $clients = $environmentIds === [] ? [] : WorkloadClient::where('code', self::MANAGED_CLIENT_CODE)
            ->whereIn('environment_id', $environmentIds)->order('id')->select()->toArray();
        $clientIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $clients));
        $grants = $clientIds === [] ? [] : ServiceGrant::whereIn('workload_client_id', $clientIds)
            ->where('status', 1)->whereNull('revoked_time')
            ->order('service_action_id')->order('audience')->order('workload_client_id')
            ->select()->toArray();
        $grantRows = [];
        foreach ($grants as $grant) {
            $grantRows[] = [(int) $grant['service_action_id'], (string) $grant['audience'], (int) $grant['workload_client_id']];
        }
        $clientRows = [];
        foreach ($clients as $client) {
            $clientRows[] = [(int) $client['id'], (int) $client['environment_id'], (string) $client['audience'], (int) $client['status']];
        }
        $environmentRows = [];
        foreach ($environments as $environment) {
            $environmentRows[] = [(int) $environment['id'], (string) $environment['code'], (int) $environment['status']];
        }
        $revision = 'cr1_' . substr(hash('sha256', $this->canonical([
            'v' => 1,
            'organization_id' => $organizationId,
            'application_id' => $applicationId,
            'catalog' => $catalogFingerprint,
            'environments' => $environmentRows,
            'managed_clients' => $clientRows,
            'grants' => $grantRows,
        ])), 0, 48);
        return ['catalog' => $catalog, 'catalog_fingerprint' => $catalogFingerprint, 'revision' => $revision];
    }

    /**
     * Visible/manageable catalog: active services and actions whose Chinese
     * name, description, and resolved group are all effective. Invalid rows
     * are filtered with a rate-limited redacted log (action id + reason only);
     * existing grants on filtered actions are never touched.
     *
     * @return list<array<string,mixed>>
     */
    private function visibleCatalog(): array
    {
        $services = Service::where('status', 1)->order('id')->select()->toArray();
        $serviceById = [];
        foreach ($services as $service) {
            $serviceById[(int) $service['id']] = $service;
        }
        $actions = ServiceAction::where('status', 1)->order('id')->select()->toArray();
        $items = [];
        foreach ($actions as $action) {
            $service = $serviceById[(int) $action['service_id']] ?? null;
            if ($service === null) {
                continue;
            }
            $name = trim((string) $action['name']);
            $description = trim((string) ($action['description'] ?? ''));
            $groupName = $this->resolveGroupName($service);
            // Contract §3.1: the Chinese name, description, and a resolvable
            // group are all mandatory; rows missing any of them are filtered
            // out of the directory and never fall back to the raw code.
            $reason = $this->filterReason($name, self::MAX_ACTION_NAME)
                ?? $this->filterReason($description, 512)
                ?? ($groupName === null ? 'group' : null);
            if ($reason !== null) {
                $this->logFiltered((int) $action['id'], $reason);
                continue;
            }
            $items[] = [
                'id' => (string) $action['id'],
                'action_id' => (int) $action['id'],
                'service_id' => (int) $service['id'],
                'service_code' => (string) $service['code'],
                'service_audience' => is_string($service['audience'] ?? null) ? trim((string) $service['audience']) : '',
                'name' => $name,
                'description' => $description,
                'group_name' => (string) $groupName,
            ];
        }
        return $items;
    }

    private function resolveGroupName(array $service): ?string
    {
        foreach ([$service['group_name'] ?? null, $service['name'] ?? null] as $candidate) {
            $value = is_string($candidate) ? trim($candidate) : '';
            if ($value !== '' && $this->isEffectiveChinese($value, self::MAX_ACTION_NAME)) {
                return $value;
            }
        }
        return null;
    }

    private function filterReason(string $value, int $maxLength): ?string
    {
        if ($value === '') return 'empty';
        if (mb_strlen($value) > $maxLength) return 'too_long';
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) return 'control_chars';
        if (!$this->isEffectiveChinese($value, $maxLength)) return 'not_chinese';
        return null;
    }

    private function isEffectiveChinese(string $value, int $maxLength): bool
    {
        return mb_strlen($value) <= $maxLength
            && preg_match('/\p{Han}/u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function logFiltered(int $actionId, string $reason): void
    {
        $key = $actionId . '|' . $reason;
        $now = time();
        if (isset(self::$filterLogAt[$key]) && $now - self::$filterLogAt[$key] < self::FILTER_LOG_TTL_SECONDS) {
            return;
        }
        self::$filterLogAt[$key] = $now;
        Log::warning('SandIAM capability directory filtered action', [
            'action_id' => $actionId,
            'reason' => $reason,
        ]);
    }

    /**
     * Read-only managed-binding resolution. Exactly one bound environment or
     * one active environment is reused; zero candidates resolve to the
     * controlled prod default that POST may create; anything else is a
     * conflict and never falls back to the first row.
     *
     * @return array{environment:?array,conflict:?string,managed_clients:list<array>}
     */
    private function resolveBinding(int $applicationId, bool $forWrite): array
    {
        $environments = Environment::where('application_id', $applicationId)->order('id')->select()->toArray();
        $environmentIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $environments));
        $clients = $environmentIds === [] ? [] : WorkloadClient::where('code', self::MANAGED_CLIENT_CODE)
            ->whereIn('environment_id', $environmentIds)->order('id')->select()->toArray();
        $boundEnvironmentIds = [];
        foreach ($clients as $client) {
            $boundEnvironmentIds[(int) $client['environment_id']] = true;
        }
        $boundEnvironmentIds = array_keys($boundEnvironmentIds);
        sort($boundEnvironmentIds);

        $trustedCode = trim((string) config('plugin.sand-iam.app.managed_environment_code', ''));
        if ($trustedCode !== '') {
            $trusted = array_values(array_filter($environments, static fn (array $row): bool => (string) $row['code'] === $trustedCode && (int) $row['status'] === 1));
            if (count($trusted) === 1) {
                return ['environment' => $trusted[0], 'conflict' => null, 'managed_clients' => $this->clientsInEnvironment($clients, (int) $trusted[0]['id'])];
            }
            if ($trusted !== []) {
                return ['environment' => null, 'conflict' => '受信任的托管环境配置存在多个候选，请修正部署配置', 'managed_clients' => []];
            }
        }

        if (count($boundEnvironmentIds) === 1) {
            $environment = $this->environmentById($environments, $boundEnvironmentIds[0]);
            if ($environment === null || (int) $environment['status'] !== 1) {
                return ['environment' => null, 'conflict' => '托管调用身份所在环境已停用，请先在环境管理中恢复', 'managed_clients' => []];
            }
            return ['environment' => $environment, 'conflict' => null, 'managed_clients' => $this->clientsInEnvironment($clients, (int) $environment['id'])];
        }
        if (count($boundEnvironmentIds) > 1) {
            return ['environment' => null, 'conflict' => '该应用存在多个托管绑定环境，无法自动确定授权目标', 'managed_clients' => []];
        }
        $active = array_values(array_filter($environments, static fn (array $row): bool => (int) $row['status'] === 1));
        if (count($active) === 1) {
            return ['environment' => $active[0], 'conflict' => null, 'managed_clients' => $this->clientsInEnvironment($clients, (int) $active[0]['id'])];
        }
        if (count($active) > 1) {
            return ['environment' => null, 'conflict' => '该应用存在多个候选环境，无法自动确定授权目标，请配置受信任的托管环境', 'managed_clients' => []];
        }
        if (!$forWrite) {
            return ['environment' => null, 'conflict' => null, 'managed_clients' => []];
        }
        return ['environment' => null, 'conflict' => null, 'managed_clients' => [], 'pending_default' => true];
    }

    /** @return list<array> */
    private function clientsInEnvironment(array $clients, int $environmentId): array
    {
        return array_values(array_filter($clients, static fn (array $row): bool => (int) $row['environment_id'] === $environmentId));
    }

    /** @param list<array> $environments @return array|null */
    private function environmentById(array $environments, int $id): ?array
    {
        foreach ($environments as $environment) {
            if ((int) $environment['id'] === $id) return $environment;
        }
        return null;
    }

    /**
     * The managed client usable for this catalog item inside the resolved
     * binding, creating the environment/client rows on first write. Returns
     * either the client row or a per-item Chinese conflict message.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $binding
     * @return array{client:?array,message:?string}
     */
    private function managedClientFor(array $item, array $binding, int $appId, int $organizationId, int $adminId, string $requestId): array
    {
        $environment = $binding['environment'] ?? null;
        if ($environment === null) {
            if (($binding['conflict'] ?? null) !== null) {
                return ['client' => null, 'message' => (string) $binding['conflict']];
            }
            if (($binding['pending_default'] ?? false) !== true) {
                return ['client' => null, 'message' => '当前没有可用的托管环境'];
            }
            // Controlled default: exactly one prod environment per application.
            $existing = Environment::where('application_id', $appId)->where('code', AccessMatrixInput::DEFAULT_ENVIRONMENT_CODE)->lock(true)->find();
            if ($existing !== null) {
                if ((int) $existing->status !== 1) {
                    return ['client' => null, 'message' => '默认托管环境已停用，无法自动恢复，请先在环境管理中处理'];
                }
                $environment = $existing->toArray();
            } else {
                $created = Environment::create([
                    'application_id' => $appId,
                    'code' => AccessMatrixInput::DEFAULT_ENVIRONMENT_CODE,
                    'name' => AccessMatrixInput::DEFAULT_ENVIRONMENT_NAME,
                    'status' => 1,
                ]);
                (new AuditWriter())->write('admin', (string) $adminId, $organizationId, $appId, 'environment.create', 'environment', (int) $created->id, 'succeeded', $this->childRequestId($requestId, 'environment.create'), ['code' => AccessMatrixInput::DEFAULT_ENVIRONMENT_CODE]);
                $environment = $created->toArray();
            }
            $binding['environment'] = $environment;
        }
        $environmentId = (int) $environment['id'];
        if ((int) $environment['status'] !== 1) {
            return ['client' => null, 'message' => '托管环境已停用，无法自动恢复'];
        }
        $audience = $this->serviceAudience((int) $item['service_id'], (string) $item['service_audience']);
        if ($audience === null) {
            return ['client' => null, 'message' => '该服务尚未登记可用的调用受众，无法自动授权'];
        }
        $client = WorkloadClient::where('environment_id', $environmentId)->where('code', self::MANAGED_CLIENT_CODE)->lock(true)->find();
        if ($client === null) {
            $created = WorkloadClient::create([
                'environment_id' => $environmentId,
                'code' => self::MANAGED_CLIENT_CODE,
                'name' => self::MANAGED_CLIENT_NAME,
                'audience' => $audience,
                'status' => 1,
            ]);
            (new AuditWriter())->write('admin', (string) $adminId, $organizationId, $appId, 'workload_client.create', 'workload_client', (int) $created->id, 'succeeded', $this->childRequestId($requestId, 'workload_client.create.' . $audience), ['code' => self::MANAGED_CLIENT_CODE, 'audience' => $audience]);
            return ['client' => $created->toArray(), 'message' => null];
        }
        if ((int) $client->status !== 1) {
            return ['client' => null, 'message' => '托管调用身份已停用，无法自动恢复，请先在调用身份管理中处理'];
        }
        if (!hash_equals((string) $client->audience, $audience)) {
            return ['client' => null, 'message' => '托管调用身份的服务受众与该功能不一致，无法自动授权'];
        }
        return ['client' => $client->toArray(), 'message' => null];
    }

    /** Registered contract audience: service declaration first, then the single
     * audience already used by that service's grants; ambiguity stays unresolved. */
    private function serviceAudience(int $serviceId, string $declared): ?string
    {
        if ($declared !== '') {
            return $declared;
        }
        $actionIds = ServiceAction::where('service_id', $serviceId)->column('id');
        if ($actionIds === []) {
            return null;
        }
        $audiences = ServiceGrant::whereIn('service_action_id', $actionIds)
            ->group('audience')->column('audience');
        $audiences = array_values(array_unique(array_map('strval', $audiences)));
        return count($audiences) === 1 ? $audiences[0] : null;
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $binding */
    private function isGranted(array $item, array $binding): bool
    {
        $environment = $binding['environment'] ?? null;
        if ($environment === null) {
            return false;
        }
        $audience = $this->serviceAudience((int) $item['service_id'], (string) $item['service_audience']);
        if ($audience === null) {
            return false;
        }
        $clientIds = [];
        foreach (($binding['managed_clients'] ?? []) as $client) {
            if ((int) $client['environment_id'] === (int) $environment['id']
                && (int) $client['status'] === 1
                && hash_equals((string) $client['audience'], $audience)) {
                $clientIds[] = (int) $client['id'];
            }
        }
        if ($clientIds === []) {
            return false;
        }
        return ServiceGrant::whereIn('workload_client_id', $clientIds)
            ->where('service_action_id', (int) $item['action_id'])
            ->where('audience', $audience)
            ->where('status', 1)->whereNull('revoked_time')
            ->count() > 0;
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $binding @return array{ok:bool,message:string} */
    private function applyGrant(array $item, array $binding, int $appId, int $organizationId, int $adminId, string $requestId): array
    {
        $resolved = $this->managedClientFor($item, $binding, $appId, $organizationId, $adminId, $requestId);
        if ($resolved['client'] === null) {
            return ['ok' => false, 'message' => (string) $resolved['message']];
        }
        $client = $resolved['client'];
        $audience = (string) $client['audience'];
        $grant = ServiceGrant::where('workload_client_id', (int) $client['id'])
            ->where('service_action_id', (int) $item['action_id'])
            ->where('audience', $audience)
            ->lock(true)
            ->find();
        if ($grant === null) {
            $grant = ServiceGrant::create([
                'workload_client_id' => (int) $client['id'],
                'service_action_id' => (int) $item['action_id'],
                'audience' => $audience,
                'data_class' => null,
                'status' => 1,
            ]);
            $this->auditGrant($adminId, $organizationId, $appId, $requestId, 'service_grant.create', (int) $grant->id, (string) $item['id']);
            return ['ok' => true, 'message' => ''];
        }
        if ((int) $grant->status === 1 && $grant->revoked_time === null) {
            return ['ok' => true, 'message' => ''];
        }
        // status=2 without revoked_time is an administrative disable (the grant
        // edit form writes status directly), not a revocation: the managed
        // flow must surface a conflict instead of silently reviving it. A
        // revoked row (revoked_time set) is this flow's own toggle-off state
        // and may be reactivated — the unique key forbids a second row.
        if ((int) $grant->status === 2 && $grant->revoked_time === null) {
            return ['ok' => false, 'message' => '该功能授权已被管理员停用，请联系管理员在授权管理中处理'];
        }
        $grant->save(['status' => 1, 'revoked_time' => null]);
        $this->auditGrant($adminId, $organizationId, $appId, $requestId, 'service_grant.reactivate', (int) $grant->id, (string) $item['id']);
        return ['ok' => true, 'message' => ''];
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $binding @return array{ok:bool,message:string} */
    private function applyRevoke(array $item, array $binding, int $appId, int $organizationId, int $adminId, string $requestId): array
    {
        $environment = $binding['environment'] ?? null;
        if ($environment === null) {
            return ['ok' => true, 'message' => ''];
        }
        $audience = $this->serviceAudience((int) $item['service_id'], (string) $item['service_audience']);
        if ($audience === null) {
            return ['ok' => true, 'message' => ''];
        }
        $clientIds = [];
        foreach (($binding['managed_clients'] ?? []) as $client) {
            if ((int) $client['environment_id'] === (int) $environment['id']
                && (int) $client['status'] === 1
                && hash_equals((string) $client['audience'], $audience)) {
                $clientIds[] = (int) $client['id'];
            }
        }
        if ($clientIds === []) {
            return ['ok' => true, 'message' => ''];
        }
        $grant = ServiceGrant::whereIn('workload_client_id', $clientIds)
            ->where('service_action_id', (int) $item['action_id'])
            ->where('audience', $audience)
            ->where('status', 1)->whereNull('revoked_time')
            ->lock(true)
            ->find();
        if ($grant === null) {
            return ['ok' => true, 'message' => ''];
        }
        // Revocation is a status update; the row and its history are preserved.
        $grant->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
        $this->auditGrant($adminId, $organizationId, $appId, $requestId, 'service_grant.revoke', (int) $grant->id, (string) $item['id']);
        return ['ok' => true, 'message' => ''];
    }

    private function auditGrant(int $adminId, int $organizationId, int $appId, string $requestId, string $action, int $grantId, string $capabilityId): void
    {
        (new AuditWriter())->write('admin', (string) $adminId, $organizationId, $appId, $action, 'service_grant', $grantId, 'succeeded', $this->childRequestId($requestId, $action . '.' . $capabilityId), ['capability_id' => $capabilityId]);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function itemResult(array $item, string $operation, bool $ok, string $message): array
    {
        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'operation' => $operation,
            'ok' => $ok,
            'message' => $message,
        ];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function stripReplayKeys(array $result): array
    {
        unset($result['secret_available'], $result['replayed']);
        return $result;
    }

    /** @return array{0:Application,1:Organization} */
    private function requireApplication(int $applicationId, AdminOrganizationAccess $access): array
    {
        $application = Application::where('id', $applicationId)->where('status', 1)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        if ($application === null || $organization === null) {
            throw new ApiException('SAND_IAM_CAPABILITY_APPLICATION_MISSING: 应用不存在或已停用', 404);
        }
        $access->assertApplication($applicationId);
        return [$application, $organization];
    }

    private function childRequestId(string $requestId, string $operation): string
    {
        return 'capx_' . substr(hash('sha256', $requestId . "\0" . $operation), 0, 48);
    }

    private function beginSerializableTransaction(): string
    {
        $rows = Db::query('SHOW default_transaction_isolation');
        $previous = strtolower(trim((string) ($rows[0]['default_transaction_isolation'] ?? '')));
        if (!in_array($previous, ['read uncommitted', 'read committed', 'repeatable read', 'serializable'], true)) {
            throw new ApiException('SAND_IAM_CAPABILITY_TRANSACTION_CONFIGURATION_INVALID', 503);
        }
        Db::query('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        try {
            Db::startTrans();
        } catch (\Throwable $exception) {
            $this->restoreTransactionIsolation($previous);
            throw $exception;
        }
        return $previous;
    }

    private function restoreTransactionIsolation(string $level): void
    {
        $keyword = match ($level) {
            'read uncommitted' => 'READ UNCOMMITTED',
            'read committed' => 'READ COMMITTED',
            'repeatable read' => 'REPEATABLE READ',
            'serializable' => 'SERIALIZABLE',
            default => throw new ApiException('SAND_IAM_CAPABILITY_TRANSACTION_CONFIGURATION_INVALID', 503),
        };
        Db::query('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL ' . $keyword);
    }

    private function isSerializationFailure(\Throwable $exception): bool
    {
        return (string) $exception->getCode() === '40001'
            || str_contains($exception->getMessage(), '40001')
            || str_contains(strtolower($exception->getMessage()), 'serialization_failure');
    }

    private function canonical(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
