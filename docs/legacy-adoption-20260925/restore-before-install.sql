-- Candidate only. Valid only before any new SandAI baseline objects are installed.
-- Requires explicit migration authorization and a verified backup.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '5min';

DO $restore$
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
BEGIN
    IF current_database() <> 'sandai' THEN
        RAISE EXCEPTION 'This restore only accepts the existing sandai database';
    END IF;
    IF to_regnamespace('sand_ai_legacy_20260925') IS NULL THEN
        RAISE EXCEPTION 'The expected archive schema is missing';
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_tables
        WHERE schemaname = 'public' AND left(tablename, 8) = 'sand_ai_'
    ) OR EXISTS (
        SELECT 1 FROM pg_sequences
        WHERE schemaname = 'public' AND left(sequencename, 8) = 'sand_ai_'
    ) THEN
        RAISE EXCEPTION 'New SandAI objects exist; restore requires a separate reviewed plan';
    END IF;
    SELECT array_agg(tablename ORDER BY tablename) INTO actual
    FROM pg_tables
    WHERE schemaname = 'sand_ai_legacy_20260925' AND left(tablename, 8) = 'sand_ai_';
    IF actual IS DISTINCT FROM expected
       OR (SELECT count(*) FROM pg_sequences
           WHERE schemaname = 'sand_ai_legacy_20260925' AND left(sequencename, 8) = 'sand_ai_') <> 18 THEN
        RAISE EXCEPTION 'The archive inventory changed';
    END IF;
    FOR table_name IN SELECT unnest(expected) LOOP
        EXECUTE format('LOCK TABLE sand_ai_legacy_20260925.%I IN ACCESS EXCLUSIVE MODE NOWAIT', table_name);
    END LOOP;
    FOR table_name IN SELECT unnest(expected) LOOP
        EXECUTE format('ALTER TABLE sand_ai_legacy_20260925.%I SET SCHEMA public', table_name);
    END LOOP;
    IF (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND left(tablename, 8) = 'sand_ai_') <> 18
       OR (SELECT count(*) FROM pg_sequences WHERE schemaname = 'public' AND left(sequencename, 8) = 'sand_ai_') <> 18
       OR EXISTS (SELECT 1 FROM pg_tables WHERE schemaname = 'sand_ai_legacy_20260925')
       OR EXISTS (SELECT 1 FROM pg_sequences WHERE schemaname = 'sand_ai_legacy_20260925') THEN
        RAISE EXCEPTION 'Restored table or identity sequence inventory is incomplete';
    END IF;
END
$restore$;

DROP SCHEMA sand_ai_legacy_20260925;
COMMIT;
