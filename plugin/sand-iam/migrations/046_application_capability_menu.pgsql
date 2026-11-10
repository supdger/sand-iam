-- SandIAM 0.9.7 WP-B1: register the hidden 「开通功能」 page menu and reparent
-- the two capability permission nodes onto it. The page stays invisible in the
-- sidebar (is_hidden=1) and is reached from the application list row action or
-- a direct link; lifecycle/menu-regroup.pgsql then places it under the
-- 应用接入 group and demotes SandIAMConnection into 高级 as 「手动接入」 on
-- both fresh installs and upgrades. Existing menus, routes, credentials and
-- disabled states are untouched; every statement converges on rerun.
BEGIN;

LOCK TABLE sand_system_menu, sand_system_role_menu, sand_iam_schema_migration IN SHARE ROW EXCLUSIVE MODE;

DO $$
DECLARE
    recorded record;
    root_rows integer;
    root_id bigint;
    group_id bigint;
    page_rows integer;
    page_id bigint;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration
    WHERE migration_file = '046_application_capability_menu.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 46
        OR recorded.checksum <> '98be5ab35d48add5f74a68150eadf5033e21cf6e67306151816b6cdd814cb5bf'
        OR recorded.package_version <> '0.9.7'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 046 ledger identity conflicts with capability menu';
    END IF;

    IF recorded.revision IS NULL THEN
        IF (SELECT count(*) FROM sand_iam_schema_migration) <> 46
           OR NOT EXISTS (
               SELECT 1 FROM sand_iam_schema_migration
               WHERE migration_file = '045_application_capability_permissions.pgsql'
                 AND revision = 45
                 AND checksum = 'abf03048e9e8744943a92228cbe3d8719be6929673be967182e71b3311454ac2'
                 AND package_version = '0.9.7'
           ) THEN
            RAISE EXCEPTION 'SandIAM migration 046 requires the complete published 001-045 ledger';
        END IF;

        SELECT count(*), min(id) INTO root_rows, root_id
        FROM sand_system_menu
        WHERE code = 'SandIAM'
          AND delete_time IS NULL;
        IF root_rows <> 1 THEN
            RAISE EXCEPTION 'SandIAM migration 046 requires exactly one SandIAM root menu, found %', root_rows;
        END IF;

        SELECT id INTO group_id
        FROM sand_system_menu
        WHERE code = 'SandIAMGroupAccess'
          AND delete_time IS NULL
        ORDER BY id
        LIMIT 1;

        SELECT count(*), min(id) INTO page_rows, page_id
        FROM sand_system_menu
        WHERE code = 'SandIAMApplicationCapability'
          AND delete_time IS NULL;
        IF page_rows > 1 THEN
            RAISE EXCEPTION 'SandIAM migration 046 found duplicate SandIAMApplicationCapability menus';
        END IF;
        IF page_rows = 0 THEN
            INSERT INTO sand_system_menu
                (parent_id, name, code, slug, type, path, component, icon, sort, is_hidden, status, create_time, update_time)
            VALUES
                (COALESCE(group_id, root_id), '开通功能', 'SandIAMApplicationCapability', '', 2,
                 '/sand-iam/application-capability', '/plugin/sand-iam/application-capability/index', '',
                 90, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id INTO page_id;
        ELSE
            UPDATE sand_system_menu
            SET parent_id = COALESCE(group_id, root_id),
                name = '开通功能',
                slug = '',
                type = 2,
                path = '/sand-iam/application-capability',
                component = '/plugin/sand-iam/application-capability/index',
                icon = '',
                sort = 90,
                is_hidden = 1,
                status = 1,
                update_time = CURRENT_TIMESTAMP
            WHERE id = page_id
              AND (
                  parent_id IS DISTINCT FROM COALESCE(group_id, root_id)
                  OR name IS DISTINCT FROM '开通功能'
                  OR slug IS DISTINCT FROM ''
                  OR type IS DISTINCT FROM 2
                  OR path IS DISTINCT FROM '/sand-iam/application-capability'
                  OR component IS DISTINCT FROM '/plugin/sand-iam/application-capability/index'
                  OR icon IS DISTINCT FROM ''
                  OR sort IS DISTINCT FROM 90
                  OR is_hidden IS DISTINCT FROM 1
                  OR status IS DISTINCT FROM 1
              );
        END IF;

        UPDATE sand_system_menu permission
        SET parent_id = page_id,
            update_time = CURRENT_TIMESTAMP
        WHERE permission.type = 3
          AND permission.slug IN ('sand_iam:application_capability:index', 'sand_iam:application_capability:save')
          AND permission.delete_time IS NULL
          AND permission.parent_id IS DISTINCT FROM page_id;

        INSERT INTO sand_system_role_menu (role_id, menu_id)
        SELECT DISTINCT holder.role_id, page_id
        FROM sand_system_role_menu holder
        JOIN sand_system_menu held
          ON held.id = holder.menu_id
         AND held.delete_time IS NULL
        WHERE (
            held.slug IN ('sand_iam:application_capability:index', 'sand_iam:application_capability:save')
            OR held.code = 'SandIAMApplication'
        )
        AND NOT EXISTS (
            SELECT 1 FROM sand_system_role_menu existing
            WHERE existing.role_id = holder.role_id
              AND existing.menu_id = page_id
        );
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('98be5ab35d48add5f74a68150eadf5033e21cf6e67306151816b6cdd814cb5bf'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '046_application_capability_menu.pgsql', 46, self_checksum.checksum, '0.9.7', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
DECLARE recorded_rows integer;
BEGIN
    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration
    WHERE migration_file = '046_application_capability_menu.pgsql'
      AND revision = 46
      AND package_version = '0.9.7';
    IF recorded_rows <> 1 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 47 THEN
        RAISE EXCEPTION 'SandIAM migration 046 did not close the exact 001-046 ledger';
    END IF;
END $$;

COMMIT;
