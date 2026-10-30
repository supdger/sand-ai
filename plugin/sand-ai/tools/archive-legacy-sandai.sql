-- Candidate only. Requires a data backup, stopped legacy consumers and explicit migration authorization.
-- This moves old SandAI tables inside the existing sandai database; it never creates a database.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '5min';

DO $archive$
DECLARE
    expected text[] := ARRAY[
        'sand_ai_api_credential', 'sand_ai_application', 'sand_ai_application_model',
        'sand_ai_audit_log', 'sand_ai_capability_profile', 'sand_ai_capability_route',
        'sand_ai_config_revision', 'sand_ai_environment', 'sand_ai_file',
        'sand_ai_file_parse', 'sand_ai_invocation', 'sand_ai_model',
        'sand_ai_model_deployment', 'sand_ai_provider', 'sand_ai_source_block',
        'sand_ai_task_run', 'sand_ai_task_step', 'sand_ai_usage'
    ];
    actual text[];
    table_name text;
    row_count bigint;
    sequence_count bigint;
    external_dependency_count bigint;
BEGIN
    IF current_database() <> 'sandai' THEN
        RAISE EXCEPTION 'This adoption only accepts the existing sandai database';
    END IF;
    IF to_regnamespace('sand_ai_legacy_20260925') IS NOT NULL THEN
        RAISE EXCEPTION 'The archive schema already exists';
    END IF;
    SELECT array_agg(tablename ORDER BY tablename) INTO actual
    FROM pg_tables
    WHERE schemaname = 'public' AND left(tablename, 8) = 'sand_ai_';
    IF actual IS DISTINCT FROM expected THEN
        RAISE EXCEPTION 'The legacy SandAI table inventory changed';
    END IF;
    SELECT count(*) INTO sequence_count
    FROM pg_class sequence
    JOIN pg_namespace sequence_schema ON sequence_schema.oid = sequence.relnamespace
    JOIN pg_depend ownership ON ownership.objid = sequence.oid AND ownership.deptype IN ('a', 'i')
    JOIN pg_class owner_table ON owner_table.oid = ownership.refobjid
    JOIN pg_namespace owner_schema ON owner_schema.oid = owner_table.relnamespace
    WHERE sequence.relkind = 'S' AND sequence_schema.nspname = 'public'
      AND owner_schema.nspname = 'public' AND left(owner_table.relname, 8) = 'sand_ai_';
    IF sequence_count <> 18 THEN
        RAISE EXCEPTION 'The legacy SandAI owned-sequence inventory changed';
    END IF;
    SELECT count(*) INTO external_dependency_count
    FROM pg_constraint fk
    JOIN pg_class parent_table ON parent_table.oid = fk.confrelid
    JOIN pg_namespace parent_schema ON parent_schema.oid = parent_table.relnamespace
    JOIN pg_class child_table ON child_table.oid = fk.conrelid
    JOIN pg_namespace child_schema ON child_schema.oid = child_table.relnamespace
    WHERE fk.contype = 'f' AND parent_schema.nspname = 'public'
      AND left(parent_table.relname, 8) = 'sand_ai_'
      AND NOT (child_schema.nspname = 'public' AND left(child_table.relname, 8) = 'sand_ai_');
    IF external_dependency_count <> 0 THEN
        RAISE EXCEPTION 'External foreign keys refer to old SandAI tables';
    END IF;
    SELECT count(DISTINCT dependent_view.oid) INTO external_dependency_count
    FROM pg_depend dependency
    JOIN pg_rewrite rule ON rule.oid = dependency.objid
    JOIN pg_class dependent_view ON dependent_view.oid = rule.ev_class
    JOIN pg_namespace view_schema ON view_schema.oid = dependent_view.relnamespace
    JOIN pg_class source_table ON source_table.oid = dependency.refobjid
    JOIN pg_namespace source_schema ON source_schema.oid = source_table.relnamespace
    WHERE source_schema.nspname = 'public' AND left(source_table.relname, 8) = 'sand_ai_'
      AND dependent_view.relkind IN ('v', 'm')
      AND NOT (view_schema.nspname = 'public' AND left(dependent_view.relname, 8) = 'sand_ai_');
    IF external_dependency_count <> 0 THEN
        RAISE EXCEPTION 'External views refer to old SandAI tables';
    END IF;
    SELECT count(*) INTO external_dependency_count
    FROM pg_trigger t
    JOIN pg_class source_table ON source_table.oid = t.tgrelid
    JOIN pg_namespace source_schema ON source_schema.oid = source_table.relnamespace
    WHERE source_schema.nspname = 'public' AND left(source_table.relname, 8) = 'sand_ai_'
      AND NOT t.tgisinternal;
    IF external_dependency_count <> 0 THEN
        RAISE EXCEPTION 'User triggers on old SandAI tables require separate review';
    END IF;
    SELECT count(*) INTO external_dependency_count
    FROM pg_proc p
    JOIN pg_namespace pn ON pn.oid = p.pronamespace
    WHERE p.prokind IN ('f', 'p')
      AND pn.nspname NOT IN ('pg_catalog', 'information_schema')
      AND strpos(lower(pg_get_functiondef(p.oid)), 'sand_ai_') > 0;
    IF external_dependency_count <> 0 THEN
        RAISE EXCEPTION 'Database functions mention old SandAI tables';
    END IF;
    FOR table_name IN SELECT unnest(expected) LOOP
        EXECUTE format('LOCK TABLE public.%I IN ACCESS EXCLUSIVE MODE NOWAIT', table_name);
        EXECUTE format('SELECT count(*) FROM public.%I', table_name) INTO row_count;
        IF table_name IN ('sand_ai_api_credential', 'sand_ai_application', 'sand_ai_environment') THEN
            IF row_count <> 1 THEN
                RAISE EXCEPTION 'The retained row count changed for %', table_name;
            END IF;
        ELSIF row_count <> 0 THEN
            RAISE EXCEPTION 'Unexpected data appeared in %', table_name;
        END IF;
    END LOOP;
END
$archive$;

CREATE SCHEMA sand_ai_legacy_20260925;

DO $move$
DECLARE
    table_name text;
BEGIN
    FOR table_name IN
        SELECT unnest(ARRAY[
            'sand_ai_api_credential', 'sand_ai_application', 'sand_ai_application_model',
            'sand_ai_audit_log', 'sand_ai_capability_profile', 'sand_ai_capability_route',
            'sand_ai_config_revision', 'sand_ai_environment', 'sand_ai_file',
            'sand_ai_file_parse', 'sand_ai_invocation', 'sand_ai_model',
            'sand_ai_model_deployment', 'sand_ai_provider', 'sand_ai_source_block',
            'sand_ai_task_run', 'sand_ai_task_step', 'sand_ai_usage'
        ])
    LOOP
        EXECUTE format('ALTER TABLE public.%I SET SCHEMA sand_ai_legacy_20260925', table_name);
    END LOOP;
    IF (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND left(tablename, 8) = 'sand_ai_') <> 0
       OR (SELECT count(*) FROM pg_tables WHERE schemaname = 'sand_ai_legacy_20260925' AND left(tablename, 8) = 'sand_ai_') <> 18
       OR (SELECT count(*) FROM pg_sequences WHERE schemaname = 'public' AND left(sequencename, 8) = 'sand_ai_') <> 0
       OR (SELECT count(*) FROM pg_sequences WHERE schemaname = 'sand_ai_legacy_20260925' AND left(sequencename, 8) = 'sand_ai_') <> 18 THEN
        RAISE EXCEPTION 'Archive table or identity sequence inventory is incomplete';
    END IF;
END
$move$;

COMMIT;
