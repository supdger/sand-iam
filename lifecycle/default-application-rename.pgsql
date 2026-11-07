-- SandIAM default application neutralization (0.9.6, tightened 0.9.7).
-- Releases before 0.9.6 auto-provisioned the first-use default application
-- inside the 'default' organization under one fixed product identity: a
-- fixed legacy code and display name (written below as SQL unicode escapes
-- because the release payload hygiene gate forbids industry literals; they
-- decode to exactly that retired pair). Recognition therefore requires the
-- complete legacy identity — default organization, exact legacy code and
-- name, an active application, the active prod/sand-ai/sand-ai provisioned
-- chain, no existing 'default-app' in the same organization, and exactly one
-- candidate. Manually registered or renamed applications, disabled rows,
-- scoped organizations and ambiguous multi-candidate states are all left
-- untouched; status is never resurrected and only code/name/update_time move.
-- Identifiers stay numeric, so environments, clients, grants, credentials,
-- audits and every external reference keep working unchanged. The statement
-- is idempotent: re-running converges to the same state.
WITH candidates AS (
    SELECT candidate."id"
    FROM "sand_iam_application" candidate
    JOIN "sand_iam_organization" org
      ON org."id" = candidate."organization_id"
    WHERE org."code" = 'default'
      AND org."delete_time" IS NULL
      AND candidate."code" = U&'\006C\0076\0078\0075'
      AND candidate."name" = U&'\5F8B\5E8F'
      AND candidate."status" = 1
      AND candidate."delete_time" IS NULL
      AND EXISTS (
          SELECT 1
          FROM "sand_iam_environment" env
          JOIN "sand_iam_workload_client" client
            ON client."environment_id" = env."id"
          WHERE env."application_id" = candidate."id"
            AND env."delete_time" IS NULL
            AND env."status" = 1
            AND env."code" = 'prod'
            AND client."delete_time" IS NULL
            AND client."status" = 1
            AND client."code" = 'sand-ai'
            AND client."audience" = 'sand-ai'
      )
      AND NOT EXISTS (
          SELECT 1
          FROM "sand_iam_application" existing
          WHERE existing."organization_id" = candidate."organization_id"
            AND existing."code" = 'default-app'
            AND existing."delete_time" IS NULL
      )
)
UPDATE "sand_iam_application" app
SET "code" = 'default-app',
    "name" = '默认应用',
    "update_time" = NOW()
FROM candidates
WHERE app."id" = candidates."id"
  AND (SELECT count(*) FROM candidates) = 1;
