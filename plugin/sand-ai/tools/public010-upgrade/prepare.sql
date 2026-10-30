-- Generated offline by build-legacy-adoption-sql.php --public010 --stage=prepare.
-- Execution requires separate migration authorization and stopped consumers.
BEGIN;
SELECT pg_advisory_xact_lock(hashtext('sand_ai_main_schema_migration_v1'));
DO $public010_prepare_identity$
BEGIN
    IF current_database() <> @@DATABASE@@ OR current_schema() <> 'public' THEN
        RAISE EXCEPTION 'Protected backup belongs to another existing database or schema';
    END IF;
    IF to_regclass('public.sand_ai_schema_migration') IS NOT NULL THEN
        RAISE EXCEPTION 'Unified ledger already exists; use the normal Package upgrade';
    END IF;
END
$public010_prepare_identity$;
@@GUARD_SQL@@
SELECT pg_temp.sand_ai_public010_check(@@BACKUP_SNAPSHOT@@::jsonb);
CREATE TABLE IF NOT EXISTS public.sand_ai_public010_upgrade (
    profile text PRIMARY KEY CHECK (profile='public010'),
    database_name text NOT NULL,
    schema_name text NOT NULL CHECK (schema_name='public'),
    old_package_sha256 char(64) NOT NULL CHECK (old_package_sha256='509fbb9ab18b112400d6979443fd00821e4bea293f033a0b130bc8ad8ac2909e'),
    target_version text NOT NULL,
    target_update_sha256 char(64) NOT NULL CHECK (target_update_sha256 ~ '^[a-f0-9]{64}$'),
    target_profile_sha256 char(64) NOT NULL CHECK (target_profile_sha256 ~ '^[a-f0-9]{64}$'),
    backup_manifest_sha256 char(64) NOT NULL CHECK (backup_manifest_sha256 ~ '^[a-f0-9]{64}$'),
    table_snapshot jsonb NOT NULL CHECK (jsonb_typeof(table_snapshot)='array' AND jsonb_array_length(table_snapshot)=14),
    prepared_by name NOT NULL,
    state text NOT NULL CHECK (state IN ('prepared','bridged')),
    prepared_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bridged_at timestamp with time zone NULL
);
DO $public010_prepare$
DECLARE
    original public.sand_ai_public010_upgrade%ROWTYPE;
BEGIN
    LOCK TABLE public.sand_ai_public010_upgrade IN ACCESS EXCLUSIVE MODE;
    IF NOT EXISTS (SELECT 1 FROM pg_class WHERE oid='public.sand_ai_public010_upgrade'::regclass
        AND relowner=(SELECT oid FROM pg_roles WHERE rolname=current_user) AND relkind='r'
        AND relacl IS NULL AND NOT relrowsecurity)
      OR EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid='public.sand_ai_public010_upgrade'::regclass AND NOT tgisinternal) THEN
        RAISE EXCEPTION 'Public 0.1.0 prepared intent is not owned by this maintenance connection';
    END IF;
    SELECT * INTO original FROM public.sand_ai_public010_upgrade WHERE profile='public010';
    IF FOUND THEN
        IF original.state <> 'prepared' OR original.database_name <> current_database()
          OR original.schema_name <> 'public' OR original.prepared_by <> current_user
          OR original.target_version <> @@TARGET_VERSION@@
          OR original.target_update_sha256 <> @@TARGET_UPDATE_SHA256@@
          OR original.target_profile_sha256 <> @@TARGET_PROFILE_SHA256@@
          OR original.backup_manifest_sha256 <> @@BACKUP_MANIFEST_SHA256@@
          OR original.table_snapshot <> @@BACKUP_SNAPSHOT@@::jsonb THEN
            RAISE EXCEPTION 'Another backup or candidate owns the prepared intent; restore or resume the original intent';
        END IF;
    ELSE
        INSERT INTO public.sand_ai_public010_upgrade(profile,database_name,schema_name,old_package_sha256,
            target_version,target_update_sha256,target_profile_sha256,backup_manifest_sha256,table_snapshot,prepared_by,state)
        VALUES ('public010',current_database(),'public','509fbb9ab18b112400d6979443fd00821e4bea293f033a0b130bc8ad8ac2909e',
            @@TARGET_VERSION@@,@@TARGET_UPDATE_SHA256@@,@@TARGET_PROFILE_SHA256@@,
            @@BACKUP_MANIFEST_SHA256@@,@@BACKUP_SNAPSHOT@@::jsonb,current_user,'prepared');
    END IF;
END
$public010_prepare$;
-- Drop only the temporary reference objects, in reverse foreign-key order.
DROP TABLE pg_temp.sand_ai_public010_upgrade;
DROP TABLE pg_temp.sand_ai_task_step;
DROP TABLE pg_temp.sand_ai_task_run;
DROP TABLE pg_temp.sand_ai_capability_route;
DROP TABLE pg_temp.sand_ai_capability_profile;
DROP TABLE pg_temp.sand_ai_source_block;
DROP TABLE pg_temp.sand_ai_file_parse;
DROP TABLE pg_temp.sand_ai_file;
DROP TABLE pg_temp.sand_ai_audit_log;
DROP TABLE pg_temp.sand_ai_usage;
DROP TABLE pg_temp.sand_ai_invocation;
DROP TABLE pg_temp.sand_ai_config_revision;
DROP TABLE pg_temp.sand_ai_model_deployment;
DROP TABLE pg_temp.sand_ai_model;
DROP TABLE pg_temp.sand_ai_provider;
DROP FUNCTION pg_temp.sand_ai_public010_check(jsonb);
COMMIT;
