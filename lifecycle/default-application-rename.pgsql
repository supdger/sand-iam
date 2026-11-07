-- SandIAM default application neutralization (0.9.6).
-- Releases before 0.9.6 auto-provisioned the first-use default application
-- under a product-specific code and display name. The neutral defaults are
-- 'default-app' / '默认应用'. Recognition is structural and never name-based:
-- the provisioned chain is the application that owns the 'prod' environment
-- hosting the 'sand-ai' workload client whose audience is 'sand-ai'. Manually
-- registered applications without that exact auto-provisioned chain are
-- untouched, and at most one application per organization is renamed.
-- Identifiers stay numeric, so environments, clients, grants, credentials,
-- audits and every external reference keep working unchanged. The statement
-- is idempotent: re-running converges to the same state.
UPDATE "sand_iam_application" app
SET "code" = 'default-app',
    "name" = '默认应用',
    "update_time" = NOW()
WHERE app."delete_time" IS NULL
  AND app."code" <> 'default-app'
  AND NOT EXISTS (
      SELECT 1 FROM "sand_iam_application" existing
      WHERE existing."organization_id" = app."organization_id"
        AND existing."code" = 'default-app'
        AND existing."delete_time" IS NULL
  )
  AND app."id" = (
      SELECT candidate."id"
      FROM "sand_iam_application" candidate
      WHERE candidate."organization_id" = app."organization_id"
        AND candidate."delete_time" IS NULL
        AND candidate."code" <> 'default-app'
        AND EXISTS (
            SELECT 1
            FROM "sand_iam_environment" env
            JOIN "sand_iam_workload_client" client
              ON client."environment_id" = env."id"
            WHERE env."application_id" = candidate."id"
              AND env."delete_time" IS NULL
              AND env."code" = 'prod'
              AND client."delete_time" IS NULL
              AND client."code" = 'sand-ai'
              AND client."audience" = 'sand-ai'
        )
      ORDER BY candidate."id"
      LIMIT 1
  );
