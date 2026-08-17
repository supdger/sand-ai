BEGIN;

-- This is the explicit destructive path after administrator confirmation.
-- Only data owned by the full SandAI package is removed. SandIAM identities,
-- applications, environments, workload clients and grants are never touched.
DROP TABLE IF EXISTS sand_ai_task_step CASCADE;
DROP TABLE IF EXISTS sand_ai_task_run CASCADE;
DROP TABLE IF EXISTS sand_ai_capability_route CASCADE;
DROP TABLE IF EXISTS sand_ai_capability_profile CASCADE;
DROP TABLE IF EXISTS sand_ai_source_block CASCADE;
DROP TABLE IF EXISTS sand_ai_file_parse CASCADE;
DROP TABLE IF EXISTS sand_ai_file CASCADE;
DROP TABLE IF EXISTS sand_ai_audit_log CASCADE;
DROP TABLE IF EXISTS sand_ai_usage CASCADE;
DROP TABLE IF EXISTS sand_ai_invocation CASCADE;
DROP TABLE IF EXISTS sand_ai_config_revision CASCADE;
DROP TABLE IF EXISTS sand_ai_model_deployment CASCADE;
DROP TABLE IF EXISTS sand_ai_model CASCADE;
DROP TABLE IF EXISTS sand_ai_provider CASCADE;

COMMIT;
