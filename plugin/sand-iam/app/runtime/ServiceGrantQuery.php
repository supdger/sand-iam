<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use think\facade\Db;

/**
 * Read-only service-grant probe for sibling Sand products (for example the
 * sand-ai host runtime). SandIAM stays the single authorization authority:
 * consumers ask this port whether a workload client currently holds an active
 * grant instead of reading sand_iam_* tables directly.
 *
 * The WHERE semantics mirror IdentityContextProvider::resolveServiceGrants
 * field for field — workload client, audience, grant status, revocation,
 * expiry, plus the active service/action join — so the answer here is exactly
 * the grant-resolution half of context issue/verify. network_policy and
 * quota_policy are deliberately NOT evaluated: they remain enforced by the
 * issue/verify path, which owns caller IP and quota facts this port never
 * sees. Multiple matching grant rows still mean "an active grant exists";
 * per-service disambiguation stays inside resolveServiceGrants.
 */
final class ServiceGrantQuery
{
    public function hasActiveGrant(
        int $workloadClientId,
        string $audience,
        string $serviceCode,
        string $actionCode
    ): bool {
        $row = Db::table('sand_iam_service_grant')->alias('service_grant')
            ->join('sand_iam_service_action action', 'action.id = service_grant.service_action_id')
            ->join('sand_iam_service service', 'service.id = action.service_id')
            ->where('service_grant.workload_client_id', $workloadClientId)
            ->where('service_grant.audience', $audience)
            ->where('service_grant.status', 1)
            ->whereNull('service_grant.revoked_time')
            ->where(static function ($query): void {
                $query->whereNull('service_grant.expire_time')
                    ->whereOr('service_grant.expire_time', '>', date('Y-m-d H:i:s'));
            })
            ->where('service.status', 1)
            ->where('action.status', 1)
            ->where('action.code', $actionCode)
            ->where('service.code', $serviceCode)
            ->field('service_grant.id')
            ->find();
        return $row !== null;
    }
}
