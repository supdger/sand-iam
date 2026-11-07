<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/**
 * Registers service-provider capabilities declared by an installed plugin.
 *
 * The catalog owns only the shared service/action directory. It never creates
 * an organization, application, workload client, credential, or grant.
 *
 * Declarations may carry optional Chinese display metadata:
 *   service:  ['code'=>…, 'name'=>…, 'group_name'=>…, 'audience'=>…]
 *   actions:  'action.code' => '动作中文名'
 *             or 'action.code' => ['name'=>'动作中文名', 'description'=>'动作说明']
 * group_name/audience/description are persisted verbatim; the application
 * capability directory filters rows whose Chinese metadata is not effective.
 */
final class ServiceCatalog
{
    private const MAX_ACTIONS = 256;
    private const MAX_GROUP_NAME = 128;
    private const MAX_AUDIENCE = 128;
    private const MAX_DESCRIPTION = 512;

    /**
     * @param array{code:string,name:string,group_name?:string,audience?:string} $service
     * @param array<string,string|array{name:string,description?:string}> $actions
     */
    public function registerServiceActions(array $service, array $actions): void
    {
        [$serviceCode, $serviceName, $serviceGroup, $serviceAudience, $normalizedActions] = $this->declaration($service, $actions);
        $this->persist($serviceCode, $serviceName, $serviceGroup, $serviceAudience, $normalizedActions, 0);
    }

    /**
     * Read-only preview. Registration still checks the same rows under locks.
     *
     * @param array{code:string,name:string,group_name?:string,audience?:string} $service
     * @param array<string,string|array{name:string,description?:string}> $actions
     * @return array{service_code:string,service_status:string,action_statuses:array<string,string>,can_register:bool}
     */
    public function inspectServiceActions(array $service, array $actions): array
    {
        [$serviceCode, , , , $normalizedActions] = $this->declaration($service, $actions);
        $serviceRecord = Service::where('code', $serviceCode)->find();
        $serviceStatus = $serviceRecord === null
            ? 'missing'
            : ((int) $serviceRecord->status === 1 ? 'active' : 'disabled');
        $actionStatuses = [];
        $canRegister = $serviceStatus !== 'disabled';
        foreach ($normalizedActions as $actionCode => $_action) {
            $action = $serviceRecord === null ? null : ServiceAction::where('service_id', (int) $serviceRecord->id)
                ->where('code', $actionCode)->find();
            $status = $action === null ? 'missing' : ((int) $action->status === 1 ? 'active' : 'disabled');
            $actionStatuses[$actionCode] = $status;
            if ($status === 'disabled') $canRegister = false;
        }
        return [
            'service_code' => $serviceCode,
            'service_status' => $serviceStatus,
            'action_statuses' => $actionStatuses,
            'can_register' => $canRegister,
        ];
    }

    /**
     * @param array<string,array{name:string,description:?string}> $actions
     */
    private function persist(string $serviceCode, string $serviceName, ?string $serviceGroup, ?string $serviceAudience, array $actions, int $retry): void
    {
        Db::startTrans();
        try {
            $serviceRecord = Service::where('code', $serviceCode)->lock(true)->find();
            if ($serviceRecord === null) {
                $serviceRecord = new Service();
                $serviceRecord->save(array_filter([
                    'code' => $serviceCode,
                    'name' => $serviceName,
                    'status' => 1,
                    'group_name' => $serviceGroup,
                    'audience' => $serviceAudience,
                ], static fn (mixed $value): bool => $value !== null));
            } elseif ((int) $serviceRecord->status !== 1) {
                throw new ApiException("SAND_IAM_SERVICE_CATALOG_DISABLED: 平台服务已停用，不能由插件安装自动恢复：{$serviceCode}", 409);
            } else {
                // The plugin declaration is authoritative for display metadata.
                // Omitted optional fields keep the stored value so older
                // declarations never erase metadata written by newer ones.
                $serviceUpdate = ['name' => $serviceName];
                if ($serviceGroup !== null) $serviceUpdate['group_name'] = $serviceGroup;
                if ($serviceAudience !== null) $serviceUpdate['audience'] = $serviceAudience;
                $serviceRecord->save($serviceUpdate);
            }

            foreach ($actions as $actionCode => $action) {
                $record = ServiceAction::where('service_id', (int) $serviceRecord->id)
                    ->where('code', $actionCode)
                    ->lock(true)
                    ->find();
                if ($record === null) {
                    $record = new ServiceAction();
                    $record->save(array_filter([
                        'service_id' => (int) $serviceRecord->id,
                        'code' => $actionCode,
                        'name' => $action['name'],
                        'description' => $action['description'],
                        'status' => 1,
                    ], static fn (mixed $value): bool => $value !== null));
                } elseif ((int) $record->status !== 1) {
                    throw new ApiException("SAND_IAM_SERVICE_CATALOG_DISABLED: 服务动作已停用，不能由插件安装自动恢复：{$actionCode}", 409);
                } else {
                    $actionUpdate = ['name' => $action['name']];
                    if ($action['description'] !== null) $actionUpdate['description'] = $action['description'];
                    $record->save($actionUpdate);
                }
            }
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if ($retry === 0 && $this->isUniqueViolation($exception)) {
                $this->persist($serviceCode, $serviceName, $serviceGroup, $serviceAudience, $actions, 1);
                return;
            }
            throw $exception;
        }
    }

    /**
     * @param array{code:string,name:string,group_name?:string,audience?:string} $service
     * @param array<string,string|array{name:string,description?:string}> $actions
     * @return array{0:string,1:string,2:?string,3:?string,4:array<string,array{name:string,description:?string}>}
     */
    private function declaration(array $service, array $actions): array
    {
        $serviceCode = trim((string) ($service['code'] ?? ''));
        $serviceName = trim((string) ($service['name'] ?? ''));
        $serviceGroup = $this->optionalText($service['group_name'] ?? null, self::MAX_GROUP_NAME, '服务分组');
        $serviceAudience = $this->optionalAudience($service['audience'] ?? null);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $serviceCode) !== 1
            || $serviceName === '' || mb_strlen($serviceName) > 128 || $this->hasControlChars($serviceName)
            || $actions === [] || count($actions) > self::MAX_ACTIONS) {
            throw new ApiException('SAND_IAM_SERVICE_CATALOG_INVALID: 服务代码、名称或动作数量不符合插件目录契约', 400);
        }

        $normalized = [];
        foreach ($actions as $actionCode => $action) {
            $actionCode = trim((string) $actionCode);
            if (is_array($action)) {
                $actionName = trim((string) ($action['name'] ?? ''));
                $actionDescription = $this->optionalText($action['description'] ?? null, self::MAX_DESCRIPTION, '动作说明');
            } else {
                $actionName = trim((string) $action);
                $actionDescription = null;
            }
            if (preg_match('/^[a-z0-9][a-z0-9._-]{1,95}$/', $actionCode) !== 1
                || $actionName === '' || mb_strlen($actionName) > 128 || $this->hasControlChars($actionName)
                || isset($normalized[$actionCode])) {
                throw new ApiException('SAND_IAM_SERVICE_CATALOG_INVALID: 服务动作代码、名称或唯一性不符合插件目录契约', 400);
            }
            $normalized[$actionCode] = ['name' => $actionName, 'description' => $actionDescription];
        }

        return [$serviceCode, $serviceName, $serviceGroup, $serviceAudience, $normalized];
    }

    private function optionalText(mixed $value, int $maxLength, string $label): ?string
    {
        if ($value === null) return null;
        if (!is_string($value)) {
            throw new ApiException("SAND_IAM_SERVICE_CATALOG_INVALID: {$label}必须是字符串", 400);
        }
        $value = trim($value);
        if ($value === '') return null;
        if (mb_strlen($value) > $maxLength || $this->hasControlChars($value)) {
            throw new ApiException("SAND_IAM_SERVICE_CATALOG_INVALID: {$label}长度或字符不符合插件目录契约", 400);
        }
        return $value;
    }

    private function optionalAudience(mixed $value): ?string
    {
        $audience = $this->optionalText($value, self::MAX_AUDIENCE, '服务受众');
        if ($audience !== null && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._~-]*$/', $audience) !== 1) {
            throw new ApiException('SAND_IAM_SERVICE_CATALOG_INVALID: 服务受众格式不符合插件目录契约', 400);
        }
        return $audience;
    }

    private function hasControlChars(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private function isUniqueViolation(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, '23505') || str_contains($message, 'unique constraint');
    }
}
