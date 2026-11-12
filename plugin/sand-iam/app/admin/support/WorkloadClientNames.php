<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

/**
 * 列表行上补服务调用身份的名称，避免页面再按筛选去猜。
 */
final class WorkloadClientNames
{
    /**
     * @param array<int, mixed> $rows
     * @param array<int, string> $namesById
     * @return array<int, mixed>
     */
    public static function apply(array $rows, array $namesById): array
    {
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $clientId = (int) ($row['workload_client_id'] ?? 0);
            $name = $clientId > 0 ? ($namesById[$clientId] ?? '') : '';
            $row['workload_client_name'] = $name === '' ? null : $name;
            $rows[$index] = $row;
        }
        return $rows;
    }
}
