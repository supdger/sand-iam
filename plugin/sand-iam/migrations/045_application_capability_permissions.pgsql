-- SandIAM 0.9.7: hidden permission nodes for the two-click application
-- capability authorization API. The nodes stay invisible in the menu tree.
-- Inheritance is split by privilege level: the view node follows application
-- listers and every role that can already mutate application or service-grant
-- management (so writers never get a page they cannot open), while the write
-- node only follows the connection menu and the existing management slugs —
-- a view-only application role must never gain authorization writes.
-- Existing hidden rows are realigned to the canonical node shape on
-- rerun, and roles inherit the two new nodes by privilege level: view follows
-- application listers plus existing application/grant writers, write follows
-- only the connection menu and existing grant/application write slugs.
-- The SandIAMConnection candidate is pinned by row only, not by parentage:
-- fresh installs keep it directly under the SandIAM root while upgraded
-- installations already sit inside a navigation group after the 0.9.x menu
-- regroup; revision046 reparents the permission nodes onto the capability
-- page either way, and role inheritance resolves holders by menu code.
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
        OR recorded.checksum <> 'abf03048e9e8744943a92228cbe3d8719be6929673be967182e71b3311454ac2'
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
        WHERE parent.code = 'SandIAMConnection'
          AND parent.type IN (1, 2)
          AND parent.status = 1
          AND parent.delete_time IS NULL;
        IF parent_rows <> 1 THEN
            RAISE EXCEPTION 'SandIAM migration 045 requires exactly one active SandIAMConnection menu, found %', parent_rows;
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
          ON permission.slug = 'sand_iam:application_capability:index'
         AND permission.type = 3
         AND permission.delete_time IS NULL
        WHERE (
            held.code = 'SandIAMConnection'
            OR held.slug IN (
                'sand_iam:application:index',
                'sand_iam:grant:save',
                'sand_iam:application:save',
                'sand_iam:application:update'
            )
        )
        AND NOT EXISTS (
            SELECT 1 FROM sand_system_role_menu existing
            WHERE existing.role_id = holder.role_id
              AND existing.menu_id = permission.id
        );

        INSERT INTO sand_system_role_menu (role_id, menu_id)
        SELECT DISTINCT holder.role_id, permission.id
        FROM sand_system_role_menu holder
        JOIN sand_system_menu held ON held.id = holder.menu_id AND held.delete_time IS NULL
        JOIN sand_system_menu permission
          ON permission.slug = 'sand_iam:application_capability:save'
         AND permission.type = 3
         AND permission.delete_time IS NULL
        WHERE (
            held.code = 'SandIAMConnection'
            OR held.slug IN (
                'sand_iam:grant:save',
                'sand_iam:application:save',
                'sand_iam:application:update'
            )
        )
        AND NOT EXISTS (
            SELECT 1 FROM sand_system_role_menu existing
            WHERE existing.role_id = holder.role_id
              AND existing.menu_id = permission.id
        );
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('abf03048e9e8744943a92228cbe3d8719be6929673be967182e71b3311454ac2'))
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
