-- SandIAM 0.9.7: application capability directory metadata.
-- sand_iam_service_action gains a Chinese description and sand_iam_service
-- gains a display group plus the registered contract audience. Existing rows
-- keep working: every new column is nullable and backfill only touches rows
-- whose metadata is still empty.
BEGIN;

LOCK TABLE sand_iam_service, sand_iam_service_action, sand_iam_service_grant, sand_iam_schema_migration IN ACCESS EXCLUSIVE MODE;

DO $$
DECLARE
    recorded record;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration
    WHERE migration_file = '044_application_capability_directory.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 44
        OR recorded.checksum <> '7e0b920249f8cc92ce7b35993c173b2c0ecc16ac17d3f71e0812bfea64cb59fc'
        OR recorded.package_version <> '0.9.7'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 044 ledger identity conflicts with application capability directory';
    END IF;

    IF recorded.revision IS NULL THEN
        IF (SELECT count(*) FROM sand_iam_schema_migration) <> 44
           OR NOT EXISTS (
               SELECT 1 FROM sand_iam_schema_migration
               WHERE migration_file = '043_scope_audit_event_key.pgsql'
                 AND revision = 43
                 AND checksum = '937a58b5cd1ceb3ada3ae0e0cd2a34166d53db87b60a1c096a5a55dd5c549994'
                 AND package_version = '0.8.0'
           ) THEN
            RAISE EXCEPTION 'SandIAM migration 044 requires the complete published 001-043 ledger';
        END IF;
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'sand_iam_service_action'
              AND column_name = 'description'
        ) OR EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'sand_iam_service'
              AND column_name IN ('group_name', 'audience')
        ) THEN
            RAISE EXCEPTION 'SandIAM migration 044 refuses an unrecorded capability directory column';
        END IF;
        ALTER TABLE sand_iam_service_action
            ADD COLUMN IF NOT EXISTS description varchar(512) NULL;
        ALTER TABLE sand_iam_service
            ADD COLUMN IF NOT EXISTS group_name varchar(128) NULL,
            ADD COLUMN IF NOT EXISTS audience varchar(128) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'sand_iam_service_action'
          AND column_name = 'description' AND data_type = 'character varying'
          AND character_maximum_length = 512 AND is_nullable = 'YES'
    ) OR (SELECT count(*) FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'sand_iam_service'
          AND column_name IN ('group_name', 'audience')
          AND data_type = 'character varying'
          AND character_maximum_length = 128 AND is_nullable = 'YES') <> 2 THEN
        RAISE EXCEPTION 'SandIAM migration 044 capability directory column fingerprint is incompatible';
    END IF;
END $$;

-- Deterministic Chinese grouping for the services SandIAM already knows. The
-- runtime catalog accepts provider declarations for every other service; rows
-- without a valid Chinese group simply stay out of the capability directory.
UPDATE sand_iam_service
SET group_name = '身份与访问', update_time = CURRENT_TIMESTAMP
WHERE code = 'sand-iam' AND delete_time IS NULL
  AND (group_name IS NULL OR btrim(group_name) = '');

UPDATE sand_iam_service
SET group_name = 'AI 能力', update_time = CURRENT_TIMESTAMP
WHERE code = 'sand_ai' AND delete_time IS NULL
  AND (group_name IS NULL OR btrim(group_name) = '');

UPDATE sand_iam_service_action action_row
SET description = '由服务调用创建或更新应用访客身份', update_time = CURRENT_TIMESTAMP
FROM sand_iam_service service_row
WHERE service_row.id = action_row.service_id
  AND service_row.code = 'sand-iam' AND action_row.code = 'identity.guest.upsert'
  AND action_row.delete_time IS NULL
  AND (action_row.description IS NULL OR btrim(action_row.description) = '');

-- The registered contract audience is whatever the service's grants already
-- use, but only when the history is unambiguous. Ambiguous or empty history
-- stays NULL so the capability API reports a controlled conflict instead of
-- guessing an audience from request input.
UPDATE sand_iam_service service_row
SET audience = derived.audience, update_time = CURRENT_TIMESTAMP
FROM (
    SELECT action.service_id, min(grant_row.audience) AS audience
    FROM sand_iam_service_grant grant_row
    JOIN sand_iam_service_action action ON action.id = grant_row.service_action_id
    WHERE grant_row.delete_time IS NULL
    GROUP BY action.service_id
    HAVING count(DISTINCT grant_row.audience) = 1
) derived
WHERE service_row.id = derived.service_id
  AND service_row.delete_time IS NULL
  AND (service_row.audience IS NULL OR btrim(service_row.audience) = '');

WITH self_checksum(checksum) AS (VALUES ('7e0b920249f8cc92ce7b35993c173b2c0ecc16ac17d3f71e0812bfea64cb59fc'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '044_application_capability_directory.pgsql', 44, self_checksum.checksum, '0.9.7', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
DECLARE recorded_rows integer;
BEGIN
    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration
    WHERE migration_file = '044_application_capability_directory.pgsql'
      AND revision = 44
      AND package_version = '0.9.7';
    IF recorded_rows <> 1 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 45 THEN
        RAISE EXCEPTION 'SandIAM migration 044 did not close the exact 001-044 ledger';
    END IF;
END $$;

COMMIT;
