<?php

declare(strict_types=1);

require_once __DIR__ . '/lifecycle-sql.php';

/**
 * Build-only SQL for standard 0.9.7 upgrades.
 *
 * Admits the exact published ledger states 43/44/45/46, converges them through
 * the immutable 043/044/045 migration bodies, then binds the post-revision045
 * sand_iam catalog capture. The catalog fixture is generated from a scratch
 * PostgreSQL database running the frozen install payload; tools/ never ships
 * in runtime ZIPs.
 */
function sandIam097CatalogSha256(): string
{
    return '539650c41de4848d93680abf20184d3d72bb8c76dfc0db28248e91e02a5cd703';
}

/** @return array{relations:int,columns:int,constraints:int,indexes:int,triggers:int,policies:int} */
function sandIam097CatalogCounts(): array
{
    return ['relations' => 259, 'columns' => 1524, 'constraints' => 1437, 'indexes' => 310, 'triggers' => 0, 'policies' => 0];
}

function sandIamBuild097Update(string $root): string
{
    // SAND_IAM_LIFECYCLE_BOOTSTRAP=1 exists only so the frozen install payload
    // can be generated once on a scratch database to capture the reviewed
    // revision045 catalog; every committed build has the capture committed.
    $bootstrap = getenv('SAND_IAM_LIFECYCLE_BOOTSTRAP') === '1';
    $catalogPath = $root . '/release/tools/resources/schema-045-catalog.json';
    $rawCatalog = is_file($catalogPath) ? file_get_contents($catalogPath) : false;
    if (!$bootstrap && (!is_string($rawCatalog)
        || hash('sha256', $rawCatalog) !== sandIam097CatalogSha256())) {
        throw new RuntimeException('0.9.7 requires the exact reviewed revision045 catalog capture');
    }
    $catalog = is_string($rawCatalog) ? json_decode($rawCatalog, true, 32, JSON_THROW_ON_ERROR) : null;
    if (!$bootstrap && (!is_array($catalog)
        || array_keys($catalog) !== ['relations', 'columns', 'constraints', 'indexes', 'triggers', 'policies']
        || array_map('count', $catalog) !== sandIam097CatalogCounts())) {
        throw new RuntimeException('0.9.7 reviewed catalog groups are incomplete');
    }
    $oldAdmission = file_get_contents($root . '/lifecycle/update-073-or-075-or-076-to-080-preflight.pgsql');
    $migration043 = file_get_contents($root . '/migrations/043_scope_audit_event_key.pgsql');
    $migration044 = file_get_contents($root . '/migrations/044_application_capability_directory.pgsql');
    $migration045 = file_get_contents($root . '/migrations/045_application_capability_permissions.pgsql');
    $migration046 = file_get_contents($root . '/migrations/046_application_capability_menu.pgsql');
    if (!is_string($oldAdmission) || !is_string($migration043) || !is_string($migration044) || !is_string($migration045) || !is_string($migration046)
        || hash('sha256', $oldAdmission) !== '4c4e867f013f33571129ea0ad62a00952e4a75fd4177ef68caf8fdb997e2c3f2'
        || hash('sha256', $migration043) !== '25b37adbb3cf28aebaf997fd25322b6c4798a6ce734d59d81896d32f0174be12'
        || hash('sha256', $migration044) !== '1f9754bb41468dc2a513b173bb3dd15fd73e3f3ceb7c493a09e6c4bcfbbe8913'
        || hash('sha256', $migration045) !== 'd37ab6f53729cec302c841795d01a4a16b6166d4476449108c76174c9abc8c5f'
        || hash('sha256', $migration046) !== '3b70480fddbc9b24e6cef60f48b3b9158e8510494d02e9e9fc870b53233c6884') {
        throw new RuntimeException('0.9.7 requires the original immutable revision043-046 sources');
    }
    $admissionStart = strpos($oldAdmission, 'DO $$');
    $admissionEnd = strrpos($oldAdmission, 'END $$;');
    $structureStart = strpos($oldAdmission, "BEGIN\n");
    $structureEnd = strpos($oldAdmission, '    WITH expected(');
    $valuesStart = strpos($oldAdmission, "        VALUES\n");
    $valuesEnd = strpos($oldAdmission, "\n    ), difference AS");
    if ($admissionStart === false || $admissionEnd === false
        || $structureStart === false || $structureEnd === false
        || $valuesStart === false || $valuesEnd === false) {
        throw new RuntimeException('Cannot reuse the frozen published43 admission');
    }
    $oldAdmissionBody = substr($oldAdmission, $admissionStart, $admissionEnd + strlen('END $$;') - $admissionStart);
    $ledgerStructure = substr($oldAdmission, $structureStart + strlen("BEGIN\n"), $structureEnd - ($structureStart + strlen("BEGIN\n")));
    $values = substr($oldAdmission, $valuesStart + strlen("        VALUES\n"), $valuesEnd - ($valuesStart + strlen("        VALUES\n")));
    preg_match_all("/\\('([0-9]{3}_[^']+\\.pgsql)', (\\d+), '([a-f0-9]{64})', '([^']+)'\\)/", $values, $rows);
    if (count($rows[0]) !== 43) throw new RuntimeException('Published43 ledger cannot be reused exactly');
    $values .= ",\n            ('043_scope_audit_event_key.pgsql', 43, '937a58b5cd1ceb3ada3ae0e0cd2a34166d53db87b60a1c096a5a55dd5c549994', '0.8.0')"
        . ",\n            ('044_application_capability_directory.pgsql', 44, '7e0b920249f8cc92ce7b35993c173b2c0ecc16ac17d3f71e0812bfea64cb59fc', '0.9.7')"
        . ",\n            ('045_application_capability_permissions.pgsql', 45, '3fbb764237de7a3d2380fe17c82fa197680e50848765d8f5be81969157c5aff6', '0.9.7')"
        . ",\n            ('046_application_capability_menu.pgsql', 46, '5c9f95af06b97ad399f4e2431bd82751810316b69cbdfd9390a165892fd08f51', '0.9.7')";

    // Query columns and ordering are copied exactly from the captured public
    // provider used for the revision043 capture.
    $match = "left(c.relname, char_length('sand_iam')) = 'sand_iam'";
    $queries = [
        'relations' => "SELECT c.relname, c.relkind::text, c.relpersistence::text, c.relrowsecurity, c.relforcerowsecurity,
            CASE WHEN c.relkind IN ('v','m') THEN pg_get_viewdef(c.oid, true) ELSE NULL END AS view_definition
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND $match ORDER BY c.relname, c.relkind",
        'columns' => "SELECT c.relname, a.attnum, a.attname, a.atttypid::text, a.atttypmod, a.attnotnull,
            a.attidentity, a.attgenerated, pg_get_expr(d.adbin,d.adrelid) AS default_expression
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_attribute a ON a.attrelid=c.oid
            LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum
            WHERE n.nspname='public' AND $match AND a.attnum>0 AND NOT a.attisdropped
            ORDER BY c.relname,a.attnum",
        'constraints' => "SELECT c.relname, con.conname, pg_get_constraintdef(con.oid, true) AS definition
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_constraint con ON con.conrelid=c.oid
            WHERE n.nspname='public' AND $match ORDER BY c.relname,con.conname",
        'indexes' => "SELECT c.relname, i.relname AS index_name, pg_get_indexdef(i.oid) AS definition
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_index x ON x.indrelid=c.oid
            JOIN pg_class i ON i.oid=x.indexrelid
            WHERE n.nspname='public' AND $match ORDER BY c.relname,i.relname",
        'triggers' => "SELECT c.relname, t.tgname, pg_get_triggerdef(t.oid, true) AS definition
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_trigger t ON t.tgrelid=c.oid
            WHERE n.nspname='public' AND $match AND NOT t.tgisinternal
            ORDER BY c.relname,t.tgname",
        'policies' => "SELECT c.relname, p.polname, p.polcmd, p.polpermissive,
            pg_get_expr(p.polqual,p.polrelid) AS using_expression,
            pg_get_expr(p.polwithcheck,p.polrelid) AS check_expression
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_policy p ON p.polrelid=c.oid
            WHERE n.nspname='public' AND $match ORDER BY c.relname,p.polname",
    ];
    $catalogChecks = '';
    if (!$bootstrap) {
        foreach ($queries as $group => $query) {
            $expected = json_encode($catalog[$group], JSON_THROW_ON_ERROR);
            $tag = '$iam097_' . $group . '$';
            if (str_contains($expected, $tag)) throw new RuntimeException('Catalog dollar-quote collision');
            $catalogChecks .= "    SELECT COALESCE(jsonb_agg(to_jsonb(captured)), '[]'::jsonb) INTO actual_catalog\n"
                . "    FROM ({$query}) captured;\n"
                . "    IF actual_catalog IS DISTINCT FROM {$tag}{$expected}{$tag}::jsonb THEN\n"
                . "        RAISE EXCEPTION 'SandIAM 0.9.7 revision045 {$group} catalog differs from the exact reviewed structure';\n"
                . "    END IF;\n";
        }
    }
    $migration043Body = withoutOuterTransaction($migration043, '043_scope_audit_event_key.pgsql');
    $migration044Body = withoutOuterTransaction($migration044, '044_application_capability_directory.pgsql');
    $migration045Body = withoutOuterTransaction($migration045, '045_application_capability_permissions.pgsql');
    $migration046Body = withoutOuterTransaction($migration046, '046_application_capability_menu.pgsql');
    $menuRegroup = file_get_contents($root . '/lifecycle/menu-regroup.pgsql');
    if (!is_string($menuRegroup)
        || hash('sha256', $menuRegroup) !== '647efa692b729423ff921464291300d5bd71b3b2a0d6aa53da890c206f92a283'
        || str_contains($menuRegroup, '$iam097_menu_regroup$')) {
        throw new RuntimeException('Menu regroup lifecycle source differs from the exact reviewed input');
    }
    $defaultAppRename = file_get_contents($root . '/lifecycle/default-application-rename.pgsql');
    if (!is_string($defaultAppRename) || trim($defaultAppRename) === '') {
        throw new RuntimeException('Default application neutralization lifecycle source is unreadable');
    }
    foreach (['$iam097_prior$', '$iam097_migration043$', '$iam097_migration044$', '$iam097_migration045$', '$iam097_migration046$', '$iam097_menu_regroup$', '$iam097_default_app$'] as $tag) {
        if (str_contains($oldAdmissionBody . $migration043Body . $migration044Body . $migration045Body . $migration046Body . $menuRegroup . $defaultAppRename, $tag)) {
            throw new RuntimeException('Migration dollar-quote collision');
        }
    }
    $upgrade = <<<'SQL'
DO $sand_iam_upgrade_097$
DECLARE
    ledger_pk text;
    mismatch_count integer;
    source_rows integer;
    actual_catalog jsonb;
BEGIN
    IF current_schema() IS DISTINCT FROM 'public' THEN
        RAISE EXCEPTION 'SandIAM 0.9.7 requires the public schema';
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_index x JOIN pg_class c ON c.oid=x.indrelid
        JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND left(c.relname, char_length('sand_iam'))='sand_iam'
          AND (NOT x.indisvalid OR NOT x.indisready OR NOT x.indislive)
    ) THEN
        RAISE EXCEPTION 'SandIAM 0.9.7 refuses invalid, unfinished or retired plugin indexes';
    END IF;
SQL;
    $upgrade .= "\n" . $ledgerStructure
        . "    SELECT count(*) INTO source_rows FROM public.sand_iam_schema_migration;\n"
        . "    IF source_rows = 43 THEN\n"
        . "        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public'\n"
        . "            AND table_name='sand_iam_audit_log' AND column_name='event_key') THEN\n"
        . "            RAISE EXCEPTION 'SandIAM 0.9.7 refuses revision043 structure with a missing or substituted ledger row';\n"
        . "        END IF;\n"
        . "        EXECUTE \$iam097_prior\${$oldAdmissionBody}\$iam097_prior\$;\n"
        . "        EXECUTE \$iam097_migration043\${$migration043Body}\$iam097_migration043\$;\n"
        . "    ELSIF source_rows NOT IN (44, 45, 46, 47) THEN\n"
        . "        RAISE EXCEPTION 'SandIAM 0.9.7 requires the exact published43-47 ledger';\n"
        . "    END IF;\n"
        . "    IF (SELECT count(*) FROM public.sand_iam_schema_migration) = 44\n"
        . "       AND NOT EXISTS (SELECT 1 FROM public.sand_iam_schema_migration\n"
        . "           WHERE migration_file = '044_application_capability_directory.pgsql') THEN\n"
        . "        EXECUTE \$iam097_migration044\${$migration044Body}\$iam097_migration044\$;\n"
        . "    END IF;\n"
        . "    IF (SELECT count(*) FROM public.sand_iam_schema_migration) = 45\n"
        . "       AND EXISTS (SELECT 1 FROM public.sand_iam_schema_migration\n"
        . "           WHERE migration_file = '044_application_capability_directory.pgsql'\n"
        . "             AND revision = 44 AND package_version = '0.9.7')\n"
        . "       AND NOT EXISTS (SELECT 1 FROM public.sand_iam_schema_migration\n"
        . "           WHERE migration_file = '045_application_capability_permissions.pgsql') THEN\n"
        . "        EXECUTE \$iam097_migration045\${$migration045Body}\$iam097_migration045\$;\n"
        . "    END IF;\n"
        . "    IF (SELECT count(*) FROM public.sand_iam_schema_migration) = 46\n"
        . "       AND EXISTS (SELECT 1 FROM public.sand_iam_schema_migration\n"
        . "           WHERE migration_file = '045_application_capability_permissions.pgsql'\n"
        . "             AND revision = 45 AND package_version = '0.9.7')\n"
        . "       AND NOT EXISTS (SELECT 1 FROM public.sand_iam_schema_migration\n"
        . "           WHERE migration_file = '046_application_capability_menu.pgsql') THEN\n"
        . "        EXECUTE \$iam097_migration046\${$migration046Body}\$iam097_migration046\$;\n"
        . "    END IF;\n"
        . "    WITH expected(migration_file, revision, checksum, package_version) AS (VALUES\n"
        . $values . "\n"
        . "    ), difference AS (\n"
        . "        SELECT e.migration_file FROM expected e FULL OUTER JOIN public.sand_iam_schema_migration a\n"
        . "          ON a.migration_file = e.migration_file\n"
        . "        WHERE e.migration_file IS NULL OR a.migration_file IS NULL\n"
        . "           OR a.revision IS DISTINCT FROM e.revision OR a.checksum::text IS DISTINCT FROM e.checksum\n"
        . "           OR a.package_version IS DISTINCT FROM e.package_version\n"
        . "    ) SELECT count(*) INTO mismatch_count FROM difference;\n"
        . "    IF mismatch_count <> 0 OR (SELECT count(*) FROM public.sand_iam_schema_migration) <> 47 THEN\n"
        . "        RAISE EXCEPTION 'SandIAM 0.9.7 requires the exact complete 47-row ledger through revision046';\n"
        . "    END IF;\n"
        . $catalogChecks
        . "    EXECUTE \$iam097_menu_regroup\$"
        . $menuRegroup
        . "\$iam097_menu_regroup\$;\n"
        . "    EXECUTE \$iam097_default_app\$"
        . $defaultAppRename
        . "\$iam097_default_app\$;\n"
        . "END \$sand_iam_upgrade_097\$;\n";

    return "-- SandIAM 0.9.7 standard upgrade: strict43-47 admission, immutable043/044/045/046 conditional bodies.\n"
        . "-- Catalog input: scratch PostgreSQL capture of the frozen install payload at revision045.\n"
        . "BEGIN ISOLATION LEVEL REPEATABLE READ;\n" . $upgrade . "\nCOMMIT;\n";
}
