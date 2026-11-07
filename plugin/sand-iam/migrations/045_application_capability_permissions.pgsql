-- SandIAM 0.9.7: hidden permission nodes for the two-click application
-- capability authorization API. The nodes stay invisible in the menu tree;
-- roles that already hold application-management permissions inherit them so
-- existing delegations keep working without a second grant step.
-- Existing hidden rows are realigned to the canonical node shape on
-- rerun, and roles already holding application-management permissions (or
-- the connection menu) inherit the two new nodes the same way migration 021
-- granted every other hidden permission.
BEGIN;

LOCK TABLE sand_system_menu, sand_system_role_menu, sand_iam_schema_migration IN SHARE ROW EXCLUSIVE MODE;

DO $$
DECLARE
    recorded record;
    parent_rows integer;
    capability_parent_id bigint;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration
    WHERE migration_file = '045_application_capability_permissions.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 45
        OR recorded.checksum <> '3fbb764237de7a3d2380fe17c82fa197680e50848765d8f5be81969157c5aff6'
        OR recorded.package_version <> '0.9.7'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 045 ledger identity conflicts with application capability permissions';
    END IF;

    IF recorded.revision IS NULL THEN
        IF (SELECT count(*) FROM sand_iam_schema_migration) <> 45
           OR NOT EXISTS (
               SELECT 1 FROM sand_iam_schema_migration
               WHERE migration_file = '044_application_capability_directory.pgsql'
                 AND revision = 44
                 AND package_version = '0.9.7'
           ) THEN
            RAISE EXCEPTION 'SandIAM migration 045 requires the complete published 001-044 ledger';
        END IF;

        SELECT count(*), min(parent.id) INTO parent_rows, capability_parent_id
        FROM sand_system_menu parent
        JOIN sand_system_menu root
          ON root.id = parent.parent_id
         AND root.code = 'SandIAM'
         AND root.delete_time IS NULL
        WHERE parent.code = 'SandIAMConnection'
          AND parent.type IN (1, 2)
          AND parent.status = 1
          AND parent.delete_time IS NULL;
        IF parent_rows <> 1 THEN
            RAISE EXCEPTION 'SandIAM migration 045 requires exactly one active SandIAMConnection parent, found %', parent_rows;
        END IF;

        INSERT INTO sand_system_menu (parent_id, name, code, slug, type, path, component, icon, sort, is_hidden, status, create_time, update_time)
        SELECT capability_parent_id, seed.permission_name, seed.slug, seed.slug, 3, '', '', '', seed.sort, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        FROM (VALUES
            ('sand_iam:application_capability:index', '查看应用功能授权', 2090),
            ('sand_iam:application_capability:save', '管理应用功能授权', 2091)
        ) AS seed(slug, permission_name, sort)
        WHERE NOT EXISTS (
            SELECT 1 FROM sand_system_menu known
            WHERE known.slug = seed.slug AND known.delete_time IS NULL
        );

        UPDATE sand_system_menu permission
        SET parent_id = capability_parent_id,
            name = seed.permission_name,
            code = seed.slug,
            type = 3,
            path = '',
            component = '',
            icon = '',
            is_hidden = 1,
            status = 1,
            update_time = CURRENT_TIMESTAMP
        FROM (VALUES
            ('sand_iam:application_capability:index', '查看应用功能授权', 2090),
            ('sand_iam:application_capability:save', '管理应用功能授权', 2091)
        ) AS seed(slug, permission_name, sort)
        WHERE permission.slug = seed.slug
          AND permission.type = 3
          AND permission.delete_time IS NULL
          AND (
              permission.parent_id IS DISTINCT FROM capability_parent_id
              OR permission.name IS DISTINCT FROM seed.permission_name
              OR permission.code IS DISTINCT FROM seed.slug
              OR permission.is_hidden IS DISTINCT FROM 1
              OR permission.status IS DISTINCT FROM 1
          );

        INSERT INTO sand_system_role_menu (role_id, menu_id)
        SELECT DISTINCT holder.role_id, permission.id
        FROM sand_system_role_menu holder
        JOIN sand_system_menu held ON held.id = holder.menu_id AND held.delete_time IS NULL
        JOIN sand_system_menu permission
          ON permission.slug IN ('sand_iam:application_capability:index', 'sand_iam:application_capability:save')
         AND permission.type = 3
         AND permission.delete_time IS NULL
        WHERE (
            held.code = 'SandIAMConnection'
            OR held.slug = 'sand_iam:application:index'
        )
        AND NOT EXISTS (
            SELECT 1 FROM sand_system_role_menu existing
            WHERE existing.role_id = holder.role_id
              AND existing.menu_id = permission.id
        );
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('3fbb764237de7a3d2380fe17c82fa197680e50848765d8f5be81969157c5aff6'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '045_application_capability_permissions.pgsql', 45, self_checksum.checksum, '0.9.7', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
DECLARE recorded_rows integer;
BEGIN
    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration
    WHERE migration_file = '045_application_capability_permissions.pgsql'
      AND revision = 45
      AND package_version = '0.9.7';
    IF recorded_rows <> 1 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 46 THEN
        RAISE EXCEPTION 'SandIAM migration 045 did not close the exact 001-045 ledger';
    END IF;
END $$;

COMMIT;
