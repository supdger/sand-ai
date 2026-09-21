BEGIN;

-- This is the explicit destructive path after administrator confirmation.
-- Only data owned by the full SandAI package is removed. SandIAM identities,
-- applications, environments, workload clients and grants are never touched.
DROP TABLE IF EXISTS sand_ai_task_step RESTRICT;
DROP TABLE IF EXISTS sand_ai_task_run RESTRICT;
DROP TABLE IF EXISTS sand_ai_capability_route RESTRICT;
DROP TABLE IF EXISTS sand_ai_capability_profile RESTRICT;
DROP TABLE IF EXISTS sand_ai_source_block RESTRICT;
DROP TABLE IF EXISTS sand_ai_file_parse RESTRICT;
DROP TABLE IF EXISTS sand_ai_file RESTRICT;
DROP TABLE IF EXISTS sand_ai_audit_log RESTRICT;
DROP TABLE IF EXISTS sand_ai_usage RESTRICT;
DROP TABLE IF EXISTS sand_ai_invocation RESTRICT;
DROP TABLE IF EXISTS sand_ai_config_revision RESTRICT;
DROP TABLE IF EXISTS sand_ai_model_deployment RESTRICT;
DROP TABLE IF EXISTS sand_ai_model RESTRICT;
DROP TABLE IF EXISTS sand_ai_provider RESTRICT;

COMMIT;
