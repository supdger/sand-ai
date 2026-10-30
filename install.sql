-- Generated from database/migrations by scripts/build-sand-ai-install-sql.php.
-- Fresh installs only. Existing SandAI objects require explicit adoption.
BEGIN;
DO $sand_ai_install$
BEGIN
    -- Reject objects that would collide with this package before the first schema write.
    IF EXISTS (
        SELECT 1 FROM pg_class AS object
        JOIN pg_namespace AS namespace ON namespace.oid = object.relnamespace
        WHERE namespace.nspname = current_schema()
          AND (starts_with(object.relname::text, 'sand_ai_')
            OR starts_with(object.relname::text, 'idx_sand_ai_')
            OR starts_with(object.relname::text, 'ix_sand_ai_')
            OR starts_with(object.relname::text, 'uk_sand_ai_')
            OR starts_with(object.relname::text, 'uq_sand_ai_'))
    ) THEN
        RAISE EXCEPTION 'Existing SandAI relation requires explicit adoption before fresh installation';
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_type AS object
        JOIN pg_namespace AS namespace ON namespace.oid = object.typnamespace
        WHERE namespace.nspname = current_schema()
          AND (starts_with(object.typname::text, 'sand_ai_')
            OR starts_with(object.typname::text, 'idx_sand_ai_')
            OR starts_with(object.typname::text, 'ix_sand_ai_')
            OR starts_with(object.typname::text, 'uk_sand_ai_')
            OR starts_with(object.typname::text, 'uq_sand_ai_'))
    ) THEN
        RAISE EXCEPTION 'Existing SandAI type requires explicit adoption before fresh installation';
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_proc AS routine
        JOIN pg_namespace AS namespace ON namespace.oid = routine.pronamespace
        WHERE namespace.nspname = current_schema()
          AND (
            (routine.proname::text = ANY (ARRAY[
            'sand_ai_provider_call_limit_immutable_v157',
            'sand_ai_provider_call_limit_pool_guard_v157',
            'sand_ai_provider_call_limit_unknown_v157',
            'sand_ai_composition_immutable_published',
            'sand_ai_rerank_soft_budget_guard_v138',
            'sand_ai_professional_rerank_usage_guard_v137',
            'sand_ai_meeting_terminal_proof_guard',
            'sand_ai_meeting_terminal_capture',
            'sand_ai_provider_identity_binding_v153',
            'sand_ai_provider_identity_immutable_v153',
            'sand_ai_ocr_frozen_binding_v150',
            'sand_ai_reject_ocr_mutation_v103',
            'sand_ai_ocr_dispatch_binding_v103',
            'sand_ai_ocr_usage_binding_v103',
            'sand_ai_audio_segment_review_immutable',
            'sand_ai_audio_transcript_seal_insert',
            'sand_ai_audio_transcript_seal_immutable',
            'sand_ai_audio_task_seal_guard',
            'sand_ai_audio_task_seal_purge',
            'sand_ai_audio_usage_task_guard_v029',
            'sand_ai_enforce_active_knowledge_version',
            'sand_ai_external_audio_dispatch_immutable_v031',
            'sand_ai_external_audio_receipt_immutable_v031',
            'sand_ai_external_audio_sync_completion_v032',
            'sand_ai_guard_knowledge_embedding_active',
            'sand_ai_guard_knowledge_embedding_index',
            'sand_ai_guard_knowledge_embedding_vector',
            'sand_ai_invocation_dispatch_no_provider_visible_delete_v026',
            'sand_ai_invocation_dispatch_no_terminal_overwrite',
            'sand_ai_invocation_dispatch_state_transition_v026',
            'sand_ai_invocation_outcome_receipt_prevent_overwrite',
            'sand_ai_invocation_skill_identity_immutable_v019',
            'sand_ai_knowledge_source_immutable',
            'sand_ai_meeting_summary_node_guard',
            'sand_ai_meeting_summary_node_parent_guard',
            'sand_ai_meeting_summary_node_purge',
            'sand_ai_pre_dispatch_rejection_immutable_v027',
            'sand_ai_billing_line_evidence_binding_v155',
            'sand_ai_billing_evidence_guard',
            'sand_ai_billing_review_guard',
            'sand_ai_reject_billing_mutation',
            'sand_ai_reject_budget_evidence_mutation',
            'sand_ai_budget_scope_matches_v093',
            'sand_ai_budget_owner_guard_v093',
            'sand_ai_skill_scope_immutable_v092',
            'sand_ai_skill_consumer_context_guard_v092',
            'sand_ai_skill_publish_consumer_guard_v092',
            'sand_ai_skill_source_suggestion_guard',
            'sand_ai_skill_provenance_immutable',
            'sand_ai_skill_adopted_source_guard',
            'sand_ai_skill_source_purge_request_immutable',
            'sand_ai_skill_source_redaction_allowed',
            'sand_ai_skill_source_suggestion_source_guard',
            'sand_ai_budget_event_owner_guard_v093',
            'sand_ai_reject_call_start_pricing_mutation',
            'sand_ai_reject_published_budget_policy_mutation',
            'sand_ai_reject_published_knowledge_version_mutation',
            'sand_ai_reject_published_price_mutation',
            'sand_ai_reject_published_source_block_mutation',
            'sand_ai_reject_usage_event_mutation',
            'sand_ai_skill_active_version_guard_v019',
            'sand_ai_skill_case_evaluation_guard',
            'sand_ai_skill_case_guard',
            'sand_ai_skill_knowledge_ref_immutable_v019',
            'sand_ai_skill_operation_immutable_v019',
            'sand_ai_skill_publish_guard_v019',
            'sand_ai_skill_published_version_guard',
            'sand_ai_skill_run_invocation_match_v019',
            'sand_ai_skill_version_immutable',
            'sand_ai_speech_budget_link_guard_v062',
            'sand_ai_speech_usage_guard_v061',
            'sand_ai_tool_attempt_terminal_immutable_v023',
            'sand_ai_tool_binding_guard',
            'sand_ai_tool_binding_published_guard_v023',
            'sand_ai_tool_immutable_guard',
            'sand_ai_tool_mcp_binding_guard',
            'sand_ai_tool_mcp_immutable_guard',
            'sand_ai_tool_mcp_run_guard',
            'sand_ai_tool_mcp_snapshot_item_insert_guard',
            'sand_ai_tool_mcp_snapshot_terminal_guard',
            'sand_ai_tool_mcp_version_guard',
            'sand_ai_tool_protocol_request_insert_guard',
            'sand_ai_tool_run_guard',
            'sand_ai_tool_run_terminal_immutable_v023',
            'sand_ai_tool_usage_protocol_quantity_guard',
            'sand_ai_usage_event_invocation_skill_match_v019',
            'sand_ai_workflow_cross_file_review_count_guard',
            'sand_ai_workflow_cross_file_review_evidence_immutable',
            'sand_ai_workflow_file_result_immutable',
            'sand_ai_workflow_file_output_failure_immutable',
            'sand_ai_meeting_token_count_immutable',
            'sand_ai_workflow_file_result_source_count_guard'
            ]) AND routine.pronargs = 0)
            OR routine.oid = to_regprocedure(format('%I.sand_ai_meeting_terminal_step(bigint, text)', current_schema()))
            OR routine.oid = to_regprocedure(format('%I.sand_ai_embedding_cosine(real[], real[])', current_schema()))
            OR routine.oid = to_regprocedure(format('%I.table_msg(text)', current_schema()))
          )
    ) THEN
        RAISE EXCEPTION 'Existing SandAI routine requires explicit adoption before fresh installation';
    END IF;
END
$sand_ai_install$;
CREATE TABLE sand_ai_schema_migration (
    version varchar(128) PRIMARY KEY,
    checksum char(64) NOT NULL,
    applied_time timestamp(0) without time zone NOT NULL
);

-- Migration 202608200000_create_main_application_baseline (sha256 9f49b93ae998206c88da49b5a08ab89963d18b938db11178b4a7e4d894699fec)
-- SandAI main-application baseline.  SandIAM owns identities, applications,
-- environments, clients, credentials and grants; their ids are opaque here.

CREATE TABLE IF NOT EXISTS sand_ai_provider (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code varchar(96) NOT NULL,
    name varchar(160) NOT NULL,
    adapter varchar(64) NOT NULL,
    encrypted_config jsonb NOT NULL DEFAULT '{}'::jsonb,
    timeout_ms integer NOT NULL DEFAULT 30000 CHECK (timeout_ms BETWEEN 1000 AND 120000),
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_provider_code_uniq UNIQUE (code)
);

CREATE TABLE IF NOT EXISTS sand_ai_model (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code varchar(128) NOT NULL,
    name varchar(160) NOT NULL,
    type varchar(32) NOT NULL,
    capabilities jsonb NOT NULL DEFAULT '[]'::jsonb,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_model_code_uniq UNIQUE (code)
);

CREATE TABLE IF NOT EXISTS sand_ai_model_deployment (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    model_id bigint NOT NULL REFERENCES sand_ai_model(id),
    provider_id bigint NOT NULL REFERENCES sand_ai_provider(id),
    remote_model varchar(256) NOT NULL,
    config jsonb NOT NULL DEFAULT '{}'::jsonb,
    revision integer NOT NULL CHECK (revision > 0),
    status smallint NOT NULL DEFAULT 2 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_model_deployment_model_revision_uniq UNIQUE (model_id, revision)
);
CREATE INDEX IF NOT EXISTS sand_ai_model_deployment_provider_idx ON sand_ai_model_deployment (provider_id, status);

CREATE TABLE IF NOT EXISTS sand_ai_config_revision (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    resource_type varchar(64) NOT NULL,
    resource_id bigint NOT NULL,
    revision integer NOT NULL CHECK (revision > 0),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    state varchar(32) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_config_revision_resource_revision_uniq UNIQUE (resource_type, resource_id, revision)
);
CREATE INDEX IF NOT EXISTS sand_ai_config_revision_lookup_idx ON sand_ai_config_revision (resource_type, resource_id, state, status);

CREATE TABLE IF NOT EXISTS sand_ai_capability_profile (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    name varchar(160) NOT NULL,
    template_code varchar(96) NULL,
    data_egress_policy varchar(64) NOT NULL,
    revision integer NOT NULL CHECK (revision > 0),
    state varchar(32) NOT NULL,
    status smallint NOT NULL DEFAULT 2 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_capability_profile_environment_revision_uniq UNIQUE (environment_id, revision)
);

CREATE TABLE IF NOT EXISTS sand_ai_capability_route (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    profile_id bigint NOT NULL REFERENCES sand_ai_capability_profile(id) ON DELETE CASCADE,
    capability_code varchar(96) NOT NULL,
    driver_code varchar(96) NULL,
    deployment_id bigint NULL REFERENCES sand_ai_model_deployment(id),
    priority integer NOT NULL DEFAULT 100 CHECK (priority >= 0),
    config jsonb NOT NULL DEFAULT '{}'::jsonb,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_capability_route_profile_capability_priority_uniq UNIQUE (profile_id, capability_code, priority)
);
CREATE INDEX IF NOT EXISTS sand_ai_capability_route_profile_capability_idx ON sand_ai_capability_route (profile_id, capability_code, status);

CREATE TABLE IF NOT EXISTS sand_ai_file (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    original_name varchar(512) NOT NULL,
    extension varchar(32) NOT NULL,
    media_type varchar(128) NOT NULL,
    size_bytes bigint NOT NULL CHECK (size_bytes >= 0),
    sha256 char(64) NOT NULL,
    storage_driver varchar(64) NOT NULL,
    object_key varchar(512) NOT NULL,
    state varchar(32) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL
);
CREATE INDEX IF NOT EXISTS sand_ai_file_environment_state_idx ON sand_ai_file (environment_id, state, status);

CREATE TABLE IF NOT EXISTS sand_ai_file_parse (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    file_id bigint NOT NULL REFERENCES sand_ai_file(id) ON DELETE CASCADE,
    parser_code varchar(96) NOT NULL,
    parser_version varchar(96) NOT NULL,
    state varchar(32) NOT NULL,
    source_block_count integer NOT NULL DEFAULT 0 CHECK (source_block_count >= 0),
    error_code varchar(96) NULL,
    error_summary varchar(512) NULL,
    started_at timestamp(0) without time zone NULL,
    completed_at timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL
);
CREATE INDEX IF NOT EXISTS sand_ai_file_parse_file_state_idx ON sand_ai_file_parse (file_id, state, id DESC);

CREATE TABLE IF NOT EXISTS sand_ai_source_block (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    file_id bigint NOT NULL REFERENCES sand_ai_file(id) ON DELETE CASCADE,
    parse_id bigint NOT NULL REFERENCES sand_ai_file_parse(id) ON DELETE CASCADE,
    sequence_no integer NOT NULL CHECK (sequence_no > 0),
    locator_type varchar(64) NOT NULL,
    locator jsonb NOT NULL DEFAULT '{}'::jsonb,
    content text NOT NULL,
    content_sha256 char(64) NOT NULL,
    search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', content)) STORED,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_source_block_parse_sequence_uniq UNIQUE (parse_id, sequence_no)
);
CREATE INDEX IF NOT EXISTS sand_ai_source_block_file_parse_idx ON sand_ai_source_block (file_id, parse_id, status, sequence_no);

CREATE INDEX IF NOT EXISTS sand_ai_source_block_search_vector_gin_idx
    ON sand_ai_source_block USING gin (search_vector);

CREATE TABLE IF NOT EXISTS sand_ai_task_run (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    task_type varchar(64) NOT NULL,
    resource_type varchar(64) NOT NULL,
    resource_id bigint NOT NULL,
    file_parse_id bigint NULL REFERENCES sand_ai_file_parse(id),
    request_id varchar(128) NOT NULL,
    state varchar(32) NOT NULL,
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    max_attempts integer NOT NULL CHECK (max_attempts > 0),
    available_at timestamp(0) without time zone NOT NULL,
    locked_at timestamp(0) without time zone NULL,
    locked_by varchar(160) NULL,
    started_at timestamp(0) without time zone NULL,
    completed_at timestamp(0) without time zone NULL,
    error_code varchar(96) NULL,
    error_summary varchar(512) NULL,
    input jsonb NOT NULL DEFAULT '{}'::jsonb,
    result jsonb NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_task_run_environment_request_uniq UNIQUE (environment_id, request_id)
);
CREATE INDEX IF NOT EXISTS sand_ai_task_run_claim_idx ON sand_ai_task_run (state, available_at, id);
CREATE INDEX IF NOT EXISTS sand_ai_task_run_file_parse_idx ON sand_ai_task_run (file_parse_id);

CREATE TABLE IF NOT EXISTS sand_ai_task_step (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE CASCADE,
    step_code varchar(96) NOT NULL,
    sequence_no integer NOT NULL CHECK (sequence_no > 0),
    state varchar(32) NOT NULL,
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    context jsonb NOT NULL DEFAULT '{}'::jsonb,
    error_code varchar(96) NULL,
    error_summary varchar(512) NULL,
    started_at timestamp(0) without time zone NULL,
    completed_at timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_task_step_task_sequence_uniq UNIQUE (task_id, sequence_no)
);

CREATE TABLE IF NOT EXISTS sand_ai_invocation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    request_id varchar(160) NOT NULL,
    environment_id bigint NOT NULL,
    model_id bigint NOT NULL REFERENCES sand_ai_model(id),
    deployment_id bigint NOT NULL REFERENCES sand_ai_model_deployment(id),
    request_fingerprint char(64) NOT NULL,
    state varchar(32) NOT NULL,
    latency_ms integer NULL CHECK (latency_ms >= 0),
    error_code varchar(96) NULL,
    error_summary varchar(512) NULL,
    request_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    completed_at timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_invocation_request_uniq UNIQUE (request_id)
);
CREATE INDEX IF NOT EXISTS sand_ai_invocation_environment_request_idx ON sand_ai_invocation (environment_id, request_at DESC);

CREATE TABLE IF NOT EXISTS sand_ai_usage (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id) ON DELETE CASCADE,
    input_tokens integer NOT NULL DEFAULT 0 CHECK (input_tokens >= 0),
    output_tokens integer NOT NULL DEFAULT 0 CHECK (output_tokens >= 0),
    total_tokens integer NOT NULL DEFAULT 0 CHECK (total_tokens >= 0),
    cost_amount numeric(20, 8) NOT NULL DEFAULT 0,
    currency varchar(3) NOT NULL DEFAULT 'USD',
    source varchar(64) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_usage_invocation_uniq UNIQUE (invocation_id)
);

CREATE TABLE IF NOT EXISTS sand_ai_audit_log (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NULL,
    actor_type varchar(64) NOT NULL,
    actor_ref varchar(160) NOT NULL,
    action varchar(128) NOT NULL,
    resource_type varchar(96) NOT NULL,
    resource_id bigint NOT NULL,
    summary varchar(512) NOT NULL,
    context jsonb NOT NULL DEFAULT '{}'::jsonb,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL
);
CREATE INDEX IF NOT EXISTS sand_ai_audit_log_environment_action_time_idx ON sand_ai_audit_log (environment_id, action, create_time DESC);
CREATE OR REPLACE FUNCTION table_msg(requested_table text)
RETURNS TABLE(
    fields_name text,
    fields_type text,
    fields_not_null text,
    fields_key_name text,
    fields_default text
)
LANGUAGE sql
STABLE
AS $$
    SELECT
        columns.column_name::text,
        columns.data_type::text,
        CASE WHEN columns.is_nullable = 'NO' THEN '1' ELSE '' END,
        CASE WHEN EXISTS (
            SELECT 1
            FROM information_schema.table_constraints constraints
            INNER JOIN information_schema.key_column_usage keys
                ON keys.constraint_name = constraints.constraint_name
                AND keys.table_schema = constraints.table_schema
                AND keys.table_name = constraints.table_name
            WHERE constraints.constraint_type = 'PRIMARY KEY'
                AND keys.table_schema = columns.table_schema
                AND keys.table_name = columns.table_name
                AND keys.column_name = columns.column_name
        ) THEN 'PRI' ELSE '' END,
        columns.column_default::text
    FROM information_schema.columns
    WHERE columns.table_schema = current_schema()
        AND columns.table_name = requested_table
    ORDER BY columns.ordinal_position;
$$;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608200000_create_main_application_baseline', '9f49b93ae998206c88da49b5a08ab89963d18b938db11178b4a7e4d894699fec', CURRENT_TIMESTAMP(0));

-- Migration 202608210001_create_structured_workflow (sha256 4561542c617c0d6f4991564305e7caf6870416c4b6eb2ad79278868d507cab59)

CREATE TABLE IF NOT EXISTS sand_ai_workflow_run (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    task_id bigint NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    workflow_code varchar(64) NOT NULL,
    workflow_version varchar(32) NOT NULL,
    output_schema_code varchar(128) NOT NULL,
    output_schema_version varchar(32) NOT NULL,
    business_scope_ref varchar(512) NOT NULL,
    idempotency_key varchar(128) NOT NULL,
    snapshot_sha256 char(64) NOT NULL,
    definition_snapshot jsonb NOT NULL,
    input_snapshot jsonb NOT NULL,
    file_snapshot jsonb NOT NULL,
    review_required boolean NOT NULL DEFAULT false,
    status smallint NOT NULL DEFAULT 1,
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_ai_workflow_run_environment_idempotency UNIQUE (environment_id, idempotency_key),
    CONSTRAINT uk_sand_ai_workflow_run_task UNIQUE (task_id),
    CONSTRAINT ck_sand_ai_workflow_run_code CHECK (workflow_code ~ '^[a-z][a-z0-9_]{1,63}$'),
    CONSTRAINT ck_sand_ai_workflow_run_version CHECK (workflow_version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'),
    CONSTRAINT ck_sand_ai_workflow_run_snapshot_hash CHECK (snapshot_sha256 ~ '^[a-f0-9]{64}$')
);

CREATE TABLE IF NOT EXISTS sand_ai_workflow_run_file (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    workflow_run_id bigint NOT NULL REFERENCES sand_ai_workflow_run(id) ON DELETE RESTRICT,
    file_id bigint NOT NULL REFERENCES sand_ai_file(id) ON DELETE RESTRICT,
    parse_id bigint NOT NULL REFERENCES sand_ai_file_parse(id) ON DELETE RESTRICT,
    sha256 char(64) NOT NULL,
    version_ref varchar(512) NOT NULL,
    status smallint NOT NULL DEFAULT 1,
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_ai_workflow_run_file UNIQUE (workflow_run_id, file_id),
    CONSTRAINT ck_sand_ai_workflow_run_file_hash CHECK (sha256 ~ '^[a-f0-9]{64}$')
);

CREATE TABLE IF NOT EXISTS sand_ai_artifact (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    workflow_run_id bigint NOT NULL REFERENCES sand_ai_workflow_run(id) ON DELETE RESTRICT,
    schema_code varchar(128) NOT NULL,
    schema_version varchar(32) NOT NULL,
    payload jsonb NOT NULL,
    review_required boolean NOT NULL DEFAULT false,
    state varchar(24) NOT NULL,
    status smallint NOT NULL DEFAULT 1,
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_ai_artifact_workflow_run UNIQUE (workflow_run_id),
    CONSTRAINT ck_sand_ai_artifact_version CHECK (schema_version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'),
    CONSTRAINT ck_sand_ai_artifact_state CHECK (state IN ('succeeded'))
);

CREATE TABLE IF NOT EXISTS sand_ai_artifact_field_source (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    artifact_id bigint NOT NULL REFERENCES sand_ai_artifact(id) ON DELETE RESTRICT,
    field_path varchar(1024) NOT NULL,
    source_block_id bigint NOT NULL REFERENCES sand_ai_source_block(id) ON DELETE RESTRICT,
    status smallint NOT NULL DEFAULT 1,
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_ai_artifact_field_source UNIQUE (artifact_id, field_path, source_block_id),
    CONSTRAINT ck_sand_ai_artifact_field_path CHECK (field_path ~ '^/')
);

CREATE INDEX IF NOT EXISTS idx_sand_ai_workflow_run_environment_time
    ON sand_ai_workflow_run (environment_id, create_time DESC);
CREATE INDEX IF NOT EXISTS idx_sand_ai_workflow_run_scope_time
    ON sand_ai_workflow_run (environment_id, business_scope_ref, create_time DESC);
CREATE INDEX IF NOT EXISTS idx_sand_ai_workflow_run_file_parse
    ON sand_ai_workflow_run_file (parse_id, workflow_run_id);
CREATE INDEX IF NOT EXISTS idx_sand_ai_artifact_field_source_block
    ON sand_ai_artifact_field_source (source_block_id, artifact_id);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608210001_create_structured_workflow', '4561542c617c0d6f4991564305e7caf6870416c4b6eb2ad79278868d507cab59', CURRENT_TIMESTAMP(0));

-- Migration 202608230001_create_usage_pricing (sha256 941e902ba56b070c34d7c38720515ef5e0c505caed6420bc9e787825edaac3b0)
CREATE TABLE IF NOT EXISTS sand_ai_price_revision (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    provider_id bigint NOT NULL REFERENCES sand_ai_provider(id) ON DELETE RESTRICT,
    model_id bigint NOT NULL REFERENCES sand_ai_model(id) ON DELETE RESTRICT,
    capability_code varchar(64) NOT NULL,
    currency varchar(8) NOT NULL,
    state varchar(16) NOT NULL DEFAULT 'draft',
    effective_from timestamp(0) without time zone NOT NULL,
    effective_until timestamp(0) without time zone NULL,
    published_time timestamp(0) without time zone NULL,
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_ai_price_revision_effective UNIQUE (provider_id, model_id, capability_code, effective_from),
    CONSTRAINT ck_sand_ai_price_revision_state CHECK (state IN ('draft', 'published', 'archived')),
    CONSTRAINT ck_sand_ai_price_revision_effective_range CHECK (effective_until IS NULL OR effective_until > effective_from),
    CONSTRAINT ck_sand_ai_price_revision_published CHECK ((state = 'published' AND published_time IS NOT NULL) OR state <> 'published')
);

CREATE TABLE IF NOT EXISTS sand_ai_price_rate (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    price_revision_id bigint NOT NULL REFERENCES sand_ai_price_revision(id) ON DELETE RESTRICT,
    meter_code varchar(32) NOT NULL,
    unit_price numeric(20, 8) NOT NULL,
    per_quantity bigint NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_price_rate_meter UNIQUE (price_revision_id, meter_code),
    CONSTRAINT ck_sand_ai_price_rate_meter CHECK (meter_code IN ('input_tokens', 'output_tokens')),
    CONSTRAINT ck_sand_ai_price_rate_amount CHECK (unit_price >= 0),
    CONSTRAINT ck_sand_ai_price_rate_quantity CHECK (per_quantity > 0)
);

CREATE TABLE IF NOT EXISTS sand_ai_usage_event (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    organization_id bigint NULL,
    application_id bigint NULL,
    environment_id bigint NOT NULL,
    workload_client_id bigint NULL,
    identity_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    provider_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    model_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    deployment_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT,
    provider_id bigint NOT NULL REFERENCES sand_ai_provider(id) ON DELETE RESTRICT,
    model_id bigint NOT NULL REFERENCES sand_ai_model(id) ON DELETE RESTRICT,
    deployment_id bigint NOT NULL REFERENCES sand_ai_model_deployment(id) ON DELETE RESTRICT,
    service_action varchar(96) NOT NULL,
    source_kind varchar(32) NOT NULL,
    capability_code varchar(64) NOT NULL,
    input_tokens integer NOT NULL,
    output_tokens integer NOT NULL,
    total_tokens integer NOT NULL,
    usage_state varchar(16) NOT NULL,
    price_revision_id bigint NULL REFERENCES sand_ai_price_revision(id) ON DELETE RESTRICT,
    price_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    metered_amount numeric(20, 6) NULL,
    reconciled_amount numeric(20, 6) NULL,
    currency varchar(8) NULL,
    pricing_state varchar(16) NOT NULL,
    reconciliation_state varchar(16) NOT NULL DEFAULT 'unreconciled',
    idempotency_key varchar(128) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_usage_event_invocation UNIQUE (invocation_id),
    CONSTRAINT uk_sand_ai_usage_event_idempotency UNIQUE (idempotency_key),
    CONSTRAINT ck_sand_ai_usage_event_source_kind CHECK (source_kind IN ('chat', 'ai_task', 'agent')),
    CONSTRAINT ck_sand_ai_usage_event_tokens CHECK (input_tokens >= 0 AND output_tokens >= 0 AND total_tokens = input_tokens + output_tokens),
    CONSTRAINT ck_sand_ai_usage_event_usage_state CHECK (usage_state IN ('measured', 'unmeasured')),
    CONSTRAINT ck_sand_ai_usage_event_pricing CHECK (
        (pricing_state = 'priced' AND price_revision_id IS NOT NULL AND metered_amount IS NOT NULL AND currency IS NOT NULL)
        OR (pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL)
    ),
    CONSTRAINT ck_sand_ai_usage_event_reconciliation CHECK (reconciliation_state = 'unreconciled' AND reconciled_amount IS NULL)
);

CREATE INDEX IF NOT EXISTS idx_sand_ai_price_revision_lookup
    ON sand_ai_price_revision (provider_id, model_id, capability_code, effective_from DESC)
    WHERE state = 'published' AND delete_time IS NULL;
CREATE INDEX IF NOT EXISTS idx_sand_ai_usage_event_environment_time
    ON sand_ai_usage_event (environment_id, create_time DESC);
CREATE INDEX IF NOT EXISTS idx_sand_ai_usage_event_application_time
    ON sand_ai_usage_event (application_id, create_time DESC);
CREATE INDEX IF NOT EXISTS idx_sand_ai_usage_event_service_time
    ON sand_ai_usage_event (service_action, create_time DESC);

CREATE OR REPLACE FUNCTION sand_ai_reject_usage_event_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'sand_ai_usage_event is immutable';
END;
$$;

DROP TRIGGER IF EXISTS trg_sand_ai_usage_event_immutable ON sand_ai_usage_event;
CREATE TRIGGER trg_sand_ai_usage_event_immutable
BEFORE UPDATE OR DELETE ON sand_ai_usage_event
FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_usage_event_mutation();

CREATE OR REPLACE FUNCTION sand_ai_reject_published_price_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_TABLE_NAME = 'sand_ai_price_revision' THEN
        IF OLD.state = 'published' THEN
            RAISE EXCEPTION 'published sand_ai_price_revision is immutable';
        END IF;
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP IN ('UPDATE', 'DELETE') AND EXISTS (
        SELECT 1 FROM sand_ai_price_revision
        WHERE id = OLD.price_revision_id AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'rates for a published sand_ai_price_revision are immutable';
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') AND EXISTS (
        SELECT 1 FROM sand_ai_price_revision
        WHERE id = NEW.price_revision_id AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'rates for a published sand_ai_price_revision are immutable';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_sand_ai_price_revision_immutable ON sand_ai_price_revision;
CREATE TRIGGER trg_sand_ai_price_revision_immutable
BEFORE UPDATE OR DELETE ON sand_ai_price_revision
FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_published_price_mutation();

DROP TRIGGER IF EXISTS trg_sand_ai_price_rate_immutable ON sand_ai_price_rate;
CREATE TRIGGER trg_sand_ai_price_rate_immutable
BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_price_rate
FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_published_price_mutation();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230001_create_usage_pricing', '941e902ba56b070c34d7c38720515ef5e0c505caed6420bc9e787825edaac3b0', CURRENT_TIMESTAMP(0));

-- Migration 202608230002_extend_usage_pricing_admin (sha256 8cdead511dc08fbbc93f98de8a72614a6d70be3ea8c54f2a901ebcd2af3bf2a7)

ALTER TABLE sand_ai_price_revision
    ADD COLUMN IF NOT EXISTS revision integer NULL,
    ADD COLUMN IF NOT EXISTS name varchar(128) NULL;

WITH existing_revision_max AS (
    SELECT
        provider_id,
        model_id,
        capability_code,
        COALESCE(MAX(revision), 0) AS max_revision
    FROM sand_ai_price_revision
    GROUP BY provider_id, model_id, capability_code
), numbered_null_revision AS (
    SELECT
        revision_target.id,
        existing_revision_max.max_revision + ROW_NUMBER() OVER (
            PARTITION BY revision_target.provider_id, revision_target.model_id, revision_target.capability_code
            ORDER BY revision_target.effective_from NULLS FIRST, revision_target.id
        ) AS next_revision
    FROM sand_ai_price_revision AS revision_target
    INNER JOIN existing_revision_max
        ON existing_revision_max.provider_id = revision_target.provider_id
        AND existing_revision_max.model_id = revision_target.model_id
        AND existing_revision_max.capability_code = revision_target.capability_code
    WHERE revision_target.revision IS NULL
)
UPDATE sand_ai_price_revision AS target
SET revision = numbered_null_revision.next_revision
FROM numbered_null_revision
WHERE target.id = numbered_null_revision.id;

UPDATE sand_ai_price_revision
SET name = '未命名价格'
WHERE name IS NULL;

ALTER TABLE sand_ai_price_revision
    ALTER COLUMN revision SET NOT NULL,
    ALTER COLUMN revision SET DEFAULT 1,
    ALTER COLUMN name SET NOT NULL,
    ALTER COLUMN name SET DEFAULT '未命名价格';

CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_price_revision_version
    ON sand_ai_price_revision (provider_id, model_id, capability_code, revision)
    WHERE delete_time IS NULL;

ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT IF EXISTS ck_sand_ai_usage_event_source_kind;

ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT ck_sand_ai_usage_event_source_kind CHECK (
        source_kind IN (
            'chat', 'ai_task', 'agent', 'capability', 'workflow',
            'external_service', 'onboarding_verification'
        )
    );

CREATE INDEX IF NOT EXISTS idx_sand_ai_usage_event_summary_filter
    ON sand_ai_usage_event (
        environment_id,
        create_time DESC,
        pricing_state,
        usage_state,
        reconciliation_state
    );

CREATE INDEX IF NOT EXISTS idx_sand_ai_usage_event_summary_dimensions
    ON sand_ai_usage_event (
        environment_id,
        application_id,
        service_action,
        capability_code,
        provider_id,
        model_id,
        currency,
        create_time DESC
    );
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230002_extend_usage_pricing_admin', '8cdead511dc08fbbc93f98de8a72614a6d70be3ea8c54f2a901ebcd2af3bf2a7', CURRENT_TIMESTAMP(0));

-- Migration 202608230003_create_billing_reconciliation (sha256 9ab5b36dd583b819bed8294df2827555099a4797798ead912d787fc9dbfeede5)

CREATE TABLE IF NOT EXISTS sand_ai_billing_import (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    provider_id BIGINT NOT NULL REFERENCES sand_ai_provider(id) ON DELETE RESTRICT,
    period_start TIMESTAMP NOT NULL,
    period_end TIMESTAMP NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    source_kind VARCHAR(96) NOT NULL,
    reference VARCHAR(256) NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'imported' CHECK (state = 'imported'),
    create_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_ai_billing_import_period CHECK (period_end > period_start),
    CONSTRAINT uk_sand_ai_billing_import_provider_key UNIQUE (provider_id, idempotency_key)
);

CREATE TABLE IF NOT EXISTS sand_ai_billing_line (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    billing_import_id BIGINT NOT NULL REFERENCES sand_ai_billing_import(id) ON DELETE RESTRICT,
    external_line_id VARCHAR(128) NOT NULL,
    model_code VARCHAR(96) NOT NULL,
    capability_code VARCHAR(96) NOT NULL,
    meter_code VARCHAR(96) NOT NULL,
    quantity NUMERIC(20, 6) NOT NULL CHECK (quantity >= 0),
    amount NUMERIC(20, 6) NOT NULL CHECK (amount >= 0),
    currency VARCHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    occurred_start TIMESTAMP NOT NULL,
    occurred_end TIMESTAMP NOT NULL,
    create_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_ai_billing_line_period CHECK (occurred_end >= occurred_start),
    CONSTRAINT uk_sand_ai_billing_line_external UNIQUE (billing_import_id, external_line_id)
);

CREATE TABLE IF NOT EXISTS sand_ai_usage_reconciliation (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    billing_line_id BIGINT NOT NULL REFERENCES sand_ai_billing_line(id) ON DELETE RESTRICT,
    usage_event_id BIGINT NULL REFERENCES sand_ai_usage_event(id) ON DELETE RESTRICT,
    state VARCHAR(16) NOT NULL CHECK (state IN ('reconciled', 'discrepancy', 'unmatched', 'ambiguous')),
    quantity_difference NUMERIC(20, 6) NULL,
    amount_difference NUMERIC(20, 6) NULL,
    billed_amount NUMERIC(20, 6) NOT NULL CHECK (billed_amount >= 0),
    currency VARCHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    create_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_usage_reconciliation_line UNIQUE (billing_line_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_usage_reconciliation_event
    ON sand_ai_usage_reconciliation (usage_event_id) WHERE usage_event_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sand_ai_billing_import_provider_period
    ON sand_ai_billing_import (provider_id, period_start, period_end);
CREATE INDEX IF NOT EXISTS idx_sand_ai_usage_event_reconciliation_match
    ON sand_ai_usage_event (provider_id, capability_code, currency, create_time, model_id);

CREATE OR REPLACE FUNCTION sand_ai_reject_billing_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'SandAI billing and reconciliation facts are immutable';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS sand_ai_billing_import_immutable ON sand_ai_billing_import;
CREATE TRIGGER sand_ai_billing_import_immutable BEFORE UPDATE OR DELETE ON sand_ai_billing_import
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();
DROP TRIGGER IF EXISTS sand_ai_billing_line_immutable ON sand_ai_billing_line;
CREATE TRIGGER sand_ai_billing_line_immutable BEFORE UPDATE OR DELETE ON sand_ai_billing_line
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();
DROP TRIGGER IF EXISTS sand_ai_usage_reconciliation_immutable ON sand_ai_usage_reconciliation;
CREATE TRIGGER sand_ai_usage_reconciliation_immutable BEFORE UPDATE OR DELETE ON sand_ai_usage_reconciliation
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230003_create_billing_reconciliation', '9ab5b36dd583b819bed8294df2827555099a4797798ead912d787fc9dbfeede5', CURRENT_TIMESTAMP(0));

-- Migration 202608230004_billing_candidate_limits_legacy_usage (sha256 bde6ae3d1598f7160989ee0f3c46854058ba7f3cb3ab054d2a292ec938bb1c00)

ALTER TABLE sand_ai_usage_reconciliation ADD COLUMN IF NOT EXISTS reason VARCHAR(64) NOT NULL DEFAULT 'unspecified';
COMMENT ON COLUMN sand_ai_usage_reconciliation.reason IS 'Final automatic reconciliation reason; immutable with the result.';

ALTER TABLE sand_ai_usage ALTER COLUMN cost_amount DROP NOT NULL;
ALTER TABLE sand_ai_usage ALTER COLUMN currency DROP NOT NULL;
ALTER TABLE sand_ai_usage ALTER COLUMN cost_amount DROP DEFAULT;
ALTER TABLE sand_ai_usage ALTER COLUMN currency DROP DEFAULT;
COMMENT ON TABLE sand_ai_usage IS 'Legacy compatibility usage rows only; sand_ai_usage_event is the pricing authority.';
COMMENT ON COLUMN sand_ai_usage.cost_amount IS 'NULL means unknown or unpriced; do not treat as zero cost.';
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230004_billing_candidate_limits_legacy_usage', 'bde6ae3d1598f7160989ee0f3c46854058ba7f3cb3ab054d2a292ec938bb1c00', CURRENT_TIMESTAMP(0));

-- Migration 202608230005_create_budget_control (sha256 2fdc3b5cfecd8b20209cef50de1e7fe20bc07d2f4ea6bc4832a45f5ce8520ef2)

ALTER TABLE sand_ai_invocation
    ADD COLUMN IF NOT EXISTS request_fingerprint char(64) NULL;

CREATE TABLE IF NOT EXISTS sand_ai_budget_policy (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    revision integer NOT NULL,
    environment_id bigint NOT NULL,
    scope_kind varchar(32) NOT NULL DEFAULT 'environment',
    period_start timestamp(0) without time zone NOT NULL,
    period_end timestamp(0) without time zone NOT NULL,
    currency char(3) NOT NULL,
    limit_amount numeric(20,6) NOT NULL,
    enforcement varchar(16) NOT NULL,
    unpriced_policy varchar(16) NOT NULL,
    unmeasured_policy varchar(16) NOT NULL,
    state varchar(16) NOT NULL DEFAULT 'draft',
    published_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT ck_sand_ai_budget_policy_period CHECK (period_end > period_start),
    CONSTRAINT ck_sand_ai_budget_policy_limit CHECK (limit_amount > 0),
    CONSTRAINT ck_sand_ai_budget_policy_scope CHECK (scope_kind IN ('environment')),
    CONSTRAINT ck_sand_ai_budget_policy_enforcement CHECK (enforcement IN ('hard', 'soft')),
    CONSTRAINT ck_sand_ai_budget_policy_unpriced CHECK (unpriced_policy IN ('block', 'allow')),
    CONSTRAINT ck_sand_ai_budget_policy_unmeasured CHECK (unmeasured_policy IN ('hold', 'allow')),
    CONSTRAINT ck_sand_ai_budget_policy_state CHECK (state IN ('draft', 'published', 'archived')),
    CONSTRAINT ck_sand_ai_budget_policy_hard CHECK (enforcement <> 'hard' OR (unpriced_policy = 'block' AND unmeasured_policy = 'hold'))
);

CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_budget_policy_revision
    ON sand_ai_budget_policy (environment_id, revision)
    WHERE delete_time IS NULL;
CREATE INDEX IF NOT EXISTS idx_sand_ai_budget_policy_active
    ON sand_ai_budget_policy (environment_id, period_start, period_end)
    WHERE state = 'published' AND delete_time IS NULL;

CREATE TABLE IF NOT EXISTS sand_ai_budget_window (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    policy_id bigint NOT NULL UNIQUE REFERENCES sand_ai_budget_policy(id),
    currency char(3) NOT NULL,
    limit_amount numeric(20,6) NOT NULL,
    reserved_amount numeric(20,6) NOT NULL DEFAULT 0,
    settled_amount numeric(20,6) NOT NULL DEFAULT 0,
    held_amount numeric(20,6) NOT NULL DEFAULT 0,
    lock_version bigint NOT NULL DEFAULT 0,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT ck_sand_ai_budget_window_amounts CHECK (
        limit_amount > 0 AND reserved_amount >= 0 AND settled_amount >= 0 AND held_amount >= 0
    )
);

CREATE TABLE IF NOT EXISTS sand_ai_budget_reservation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    policy_id bigint NOT NULL REFERENCES sand_ai_budget_policy(id),
    invocation_id bigint NULL REFERENCES sand_ai_invocation(id),
    request_id varchar(128) NOT NULL,
    request_fingerprint char(64) NOT NULL,
    estimate_amount numeric(20,6) NOT NULL,
    metered_amount numeric(20,6) NULL,
    currency char(3) NOT NULL,
    price_revision_id bigint NOT NULL REFERENCES sand_ai_price_revision(id),
    state varchar(16) NOT NULL DEFAULT 'reserved',
    reason varchar(64) NULL,
    expires_at timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT ck_sand_ai_budget_reservation_amount CHECK (estimate_amount > 0 AND (metered_amount IS NULL OR metered_amount >= 0)),
    CONSTRAINT ck_sand_ai_budget_reservation_state CHECK (state IN ('reserved', 'settled', 'held', 'released'))
);

CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_budget_reservation_request
    ON sand_ai_budget_reservation (policy_id, request_id)
    WHERE delete_time IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_budget_reservation_invocation
    ON sand_ai_budget_reservation (invocation_id)
    WHERE invocation_id IS NOT NULL AND delete_time IS NULL;
CREATE INDEX IF NOT EXISTS idx_sand_ai_budget_reservation_expiry
    ON sand_ai_budget_reservation (policy_id, expires_at, id)
    WHERE state = 'reserved' AND delete_time IS NULL;

CREATE TABLE IF NOT EXISTS sand_ai_budget_event (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    policy_id bigint NOT NULL REFERENCES sand_ai_budget_policy(id),
    reservation_id bigint NULL REFERENCES sand_ai_budget_reservation(id),
    invocation_id bigint NULL REFERENCES sand_ai_invocation(id),
    event_type varchar(16) NOT NULL,
    amount numeric(20,6) NULL,
    currency char(3) NOT NULL,
    reason varchar(64) NULL,
    idempotency_key varchar(191) NOT NULL,
    snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_ai_budget_event_type CHECK (event_type IN ('reserve', 'settle', 'hold', 'release', 'reject')),
    CONSTRAINT ck_sand_ai_budget_event_amount CHECK (amount IS NULL OR amount >= 0)
);

CREATE INDEX IF NOT EXISTS idx_sand_ai_budget_event_policy_time
    ON sand_ai_budget_event (policy_id, create_time, id);
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_budget_event_transition
    ON sand_ai_budget_event (reservation_id, event_type)
    WHERE reservation_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_budget_event_idempotency
    ON sand_ai_budget_event (idempotency_key);

CREATE OR REPLACE FUNCTION sand_ai_reject_budget_evidence_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'SandAI budget evidence is immutable';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_budget_event_immutable ON sand_ai_budget_event;
CREATE TRIGGER trg_sand_ai_budget_event_immutable
BEFORE UPDATE OR DELETE ON sand_ai_budget_event
FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_budget_evidence_mutation();

CREATE OR REPLACE FUNCTION sand_ai_reject_published_budget_policy_mutation()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' OR OLD.state = 'archived' THEN
        RAISE EXCEPTION 'Published SandAI budget policy revisions are immutable';
    END IF;
    IF OLD.state = 'published' AND (
        NEW.state <> 'archived'
        OR ROW(NEW.revision, NEW.environment_id, NEW.scope_kind, NEW.period_start, NEW.period_end, NEW.currency, NEW.limit_amount, NEW.enforcement, NEW.unpriced_policy, NEW.unmeasured_policy, NEW.published_time, NEW.create_time, NEW.delete_time)
           IS DISTINCT FROM
           ROW(OLD.revision, OLD.environment_id, OLD.scope_kind, OLD.period_start, OLD.period_end, OLD.currency, OLD.limit_amount, OLD.enforcement, OLD.unpriced_policy, OLD.unmeasured_policy, OLD.published_time, OLD.create_time, OLD.delete_time)
    ) THEN
        RAISE EXCEPTION 'Published SandAI budget policy revisions are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_budget_policy_immutable ON sand_ai_budget_policy;
CREATE TRIGGER trg_sand_ai_budget_policy_immutable
BEFORE UPDATE OR DELETE ON sand_ai_budget_policy
FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_published_budget_policy_mutation();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230005_create_budget_control', '2fdc3b5cfecd8b20209cef50de1e7fe20bc07d2f4ea6bc4832a45f5ce8520ef2', CURRENT_TIMESTAMP(0));

-- Migration 202608230006_create_invocation_outcome_receipt (sha256 e4781078a3663f87ddf02012232ea28abaaff83832a1c45b74fd609093476cc8)
-- SAND-119B-1B: private, replayable provider outcome only.  No application
-- identity, business scope, provider secret, request input, or plaintext is stored.
CREATE TABLE IF NOT EXISTS sand_ai_invocation_outcome_receipt (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    invocation_id bigint NOT NULL,
    environment_id bigint NOT NULL,
    capability_code varchar(64) NOT NULL,
    model_id bigint NOT NULL,
    deployment_id bigint NOT NULL,
    request_fingerprint char(64) NOT NULL,
    receipt_version varchar(8) NOT NULL DEFAULT 'v1',
    cipher_version varchar(8) NOT NULL DEFAULT 'v1',
    key_id varchar(64),
    plaintext_bytes integer,
    ciphertext_bytes integer,
    content_hmac bytea,
    nonce bytea,
    tag bytea,
    ciphertext bytea,
    state varchar(16) NOT NULL DEFAULT 'available',
    expire_time timestamp(0) without time zone NOT NULL,
    cleanup_owner varchar(128),
    cleanup_claim_time timestamp(0) without time zone,
    cleanup_fence_version integer NOT NULL DEFAULT 0,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    destroy_time timestamp(0) without time zone,
    CONSTRAINT sand_ai_invocation_outcome_receipt_invocation_uq UNIQUE (invocation_id),
    CONSTRAINT sand_ai_invocation_outcome_receipt_invocation_fk FOREIGN KEY (invocation_id) REFERENCES sand_ai_invocation (id) ON DELETE RESTRICT,
    CONSTRAINT sand_ai_invocation_outcome_receipt_state_ck CHECK (state IN ('available', 'expired', 'destroyed')),
    CONSTRAINT sand_ai_invocation_outcome_receipt_cleanup_fence_ck CHECK (cleanup_fence_version >= 0),
    CONSTRAINT sand_ai_invocation_outcome_receipt_version_ck CHECK (receipt_version = 'v1' AND cipher_version = 'v1'),
    CONSTRAINT sand_ai_invocation_outcome_receipt_key_id_ck CHECK (key_id IS NULL OR key_id ~ '^[a-z][a-z0-9_-]{0,63}$'),
    CONSTRAINT sand_ai_invocation_outcome_receipt_plaintext_ck CHECK (plaintext_bytes IS NULL OR plaintext_bytes BETWEEN 1 AND 262144),
    CONSTRAINT sand_ai_invocation_outcome_receipt_ciphertext_ck CHECK (ciphertext_bytes IS NULL OR ciphertext_bytes BETWEEN 1 AND 262144),
    CONSTRAINT sand_ai_invocation_outcome_receipt_hmac_ck CHECK (content_hmac IS NULL OR octet_length(content_hmac) = 32),
    CONSTRAINT sand_ai_invocation_outcome_receipt_available_material_ck CHECK (
        (state = 'available' AND cleanup_owner IS NULL AND cleanup_claim_time IS NULL AND key_id IS NOT NULL AND plaintext_bytes IS NOT NULL AND ciphertext_bytes IS NOT NULL
            AND content_hmac IS NOT NULL AND nonce IS NOT NULL AND tag IS NOT NULL AND ciphertext IS NOT NULL AND destroy_time IS NULL)
        OR (state = 'expired' AND cleanup_owner IS NOT NULL AND cleanup_claim_time IS NOT NULL AND key_id IS NOT NULL AND plaintext_bytes IS NOT NULL AND ciphertext_bytes IS NOT NULL
            AND content_hmac IS NOT NULL AND nonce IS NOT NULL AND tag IS NOT NULL AND ciphertext IS NOT NULL AND destroy_time IS NULL)
        OR (state = 'destroyed' AND cleanup_owner IS NULL AND key_id IS NULL AND plaintext_bytes IS NULL AND ciphertext_bytes IS NULL
            AND content_hmac IS NULL AND nonce IS NULL AND tag IS NULL AND ciphertext IS NULL AND destroy_time IS NOT NULL)
    ),
    CONSTRAINT sand_ai_invocation_outcome_receipt_material_length_ck CHECK (
        (nonce IS NULL OR octet_length(nonce) = 12)
        AND (tag IS NULL OR octet_length(tag) = 16)
        AND (ciphertext IS NULL OR octet_length(ciphertext) = ciphertext_bytes)
    )
);

CREATE INDEX IF NOT EXISTS sand_ai_invocation_outcome_receipt_environment_state_expire_idx
    ON sand_ai_invocation_outcome_receipt (environment_id, state, expire_time);
CREATE INDEX IF NOT EXISTS sand_ai_invocation_outcome_receipt_cleanup_claim_idx
    ON sand_ai_invocation_outcome_receipt (state, cleanup_claim_time, expire_time);

CREATE OR REPLACE FUNCTION sand_ai_invocation_outcome_receipt_prevent_overwrite()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.invocation_id IS DISTINCT FROM OLD.invocation_id
       OR NEW.environment_id IS DISTINCT FROM OLD.environment_id
       OR NEW.capability_code IS DISTINCT FROM OLD.capability_code
       OR NEW.model_id IS DISTINCT FROM OLD.model_id
       OR NEW.deployment_id IS DISTINCT FROM OLD.deployment_id
       OR NEW.request_fingerprint IS DISTINCT FROM OLD.request_fingerprint
       OR NEW.receipt_version IS DISTINCT FROM OLD.receipt_version
       OR NEW.cipher_version IS DISTINCT FROM OLD.cipher_version THEN
        RAISE EXCEPTION 'sand_ai invocation outcome receipt is immutable';
    END IF;
    IF NEW.state <> 'destroyed' AND (NEW.key_id IS DISTINCT FROM OLD.key_id
       OR NEW.plaintext_bytes IS DISTINCT FROM OLD.plaintext_bytes
       OR NEW.ciphertext_bytes IS DISTINCT FROM OLD.ciphertext_bytes
       OR NEW.content_hmac IS DISTINCT FROM OLD.content_hmac) THEN
        RAISE EXCEPTION 'sand_ai invocation outcome receipt is immutable';
    END IF;

    IF OLD.state = 'destroyed' THEN
        RAISE EXCEPTION 'sand_ai invocation outcome receipt is destroyed';
    END IF;
    IF (OLD.state = 'available' AND NEW.state NOT IN ('available', 'expired'))
       OR (OLD.state = 'expired' AND NEW.state NOT IN ('expired', 'destroyed')) THEN
        RAISE EXCEPTION 'invalid sand_ai invocation outcome receipt state transition';
    END IF;
    IF NEW.state <> 'destroyed'
       AND (NEW.nonce IS DISTINCT FROM OLD.nonce OR NEW.tag IS DISTINCT FROM OLD.tag OR NEW.ciphertext IS DISTINCT FROM OLD.ciphertext) THEN
        RAISE EXCEPTION 'sand_ai invocation outcome receipt ciphertext is immutable';
    END IF;
    IF NEW.state = 'destroyed'
       AND (NEW.key_id IS NOT NULL OR NEW.plaintext_bytes IS NOT NULL OR NEW.ciphertext_bytes IS NOT NULL OR NEW.content_hmac IS NOT NULL
           OR NEW.nonce IS NOT NULL OR NEW.tag IS NOT NULL OR NEW.ciphertext IS NOT NULL OR NEW.destroy_time IS NULL) THEN
        RAISE EXCEPTION 'destroyed sand_ai invocation outcome receipt must remove ciphertext';
    END IF;
    IF NEW.state = 'available' AND (NEW.cleanup_owner IS NOT NULL OR NEW.cleanup_claim_time IS NOT NULL) THEN
        RAISE EXCEPTION 'available sand_ai invocation outcome receipt cannot be claimed';
    END IF;
    IF NEW.state = 'expired' AND (NEW.cleanup_owner IS NULL OR NEW.cleanup_claim_time IS NULL OR NEW.cleanup_fence_version <= OLD.cleanup_fence_version) THEN
        RAISE EXCEPTION 'expired sand_ai invocation outcome receipt requires a cleanup claim fence';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS sand_ai_invocation_outcome_receipt_immutable ON sand_ai_invocation_outcome_receipt;
CREATE TRIGGER sand_ai_invocation_outcome_receipt_immutable
BEFORE UPDATE ON sand_ai_invocation_outcome_receipt
FOR EACH ROW EXECUTE FUNCTION sand_ai_invocation_outcome_receipt_prevent_overwrite();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230006_create_invocation_outcome_receipt', 'e4781078a3663f87ddf02012232ea28abaaff83832a1c45b74fd609093476cc8', CURRENT_TIMESTAMP(0));

-- Migration 202608230007_task_fencing_execution_snapshot (sha256 39ba0c548b4bee8f1a6a384c942195a9e40c9b898aa7e68687e39b22006c3051)
ALTER TABLE sand_ai_task_run
    ADD COLUMN IF NOT EXISTS lease_owner varchar(128),
    ADD COLUMN IF NOT EXISTS lease_token char(64),
    ADD COLUMN IF NOT EXISTS lease_fence integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS lease_expire_time timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS heartbeat_time timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS version integer NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS sand_ai_task_run_lease_claim_idx ON sand_ai_task_run (state, available_at, lease_expire_time);

ALTER TABLE sand_ai_workflow_run
    ADD COLUMN IF NOT EXISTS organization_id bigint,
    ADD COLUMN IF NOT EXISTS application_id bigint,
    ADD COLUMN IF NOT EXISTS workload_client_id bigint,
    ADD COLUMN IF NOT EXISTS attribution_snapshot jsonb,
    ADD COLUMN IF NOT EXISTS idempotency_application_id bigint,
    ADD COLUMN IF NOT EXISTS logical_execution_id char(48),
    ADD COLUMN IF NOT EXISTS execution_snapshot_version varchar(32),
    ADD COLUMN IF NOT EXISTS execution_snapshot jsonb,
    ADD COLUMN IF NOT EXISTS execution_snapshot_sha256 char(64),
    ADD COLUMN IF NOT EXISTS retain_until timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS consumer_ack_time timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS consumer_ack_ref varchar(128);
CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_workflow_run_application_idempotency_uq ON sand_ai_workflow_run (environment_id, idempotency_application_id, idempotency_key) WHERE idempotency_application_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_workflow_run_logical_execution_uq ON sand_ai_workflow_run (logical_execution_id) WHERE logical_execution_id IS NOT NULL;

ALTER TABLE sand_ai_workflow_run_file
    ADD COLUMN IF NOT EXISTS analysis_state varchar(32) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS attempt_count integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS failure_summary varchar(512),
    ADD COLUMN IF NOT EXISTS last_invocation_id bigint;
CREATE INDEX IF NOT EXISTS sand_ai_workflow_run_file_analysis_idx ON sand_ai_workflow_run_file (workflow_run_id, analysis_state);

ALTER TABLE sand_ai_invocation
    ADD COLUMN IF NOT EXISTS parent_workflow_run_id bigint,
    ADD COLUMN IF NOT EXISTS parent_task_id bigint,
    ADD COLUMN IF NOT EXISTS parent_task_step_id bigint,
    ADD COLUMN IF NOT EXISTS parent_workflow_run_file_id bigint,
    ADD COLUMN IF NOT EXISTS node_code varchar(64),
    ADD COLUMN IF NOT EXISTS logical_execution_id char(48),
    ADD COLUMN IF NOT EXISTS execution_attempt integer,
    ADD COLUMN IF NOT EXISTS execution_snapshot_sha256 char(64);
CREATE INDEX IF NOT EXISTS sand_ai_invocation_parent_workflow_idx ON sand_ai_invocation (parent_workflow_run_id, logical_execution_id);

CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_artifact_run_schema_uq ON sand_ai_artifact (workflow_run_id, schema_code, schema_version);
CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_artifact_workflow_schema_uniq
    ON sand_ai_artifact (workflow_run_id, schema_code, schema_version);
ALTER TABLE sand_ai_invocation
    ADD COLUMN IF NOT EXISTS parent_task_run_id bigint NULL,
    ADD COLUMN IF NOT EXISTS parent_task_step_id bigint NULL,
    ADD COLUMN IF NOT EXISTS parent_workflow_run_file_id bigint NULL,
    ADD COLUMN IF NOT EXISTS workflow_node_code varchar(128) NULL,
    ADD COLUMN IF NOT EXISTS logical_execution_id char(48) NULL,
    ADD COLUMN IF NOT EXISTS execution_attempt_no integer NULL,
    ADD COLUMN IF NOT EXISTS execution_snapshot_sha256 char(64) NULL;

CREATE INDEX IF NOT EXISTS sand_ai_invocation_parent_task_run_idx
    ON sand_ai_invocation (parent_task_run_id, id);
ALTER TABLE sand_ai_workflow_run
    ADD COLUMN IF NOT EXISTS idempotency_application_ref char(64) NULL;

ALTER TABLE sand_ai_workflow_run
    DROP CONSTRAINT IF EXISTS sand_ai_workflow_run_environment_id_idempotency_key_key;
DROP INDEX IF EXISTS sand_ai_workflow_run_environment_id_idempotency_key_key;

CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_workflow_run_environment_application_idempotency_uniq
    ON sand_ai_workflow_run (environment_id, idempotency_application_ref, idempotency_key)
    WHERE idempotency_application_ref IS NOT NULL;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230007_task_fencing_execution_snapshot', '39ba0c548b4bee8f1a6a384c942195a9e40c9b898aa7e68687e39b22006c3051', CURRENT_TIMESTAMP(0));

-- Migration 202608230008_invocation_dispatch_ledger (sha256 86f19ba3ad8b961869fa34a24de9a50e620f54c23bdadb8b4836fae435d66f04)
CREATE TABLE IF NOT EXISTS sand_ai_invocation_dispatch (
    id bigserial PRIMARY KEY,
    invocation_id bigint NOT NULL UNIQUE REFERENCES sand_ai_invocation(id),
    environment_id bigint NOT NULL,
    logical_execution_id char(48) NOT NULL,
    logical_request_id char(64) NOT NULL,
    parent_workflow_run_id bigint NULL,
    parent_task_run_id bigint NULL,
    parent_task_step_id bigint NULL,
    parent_workflow_run_file_id bigint NULL,
    workflow_node_code varchar(128) NULL,
    attempt_no integer NOT NULL DEFAULT 1 CHECK (attempt_no > 0),
    state varchar(32) NOT NULL CHECK (state IN ('prepared','dispatching','succeeded','not_dispatched','ambiguous','failed')),
    provider_id bigint NOT NULL,
    deployment_id bigint NOT NULL,
    model_id bigint NOT NULL,
    capability_code varchar(64) NOT NULL,
    route_snapshot jsonb NOT NULL,
    price_snapshot jsonb NOT NULL,
    execution_snapshot_sha256 char(64) NOT NULL,
    request_body_digest char(64) NOT NULL,
    provider_idempotency_mode varchar(32) NOT NULL CHECK (provider_idempotency_mode IN ('none','provider_key')),
    provider_idempotency_key char(64) NULL,
    lease_owner varchar(128) NOT NULL,
    lease_token char(64) NOT NULL,
    lease_fence integer NOT NULL CHECK (lease_fence > 0),
    dispatch_start_time timestamp(0) without time zone NULL,
    dispatch_complete_time timestamp(0) without time zone NULL,
    ambiguous_time timestamp(0) without time zone NULL,
    error_code varchar(128) NULL,
    error_summary varchar(512) NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS sand_ai_invocation_dispatch_environment_state_idx ON sand_ai_invocation_dispatch(environment_id, state, create_time);
CREATE INDEX IF NOT EXISTS sand_ai_invocation_dispatch_task_idx ON sand_ai_invocation_dispatch(parent_task_run_id, lease_fence);

CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_no_terminal_overwrite() RETURNS trigger AS $$
BEGIN
    IF OLD.state IN ('succeeded','not_dispatched','failed') THEN
        RAISE EXCEPTION 'terminal SandAI dispatch cannot be overwritten';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS sand_ai_invocation_dispatch_terminal_guard ON sand_ai_invocation_dispatch;
CREATE TRIGGER sand_ai_invocation_dispatch_terminal_guard BEFORE UPDATE ON sand_ai_invocation_dispatch
FOR EACH ROW EXECUTE FUNCTION sand_ai_invocation_dispatch_no_terminal_overwrite();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230008_invocation_dispatch_ledger', '86f19ba3ad8b961869fa34a24de9a50e620f54c23bdadb8b4836fae435d66f04', CURRENT_TIMESTAMP(0));

-- Migration 202608230009_task_local_recovery (sha256 ab11622ee3707776e97a81505cb64637e8deadde07ea887ba554b9342f7e9a22)
-- SAND-119B-1C-B1. A provider-successful dispatch can only be recovered from
-- its receipt locally; it is never eligible for a new provider dispatch.
ALTER TABLE sand_ai_task_run
    ADD COLUMN IF NOT EXISTS local_recovery_count integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS local_recovery_max integer NOT NULL DEFAULT 1;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sand_ai_task_run_local_recovery_limits') THEN
        ALTER TABLE sand_ai_task_run
            ADD CONSTRAINT ck_sand_ai_task_run_local_recovery_limits
            CHECK (local_recovery_count >= 0 AND local_recovery_max = 1 AND local_recovery_count <= local_recovery_max);
    END IF;
END $$;

-- The task state constraint is named in the fencing migration.  Recreate that
-- specific state constraint to add the non-claimable recovery state.
ALTER TABLE sand_ai_task_run DROP CONSTRAINT IF EXISTS ck_sand_ai_task_run_state;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_run_state
    CHECK (state IN ('queued', 'running', 'retrying', 'recovery_pending', 'succeeded', 'failed', 'canceled'));

CREATE INDEX IF NOT EXISTS idx_sand_ai_task_run_recovery_pending
    ON sand_ai_task_run (state, local_recovery_count, id)
    WHERE state = 'recovery_pending';
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230009_task_local_recovery', 'ab11622ee3707776e97a81505cb64637e8deadde07ea887ba554b9342f7e9a22', CURRENT_TIMESTAMP(0));

-- Migration 202608230010_allow_structured_workflow_usage_source (sha256 29e657c9587251809b85378df1efa9a735d3c5e24b8b2141e061d61aad5538bc)
-- Structured workflows persist `structured_workflow` as their trusted source
-- kind. Keep the immutable usage-event vocabulary aligned with that runtime
-- contract without changing already-applied migration checksums.
ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT IF EXISTS ck_sand_ai_usage_event_source_kind;

ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT ck_sand_ai_usage_event_source_kind CHECK (
        source_kind IN (
            'chat',
            'ai_task',
            'agent',
            'capability',
            'workflow',
            'structured_workflow',
            'external_service',
            'onboarding_verification'
        )
    );
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608230010_allow_structured_workflow_usage_source', '29e657c9587251809b85378df1efa9a735d3c5e24b8b2141e061d61aad5538bc', CURRENT_TIMESTAMP(0));

-- Migration 202608260011_outcome_receipt_artifact_recovery (sha256 c8c0df806eea81c34d1a30d8b1580aa85b723b54ec9ab37f812b21a9d2dd6c3a)
ALTER TABLE sand_ai_invocation_outcome_receipt
    ADD COLUMN IF NOT EXISTS consumer_ack_time timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS consumer_ref varchar(128),
    ADD COLUMN IF NOT EXISTS retain_until timestamp(0) without time zone;

ALTER TABLE sand_ai_invocation_outcome_receipt
    DROP CONSTRAINT IF EXISTS sand_ai_outcome_receipt_consumer_ack_check;

ALTER TABLE sand_ai_invocation_outcome_receipt
    ADD CONSTRAINT sand_ai_outcome_receipt_consumer_ack_check CHECK (
        (consumer_ack_time IS NULL AND consumer_ref IS NULL AND retain_until IS NULL)
        OR
        (consumer_ack_time IS NOT NULL
            AND consumer_ref ~ '^artifact:[1-9][0-9]*$'
            AND retain_until IS NOT NULL
            AND retain_until > consumer_ack_time)
    );

CREATE INDEX IF NOT EXISTS sand_ai_invocation_outcome_receipt_artifact_retention_idx
    ON sand_ai_invocation_outcome_receipt (retain_until, id)
    WHERE state IN ('available', 'expired') AND consumer_ack_time IS NOT NULL;
-- B2C terminal Artifact acknowledgement must retain the producing Invocation on
-- the owning workflow run. This is nullable for pre-recovery rows.
ALTER TABLE sand_ai_workflow_run
    ADD COLUMN IF NOT EXISTS last_invocation_id bigint NULL;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260011_outcome_receipt_artifact_recovery', 'c8c0df806eea81c34d1a30d8b1580aa85b723b54ec9ab37f812b21a9d2dd6c3a', CURRENT_TIMESTAMP(0));

-- Migration 202608260012_workflow_last_invocation_fk (sha256 b39bd426ebced285024420f2903d403d2a0a0257283b713c89bc785998073a32)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_workflow_run workflow
        LEFT JOIN sand_ai_invocation invocation ON invocation.id = workflow.last_invocation_id
        WHERE workflow.last_invocation_id IS NOT NULL AND invocation.id IS NULL
    ) THEN
        RAISE EXCEPTION 'sand_ai_workflow_run.last_invocation_id contains orphaned invocation references';
    END IF;
END
$$;

ALTER TABLE sand_ai_workflow_run
    ADD CONSTRAINT sand_ai_workflow_run_last_invocation_fk
    FOREIGN KEY (last_invocation_id) REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260012_workflow_last_invocation_fk', 'b39bd426ebced285024420f2903d403d2a0a0257283b713c89bc785998073a32', CURRENT_TIMESTAMP(0));

-- Migration 202608260013_capability_environment_lock (sha256 32a21808bfa0ac5fe5073126fafe1e83815556a2d6c5b6cea3e7d7c8cd3cddce)
CREATE TABLE sand_ai_capability_environment_lock (
    environment_id bigint PRIMARY KEY,
    create_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sand_ai_capability_environment_lock_environment_positive CHECK (environment_id > 0)
);

COMMENT ON TABLE sand_ai_capability_environment_lock IS 'Operational row locks for serializing capability writes by full bigint environment reference';
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260013_capability_environment_lock', '32a21808bfa0ac5fe5073126fafe1e83815556a2d6c5b6cea3e7d7c8cd3cddce', CURRENT_TIMESTAMP(0));

-- Migration 202608260014_call_start_pricing_snapshot (sha256 14845eae5e3b4fa945ba17fa7f674b4a9822e8307fa65faea5bc37ebd8d16c93)
ALTER TABLE sand_ai_invocation
    ADD COLUMN call_started_at timestamp(0) without time zone NULL,
    ADD COLUMN call_start_pricing_state varchar(16) NULL,
    ADD COLUMN call_start_price_revision_id bigint NULL,
    ADD COLUMN call_start_price_snapshot jsonb NULL;

ALTER TABLE sand_ai_invocation
    ADD CONSTRAINT fk_sand_ai_invocation_call_start_price_revision
        FOREIGN KEY (call_start_price_revision_id) REFERENCES sand_ai_price_revision(id) ON DELETE RESTRICT,
    ADD CONSTRAINT ck_sand_ai_invocation_call_start_pricing CHECK (
        (
            call_started_at IS NULL
            AND call_start_pricing_state IS NULL
            AND call_start_price_revision_id IS NULL
            AND call_start_price_snapshot IS NULL
        )
        OR
        (
            call_started_at IS NOT NULL
            AND call_start_pricing_state IN ('priced', 'unpriced')
            AND jsonb_typeof(call_start_price_snapshot) = 'object'
            AND call_start_price_snapshot -> 'snapshot_version' = to_jsonb(1)
            AND (
                (
                    call_start_pricing_state = 'priced'
                    AND call_start_price_revision_id IS NOT NULL
                    AND call_start_price_snapshot -> 'price_revision_id' = to_jsonb(call_start_price_revision_id)
                    AND jsonb_typeof(call_start_price_snapshot -> 'rates') = 'object'
                )
                OR
                (
                    call_start_pricing_state = 'unpriced'
                    AND call_start_price_revision_id IS NULL
                    AND jsonb_typeof(call_start_price_snapshot -> 'reason') = 'string'
                    AND length(call_start_price_snapshot ->> 'reason') > 0
                )
            )
        )
    );

CREATE OR REPLACE FUNCTION sand_ai_reject_call_start_pricing_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF ROW(
        NEW.call_started_at,
        NEW.call_start_pricing_state,
        NEW.call_start_price_revision_id,
        NEW.call_start_price_snapshot
    ) IS DISTINCT FROM ROW(
        OLD.call_started_at,
        OLD.call_start_pricing_state,
        OLD.call_start_price_revision_id,
        OLD.call_start_price_snapshot
    ) THEN
        RAISE EXCEPTION 'sand_ai_invocation call-start pricing snapshot is immutable';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_sand_ai_invocation_call_start_pricing_immutable
BEFORE UPDATE ON sand_ai_invocation
FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_call_start_pricing_mutation();

COMMENT ON COLUMN sand_ai_invocation.call_start_price_snapshot IS
    'Versioned Provider-before pricing decision; contains rates or an explicit unpriced reason, never credentials.';
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260014_call_start_pricing_snapshot', '14845eae5e3b4fa945ba17fa7f674b4a9822e8307fa65faea5bc37ebd8d16c93', CURRENT_TIMESTAMP(0));

-- Migration 202608260015_knowledge_base_snapshot (sha256 bf4a4088cf2a8d9e2cf1bcaa9f8e8cfcb1efea3957547bd34a0fc78121f0e5b2)
CREATE TABLE sand_ai_knowledge_base (
    id BIGSERIAL PRIMARY KEY,
    environment_id BIGINT NOT NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    description VARCHAR(500) NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK (state IN ('draft', 'published', 'disabled')),
    active_version_id BIGINT NULL,
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    CHECK (code ~ '^[a-z][a-z0-9_-]{0,63}$')
);
CREATE UNIQUE INDEX sand_ai_knowledge_base_environment_code_uq ON sand_ai_knowledge_base(environment_id, code) WHERE delete_time IS NULL;

CREATE TABLE sand_ai_knowledge_base_version (
    id BIGSERIAL PRIMARY KEY,
    knowledge_base_id BIGINT NOT NULL REFERENCES sand_ai_knowledge_base(id),
    version_no INTEGER NOT NULL CHECK (version_no > 0),
    state VARCHAR(16) NOT NULL CHECK (state IN ('draft', 'published')),
    snapshot_sha256 CHAR(64) NOT NULL CHECK (snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    source_count INTEGER NOT NULL CHECK (source_count >= 0),
    published_time TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (knowledge_base_id, version_no)
);
ALTER TABLE sand_ai_knowledge_base ADD CONSTRAINT sand_ai_knowledge_base_active_version_fk FOREIGN KEY (active_version_id) REFERENCES sand_ai_knowledge_base_version(id);

CREATE TABLE sand_ai_knowledge_base_source (
    id BIGSERIAL PRIMARY KEY,
    knowledge_base_version_id BIGINT NOT NULL REFERENCES sand_ai_knowledge_base_version(id),
    file_id BIGINT NOT NULL REFERENCES sand_ai_file(id),
    parse_id BIGINT NOT NULL REFERENCES sand_ai_file_parse(id),
    source_block_id BIGINT NOT NULL REFERENCES sand_ai_source_block(id),
    sequence_no INTEGER NOT NULL CHECK (sequence_no >= 0),
    locator_type VARCHAR(32) NOT NULL,
    locator JSONB NOT NULL,
    content_sha256 CHAR(64) NOT NULL CHECK (content_sha256 ~ '^[a-f0-9]{64}$'),
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (knowledge_base_version_id, source_block_id),
    UNIQUE (knowledge_base_version_id, file_id, parse_id, sequence_no)
);
CREATE INDEX sand_ai_knowledge_base_source_version_idx ON sand_ai_knowledge_base_source(knowledge_base_version_id, source_block_id);

CREATE TABLE sand_ai_knowledge_operation (
    id BIGSERIAL PRIMARY KEY,
    environment_id BIGINT NOT NULL,
    operation_code VARCHAR(32) NOT NULL CHECK (operation_code IN ('create_draft', 'publish', 'disable')),
    idempotency_key VARCHAR(128) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    knowledge_base_id BIGINT NOT NULL REFERENCES sand_ai_knowledge_base(id),
    version_id BIGINT NULL REFERENCES sand_ai_knowledge_base_version(id),
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (environment_id, operation_code, idempotency_key)
);

CREATE OR REPLACE FUNCTION sand_ai_reject_published_knowledge_version_mutation() RETURNS trigger AS $$
BEGIN
    IF OLD.state = 'published' THEN
        RAISE EXCEPTION 'published knowledge versions are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER sand_ai_knowledge_version_immutable BEFORE UPDATE OR DELETE ON sand_ai_knowledge_base_version FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_published_knowledge_version_mutation();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260015_knowledge_base_snapshot', 'bf4a4088cf2a8d9e2cf1bcaa9f8e8cfcb1efea3957547bd34a0fc78121f0e5b2', CURRENT_TIMESTAMP(0));

-- Migration 202608260016_knowledge_source_immutable (sha256 df186b912d8ec759ffebfe8af81c81d24b0aa10bf675687251eee86c4c4327eb)
CREATE OR REPLACE FUNCTION sand_ai_knowledge_source_immutable() RETURNS trigger AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(NEW.knowledge_base_version_id, OLD.knowledge_base_version_id)
          AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'published knowledge sources are immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER sand_ai_knowledge_source_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_knowledge_base_source
    FOR EACH ROW EXECUTE FUNCTION sand_ai_knowledge_source_immutable();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260016_knowledge_source_immutable', 'df186b912d8ec759ffebfe8af81c81d24b0aa10bf675687251eee86c4c4327eb', CURRENT_TIMESTAMP(0));

-- Migration 202608260017_knowledge_snapshot_hardening (sha256 6953e2d5ca2401dd744b2e3cc7f8e99562b6179845a3d2dfff96343e80ded971)
-- SAND-133A hardening.  This migration deliberately leaves 015/016 checksums
-- untouched and upgrades their trigger semantics in place.

CREATE OR REPLACE FUNCTION sand_ai_knowledge_source_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(NEW.knowledge_base_version_id, OLD.knowledge_base_version_id)
          AND state = 'published'
    ) OR EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(OLD.knowledge_base_version_id, NEW.knowledge_base_version_id)
          AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'published knowledge sources are immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;

DROP TRIGGER IF EXISTS sand_ai_knowledge_source_immutable ON sand_ai_knowledge_base_source;
CREATE TRIGGER sand_ai_knowledge_source_immutable
BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_knowledge_base_source
FOR EACH ROW EXECUTE FUNCTION sand_ai_knowledge_source_immutable();

CREATE OR REPLACE FUNCTION sand_ai_reject_published_source_block_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_source s
        INNER JOIN sand_ai_knowledge_base_version v ON v.id = s.knowledge_base_version_id
        WHERE s.source_block_id = OLD.id
          AND v.state = 'published'
    ) AND (
        TG_OP = 'DELETE'
        OR OLD.file_id IS DISTINCT FROM NEW.file_id
        OR OLD.parse_id IS DISTINCT FROM NEW.parse_id
        OR OLD.sequence_no IS DISTINCT FROM NEW.sequence_no
        OR OLD.locator_type IS DISTINCT FROM NEW.locator_type
        OR OLD.locator IS DISTINCT FROM NEW.locator
        OR OLD.content IS DISTINCT FROM NEW.content
        OR OLD.content_sha256 IS DISTINCT FROM NEW.content_sha256
    ) THEN
        RAISE EXCEPTION 'published knowledge source blocks are immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;

CREATE TRIGGER sand_ai_published_source_block_immutable
BEFORE UPDATE OR DELETE ON sand_ai_source_block
FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_published_source_block_mutation();

CREATE UNIQUE INDEX sand_ai_knowledge_base_version_one_draft_idx
    ON sand_ai_knowledge_base_version (knowledge_base_id)
    WHERE state = 'draft';

ALTER TABLE sand_ai_knowledge_base_version
    ADD CONSTRAINT sand_ai_knowledge_base_version_id_base_key UNIQUE (id, knowledge_base_id);

ALTER TABLE sand_ai_knowledge_base
    DROP CONSTRAINT sand_ai_knowledge_base_active_version_fk,
    ADD CONSTRAINT sand_ai_knowledge_base_active_version_fk
        FOREIGN KEY (active_version_id, id)
        REFERENCES sand_ai_knowledge_base_version (id, knowledge_base_id)
        DEFERRABLE INITIALLY DEFERRED;

CREATE OR REPLACE FUNCTION sand_ai_enforce_active_knowledge_version()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.active_version_id IS NULL THEN
        IF NEW.state = 'published' THEN
            RAISE EXCEPTION 'published knowledge base requires an active version';
        END IF;
        RETURN NEW;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version
        WHERE id = NEW.active_version_id
          AND knowledge_base_id = NEW.id
          AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'active knowledge version must belong to the base and be published';
    END IF;

    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER sand_ai_knowledge_base_active_version_guard
AFTER INSERT OR UPDATE OF active_version_id, state ON sand_ai_knowledge_base
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION sand_ai_enforce_active_knowledge_version();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260017_knowledge_snapshot_hardening', '6953e2d5ca2401dd744b2e3cc7f8e99562b6179845a3d2dfff96343e80ded971', CURRENT_TIMESTAMP(0));

-- Migration 202608260018_create_versioned_skill (sha256 ae65306432ecc90dd5ca6e1e4c9b6c71e2d9d61ba8ac167f987a8e3f9387b468)
CREATE TABLE sand_ai_skill (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    description varchar(1000) NOT NULL DEFAULT '',
    active_version_id bigint NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_skill_environment_code_uq UNIQUE (environment_id, code),
    CONSTRAINT sand_ai_skill_code_ck CHECK (code ~ '^[a-z0-9][a-z0-9_-]{1,63}$')
);

CREATE TABLE sand_ai_skill_version (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    skill_id bigint NOT NULL REFERENCES sand_ai_skill(id),
    version_no integer NOT NULL CHECK (version_no > 0),
    name varchar(128) NOT NULL,
    description varchar(1000) NOT NULL DEFAULT '',
    instruction text NOT NULL,
    input_schema jsonb NOT NULL,
    output_schema jsonb NOT NULL,
    review_policy jsonb NOT NULL,
    state varchar(16) NOT NULL CHECK (state IN ('draft', 'published', 'revoked')),
    published_time timestamp(0) without time zone NULL,
    revoked_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_skill_version_uq UNIQUE (skill_id, version_no)
);
CREATE UNIQUE INDEX sand_ai_skill_version_one_draft_partial_idx ON sand_ai_skill_version (skill_id) WHERE state = 'draft';
ALTER TABLE sand_ai_skill_version ADD CONSTRAINT sand_ai_skill_version_id_skill_uq UNIQUE (id, skill_id);
ALTER TABLE sand_ai_skill ADD CONSTRAINT sand_ai_skill_active_version_fk FOREIGN KEY (active_version_id, id) REFERENCES sand_ai_skill_version(id, skill_id) DEFERRABLE INITIALLY DEFERRED;

CREATE TABLE sand_ai_skill_knowledge_ref (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    skill_version_id bigint NOT NULL REFERENCES sand_ai_skill_version(id),
    knowledge_base_id bigint NOT NULL REFERENCES sand_ai_knowledge_base(id),
    knowledge_base_version_id bigint NOT NULL,
    snapshot_sha256 char(64) NOT NULL CHECK (snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_skill_knowledge_ref_version_uq UNIQUE (skill_version_id, knowledge_base_version_id),
    CONSTRAINT sand_ai_skill_knowledge_ref_exact_version_fk FOREIGN KEY (knowledge_base_version_id, knowledge_base_id) REFERENCES sand_ai_knowledge_base_version(id, knowledge_base_id)
);

CREATE TABLE sand_ai_skill_run (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    skill_id bigint NOT NULL REFERENCES sand_ai_skill(id),
    skill_version_id bigint NOT NULL REFERENCES sand_ai_skill_version(id),
    request_id varchar(128) NOT NULL,
    run_kind varchar(16) NOT NULL CHECK (run_kind IN ('test', 'workload')),
    state varchar(16) NOT NULL CHECK (state IN ('running', 'succeeded', 'failed')),
    input jsonb NOT NULL,
    output jsonb NULL,
    citations jsonb NOT NULL DEFAULT '[]'::jsonb,
    review_required boolean NOT NULL DEFAULT false,
    invocation_id bigint NULL REFERENCES sand_ai_invocation(id),
    error_code varchar(64) NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_skill_run_request_uq UNIQUE (environment_id, skill_version_id, request_id)
);

ALTER TABLE sand_ai_invocation ADD COLUMN skill_id bigint NULL REFERENCES sand_ai_skill(id), ADD COLUMN skill_version_id bigint NULL REFERENCES sand_ai_skill_version(id), ADD COLUMN skill_run_id bigint NULL REFERENCES sand_ai_skill_run(id);
ALTER TABLE sand_ai_usage_event ADD COLUMN skill_id bigint NULL REFERENCES sand_ai_skill(id), ADD COLUMN skill_version_id bigint NULL REFERENCES sand_ai_skill_version(id), ADD COLUMN skill_run_id bigint NULL REFERENCES sand_ai_skill_run(id);
CREATE INDEX sand_ai_invocation_skill_version_idx ON sand_ai_invocation (skill_id, skill_version_id) WHERE skill_id IS NOT NULL;
CREATE INDEX sand_ai_usage_event_skill_version_idx ON sand_ai_usage_event (skill_id, skill_version_id) WHERE skill_id IS NOT NULL;

DO $$
DECLARE constraint_name text;
BEGIN
    SELECT conname INTO constraint_name FROM pg_constraint
    WHERE conrelid = 'sand_ai_usage_event'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%source_kind%';
    IF constraint_name IS NULL THEN RAISE EXCEPTION 'SAND_AI_USAGE_SOURCE_KIND_CONSTRAINT_MISSING'; END IF;
    EXECUTE format('ALTER TABLE sand_ai_usage_event DROP CONSTRAINT %I', constraint_name);
END $$;
ALTER TABLE sand_ai_usage_event ADD CONSTRAINT sand_ai_usage_event_source_kind_ck CHECK (source_kind IN ('chat', 'ai_task', 'agent', 'capability', 'workflow', 'structured_workflow', 'external_service', 'onboarding_verification', 'skill', 'skill_test'));


CREATE OR REPLACE FUNCTION sand_ai_skill_version_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR OLD.state IN ('published', 'revoked') THEN RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_IMMUTABLE'; END IF;
    IF NEW.state NOT IN ('draft', 'published', 'revoked') THEN RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_STATE_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_version_immutable BEFORE UPDATE OR DELETE ON sand_ai_skill_version FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_version_immutable();

CREATE OR REPLACE FUNCTION sand_ai_skill_published_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref record;
BEGIN
    IF NEW.state = 'published' THEN
        FOR ref IN SELECT * FROM sand_ai_skill_knowledge_ref WHERE skill_version_id = NEW.id LOOP
            IF NOT EXISTS (SELECT 1 FROM sand_ai_knowledge_base kb JOIN sand_ai_knowledge_base_version kv ON kv.id = ref.knowledge_base_version_id AND kv.knowledge_base_id = kb.id WHERE kb.id = ref.knowledge_base_id AND kb.environment_id = (SELECT environment_id FROM sand_ai_skill WHERE id = NEW.skill_id) AND kb.status = 1 AND kb.delete_time IS NULL AND kb.state <> 'disabled' AND kv.state = 'published' AND kv.snapshot_sha256 = ref.snapshot_sha256) THEN RAISE EXCEPTION 'SAND_AI_SKILL_KNOWLEDGE_REFERENCE_INVALID'; END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_published_version_guard BEFORE UPDATE OF state ON sand_ai_skill_version FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_published_version_guard();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260018_create_versioned_skill', 'ae65306432ecc90dd5ca6e1e4c9b6c71e2d9d61ba8ac167f987a8e3f9387b468', CURRENT_TIMESTAMP(0));

-- Migration 202608260019_harden_versioned_skill_execution (sha256 5e4f737f914bd218cbed2fda6395f695c046ea49a8b8026a1d175fe10aaac881)
-- SAND-134A additive upgrade from verified 000-018 catalog. Legacy published
-- versions receive a marker and fail closed until republished with a full snapshot.
-- 018 did not own the operation ledger. Create its pre-hardening shape before
-- this migration's additive columns/constraints so fresh and upgrade paths
-- exercise the same real schema.
CREATE TABLE IF NOT EXISTS sand_ai_skill_operation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    skill_id bigint NOT NULL REFERENCES sand_ai_skill(id),
    operation varchar(32) NOT NULL CHECK (operation IN ('create_draft','publish','revoke')),
    idempotency_key varchar(128) NOT NULL,
    result jsonb NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sand_ai_skill_operation_uq UNIQUE (environment_id, operation, idempotency_key)
);
ALTER TABLE sand_ai_skill ADD COLUMN IF NOT EXISTS state varchar(16);
UPDATE sand_ai_skill SET state = CASE WHEN status = 2 THEN 'revoked' WHEN active_version_id IS NOT NULL THEN 'published' ELSE 'draft' END WHERE state IS NULL;
ALTER TABLE sand_ai_skill ALTER COLUMN state SET DEFAULT 'draft';
ALTER TABLE sand_ai_skill ALTER COLUMN state SET NOT NULL;
ALTER TABLE sand_ai_skill ADD CONSTRAINT ck_sand_ai_skill_state_v019 CHECK (state IN ('draft','published','revoked')) NOT VALID;
ALTER TABLE sand_ai_skill ADD CONSTRAINT ck_sand_ai_skill_published_active_v019 CHECK (state <> 'published' OR active_version_id IS NOT NULL) NOT VALID;

ALTER TABLE sand_ai_skill_version ADD COLUMN IF NOT EXISTS execution_snapshot jsonb;
UPDATE sand_ai_skill_version SET execution_snapshot = jsonb_build_object('legacy_unverified', true) WHERE execution_snapshot IS NULL;
ALTER TABLE sand_ai_skill_version ALTER COLUMN execution_snapshot SET NOT NULL;
ALTER TABLE sand_ai_skill_version ADD CONSTRAINT ck_sand_ai_skill_version_snapshot_object_v019 CHECK (jsonb_typeof(execution_snapshot)='object') NOT VALID;

ALTER TABLE sand_ai_skill_operation ADD COLUMN IF NOT EXISTS skill_version_id bigint;
ALTER TABLE sand_ai_skill_operation ADD COLUMN IF NOT EXISTS request_fingerprint char(64);
ALTER TABLE sand_ai_skill_operation ADD CONSTRAINT fk_sand_ai_skill_operation_version_v019 FOREIGN KEY (skill_version_id) REFERENCES sand_ai_skill_version(id) NOT VALID;
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM sand_ai_skill_operation GROUP BY environment_id, idempotency_key HAVING count(*) > 1) THEN RAISE EXCEPTION 'SAND_AI_SKILL_OPERATION_LEGACY_KEY_CONFLICT'; END IF;
  IF EXISTS (SELECT 1 FROM sand_ai_skill_operation o WHERE jsonb_typeof(o.result) <> 'object' OR COALESCE(o.result->>'skill_id','') !~ '^[1-9][0-9]*$' OR COALESCE(o.result->>'version_id','') !~ '^[1-9][0-9]*$' OR o.skill_id <> (o.result->>'skill_id')::bigint) THEN RAISE EXCEPTION 'SAND_AI_SKILL_OPERATION_LEGACY_RESOURCE_UNRESOLVED'; END IF;
  IF EXISTS (SELECT 1 FROM sand_ai_skill_operation o LEFT JOIN sand_ai_skill_version v ON v.id=(o.result->>'version_id')::bigint AND v.skill_id=o.skill_id WHERE v.id IS NULL) THEN RAISE EXCEPTION 'SAND_AI_SKILL_OPERATION_LEGACY_VERSION_UNRESOLVED'; END IF;
END $$;
UPDATE sand_ai_skill_operation SET skill_version_id=(result->>'version_id')::bigint WHERE skill_version_id IS NULL;
UPDATE sand_ai_skill_operation SET request_fingerprint=encode(sha256(convert_to('sand-ai-skill-operation-v019:' || environment_id::text || ':' || skill_id::text || ':' || skill_version_id::text || ':' || operation || ':' || idempotency_key, 'UTF8')),'hex') WHERE request_fingerprint IS NULL;
UPDATE sand_ai_skill_operation SET result=jsonb_build_object('legacy',true,'skill_id',skill_id,'skill_version_id',skill_version_id,'operation',operation) WHERE result IS NULL;
ALTER TABLE sand_ai_skill_operation ALTER COLUMN skill_version_id SET NOT NULL;
ALTER TABLE sand_ai_skill_operation ALTER COLUMN request_fingerprint SET NOT NULL;
ALTER TABLE sand_ai_skill_operation DROP CONSTRAINT IF EXISTS sand_ai_skill_operation_uq;
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_skill_operation_global_key_v019 ON sand_ai_skill_operation(environment_id, operation, idempotency_key);

ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS request_fingerprint char(64);
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS model_id bigint;
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS lease_token varchar(64);
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS lease_fence bigint;
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS lease_expires_at timestamp;
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS delete_time timestamp;
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS error_detail jsonb;
ALTER TABLE sand_ai_skill_run ADD CONSTRAINT ck_sand_ai_skill_run_kind_v019 CHECK (run_kind IN ('workload','test')) NOT VALID;
CREATE INDEX IF NOT EXISTS idx_sand_ai_skill_run_lease_v019 ON sand_ai_skill_run(state, lease_expires_at) WHERE state = 'running';
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_skill_run_identity_v019 ON sand_ai_skill_run(skill_id, skill_version_id, id);

ALTER TABLE sand_ai_invocation ADD CONSTRAINT ck_sand_ai_invocation_skill_triple_v019 CHECK ((skill_id IS NULL AND skill_version_id IS NULL AND skill_run_id IS NULL) OR (skill_id IS NOT NULL AND skill_version_id IS NOT NULL AND skill_run_id IS NOT NULL)) NOT VALID;
ALTER TABLE sand_ai_invocation ADD CONSTRAINT fk_sand_ai_invocation_skill_triple_v019 FOREIGN KEY (skill_id, skill_version_id, skill_run_id) REFERENCES sand_ai_skill_run(skill_id, skill_version_id, id) NOT VALID;
CREATE OR REPLACE FUNCTION sand_ai_invocation_skill_identity_immutable_v019() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='UPDATE' AND (OLD.environment_id IS DISTINCT FROM NEW.environment_id OR OLD.model_id IS DISTINCT FROM NEW.model_id OR OLD.deployment_id IS DISTINCT FROM NEW.deployment_id OR OLD.skill_id IS DISTINCT FROM NEW.skill_id OR OLD.skill_version_id IS DISTINCT FROM NEW.skill_version_id OR OLD.skill_run_id IS DISTINCT FROM NEW.skill_run_id) THEN RAISE EXCEPTION 'invocation routing and skill attribution are immutable'; END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_invocation_skill_identity_immutable_v019 ON sand_ai_invocation;
CREATE TRIGGER trg_sand_ai_invocation_skill_identity_immutable_v019 BEFORE UPDATE ON sand_ai_invocation FOR EACH ROW EXECUTE FUNCTION sand_ai_invocation_skill_identity_immutable_v019();
ALTER TABLE sand_ai_usage_event ADD CONSTRAINT ck_sand_ai_usage_event_skill_triple_v019 CHECK ((skill_id IS NULL AND skill_version_id IS NULL AND skill_run_id IS NULL) OR (skill_id IS NOT NULL AND skill_version_id IS NOT NULL AND skill_run_id IS NOT NULL)) NOT VALID;
ALTER TABLE sand_ai_usage_event ADD CONSTRAINT fk_sand_ai_usage_event_skill_triple_v019 FOREIGN KEY (skill_id, skill_version_id, skill_run_id) REFERENCES sand_ai_skill_run(skill_id, skill_version_id, id) NOT VALID;
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_usage_event_invocation_v019 ON sand_ai_usage_event(invocation_id) WHERE invocation_id IS NOT NULL;
CREATE OR REPLACE FUNCTION sand_ai_usage_event_invocation_skill_match_v019() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM sand_ai_invocation i WHERE i.id=NEW.invocation_id AND (i.skill_id IS DISTINCT FROM NEW.skill_id OR i.skill_version_id IS DISTINCT FROM NEW.skill_version_id OR i.skill_run_id IS DISTINCT FROM NEW.skill_run_id OR i.environment_id IS DISTINCT FROM NEW.environment_id OR i.model_id IS DISTINCT FROM NEW.model_id OR i.deployment_id IS DISTINCT FROM NEW.deployment_id)) THEN RAISE EXCEPTION 'usage event must exactly match invocation skill attribution'; END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_usage_event_invocation_skill_match_v019 ON sand_ai_usage_event;
CREATE CONSTRAINT TRIGGER trg_sand_ai_usage_event_invocation_skill_match_v019 AFTER INSERT OR UPDATE OF invocation_id,skill_id,skill_version_id,skill_run_id,environment_id,model_id,deployment_id ON sand_ai_usage_event DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_usage_event_invocation_skill_match_v019();
CREATE OR REPLACE FUNCTION sand_ai_skill_run_invocation_match_v019() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.invocation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sand_ai_invocation i WHERE i.id=NEW.invocation_id AND i.environment_id=NEW.environment_id AND i.skill_id=NEW.skill_id AND i.skill_version_id=NEW.skill_version_id AND i.skill_run_id=NEW.id AND i.model_id=NEW.model_id) THEN RAISE EXCEPTION 'skill run invocation attribution does not match'; END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_skill_run_invocation_match_v019 ON sand_ai_skill_run;
CREATE CONSTRAINT TRIGGER trg_sand_ai_skill_run_invocation_match_v019 AFTER INSERT OR UPDATE OF invocation_id ON sand_ai_skill_run DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_run_invocation_match_v019();

CREATE OR REPLACE FUNCTION sand_ai_skill_operation_immutable_v019() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'skill operation ledger is immutable'; END IF; RETURN NEW; END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_skill_operation_immutable_v019 ON sand_ai_skill_operation;
CREATE TRIGGER trg_sand_ai_skill_operation_immutable_v019 BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_skill_operation FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_operation_immutable_v019();

CREATE OR REPLACE FUNCTION sand_ai_skill_knowledge_ref_immutable_v019() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP IN ('UPDATE','DELETE') AND EXISTS (SELECT 1 FROM sand_ai_skill_version WHERE id=OLD.skill_version_id AND state IN ('published','revoked')) THEN RAISE EXCEPTION 'published skill knowledge references are immutable'; END IF;
  IF TG_OP IN ('INSERT','UPDATE') AND EXISTS (SELECT 1 FROM sand_ai_skill_version WHERE id=NEW.skill_version_id AND state IN ('published','revoked')) THEN RAISE EXCEPTION 'published skill knowledge references are immutable'; END IF;
  IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_skill_knowledge_ref_immutable_v019 ON sand_ai_skill_knowledge_ref;
CREATE TRIGGER trg_sand_ai_skill_knowledge_ref_immutable_v019 BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_skill_knowledge_ref FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_knowledge_ref_immutable_v019();

CREATE OR REPLACE FUNCTION sand_ai_skill_publish_guard_v019() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref_count int; exact_count int;
BEGIN
  IF NEW.state = 'published' THEN
    IF NEW.execution_snapshot ? 'legacy_unverified' OR NOT (NEW.execution_snapshot ? 'snapshot_sha256') THEN RAISE EXCEPTION 'published skill version requires verified execution snapshot'; END IF;
    IF NOT EXISTS (SELECT 1 FROM sand_ai_skill s WHERE s.id = NEW.skill_id AND s.active_version_id = NEW.id AND s.state = 'published') THEN RAISE EXCEPTION 'published skill version must be active'; END IF;
    SELECT count(*) INTO ref_count FROM sand_ai_skill_knowledge_ref r WHERE r.skill_version_id = NEW.id;
    SELECT count(*) INTO exact_count FROM sand_ai_skill_knowledge_ref r JOIN sand_ai_knowledge_base_version kbv ON kbv.id = r.knowledge_base_version_id JOIN sand_ai_knowledge_base kb ON kb.id = r.knowledge_base_id WHERE r.skill_version_id = NEW.id AND kb.environment_id = (SELECT environment_id FROM sand_ai_skill WHERE id = NEW.skill_id) AND kbv.knowledge_base_id = r.knowledge_base_id AND kbv.snapshot_sha256 = r.snapshot_sha256 AND kb.delete_time IS NULL AND kb.status = 1 AND kb.state = 'published' AND kbv.state = 'published';
    IF ref_count < 1 OR ref_count <> exact_count THEN RAISE EXCEPTION 'published skill version requires exact knowledge references'; END IF;
  END IF; RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_skill_publish_guard_v019 ON sand_ai_skill_version;
CREATE CONSTRAINT TRIGGER trg_sand_ai_skill_publish_guard_v019 AFTER INSERT OR UPDATE OF state ON sand_ai_skill_version DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_publish_guard_v019();
CREATE OR REPLACE FUNCTION sand_ai_skill_active_version_guard_v019() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.active_version_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sand_ai_skill_version v WHERE v.id=NEW.active_version_id AND v.skill_id=NEW.id AND v.state='published') THEN RAISE EXCEPTION 'skill active version must be published and belong to skill'; END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_skill_active_version_guard_v019 ON sand_ai_skill;
CREATE CONSTRAINT TRIGGER trg_sand_ai_skill_active_version_guard_v019 AFTER INSERT OR UPDATE OF active_version_id,state ON sand_ai_skill DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_active_version_guard_v019();
-- Published versions are immutable history. No single-published-version index: active_version_id selects newest.
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260019_harden_versioned_skill_execution', '5e4f737f914bd218cbed2fda6395f695c046ea49a8b8026a1d175fe10aaac881', CURRENT_TIMESTAMP(0));

-- Migration 202608260020_skill_operation_ledger (sha256 f36f4038b3e5d21cb3d494ec3b27159151b1ab83d2cecf413109b63038e742fb)
-- SAND-134A operation-ledger follow-up. 019 owns the base table because it
-- hardens 000-018 deployments where that ledger did not yet exist.
ALTER TABLE sand_ai_skill_operation
    ADD CONSTRAINT ck_sand_ai_skill_operation_key_v020
    CHECK (length(idempotency_key) BETWEEN 1 AND 128) NOT VALID;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608260020_skill_operation_ledger', 'f36f4038b3e5d21cb3d494ec3b27159151b1ab83d2cecf413109b63038e742fb', CURRENT_TIMESTAMP(0));

-- Migration 202608280021_create_standard_readonly_tool (sha256 19b74e5da38acfa0eb4ced2a84e36fd903a38805083391c9c18ce48688f9f6e1)
CREATE TABLE sand_ai_tool (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    description varchar(1000) NOT NULL DEFAULT '',
    adapter varchar(64) NOT NULL,
    effect_class varchar(32) NOT NULL,
    active_version_id bigint NULL,
    state varchar(16) NOT NULL,
    status smallint NOT NULL DEFAULT 1,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_ai_tool_code UNIQUE (code),
    CONSTRAINT ck_sand_ai_tool_code CHECK (code ~ '^[a-z0-9][a-z0-9_-]{1,63}$'),
    CONSTRAINT ck_sand_ai_tool_adapter CHECK (adapter = 'standard_readonly_http'),
    CONSTRAINT ck_sand_ai_tool_effect CHECK (effect_class = 'read_only'),
    CONSTRAINT ck_sand_ai_tool_state CHECK (state IN ('draft','published','revoked')),
    CONSTRAINT ck_sand_ai_tool_status CHECK (status IN (1,2))
);

CREATE TABLE sand_ai_tool_version (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    tool_id bigint NOT NULL REFERENCES sand_ai_tool(id),
    version_no integer NOT NULL,
    name varchar(128) NOT NULL,
    description varchar(1000) NOT NULL DEFAULT '',
    input_schema jsonb NOT NULL,
    output_schema jsonb NOT NULL,
    review_policy jsonb NOT NULL,
    timeout_ms integer NOT NULL,
    response_max_bytes integer NOT NULL,
    state varchar(16) NOT NULL,
    published_time timestamp(0) without time zone NULL,
    revoked_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_version_no UNIQUE (tool_id, version_no),
    CONSTRAINT ck_sand_ai_tool_version_no CHECK (version_no > 0),
    CONSTRAINT ck_sand_ai_tool_version_schema CHECK (jsonb_typeof(input_schema)='object' AND jsonb_typeof(output_schema)='object' AND jsonb_typeof(review_policy)='object'),
    CONSTRAINT ck_sand_ai_tool_version_bounds CHECK (timeout_ms BETWEEN 100 AND 30000 AND response_max_bytes BETWEEN 1024 AND 1048576),
    CONSTRAINT ck_sand_ai_tool_version_state CHECK (state IN ('draft','published','revoked'))
);

ALTER TABLE sand_ai_tool
    ADD CONSTRAINT fk_sand_ai_tool_active_version FOREIGN KEY (active_version_id) REFERENCES sand_ai_tool_version(id) DEFERRABLE INITIALLY DEFERRED;

CREATE UNIQUE INDEX uk_sand_ai_tool_single_draft ON sand_ai_tool_version(tool_id) WHERE state='draft';

CREATE TABLE sand_ai_tool_connection (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    active_revision_id bigint NULL,
    state varchar(16) NOT NULL,
    status smallint NOT NULL DEFAULT 1,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_ai_tool_connection_code UNIQUE (environment_id, code),
    CONSTRAINT ck_sand_ai_tool_connection_identity CHECK (environment_id > 0 AND code ~ '^[a-z0-9][a-z0-9_-]{1,63}$'),
    CONSTRAINT ck_sand_ai_tool_connection_state CHECK (state IN ('published','revoked')),
    CONSTRAINT ck_sand_ai_tool_connection_status CHECK (status IN (1,2))
);

CREATE TABLE sand_ai_tool_connection_revision (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    tool_connection_id bigint NOT NULL REFERENCES sand_ai_tool_connection(id),
    revision_no integer NOT NULL,
    encrypted_config jsonb NOT NULL,
    config_fingerprint char(64) NOT NULL,
    state varchar(16) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_connection_revision UNIQUE (tool_connection_id, revision_no),
    CONSTRAINT ck_sand_ai_tool_connection_revision_no CHECK (revision_no > 0),
    CONSTRAINT ck_sand_ai_tool_connection_revision_config CHECK (jsonb_typeof(encrypted_config)='object' AND config_fingerprint ~ '^[0-9a-f]{64}$'),
    CONSTRAINT ck_sand_ai_tool_connection_revision_state CHECK (state='published')
);

ALTER TABLE sand_ai_tool_connection
    ADD CONSTRAINT fk_sand_ai_tool_connection_active_revision FOREIGN KEY (active_revision_id) REFERENCES sand_ai_tool_connection_revision(id) DEFERRABLE INITIALLY DEFERRED;

CREATE TABLE sand_ai_tool_environment_binding (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    tool_id bigint NOT NULL REFERENCES sand_ai_tool(id),
    tool_version_id bigint NOT NULL REFERENCES sand_ai_tool_version(id),
    tool_connection_id bigint NOT NULL REFERENCES sand_ai_tool_connection(id),
    tool_connection_revision_id bigint NOT NULL REFERENCES sand_ai_tool_connection_revision(id),
    state varchar(16) NOT NULL,
    status smallint NOT NULL DEFAULT 1,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_ai_tool_environment_binding UNIQUE (environment_id, tool_version_id),
    CONSTRAINT ck_sand_ai_tool_environment_binding_identity CHECK (environment_id > 0),
    CONSTRAINT ck_sand_ai_tool_environment_binding_state CHECK (state='published'),
    CONSTRAINT ck_sand_ai_tool_environment_binding_status CHECK (status IN (1,2))
);

CREATE TABLE sand_ai_tool_operation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    scope_key varchar(160) NOT NULL,
    operation varchar(32) NOT NULL,
    idempotency_key varchar(128) NOT NULL,
    request_fingerprint char(64) NOT NULL,
    result jsonb NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_operation UNIQUE (scope_key, operation, idempotency_key),
    CONSTRAINT ck_sand_ai_tool_operation_identity CHECK (scope_key <> '' AND operation ~ '^[a-z][a-z0-9_]{1,31}$' AND request_fingerprint ~ '^[0-9a-f]{64}$' AND jsonb_typeof(result)='object')
);

CREATE TABLE sand_ai_tool_run (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    organization_id bigint NULL,
    application_id bigint NULL,
    environment_id bigint NOT NULL,
    workload_client_id bigint NULL,
    identity_snapshot jsonb NOT NULL,
    tool_id bigint NOT NULL REFERENCES sand_ai_tool(id),
    tool_version_id bigint NOT NULL REFERENCES sand_ai_tool_version(id),
    tool_environment_binding_id bigint NOT NULL REFERENCES sand_ai_tool_environment_binding(id),
    tool_connection_id bigint NOT NULL REFERENCES sand_ai_tool_connection(id),
    tool_connection_revision_id bigint NOT NULL REFERENCES sand_ai_tool_connection_revision(id),
    request_id varchar(128) NOT NULL,
    request_payload_fingerprint char(64) NOT NULL,
    request_fingerprint char(64) NOT NULL,
    business_scope varchar(128) NOT NULL,
    input jsonb NOT NULL,
    execution_snapshot jsonb NOT NULL,
    timeout_ms integer NOT NULL,
    state varchar(16) NOT NULL,
    result jsonb NULL,
    citations jsonb NULL,
    review_required boolean NOT NULL DEFAULT false,
    metering jsonb NULL,
    error_code varchar(96) NULL,
    error_detail jsonb NULL,
    latency_ms integer NULL,
    lease_token varchar(96) NOT NULL,
    lease_fence integer NOT NULL,
    lease_expires_at timestamp(0) without time zone NULL,
    audit_log_id bigint NULL REFERENCES sand_ai_audit_log(id),
    completed_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_run_request UNIQUE (environment_id, request_id),
    CONSTRAINT ck_sand_ai_tool_run_identity CHECK (environment_id > 0 AND jsonb_typeof(identity_snapshot)='object'),
    CONSTRAINT ck_sand_ai_tool_run_fingerprint CHECK (request_payload_fingerprint ~ '^[0-9a-f]{64}$' AND request_fingerprint ~ '^[0-9a-f]{64}$'),
    CONSTRAINT ck_sand_ai_tool_run_payload CHECK (business_scope ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$' AND jsonb_typeof(input)='object' AND jsonb_typeof(execution_snapshot)='object'),
    CONSTRAINT ck_sand_ai_tool_run_state CHECK (state IN ('running','succeeded','failed','ambiguous')),
    CONSTRAINT ck_sand_ai_tool_run_lease CHECK (timeout_ms BETWEEN 100 AND 30000 AND lease_fence > 0 AND lease_token <> ''),
    CONSTRAINT ck_sand_ai_tool_run_terminal CHECK (
        (state='running' AND completed_time IS NULL AND error_code IS NULL)
        OR (state='succeeded' AND completed_time IS NOT NULL AND result IS NOT NULL AND citations IS NOT NULL AND metering IS NOT NULL AND error_code IS NULL)
        OR (state IN ('failed','ambiguous') AND completed_time IS NOT NULL AND result IS NULL AND error_code IS NOT NULL AND error_detail IS NOT NULL)
    )
);

CREATE TABLE sand_ai_tool_attempt (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    tool_run_id bigint NOT NULL REFERENCES sand_ai_tool_run(id),
    attempt_no integer NOT NULL,
    state varchar(16) NOT NULL,
    dispatch_fingerprint char(64) NOT NULL,
    http_status integer NULL,
    error_code varchar(96) NULL,
    dispatched_time timestamp(0) without time zone NULL,
    completed_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_attempt UNIQUE (tool_run_id, attempt_no),
    CONSTRAINT ck_sand_ai_tool_attempt_no CHECK (attempt_no=1),
    CONSTRAINT ck_sand_ai_tool_attempt_state CHECK (state IN ('prepared','dispatched','succeeded','failed','ambiguous','cancelled')),
    CONSTRAINT ck_sand_ai_tool_attempt_fingerprint CHECK (dispatch_fingerprint ~ '^[0-9a-f]{64}$'),
    CONSTRAINT ck_sand_ai_tool_attempt_http CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599)
);

CREATE TABLE sand_ai_tool_outcome_receipt (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    tool_run_id bigint NOT NULL REFERENCES sand_ai_tool_run(id),
    environment_id bigint NOT NULL,
    tool_id bigint NOT NULL REFERENCES sand_ai_tool(id),
    tool_version_id bigint NOT NULL REFERENCES sand_ai_tool_version(id),
    request_fingerprint char(64) NOT NULL,
    receipt_version varchar(16) NOT NULL,
    cipher_version varchar(16) NOT NULL,
    key_id varchar(64) NOT NULL,
    nonce bytea NOT NULL,
    tag bytea NOT NULL,
    ciphertext bytea NOT NULL,
    plaintext_bytes integer NOT NULL,
    ciphertext_bytes integer NOT NULL,
    content_hmac bytea NOT NULL,
    state varchar(16) NOT NULL,
    expire_time timestamp(0) without time zone NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_outcome_receipt_run UNIQUE (tool_run_id),
    CONSTRAINT ck_sand_ai_tool_outcome_receipt_identity CHECK (environment_id > 0 AND request_fingerprint ~ '^[0-9a-f]{64}$'),
    CONSTRAINT ck_sand_ai_tool_outcome_receipt_crypto CHECK (receipt_version='v1' AND cipher_version='v1' AND octet_length(nonce)=12 AND octet_length(tag)=16 AND octet_length(content_hmac)=32 AND plaintext_bytes > 0 AND ciphertext_bytes=octet_length(ciphertext)),
    CONSTRAINT ck_sand_ai_tool_outcome_receipt_state CHECK (state='available')
);

CREATE TABLE sand_ai_tool_source (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    tool_run_id bigint NOT NULL REFERENCES sand_ai_tool_run(id),
    sequence_no integer NOT NULL,
    source_ref varchar(256) NOT NULL,
    locator jsonb NOT NULL,
    source_version varchar(128) NULL,
    content_hash char(64) NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_source_sequence UNIQUE (tool_run_id, sequence_no),
    CONSTRAINT ck_sand_ai_tool_source_sequence CHECK (sequence_no > 0),
    CONSTRAINT ck_sand_ai_tool_source_locator CHECK (source_ref <> '' AND jsonb_typeof(locator)='object'),
    CONSTRAINT ck_sand_ai_tool_source_version CHECK ((source_version IS NOT NULL AND source_version <> '') OR content_hash ~ '^[0-9a-f]{64}$')
);

ALTER TABLE sand_ai_usage_event
    ALTER COLUMN invocation_id DROP NOT NULL,
    ALTER COLUMN provider_id DROP NOT NULL,
    ALTER COLUMN model_id DROP NOT NULL,
    ALTER COLUMN deployment_id DROP NOT NULL,
    ADD COLUMN tool_id bigint NULL REFERENCES sand_ai_tool(id),
    ADD COLUMN tool_version_id bigint NULL REFERENCES sand_ai_tool_version(id),
    ADD COLUMN tool_run_id bigint NULL REFERENCES sand_ai_tool_run(id),
    ADD COLUMN tool_connection_id bigint NULL REFERENCES sand_ai_tool_connection(id),
    ADD COLUMN tool_connection_revision_id bigint NULL REFERENCES sand_ai_tool_connection_revision(id),
    ADD COLUMN metering_unit varchar(32) NULL,
    ADD COLUMN metering_quantity bigint NULL,
    ADD CONSTRAINT ck_sand_ai_usage_event_tool_metering CHECK (
        (tool_run_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND metering_unit IS NULL AND metering_quantity IS NULL)
        OR (tool_run_id IS NOT NULL AND tool_id IS NOT NULL AND tool_version_id IS NOT NULL AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL
            AND invocation_id IS NULL AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL
            AND metering_unit='external_request' AND metering_quantity=1 AND input_tokens=0 AND output_tokens=0 AND total_tokens=0
            AND usage_state='measured' AND pricing_state='unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
    );

CREATE UNIQUE INDEX uk_sand_ai_usage_event_tool_run ON sand_ai_usage_event(tool_run_id) WHERE tool_run_id IS NOT NULL;

CREATE OR REPLACE FUNCTION sand_ai_tool_binding_guard() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM sand_ai_tool t JOIN sand_ai_tool_version v ON v.id=NEW.tool_version_id AND v.tool_id=t.id
        JOIN sand_ai_tool_connection c ON c.id=NEW.tool_connection_id AND c.environment_id=NEW.environment_id
        JOIN sand_ai_tool_connection_revision r ON r.id=NEW.tool_connection_revision_id AND r.tool_connection_id=c.id
        WHERE t.id=NEW.tool_id AND t.active_version_id=v.id AND t.state='published' AND t.status=1
          AND v.state='published' AND c.active_revision_id=r.id AND c.state='published' AND c.status=1 AND r.state='published'
    ) THEN
        RAISE EXCEPTION 'SAND_AI_TOOL_BINDING_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_sand_ai_tool_binding_guard BEFORE INSERT OR UPDATE ON sand_ai_tool_environment_binding
FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_binding_guard();

CREATE OR REPLACE FUNCTION sand_ai_tool_run_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP='INSERT' AND NOT EXISTS (
        SELECT 1 FROM sand_ai_tool_environment_binding b
        WHERE b.id=NEW.tool_environment_binding_id AND b.environment_id=NEW.environment_id AND b.tool_id=NEW.tool_id
          AND b.tool_version_id=NEW.tool_version_id AND b.tool_connection_id=NEW.tool_connection_id
          AND b.tool_connection_revision_id=NEW.tool_connection_revision_id AND b.state='published' AND b.status=1
    ) THEN
        RAISE EXCEPTION 'SAND_AI_TOOL_BINDING_INVALID';
    END IF;
    IF TG_OP='UPDATE' AND (OLD.environment_id,OLD.tool_id,OLD.tool_version_id,OLD.tool_environment_binding_id,OLD.tool_connection_id,OLD.tool_connection_revision_id,OLD.request_id,OLD.request_payload_fingerprint,OLD.request_fingerprint,OLD.business_scope,OLD.input,OLD.execution_snapshot,OLD.timeout_ms,OLD.identity_snapshot)
        IS DISTINCT FROM (NEW.environment_id,NEW.tool_id,NEW.tool_version_id,NEW.tool_environment_binding_id,NEW.tool_connection_id,NEW.tool_connection_revision_id,NEW.request_id,NEW.request_payload_fingerprint,NEW.request_fingerprint,NEW.business_scope,NEW.input,NEW.execution_snapshot,NEW.timeout_ms,NEW.identity_snapshot) THEN
        RAISE EXCEPTION 'SAND_AI_TOOL_RUN_IMMUTABLE';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_sand_ai_tool_run_guard BEFORE INSERT OR UPDATE ON sand_ai_tool_run
FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_run_guard();

CREATE OR REPLACE FUNCTION sand_ai_tool_immutable_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_TOOL_EVIDENCE_IMMUTABLE'; END IF;
    IF TG_TABLE_NAME='sand_ai_tool_version' AND OLD.state='draft' AND NEW.state='published'
       AND (OLD.tool_id,OLD.version_no,OLD.name,OLD.description,OLD.input_schema,OLD.output_schema,OLD.review_policy,OLD.timeout_ms,OLD.response_max_bytes)
           IS NOT DISTINCT FROM (NEW.tool_id,NEW.version_no,NEW.name,NEW.description,NEW.input_schema,NEW.output_schema,NEW.review_policy,NEW.timeout_ms,NEW.response_max_bytes) THEN RETURN NEW; END IF;
    RAISE EXCEPTION 'SAND_AI_TOOL_EVIDENCE_IMMUTABLE';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_sand_ai_tool_version_immutable BEFORE UPDATE OR DELETE ON sand_ai_tool_version FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_immutable_guard();
CREATE TRIGGER trg_sand_ai_tool_connection_revision_immutable BEFORE UPDATE OR DELETE ON sand_ai_tool_connection_revision FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_immutable_guard();
CREATE TRIGGER trg_sand_ai_tool_binding_immutable BEFORE UPDATE OR DELETE ON sand_ai_tool_environment_binding FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_immutable_guard();
CREATE TRIGGER trg_sand_ai_tool_operation_immutable BEFORE UPDATE OR DELETE ON sand_ai_tool_operation FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_immutable_guard();
CREATE TRIGGER trg_sand_ai_tool_receipt_immutable BEFORE UPDATE OR DELETE ON sand_ai_tool_outcome_receipt FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_immutable_guard();
CREATE TRIGGER trg_sand_ai_tool_source_immutable BEFORE UPDATE OR DELETE ON sand_ai_tool_source FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_immutable_guard();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608280021_create_standard_readonly_tool', '19b74e5da38acfa0eb4ced2a84e36fd903a38805083391c9c18ce48688f9f6e1', CURRENT_TIMESTAMP(0));

-- Migration 202608280022_harden_standard_readonly_tool (sha256 ffea5574dd068a9727b1ba2ab160c21c3783a23f954f4811ba37b68a75cad280)
-- Close the SQL NULL gap in the citation provenance invariant and align the
-- stored content hash with the API contract (lowercase SHA-256 hex, no URI).
ALTER TABLE sand_ai_tool_source
    DROP CONSTRAINT IF EXISTS sand_ai_tool_source_citation_check;

ALTER TABLE sand_ai_tool_source
    ADD CONSTRAINT sand_ai_tool_source_citation_check
    CHECK (
        NULLIF(btrim(source_version), '') IS NOT NULL
        OR (content_hash IS NOT NULL AND content_hash ~ '^[a-f0-9]{64}$')
    );
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608280022_harden_standard_readonly_tool', 'ffea5574dd068a9727b1ba2ab160c21c3783a23f954f4811ba37b68a75cad280', CURRENT_TIMESTAMP(0));

-- Migration 202608280023_harden_standard_readonly_tool_runtime (sha256 22fef72817a282479302da1b5e20ed2bce4ad3a5fb815b49afd135b5c880719f)
-- SAND-135A static review hardening: existing Usage vocabulary, rotating
-- connection key identity, frozen receipt/run identity, explicit historical
-- version bindings, and immutable terminal runs.

ALTER TABLE sand_ai_usage_event DROP CONSTRAINT IF EXISTS sand_ai_usage_event_source_kind_ck;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT sand_ai_usage_event_source_kind_ck
    CHECK (source_kind IN ('chat','ai_task','agent','capability','workflow','structured_workflow','external_service','onboarding_verification','skill','skill_test','tool'));

ALTER TABLE sand_ai_tool_connection_revision
    ADD COLUMN IF NOT EXISTS encryption_key_id varchar(128) NOT NULL DEFAULT 'legacy_config_v1';
ALTER TABLE sand_ai_tool_connection_revision ALTER COLUMN encryption_key_id DROP DEFAULT;
DO $$ DECLARE item record; BEGIN FOR item IN SELECT conname FROM pg_constraint WHERE conrelid='sand_ai_tool_connection_revision'::regclass AND contype='c' AND pg_get_constraintdef(oid) ILIKE '%encrypted_config%' LOOP EXECUTE format('ALTER TABLE sand_ai_tool_connection_revision DROP CONSTRAINT %I', item.conname); END LOOP; END $$;
ALTER TABLE sand_ai_tool_connection_revision DROP CONSTRAINT IF EXISTS sand_ai_tool_connection_revision_key_check;
ALTER TABLE sand_ai_tool_connection_revision
    ADD CONSTRAINT sand_ai_tool_connection_revision_envelope_ck_v023 CHECK (
        jsonb_typeof(encrypted_config) = 'object' AND
        encryption_key_id ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
        AND (
            (encrypted_config->>'version' = 'v1' AND encryption_key_id = 'legacy_config_v1' AND encrypted_config ?& ARRAY['iv','tag','ciphertext'])
            OR (encrypted_config->>'version' = 'v2' AND encrypted_config->>'cipher' = 'aes-256-gcm' AND encrypted_config->>'key_id' = encryption_key_id AND encrypted_config ?& ARRAY['nonce','tag','ciphertext'])
        )
    );

ALTER TABLE sand_ai_tool_run
    ADD CONSTRAINT sand_ai_tool_run_receipt_identity_uniq
    UNIQUE (id, environment_id, tool_id, tool_version_id, request_fingerprint);
ALTER TABLE sand_ai_tool_outcome_receipt
    ADD CONSTRAINT sand_ai_tool_receipt_run_identity_fk
    FOREIGN KEY (tool_run_id, environment_id, tool_id, tool_version_id, request_fingerprint)
    REFERENCES sand_ai_tool_run (id, environment_id, tool_id, tool_version_id, request_fingerprint)
    DEFERRABLE INITIALLY DEFERRED;

DO $$ DECLARE item record; BEGIN FOR item IN SELECT t.tgname FROM pg_trigger t JOIN pg_proc p ON p.oid=t.tgfoid WHERE t.tgrelid='sand_ai_tool_environment_binding'::regclass AND NOT t.tgisinternal AND (pg_get_functiondef(p.oid) ILIKE '%active_version_id%' OR pg_get_functiondef(p.oid) ILIKE '%active_revision_id%') LOOP EXECUTE format('DROP TRIGGER %I ON sand_ai_tool_environment_binding', item.tgname); END LOOP; END $$;

CREATE OR REPLACE FUNCTION sand_ai_tool_binding_published_guard_v023() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NOT EXISTS (SELECT 1 FROM sand_ai_tool t JOIN sand_ai_tool_version v ON v.tool_id=t.id AND v.id=NEW.tool_version_id JOIN sand_ai_tool_connection c ON c.id=NEW.tool_connection_id AND c.environment_id=NEW.environment_id JOIN sand_ai_tool_connection_revision r ON r.tool_connection_id=c.id AND r.id=NEW.tool_connection_revision_id WHERE t.id=NEW.tool_id AND t.state='published' AND t.status=1 AND v.state='published' AND c.state='published' AND c.status=1 AND r.state='published') THEN RAISE EXCEPTION 'tool binding requires exact published resources' USING ERRCODE='23514'; END IF; RETURN NEW; END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_tool_binding_published_guard_v023 ON sand_ai_tool_environment_binding;
CREATE CONSTRAINT TRIGGER trg_sand_ai_tool_binding_published_guard_v023 AFTER INSERT OR UPDATE ON sand_ai_tool_environment_binding DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_binding_published_guard_v023();

CREATE OR REPLACE FUNCTION sand_ai_tool_run_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP='INSERT' AND NOT EXISTS (
        SELECT 1 FROM sand_ai_tool_environment_binding b
        WHERE b.id=NEW.tool_environment_binding_id AND b.environment_id=NEW.environment_id AND b.tool_id=NEW.tool_id
          AND b.tool_version_id=NEW.tool_version_id AND b.tool_connection_id=NEW.tool_connection_id
          AND b.tool_connection_revision_id=NEW.tool_connection_revision_id AND b.state='published' AND b.status=1
    ) THEN
        RAISE EXCEPTION 'SAND_AI_TOOL_BINDING_INVALID';
    END IF;
    IF TG_OP='UPDATE' AND (OLD.environment_id,OLD.organization_id,OLD.application_id,OLD.workload_client_id,OLD.tool_id,OLD.tool_version_id,OLD.tool_environment_binding_id,OLD.tool_connection_id,OLD.tool_connection_revision_id,OLD.request_id,OLD.request_payload_fingerprint,OLD.request_fingerprint,OLD.business_scope,OLD.input,OLD.execution_snapshot,OLD.timeout_ms,OLD.identity_snapshot)
        IS DISTINCT FROM (NEW.environment_id,NEW.organization_id,NEW.application_id,NEW.workload_client_id,NEW.tool_id,NEW.tool_version_id,NEW.tool_environment_binding_id,NEW.tool_connection_id,NEW.tool_connection_revision_id,NEW.request_id,NEW.request_payload_fingerprint,NEW.request_fingerprint,NEW.business_scope,NEW.input,NEW.execution_snapshot,NEW.timeout_ms,NEW.identity_snapshot) THEN
        RAISE EXCEPTION 'SAND_AI_TOOL_RUN_IMMUTABLE';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

ALTER TABLE sand_ai_tool_run DROP CONSTRAINT IF EXISTS sand_ai_tool_run_running_lease_ck_v023;
ALTER TABLE sand_ai_tool_run
    ADD CONSTRAINT sand_ai_tool_run_running_lease_ck_v023
    CHECK (state <> 'running' OR lease_expires_at IS NOT NULL) NOT VALID;

ALTER TABLE sand_ai_tool_run DROP CONSTRAINT IF EXISTS sand_ai_tool_run_running_payload_ck_v023;
ALTER TABLE sand_ai_tool_run
    ADD CONSTRAINT sand_ai_tool_run_running_payload_ck_v023
    CHECK (state <> 'running' OR (
        result IS NULL AND citations IS NULL AND review_required = false AND metering IS NULL
        AND error_code IS NULL AND error_detail IS NULL AND audit_log_id IS NULL AND completed_time IS NULL
    )) NOT VALID;

CREATE OR REPLACE FUNCTION sand_ai_tool_run_terminal_immutable_v023() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'tool run records are append-only' USING ERRCODE='55000'; END IF; IF OLD.state IN ('succeeded','failed','ambiguous') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'terminal tool run evidence is immutable' USING ERRCODE='55000'; END IF; RETURN NEW; END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_tool_run_terminal_immutable_v023 ON sand_ai_tool_run;
CREATE TRIGGER trg_sand_ai_tool_run_terminal_immutable_v023 BEFORE UPDATE ON sand_ai_tool_run FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_run_terminal_immutable_v023();
DROP TRIGGER IF EXISTS trg_sand_ai_tool_run_delete_guard_v023 ON sand_ai_tool_run;
CREATE TRIGGER trg_sand_ai_tool_run_delete_guard_v023 BEFORE DELETE ON sand_ai_tool_run FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_run_terminal_immutable_v023();

CREATE OR REPLACE FUNCTION sand_ai_tool_attempt_terminal_immutable_v023() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'tool attempt records are append-only' USING ERRCODE='55000';
    END IF;
    IF ROW(OLD.tool_run_id,OLD.attempt_no,OLD.dispatch_fingerprint) IS DISTINCT FROM ROW(NEW.tool_run_id,NEW.attempt_no,NEW.dispatch_fingerprint) THEN
        RAISE EXCEPTION 'tool attempt identity is immutable' USING ERRCODE='55000';
    END IF;
    IF OLD.state IN ('succeeded','failed','ambiguous','cancelled') THEN
        IF NEW IS DISTINCT FROM OLD THEN
            RAISE EXCEPTION 'terminal tool attempt evidence is immutable' USING ERRCODE='55000';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.state = 'prepared' AND NEW.state = 'dispatched' THEN
        IF NEW.dispatched_time IS NULL OR NEW.http_status IS NOT NULL OR NEW.error_code IS NOT NULL OR NEW.completed_time IS NOT NULL THEN
            RAISE EXCEPTION 'prepared tool attempt dispatch transition is invalid' USING ERRCODE='55000';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.state = 'prepared' AND NEW.state = 'cancelled' THEN
        IF NEW.dispatched_time IS NOT NULL OR NEW.http_status IS NOT NULL OR NULLIF(btrim(NEW.error_code),'') IS NULL OR NEW.completed_time IS NULL THEN
            RAISE EXCEPTION 'prepared tool attempt cancellation is invalid' USING ERRCODE='55000';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.state = 'dispatched' AND NEW.state IN ('succeeded','failed','ambiguous') THEN
        IF OLD.dispatched_time IS NULL OR NEW.dispatched_time IS DISTINCT FROM OLD.dispatched_time OR NEW.completed_time IS NULL
            OR (NEW.state = 'succeeded' AND (NEW.error_code IS NOT NULL OR NEW.http_status IS NULL OR NEW.http_status < 200 OR NEW.http_status >= 300))
            OR (NEW.state IN ('failed','ambiguous') AND NULLIF(btrim(NEW.error_code),'') IS NULL) THEN
            RAISE EXCEPTION 'dispatched tool attempt terminal transition is invalid' USING ERRCODE='55000';
        END IF;
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'tool attempt state transition is invalid' USING ERRCODE='55000';
END;
$$;
DROP TRIGGER IF EXISTS trg_sand_ai_tool_attempt_terminal_immutable_v023 ON sand_ai_tool_attempt;
CREATE TRIGGER trg_sand_ai_tool_attempt_terminal_immutable_v023 BEFORE UPDATE ON sand_ai_tool_attempt FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_attempt_terminal_immutable_v023();
DROP TRIGGER IF EXISTS trg_sand_ai_tool_attempt_delete_guard_v023 ON sand_ai_tool_attempt;
CREATE TRIGGER trg_sand_ai_tool_attempt_delete_guard_v023 BEFORE DELETE ON sand_ai_tool_attempt FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_attempt_terminal_immutable_v023();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608280023_harden_standard_readonly_tool_runtime', '22fef72817a282479302da1b5e20ed2bce4ad3a5fb815b49afd135b5c880719f', CURRENT_TIMESTAMP(0));

-- Migration 202608280024_add_mcp_streamable_http_tool (sha256 681bf6b7fb769168970d9176e7f8878d023a17bdebd9103cd96fb883edd3aea4)
-- SAND-135B: stable MCP 2025-11-25 discovery snapshots and per-request metering.

ALTER TABLE sand_ai_tool DROP CONSTRAINT IF EXISTS ck_sand_ai_tool_adapter;
ALTER TABLE sand_ai_tool
    ADD CONSTRAINT ck_sand_ai_tool_adapter
    CHECK (adapter IN ('standard_readonly_http', 'mcp_streamable_http'));

CREATE TABLE IF NOT EXISTS sand_ai_tool_mcp_snapshot (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    tool_connection_id bigint NOT NULL REFERENCES sand_ai_tool_connection(id),
    tool_connection_revision_id bigint NOT NULL REFERENCES sand_ai_tool_connection_revision(id),
    request_id varchar(128) NOT NULL,
    request_fingerprint char(64) NOT NULL,
    protocol_version varchar(16) NOT NULL DEFAULT '2025-11-25',
    server_name varchar(128),
    server_version varchar(128),
    snapshot_hash char(64),
    item_count integer NOT NULL DEFAULT 0,
    request_count integer NOT NULL DEFAULT 0,
    metering jsonb,
    state varchar(16) NOT NULL DEFAULT 'discovering',
    error_code varchar(64),
    error_detail jsonb,
    lease_token varchar(96) NOT NULL,
    lease_fence integer NOT NULL DEFAULT 1,
    lease_expires_at timestamp(0) without time zone,
    completed_time timestamp(0) without time zone,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_mcp_snapshot_request UNIQUE (environment_id, request_id),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_fingerprint CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_protocol CHECK (protocol_version = '2025-11-25'),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_count CHECK (item_count >= 0 AND request_count >= 0),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_lease CHECK (lease_token <> '' AND lease_fence > 0),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_state CHECK (state IN ('discovering', 'tested', 'failed', 'ambiguous')),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_terminal CHECK (
        (state = 'discovering' AND completed_time IS NULL AND snapshot_hash IS NULL AND error_code IS NULL AND lease_expires_at IS NOT NULL)
        OR (state = 'tested' AND completed_time IS NOT NULL AND snapshot_hash ~ '^[0-9a-f]{64}$' AND item_count > 0 AND error_code IS NULL AND lease_expires_at IS NULL)
        OR (state IN ('failed', 'ambiguous') AND completed_time IS NOT NULL AND snapshot_hash IS NULL AND item_count = 0 AND error_code IS NOT NULL AND lease_expires_at IS NULL)
    )
);

ALTER TABLE sand_ai_tool_mcp_snapshot
    DROP CONSTRAINT IF EXISTS fk_sand_ai_tool_mcp_snapshot_connection_environment;
ALTER TABLE sand_ai_tool_mcp_snapshot
    DROP CONSTRAINT IF EXISTS fk_sand_ai_tool_mcp_snapshot_revision_connection;
ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT IF EXISTS fk_sand_ai_usage_event_mcp_snapshot_attribution;
ALTER TABLE sand_ai_tool_mcp_snapshot
    DROP CONSTRAINT IF EXISTS uk_sand_ai_tool_mcp_snapshot_attribution;
ALTER TABLE sand_ai_tool_connection
    DROP CONSTRAINT IF EXISTS uk_sand_ai_tool_connection_id_environment;
ALTER TABLE sand_ai_tool_connection_revision
    DROP CONSTRAINT IF EXISTS uk_sand_ai_tool_connection_revision_id_connection;
ALTER TABLE sand_ai_tool_connection
    ADD CONSTRAINT uk_sand_ai_tool_connection_id_environment
    UNIQUE (id, environment_id);
ALTER TABLE sand_ai_tool_connection_revision
    ADD CONSTRAINT uk_sand_ai_tool_connection_revision_id_connection
    UNIQUE (id, tool_connection_id);
ALTER TABLE sand_ai_tool_mcp_snapshot
    ADD CONSTRAINT fk_sand_ai_tool_mcp_snapshot_connection_environment
    FOREIGN KEY (tool_connection_id, environment_id)
    REFERENCES sand_ai_tool_connection (id, environment_id);
ALTER TABLE sand_ai_tool_mcp_snapshot
    ADD CONSTRAINT fk_sand_ai_tool_mcp_snapshot_revision_connection
    FOREIGN KEY (tool_connection_revision_id, tool_connection_id)
    REFERENCES sand_ai_tool_connection_revision (id, tool_connection_id);
ALTER TABLE sand_ai_tool_mcp_snapshot
    ADD CONSTRAINT uk_sand_ai_tool_mcp_snapshot_attribution
    UNIQUE (id, environment_id, tool_connection_id, tool_connection_revision_id);

CREATE INDEX IF NOT EXISTS idx_sand_ai_tool_mcp_snapshot_connection
    ON sand_ai_tool_mcp_snapshot (environment_id, tool_connection_id, tool_connection_revision_id, state);

CREATE TABLE IF NOT EXISTS sand_ai_tool_mcp_snapshot_item (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    mcp_snapshot_id bigint NOT NULL REFERENCES sand_ai_tool_mcp_snapshot(id),
    sequence_no integer NOT NULL,
    local_tool_code varchar(64) NOT NULL,
    remote_name varchar(128) NOT NULL,
    remote_description text NOT NULL DEFAULT '',
    remote_input_schema jsonb NOT NULL,
    remote_output_schema jsonb,
    input_schema jsonb NOT NULL,
    output_schema jsonb NOT NULL,
    citation_mode varchar(32) NOT NULL DEFAULT 'sand_ai_standard',
    registry_definition_hash char(64) NOT NULL,
    item_hash char(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_mcp_snapshot_item_sequence UNIQUE (mcp_snapshot_id, sequence_no),
    CONSTRAINT uk_sand_ai_tool_mcp_snapshot_item_code UNIQUE (mcp_snapshot_id, local_tool_code),
    CONSTRAINT uk_sand_ai_tool_mcp_snapshot_item_remote UNIQUE (mcp_snapshot_id, remote_name),
    CONSTRAINT uk_sand_ai_tool_mcp_snapshot_item_id_snapshot UNIQUE (id, mcp_snapshot_id),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_item_sequence CHECK (sequence_no >= 1),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_item_code CHECK (local_tool_code ~ '^[a-z0-9][a-z0-9_-]{1,63}$'),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_item_remote CHECK (remote_name ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_item_hashes CHECK (registry_definition_hash ~ '^[0-9a-f]{64}$' AND item_hash ~ '^[0-9a-f]{64}$'),
    CONSTRAINT ck_sand_ai_tool_mcp_snapshot_item_citation CHECK (citation_mode = 'sand_ai_standard')
);

ALTER TABLE sand_ai_tool_version
    ADD COLUMN IF NOT EXISTS mcp_snapshot_item_id bigint REFERENCES sand_ai_tool_mcp_snapshot_item(id);
ALTER TABLE sand_ai_tool_run
    ADD COLUMN IF NOT EXISTS mcp_snapshot_item_id bigint REFERENCES sand_ai_tool_mcp_snapshot_item(id);

CREATE TABLE IF NOT EXISTS sand_ai_tool_protocol_request (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    tool_run_id bigint REFERENCES sand_ai_tool_run(id),
    mcp_snapshot_id bigint REFERENCES sand_ai_tool_mcp_snapshot(id),
    sequence_no integer NOT NULL,
    request_kind varchar(32) NOT NULL,
    event_kind varchar(32) NOT NULL,
    http_status integer,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_ai_tool_protocol_request_owner CHECK ((tool_run_id IS NULL) <> (mcp_snapshot_id IS NULL)),
    CONSTRAINT ck_sand_ai_tool_protocol_request_owner_kind CHECK (
        (mcp_snapshot_id IS NOT NULL AND request_kind IN ('initialize', 'initialized', 'tools_list'))
        OR (tool_run_id IS NOT NULL AND request_kind IN ('initialize', 'initialized', 'tools_call'))
    ),
    CONSTRAINT ck_sand_ai_tool_protocol_request_sequence CHECK (sequence_no >= 1),
    CONSTRAINT ck_sand_ai_tool_protocol_request_kind CHECK (request_kind IN ('initialize', 'initialized', 'tools_list', 'tools_call')),
    CONSTRAINT ck_sand_ai_tool_protocol_request_event CHECK (
        (event_kind = 'dispatch_started' AND http_status IS NULL)
        OR (event_kind = 'response_known' AND http_status BETWEEN 100 AND 599)
    )
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_tool_protocol_request_run_sequence
    ON sand_ai_tool_protocol_request (tool_run_id, sequence_no, event_kind) WHERE tool_run_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_tool_protocol_request_snapshot_sequence
    ON sand_ai_tool_protocol_request (mcp_snapshot_id, sequence_no, event_kind) WHERE mcp_snapshot_id IS NOT NULL;

ALTER TABLE sand_ai_usage_event
    ADD COLUMN IF NOT EXISTS mcp_snapshot_id bigint NULL REFERENCES sand_ai_tool_mcp_snapshot(id);
ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT IF EXISTS fk_sand_ai_usage_event_mcp_snapshot_attribution;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT fk_sand_ai_usage_event_mcp_snapshot_attribution
    FOREIGN KEY (mcp_snapshot_id, environment_id, tool_connection_id, tool_connection_revision_id)
    REFERENCES sand_ai_tool_mcp_snapshot (id, environment_id, tool_connection_id, tool_connection_revision_id);
ALTER TABLE sand_ai_usage_event DROP CONSTRAINT IF EXISTS ck_sand_ai_usage_event_tool_metering;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT ck_sand_ai_usage_event_tool_metering CHECK ((
        (tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND metering_unit IS NULL AND metering_quantity IS NULL)
        OR (tool_run_id IS NOT NULL AND mcp_snapshot_id IS NULL AND tool_id IS NOT NULL AND tool_version_id IS NOT NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL
            AND invocation_id IS NULL AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL
            AND metering_unit = 'external_request' AND metering_quantity BETWEEN 1 AND 1024
            AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string'
            AND btrim(deployment_snapshot->>'adapter') <> ''
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string'
            AND btrim(deployment_snapshot->>'operation') <> '' AND deployment_snapshot->>'operation' = 'runtime'
            AND ((deployment_snapshot->>'adapter' = 'standard_readonly_http' AND metering_quantity = 1)
                OR (deployment_snapshot->>'adapter' = 'mcp_streamable_http' AND metering_quantity BETWEEN 1 AND 1024))
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL
            AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (tool_run_id IS NULL AND mcp_snapshot_id IS NOT NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL
            AND invocation_id IS NULL AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL
            AND metering_unit = 'external_request' AND metering_quantity BETWEEN 1 AND 1024
            AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string'
            AND btrim(deployment_snapshot->>'adapter') <> '' AND deployment_snapshot->>'adapter' = 'mcp_streamable_http'
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string'
            AND btrim(deployment_snapshot->>'operation') <> '' AND deployment_snapshot->>'operation' = 'discovery'
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL
            AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
    ) IS TRUE) NOT VALID;
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_usage_event_mcp_snapshot
    ON sand_ai_usage_event(mcp_snapshot_id) WHERE mcp_snapshot_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS sand_ai_tool_usage_seal (
    owner_kind varchar(16) NOT NULL,
    owner_id bigint NOT NULL,
    dispatch_count integer NOT NULL DEFAULT 0,
    usage_quantity integer,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (owner_kind, owner_id),
    CONSTRAINT ck_sand_ai_tool_usage_seal_owner CHECK (owner_kind IN ('runtime', 'discovery') AND owner_id > 0),
    CONSTRAINT ck_sand_ai_tool_usage_seal_quantity CHECK (
        dispatch_count BETWEEN 0 AND 1024
        AND (usage_quantity IS NULL OR (usage_quantity BETWEEN 1 AND 1024 AND usage_quantity = dispatch_count))
    )
);

INSERT INTO sand_ai_tool_usage_seal(owner_kind, owner_id, dispatch_count)
SELECT CASE WHEN tool_run_id IS NOT NULL THEN 'runtime' ELSE 'discovery' END,
       COALESCE(tool_run_id, mcp_snapshot_id), count(*)::integer
  FROM sand_ai_tool_protocol_request
 WHERE event_kind = 'dispatch_started'
 GROUP BY CASE WHEN tool_run_id IS NOT NULL THEN 'runtime' ELSE 'discovery' END,
          COALESCE(tool_run_id, mcp_snapshot_id)
ON CONFLICT (owner_kind, owner_id) DO UPDATE
SET dispatch_count = EXCLUDED.dispatch_count, update_time = CURRENT_TIMESTAMP
WHERE sand_ai_tool_usage_seal.usage_quantity IS NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
          FROM sand_ai_usage_event usage
         WHERE usage.deployment_snapshot->>'adapter' = 'mcp_streamable_http'
           AND usage.deployment_snapshot->>'operation' IN ('runtime', 'discovery')
           AND usage.metering_quantity IS DISTINCT FROM (
               SELECT count(*)
                 FROM sand_ai_tool_protocol_request request
                WHERE request.event_kind = 'dispatch_started'
                  AND ((usage.tool_run_id IS NOT NULL AND request.tool_run_id = usage.tool_run_id)
                    OR (usage.mcp_snapshot_id IS NOT NULL AND request.mcp_snapshot_id = usage.mcp_snapshot_id))
           )
    ) THEN
        RAISE EXCEPTION 'existing MCP Usage quantity does not match immutable dispatch evidence';
    END IF;
END;
$$;

INSERT INTO sand_ai_tool_usage_seal(owner_kind, owner_id, dispatch_count, usage_quantity)
SELECT CASE WHEN tool_run_id IS NOT NULL THEN 'runtime' ELSE 'discovery' END,
       COALESCE(tool_run_id, mcp_snapshot_id), metering_quantity, metering_quantity
  FROM sand_ai_usage_event
 WHERE deployment_snapshot->>'adapter' = 'mcp_streamable_http'
   AND deployment_snapshot->>'operation' IN ('runtime', 'discovery')
ON CONFLICT (owner_kind, owner_id) DO UPDATE
SET usage_quantity = EXCLUDED.usage_quantity, update_time = CURRENT_TIMESTAMP
WHERE sand_ai_tool_usage_seal.usage_quantity IS NULL
  AND sand_ai_tool_usage_seal.dispatch_count = EXCLUDED.dispatch_count;

CREATE OR REPLACE FUNCTION sand_ai_tool_usage_protocol_quantity_guard() RETURNS trigger AS $$
DECLARE
    adapter_value text;
    operation_value text;
    sealed_quantity integer;
    owner_kind_value varchar(16);
    owner_id_value bigint;
BEGIN
    IF NEW.tool_run_id IS NULL AND NEW.mcp_snapshot_id IS NULL THEN
        RETURN NEW;
    END IF;
    adapter_value := NEW.deployment_snapshot->>'adapter';
    operation_value := NEW.deployment_snapshot->>'operation';
    IF NEW.tool_run_id IS NOT NULL AND adapter_value = 'mcp_streamable_http' AND operation_value = 'runtime' THEN
        owner_kind_value := 'runtime';
        owner_id_value := NEW.tool_run_id;
    ELSIF NEW.mcp_snapshot_id IS NOT NULL AND adapter_value = 'mcp_streamable_http' AND operation_value = 'discovery' THEN
        owner_kind_value := 'discovery';
        owner_id_value := NEW.mcp_snapshot_id;
    ELSE
        RETURN NEW;
    END IF;
    INSERT INTO sand_ai_tool_usage_seal(owner_kind, owner_id)
    VALUES(owner_kind_value, owner_id_value)
    ON CONFLICT (owner_kind, owner_id) DO NOTHING;
    UPDATE sand_ai_tool_usage_seal
       SET usage_quantity = NEW.metering_quantity, update_time = CURRENT_TIMESTAMP
     WHERE owner_kind = owner_kind_value AND owner_id = owner_id_value
       AND dispatch_count = NEW.metering_quantity
       AND (usage_quantity IS NULL OR usage_quantity = NEW.metering_quantity)
    RETURNING usage_quantity INTO sealed_quantity;
    IF sealed_quantity IS NULL THEN
        RAISE EXCEPTION 'MCP Usage quantity must atomically seal immutable dispatch evidence';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_tool_usage_protocol_quantity_guard ON sand_ai_usage_event;
CREATE TRIGGER trg_sand_ai_tool_usage_protocol_quantity_guard
    BEFORE INSERT OR UPDATE OF tool_run_id, mcp_snapshot_id, deployment_snapshot, metering_quantity
    ON sand_ai_usage_event
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_usage_protocol_quantity_guard();

CREATE OR REPLACE FUNCTION sand_ai_tool_mcp_snapshot_terminal_guard() RETURNS trigger AS $$
DECLARE
    actual_item_count bigint;
    actual_request_count bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'MCP discovery snapshot is append-only';
    END IF;
    IF OLD.state IN ('tested', 'failed', 'ambiguous') AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'terminal MCP discovery snapshot is immutable';
    END IF;
    IF OLD.state = 'discovering' AND NEW.state NOT IN ('discovering', 'tested', 'failed', 'ambiguous') THEN
        RAISE EXCEPTION 'invalid MCP discovery snapshot transition';
    END IF;
    IF OLD.state = 'discovering' AND NEW.state IN ('tested', 'failed', 'ambiguous') THEN
        SELECT count(*) INTO actual_item_count
          FROM sand_ai_tool_mcp_snapshot_item WHERE mcp_snapshot_id = NEW.id;
        SELECT count(*) INTO actual_request_count
          FROM sand_ai_tool_protocol_request
         WHERE mcp_snapshot_id = NEW.id AND event_kind = 'dispatch_started';
        IF NEW.item_count IS DISTINCT FROM actual_item_count
            OR NEW.request_count IS DISTINCT FROM actual_request_count THEN
            RAISE EXCEPTION 'terminal MCP discovery snapshot counts do not match immutable evidence';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_tool_mcp_snapshot_terminal_guard ON sand_ai_tool_mcp_snapshot;
CREATE TRIGGER trg_sand_ai_tool_mcp_snapshot_terminal_guard
    BEFORE UPDATE OR DELETE ON sand_ai_tool_mcp_snapshot
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_mcp_snapshot_terminal_guard();

CREATE OR REPLACE FUNCTION sand_ai_tool_mcp_immutable_guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'MCP snapshot item and protocol request rows are immutable';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_tool_mcp_snapshot_item_immutable ON sand_ai_tool_mcp_snapshot_item;
CREATE TRIGGER trg_sand_ai_tool_mcp_snapshot_item_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_tool_mcp_snapshot_item
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_mcp_immutable_guard();

CREATE OR REPLACE FUNCTION sand_ai_tool_mcp_snapshot_item_insert_guard() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sand_ai_tool_mcp_snapshot WHERE id = NEW.mcp_snapshot_id AND state = 'discovering') THEN
        RAISE EXCEPTION 'MCP snapshot items may only be added while discovery is active';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_tool_mcp_snapshot_item_insert_guard ON sand_ai_tool_mcp_snapshot_item;
CREATE TRIGGER trg_sand_ai_tool_mcp_snapshot_item_insert_guard
    BEFORE INSERT ON sand_ai_tool_mcp_snapshot_item
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_mcp_snapshot_item_insert_guard();

DROP TRIGGER IF EXISTS trg_sand_ai_tool_protocol_request_immutable ON sand_ai_tool_protocol_request;
CREATE TRIGGER trg_sand_ai_tool_protocol_request_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_tool_protocol_request
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_mcp_immutable_guard();

CREATE OR REPLACE FUNCTION sand_ai_tool_protocol_request_insert_guard() RETURNS trigger AS $$
DECLARE
    dispatched_kind varchar(32);
    sealed_dispatch_count integer;
    owner_kind_value varchar(16);
    owner_id_value bigint;
BEGIN
    IF NEW.tool_run_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM sand_ai_tool_run WHERE id = NEW.tool_run_id AND state = 'running'
    ) THEN
        RAISE EXCEPTION 'MCP protocol events require an active Tool run';
    END IF;
    IF NEW.mcp_snapshot_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM sand_ai_tool_mcp_snapshot WHERE id = NEW.mcp_snapshot_id AND state = 'discovering'
    ) THEN
        RAISE EXCEPTION 'MCP protocol events require an active discovery snapshot';
    END IF;
    IF NEW.event_kind = 'dispatch_started' THEN
        owner_kind_value := CASE WHEN NEW.tool_run_id IS NOT NULL THEN 'runtime' ELSE 'discovery' END;
        owner_id_value := COALESCE(NEW.tool_run_id, NEW.mcp_snapshot_id);
        INSERT INTO sand_ai_tool_usage_seal(owner_kind, owner_id)
        VALUES(owner_kind_value, owner_id_value)
        ON CONFLICT (owner_kind, owner_id) DO NOTHING;
        UPDATE sand_ai_tool_usage_seal
           SET dispatch_count = dispatch_count + 1, update_time = CURRENT_TIMESTAMP
         WHERE owner_kind = owner_kind_value AND owner_id = owner_id_value AND usage_quantity IS NULL
        RETURNING dispatch_count INTO sealed_dispatch_count;
        IF sealed_dispatch_count IS NULL THEN
            RAISE EXCEPTION 'MCP dispatch evidence cannot be appended after Usage is sealed';
        END IF;
    END IF;
    IF NEW.event_kind = 'response_known' THEN
        SELECT request_kind INTO dispatched_kind
          FROM sand_ai_tool_protocol_request
         WHERE tool_run_id IS NOT DISTINCT FROM NEW.tool_run_id
           AND mcp_snapshot_id IS NOT DISTINCT FROM NEW.mcp_snapshot_id
           AND sequence_no = NEW.sequence_no
           AND event_kind = 'dispatch_started';
        IF dispatched_kind IS NULL OR dispatched_kind <> NEW.request_kind THEN
            RAISE EXCEPTION 'MCP response event requires its exact dispatch event';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_tool_protocol_request_insert_guard ON sand_ai_tool_protocol_request;
CREATE TRIGGER trg_sand_ai_tool_protocol_request_insert_guard
    BEFORE INSERT ON sand_ai_tool_protocol_request
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_protocol_request_insert_guard();

CREATE OR REPLACE FUNCTION sand_ai_tool_mcp_version_guard() RETURNS trigger AS $$
DECLARE
    adapter_value varchar(64);
    tool_code_value varchar(64);
    snapshot_state_value varchar(16);
    snapshot_code_value varchar(64);
BEGIN
    SELECT adapter, code INTO adapter_value, tool_code_value FROM sand_ai_tool WHERE id = NEW.tool_id;
    IF adapter_value = 'mcp_streamable_http' THEN
        IF NEW.mcp_snapshot_item_id IS NULL THEN
            RAISE EXCEPTION 'MCP tool version requires an exact snapshot item';
        END IF;
        SELECT s.state, i.local_tool_code INTO snapshot_state_value, snapshot_code_value
          FROM sand_ai_tool_mcp_snapshot_item i
          JOIN sand_ai_tool_mcp_snapshot s ON s.id = i.mcp_snapshot_id
         WHERE i.id = NEW.mcp_snapshot_item_id;
        IF snapshot_state_value <> 'tested' OR snapshot_code_value <> tool_code_value THEN
            RAISE EXCEPTION 'MCP tool version snapshot item is not a tested exact match';
        END IF;
    ELSIF NEW.mcp_snapshot_item_id IS NOT NULL THEN
        RAISE EXCEPTION 'standard HTTP tool version cannot bind an MCP snapshot item';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_tool_mcp_version_guard ON sand_ai_tool_version;
CREATE TRIGGER trg_sand_ai_tool_mcp_version_guard
    BEFORE INSERT OR UPDATE OF tool_id, mcp_snapshot_item_id ON sand_ai_tool_version
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_mcp_version_guard();

CREATE OR REPLACE FUNCTION sand_ai_tool_mcp_binding_guard() RETURNS trigger AS $$
DECLARE
    snapshot_environment_id bigint;
    snapshot_connection_id bigint;
    snapshot_revision_id bigint;
BEGIN
    SELECT s.environment_id, s.tool_connection_id, s.tool_connection_revision_id
      INTO snapshot_environment_id, snapshot_connection_id, snapshot_revision_id
      FROM sand_ai_tool_version v
      JOIN sand_ai_tool_mcp_snapshot_item i ON i.id = v.mcp_snapshot_item_id
      JOIN sand_ai_tool_mcp_snapshot s ON s.id = i.mcp_snapshot_id AND s.state = 'tested'
     WHERE v.id = NEW.tool_version_id;
    IF snapshot_environment_id IS NOT NULL AND (
        snapshot_environment_id <> NEW.environment_id OR snapshot_connection_id <> NEW.tool_connection_id
        OR snapshot_revision_id <> NEW.tool_connection_revision_id
    ) THEN
        RAISE EXCEPTION 'MCP binding must use the snapshot environment and exact connection revision';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_tool_mcp_binding_guard ON sand_ai_tool_environment_binding;
CREATE TRIGGER trg_sand_ai_tool_mcp_binding_guard
    BEFORE INSERT OR UPDATE OF environment_id, tool_version_id, tool_connection_id, tool_connection_revision_id
    ON sand_ai_tool_environment_binding
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_mcp_binding_guard();

CREATE OR REPLACE FUNCTION sand_ai_tool_mcp_run_guard() RETURNS trigger AS $$
DECLARE
    version_snapshot_item_id bigint;
    snapshot_environment_id bigint;
    snapshot_connection_id bigint;
    snapshot_revision_id bigint;
BEGIN
    SELECT v.mcp_snapshot_item_id, s.environment_id, s.tool_connection_id, s.tool_connection_revision_id
      INTO version_snapshot_item_id, snapshot_environment_id, snapshot_connection_id, snapshot_revision_id
      FROM sand_ai_tool_version v
      LEFT JOIN sand_ai_tool_mcp_snapshot_item i ON i.id = v.mcp_snapshot_item_id
      LEFT JOIN sand_ai_tool_mcp_snapshot s ON s.id = i.mcp_snapshot_id
     WHERE v.id = NEW.tool_version_id;
    IF version_snapshot_item_id IS DISTINCT FROM NEW.mcp_snapshot_item_id THEN
        RAISE EXCEPTION 'Tool run MCP snapshot item must equal the frozen version item';
    END IF;
    IF version_snapshot_item_id IS NOT NULL AND (
        snapshot_environment_id <> NEW.environment_id OR snapshot_connection_id <> NEW.tool_connection_id
        OR snapshot_revision_id <> NEW.tool_connection_revision_id
    ) THEN
        RAISE EXCEPTION 'MCP Tool run must use the snapshot environment and exact connection revision';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sand_ai_tool_mcp_run_guard ON sand_ai_tool_run;
CREATE TRIGGER trg_sand_ai_tool_mcp_run_guard
    BEFORE INSERT OR UPDATE OF environment_id, tool_version_id, tool_connection_id, tool_connection_revision_id, mcp_snapshot_item_id ON sand_ai_tool_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_mcp_run_guard();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608280024_add_mcp_streamable_http_tool', '681bf6b7fb769168970d9176e7f8878d023a17bdebd9103cd96fb883edd3aea4', CURRENT_TIMESTAMP(0));

-- Migration 202608300025_validate_skill_environment_attribution (sha256 b83a3ba5793a88bc6f55200a1ffb059cae05641ca850e67351cb825d42d2b547)
-- SAND-139: close the Skill environment identity gap without changing the
-- historical SAND-134A migrations or their checksums.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_skill_run run
        LEFT JOIN sand_ai_skill skill ON skill.id = run.skill_id
        LEFT JOIN sand_ai_skill_version version ON version.id = run.skill_version_id AND version.skill_id = run.skill_id
        WHERE skill.id IS NULL OR version.id IS NULL OR run.environment_id <> skill.environment_id
    ) THEN
        RAISE EXCEPTION 'SAND_AI_SKILL_RUN_ENVIRONMENT_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_skill_run run
        LEFT JOIN sand_ai_invocation invocation ON invocation.id = run.invocation_id
        WHERE run.invocation_id IS NOT NULL
            AND (invocation.id IS NULL
                OR invocation.environment_id IS DISTINCT FROM run.environment_id
                OR invocation.skill_id IS DISTINCT FROM run.skill_id
                OR invocation.skill_version_id IS DISTINCT FROM run.skill_version_id
                OR invocation.skill_run_id IS DISTINCT FROM run.id
                OR invocation.model_id IS DISTINCT FROM run.model_id)
    ) THEN
        RAISE EXCEPTION 'SAND_AI_SKILL_RUN_INVOCATION_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_invocation invocation
        LEFT JOIN sand_ai_skill_run run ON run.environment_id = invocation.environment_id
            AND run.skill_id = invocation.skill_id AND run.skill_version_id = invocation.skill_version_id AND run.id = invocation.skill_run_id
        WHERE num_nonnulls(invocation.skill_id, invocation.skill_version_id, invocation.skill_run_id) NOT IN (0, 3)
            OR (invocation.skill_id IS NOT NULL AND run.id IS NULL)
    ) THEN
        RAISE EXCEPTION 'SAND_AI_INVOCATION_SKILL_ENVIRONMENT_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_usage_event usage_event
        LEFT JOIN sand_ai_invocation invocation ON invocation.id = usage_event.invocation_id
        WHERE (usage_event.invocation_id IS NOT NULL
                AND (invocation.id IS NULL
                    OR invocation.environment_id IS DISTINCT FROM usage_event.environment_id
                    OR invocation.model_id IS DISTINCT FROM usage_event.model_id
                    OR invocation.deployment_id IS DISTINCT FROM usage_event.deployment_id
                    OR invocation.skill_id IS DISTINCT FROM usage_event.skill_id
                    OR invocation.skill_version_id IS DISTINCT FROM usage_event.skill_version_id
                    OR invocation.skill_run_id IS DISTINCT FROM usage_event.skill_run_id))
            OR (usage_event.invocation_id IS NULL
                AND num_nonnulls(usage_event.skill_id, usage_event.skill_version_id, usage_event.skill_run_id) > 0)
    ) THEN
        RAISE EXCEPTION 'SAND_AI_USAGE_EVENT_INVOCATION_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_usage_event usage_event
        LEFT JOIN sand_ai_skill_run run ON run.environment_id = usage_event.environment_id
            AND run.skill_id = usage_event.skill_id AND run.skill_version_id = usage_event.skill_version_id AND run.id = usage_event.skill_run_id
        WHERE num_nonnulls(usage_event.skill_id, usage_event.skill_version_id, usage_event.skill_run_id) NOT IN (0, 3)
            OR (usage_event.skill_id IS NOT NULL AND run.id IS NULL)
    ) THEN
        RAISE EXCEPTION 'SAND_AI_USAGE_EVENT_SKILL_ENVIRONMENT_MISMATCH';
    END IF;
END $$;

ALTER TABLE sand_ai_skill
    ADD CONSTRAINT uq_sand_ai_skill_id_environment_v025 UNIQUE (id, environment_id);
ALTER TABLE sand_ai_skill_run
    ADD CONSTRAINT fk_sand_ai_skill_run_environment_v025 FOREIGN KEY (skill_id, environment_id)
        REFERENCES sand_ai_skill(id, environment_id) NOT VALID,
    ADD CONSTRAINT fk_sand_ai_skill_run_version_v025 FOREIGN KEY (skill_version_id, skill_id)
        REFERENCES sand_ai_skill_version(id, skill_id) NOT VALID,
    ADD CONSTRAINT uq_sand_ai_skill_run_environment_triple_v025 UNIQUE (environment_id, skill_id, skill_version_id, id);
ALTER TABLE sand_ai_invocation
    ADD CONSTRAINT fk_sand_ai_invocation_skill_environment_triple_v025
        FOREIGN KEY (environment_id, skill_id, skill_version_id, skill_run_id)
        REFERENCES sand_ai_skill_run(environment_id, skill_id, skill_version_id, id) NOT VALID;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT fk_sand_ai_usage_event_skill_environment_triple_v025
        FOREIGN KEY (environment_id, skill_id, skill_version_id, skill_run_id)
        REFERENCES sand_ai_skill_run(environment_id, skill_id, skill_version_id, id) NOT VALID;

ALTER TABLE sand_ai_skill VALIDATE CONSTRAINT ck_sand_ai_skill_state_v019;
ALTER TABLE sand_ai_skill VALIDATE CONSTRAINT ck_sand_ai_skill_published_active_v019;
ALTER TABLE sand_ai_skill_version VALIDATE CONSTRAINT ck_sand_ai_skill_version_snapshot_object_v019;
ALTER TABLE sand_ai_skill_operation VALIDATE CONSTRAINT fk_sand_ai_skill_operation_version_v019;
ALTER TABLE sand_ai_skill_operation VALIDATE CONSTRAINT ck_sand_ai_skill_operation_key_v020;
ALTER TABLE sand_ai_skill_run VALIDATE CONSTRAINT ck_sand_ai_skill_run_kind_v019;
ALTER TABLE sand_ai_skill_run VALIDATE CONSTRAINT fk_sand_ai_skill_run_environment_v025;
ALTER TABLE sand_ai_skill_run VALIDATE CONSTRAINT fk_sand_ai_skill_run_version_v025;
ALTER TABLE sand_ai_invocation VALIDATE CONSTRAINT ck_sand_ai_invocation_skill_triple_v019;
ALTER TABLE sand_ai_invocation VALIDATE CONSTRAINT fk_sand_ai_invocation_skill_triple_v019;
ALTER TABLE sand_ai_invocation VALIDATE CONSTRAINT fk_sand_ai_invocation_skill_environment_triple_v025;
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT ck_sand_ai_usage_event_skill_triple_v019;
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT fk_sand_ai_usage_event_skill_triple_v019;
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT fk_sand_ai_usage_event_skill_environment_triple_v025;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608300025_validate_skill_environment_attribution', 'b83a3ba5793a88bc6f55200a1ffb059cae05641ca850e67351cb825d42d2b547', CURRENT_TIMESTAMP(0));

-- Migration 202608310026_unify_provider_dispatch_scope (sha256 a6eafd11817b87011257dfebbbce25e72153861840b6c5dc7b7f2ba3f188680c)
-- SAND-140. Additive only: retain 008/025 checksums and fail before any
-- constraint is validated when historical ledger ownership is inconsistent.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_invocation_dispatch dispatch
        LEFT JOIN sand_ai_invocation invocation ON invocation.id = dispatch.invocation_id
        WHERE invocation.id IS NULL
            OR invocation.environment_id IS DISTINCT FROM dispatch.environment_id
            OR invocation.model_id IS DISTINCT FROM dispatch.model_id
            OR invocation.deployment_id IS DISTINCT FROM dispatch.deployment_id
    ) THEN
        RAISE EXCEPTION 'SAND_AI_DISPATCH_INVOCATION_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_invocation_dispatch dispatch
        LEFT JOIN sand_ai_task_run task ON task.id = dispatch.parent_task_run_id
        LEFT JOIN sand_ai_task_step step ON step.id = dispatch.parent_task_step_id AND step.task_id = dispatch.parent_task_run_id
        WHERE (dispatch.parent_task_run_id IS NULL) IS DISTINCT FROM (dispatch.parent_task_step_id IS NULL)
           OR (dispatch.parent_task_run_id IS NOT NULL
          AND (task.id IS NULL OR step.id IS NULL
               OR task.environment_id IS DISTINCT FROM dispatch.environment_id)
          )
    ) THEN
        RAISE EXCEPTION 'SAND_AI_DISPATCH_TASK_SCOPE_MISMATCH';
    END IF;
END $$;

ALTER TABLE sand_ai_invocation_dispatch
    ADD COLUMN IF NOT EXISTS execution_scope varchar(32) NOT NULL DEFAULT 'legacy_unverified',
    ADD COLUMN IF NOT EXISTS application_id bigint NULL,
    ADD COLUMN IF NOT EXISTS service_action varchar(96) NULL,
    ADD COLUMN IF NOT EXISTS source_kind varchar(32) NULL,
    ADD COLUMN IF NOT EXISTS skill_id bigint NULL,
    ADD COLUMN IF NOT EXISTS skill_version_id bigint NULL,
    ADD COLUMN IF NOT EXISTS skill_run_id bigint NULL;

ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT uq_sand_ai_task_run_id_environment_v026 UNIQUE (id, environment_id);
ALTER TABLE sand_ai_task_step
    ADD CONSTRAINT uq_sand_ai_task_step_id_task_v026 UNIQUE (id, task_id);

ALTER TABLE sand_ai_invocation_dispatch
    ADD CONSTRAINT ck_sand_ai_invocation_dispatch_scope_v026
    CHECK (execution_scope IN ('legacy_unverified', 'chat', 'ai_task', 'agent', 'structured_workflow', 'skill')
        AND (execution_scope = 'legacy_unverified' OR (
            (execution_scope IN ('ai_task', 'agent', 'structured_workflow')) = (parent_task_run_id IS NOT NULL AND parent_task_step_id IS NOT NULL AND workflow_node_code IS NOT NULL)
            AND (execution_scope IN ('ai_task', 'agent', 'structured_workflow') OR (parent_task_run_id IS NULL AND parent_task_step_id IS NULL AND workflow_node_code IS NULL))
        ))) NOT VALID;

CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_invocation_dispatch_task_scope_attempt_v026
    ON sand_ai_invocation_dispatch (execution_scope, parent_task_run_id, parent_task_step_id, workflow_node_code, attempt_no)
    WHERE execution_scope IN ('ai_task', 'agent');
CREATE INDEX IF NOT EXISTS idx_sand_ai_invocation_dispatch_ambiguous_v026
    ON sand_ai_invocation_dispatch (environment_id, execution_scope, create_time)
    WHERE state = 'ambiguous';

ALTER TABLE sand_ai_invocation_dispatch
    ADD CONSTRAINT fk_sand_ai_invocation_dispatch_task_environment_v026
        FOREIGN KEY (parent_task_run_id, environment_id) REFERENCES sand_ai_task_run(id, environment_id) NOT VALID,
    ADD CONSTRAINT fk_sand_ai_invocation_dispatch_task_step_v026
        FOREIGN KEY (parent_task_step_id, parent_task_run_id) REFERENCES sand_ai_task_step(id, task_id) NOT VALID;

CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_no_terminal_overwrite() RETURNS trigger AS $$
BEGIN
    IF OLD.state IN ('succeeded', 'not_dispatched', 'failed', 'ambiguous') THEN
        RAISE EXCEPTION 'terminal SandAI dispatch cannot be overwritten';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_state_transition_v026() RETURNS trigger AS $$
BEGIN
    IF OLD.state IS DISTINCT FROM NEW.state
       AND NOT ((OLD.state = 'prepared' AND NEW.state IN ('dispatching', 'not_dispatched'))
                OR (OLD.state = 'dispatching' AND NEW.state IN ('succeeded', 'failed', 'ambiguous'))) THEN
        RAISE EXCEPTION 'invalid SandAI dispatch state transition: % -> %', OLD.state, NEW.state;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_sand_ai_invocation_dispatch_state_transition_v026 ON sand_ai_invocation_dispatch;
CREATE TRIGGER trg_sand_ai_invocation_dispatch_state_transition_v026
    BEFORE UPDATE ON sand_ai_invocation_dispatch
    FOR EACH ROW EXECUTE FUNCTION sand_ai_invocation_dispatch_state_transition_v026();

CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_no_provider_visible_delete_v026() RETURNS trigger AS $$
BEGIN
    IF OLD.state IN ('dispatching', 'succeeded', 'failed', 'ambiguous') THEN
        RAISE EXCEPTION 'provider-visible SandAI dispatch cannot be deleted';
    END IF;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_sand_ai_invocation_dispatch_no_provider_visible_delete_v026 ON sand_ai_invocation_dispatch;
CREATE TRIGGER trg_sand_ai_invocation_dispatch_no_provider_visible_delete_v026
    BEFORE DELETE ON sand_ai_invocation_dispatch
    FOR EACH ROW EXECUTE FUNCTION sand_ai_invocation_dispatch_no_provider_visible_delete_v026();

ALTER TABLE sand_ai_invocation_dispatch VALIDATE CONSTRAINT ck_sand_ai_invocation_dispatch_scope_v026;
ALTER TABLE sand_ai_invocation_dispatch VALIDATE CONSTRAINT fk_sand_ai_invocation_dispatch_task_environment_v026;
ALTER TABLE sand_ai_invocation_dispatch VALIDATE CONSTRAINT fk_sand_ai_invocation_dispatch_task_step_v026;
ALTER TABLE sand_ai_invocation_dispatch ALTER COLUMN execution_scope DROP DEFAULT;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202608310026_unify_provider_dispatch_scope', 'a6eafd11817b87011257dfebbbce25e72153861840b6c5dc7b7f2ba3f188680c', CURRENT_TIMESTAMP(0));

-- Migration 202609080027_pre_dispatch_rejection_evidence (sha256 aa58a4d111756caf99e7a5aee35640e0dc8910455745c4a0b7bbb8190297eb8a)
-- SAND-132/API 1.47.  This is evidence only: it never substitutes an
-- InvocationDispatch whose real model/deployment/provider route exists.
ALTER TABLE sand_ai_invocation
    ADD CONSTRAINT uq_sand_ai_invocation_id_environment_v027 UNIQUE (id, environment_id);

CREATE TABLE sand_ai_pre_dispatch_rejection (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    application_id bigint NULL,
    workload_client_id bigint NULL,
    trusted_scope char(64) NOT NULL,
    request_id varchar(160) NOT NULL,
    request_fingerprint char(64) NOT NULL,
    execution_scope varchar(32) NOT NULL,
    parent_task_run_id bigint NULL,
    parent_task_step_id bigint NULL,
    workflow_node_code varchar(128) NULL,
    attempt_no integer NOT NULL DEFAULT 1,
    invocation_id bigint NULL,
    error_code varchar(128) NOT NULL,
    rejection_stage varchar(32) NOT NULL,
    rejected_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sand_ai_pre_dispatch_rejection_scope_request UNIQUE (trusted_scope, request_id),
    CONSTRAINT ck_sand_ai_pre_dispatch_rejection_scope CHECK (
        execution_scope IN ('chat', 'ai_task', 'agent', 'structured_workflow', 'skill')
        AND attempt_no > 0
        AND ((execution_scope IN ('ai_task', 'agent', 'structured_workflow')) =
             (parent_task_run_id IS NOT NULL AND parent_task_step_id IS NOT NULL AND workflow_node_code IS NOT NULL))
        AND (execution_scope IN ('ai_task', 'agent', 'structured_workflow') OR
             (parent_task_run_id IS NULL AND parent_task_step_id IS NULL AND workflow_node_code IS NULL))
    ),
    CONSTRAINT ck_sand_ai_pre_dispatch_rejection_stage CHECK (
        rejection_stage IN ('model', 'profile', 'route', 'deployment', 'provider', 'config', 'price', 'budget', 'scope', 'lease')
    ),
    CONSTRAINT ck_sand_ai_pre_dispatch_rejection_error CHECK (error_code ~ '^SAND_AI_[A-Z0-9_]+$'),
    CONSTRAINT ck_sand_ai_pre_dispatch_rejection_invocation CHECK (invocation_id IS NULL OR invocation_id > 0)
);
CREATE INDEX idx_sand_ai_pre_dispatch_rejection_environment_time
    ON sand_ai_pre_dispatch_rejection (environment_id, rejected_at DESC);
CREATE INDEX idx_sand_ai_pre_dispatch_rejection_invocation
    ON sand_ai_pre_dispatch_rejection (invocation_id) WHERE invocation_id IS NOT NULL;

ALTER TABLE sand_ai_pre_dispatch_rejection
    ADD CONSTRAINT fk_sand_ai_pre_dispatch_rejection_invocation_environment
        FOREIGN KEY (invocation_id, environment_id) REFERENCES sand_ai_invocation(id, environment_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_sand_ai_pre_dispatch_rejection_task_environment
        FOREIGN KEY (parent_task_run_id, environment_id) REFERENCES sand_ai_task_run(id, environment_id) NOT VALID,
    ADD CONSTRAINT fk_sand_ai_pre_dispatch_rejection_task_step
        FOREIGN KEY (parent_task_step_id, parent_task_run_id) REFERENCES sand_ai_task_step(id, task_id) NOT VALID;

CREATE OR REPLACE FUNCTION sand_ai_pre_dispatch_rejection_immutable_v027() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'SandAI pre-dispatch rejection evidence is immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_pre_dispatch_rejection_no_update_v027
    BEFORE UPDATE ON sand_ai_pre_dispatch_rejection
    FOR EACH ROW EXECUTE FUNCTION sand_ai_pre_dispatch_rejection_immutable_v027();
CREATE TRIGGER trg_sand_ai_pre_dispatch_rejection_no_delete_v027
    BEFORE DELETE ON sand_ai_pre_dispatch_rejection
    FOR EACH ROW EXECUTE FUNCTION sand_ai_pre_dispatch_rejection_immutable_v027();

ALTER TABLE sand_ai_pre_dispatch_rejection VALIDATE CONSTRAINT ck_sand_ai_pre_dispatch_rejection_scope;
ALTER TABLE sand_ai_pre_dispatch_rejection VALIDATE CONSTRAINT fk_sand_ai_pre_dispatch_rejection_task_environment;
ALTER TABLE sand_ai_pre_dispatch_rejection VALIDATE CONSTRAINT fk_sand_ai_pre_dispatch_rejection_task_step;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609080027_pre_dispatch_rejection_evidence', 'aa58a4d111756caf99e7a5aee35640e0dc8910455745c4a0b7bbb8190297eb8a', CURRENT_TIMESTAMP(0));

-- Migration 202609090028_harden_billing_line_window (sha256 5d4d9e8d45833e53919d5aaf22d5f8a261075fccef9b53489e57eada78e4f77e)
-- Keep 003 immutable: existing data must be clean before this stricter invariant is installed.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_billing_line
        WHERE occurred_end <= occurred_start
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'SAND_AI_BILLING_LINE_PERIOD_INVALID',
            DETAIL = 'Existing billing lines require occurred_end later than occurred_start before migration 202609090028 can run.';
    END IF;
END;
$$ LANGUAGE plpgsql;

ALTER TABLE sand_ai_billing_line
    DROP CONSTRAINT ck_sand_ai_billing_line_period;

ALTER TABLE sand_ai_billing_line
    ADD CONSTRAINT ck_sand_ai_billing_line_period
    CHECK (occurred_end > occurred_start) NOT VALID;

ALTER TABLE sand_ai_billing_line
    VALIDATE CONSTRAINT ck_sand_ai_billing_line_period;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609090028_harden_billing_line_window', '5d4d9e8d45833e53919d5aaf22d5f8a261075fccef9b53489e57eada78e4f77e', CURRENT_TIMESTAMP(0));

-- Migration 202609090029_audio_seconds_usage (sha256 c460b8e22192f8a758ea99fa716e749034a03b41a2e3922de60c9f2661503430)
-- SAND-121B. Additive audio metering: no driver, workload route, credential or ASR execution is introduced here.
ALTER TABLE sand_ai_price_rate DROP CONSTRAINT IF EXISTS ck_sand_ai_price_rate_meter;
ALTER TABLE sand_ai_price_rate
    ADD CONSTRAINT ck_sand_ai_price_rate_meter CHECK (meter_code IN ('input_tokens', 'output_tokens', 'audio_seconds'));

DROP TRIGGER trg_sand_ai_tool_usage_protocol_quantity_guard ON sand_ai_usage_event;
ALTER TABLE sand_ai_usage_event
    ALTER COLUMN metering_quantity TYPE numeric(20,6) USING metering_quantity::numeric(20,6),
    ADD COLUMN audio_task_run_id bigint NULL;
CREATE TRIGGER trg_sand_ai_tool_usage_protocol_quantity_guard
    BEFORE INSERT OR UPDATE OF tool_run_id, mcp_snapshot_id, deployment_snapshot, metering_quantity
    ON sand_ai_usage_event
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_usage_protocol_quantity_guard();

ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT fk_sand_ai_usage_event_audio_task_environment_v029
    FOREIGN KEY (audio_task_run_id, environment_id) REFERENCES sand_ai_task_run(id, environment_id) NOT VALID;
CREATE UNIQUE INDEX uq_sand_ai_usage_event_audio_task_run_v029
    ON sand_ai_usage_event(audio_task_run_id) WHERE audio_task_run_id IS NOT NULL;

-- Keep historical no-tool Invocation and Tool/MCP external_request rows valid;
-- only new audio rows must satisfy this additional fail-closed branch.
ALTER TABLE sand_ai_usage_event DROP CONSTRAINT IF EXISTS ck_sand_ai_usage_event_tool_metering;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT ck_sand_ai_usage_event_tool_metering CHECK ((
        (audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND metering_unit IS NULL AND metering_quantity IS NULL)
        OR (audio_task_run_id IS NULL AND tool_run_id IS NOT NULL AND mcp_snapshot_id IS NULL AND tool_id IS NOT NULL AND tool_version_id IS NOT NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND btrim(deployment_snapshot->>'adapter') <> ''
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'runtime'
            AND ((deployment_snapshot->>'adapter' = 'standard_readonly_http' AND metering_quantity = 1)
                OR (deployment_snapshot->>'adapter' = 'mcp_streamable_http' AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity)))
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NOT NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity) AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND deployment_snapshot->>'adapter' = 'mcp_streamable_http'
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'discovery'
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (audio_task_run_id IS NOT NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND invocation_id IS NULL
            AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
            AND service_action = 'sand_ai.audio.transcription' AND source_kind = 'capability' AND capability_code = 'audio_transcription'
            AND metering_unit = 'audio_seconds' AND metering_quantity > 0
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0 AND usage_state = 'measured'
            AND reconciliation_state = 'unreconciled' AND reconciled_amount IS NULL
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL AND metered_amount IS NOT NULL AND currency IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))
        )
    ) IS TRUE) NOT VALID;
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT fk_sand_ai_usage_event_audio_task_environment_v029;

CREATE OR REPLACE FUNCTION sand_ai_audio_usage_task_guard_v029() RETURNS trigger AS $$
DECLARE task_input jsonb;
BEGIN
    IF NEW.audio_task_run_id IS NULL THEN RETURN NEW; END IF;
    SELECT input INTO task_input FROM sand_ai_task_run WHERE id = NEW.audio_task_run_id AND environment_id = NEW.environment_id AND task_type = 'audio_transcription';
    IF task_input IS NULL OR jsonb_typeof(task_input) <> 'object'
       OR task_input->>'usage_attribution_version' <> 'audio_usage_attribution@1'
       OR task_input->>'execution_target_version' <> 'audio_execution_target@1'
       OR task_input->'usage_attribution' IS NULL OR task_input->'execution_target' IS NULL
       OR jsonb_typeof(task_input->'usage_attribution') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(task_input->'usage_attribution')) <> 7
       OR NOT (task_input->'usage_attribution' ?& ARRAY['format_version','environment_id','organization_id','application_id','workload_client_id','identity_snapshot','capability_code'])
       OR task_input->'usage_attribution'->>'format_version' <> '1'
       OR (task_input->'usage_attribution'->>'environment_id')::bigint IS DISTINCT FROM NEW.environment_id
       OR (task_input->'usage_attribution'->>'organization_id')::bigint IS DISTINCT FROM NEW.organization_id
       OR (task_input->'usage_attribution'->>'application_id')::bigint IS DISTINCT FROM NEW.application_id
       OR (task_input->'usage_attribution'->>'workload_client_id')::bigint IS DISTINCT FROM NEW.workload_client_id
       OR task_input->'usage_attribution'->>'capability_code' <> 'audio_transcription'
       OR jsonb_typeof(task_input->'usage_attribution'->'identity_snapshot') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(task_input->'usage_attribution'->'identity_snapshot')) <> 3
       OR NOT (task_input->'usage_attribution'->'identity_snapshot' ?& ARRAY['organization_ref','application_ref','workload_client_ref'])
       OR task_input->'usage_attribution'->'identity_snapshot'->>'organization_ref' IS DISTINCT FROM NEW.identity_snapshot->>'organization_ref'
       OR task_input->'usage_attribution'->'identity_snapshot'->>'application_ref' IS DISTINCT FROM NEW.identity_snapshot->>'application_ref'
       OR task_input->'usage_attribution'->'identity_snapshot'->>'workload_client_ref' IS DISTINCT FROM NEW.identity_snapshot->>'workload_client_ref'
       OR task_input->'usage_attribution'->'identity_snapshot' IS DISTINCT FROM NEW.identity_snapshot
       OR jsonb_typeof(task_input->'execution_target') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target')) <> 3
       OR NOT (task_input->'execution_target' ?& ARRAY['provider','model','deployment'])
       OR jsonb_typeof(task_input->'execution_target'->'provider') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'provider')) <> 3 OR NOT (task_input->'execution_target'->'provider' ?& ARRAY['id','code','name'])
       OR jsonb_typeof(task_input->'execution_target'->'model') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'model')) <> 3 OR NOT (task_input->'execution_target'->'model' ?& ARRAY['id','code','name'])
       OR jsonb_typeof(task_input->'execution_target'->'deployment') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'deployment')) <> 4 OR NOT (task_input->'execution_target'->'deployment' ?& ARRAY['id','remote_model','revision','route'])
       OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route') <> 'object'
       OR (task_input->'execution_target'->'deployment'->'route' <> '{}'::jsonb AND (
            (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'deployment'->'route')) <> 4
            OR NOT (task_input->'execution_target'->'deployment'->'route' ?& ARRAY['selection_kind','profile_id','profile_revision','route_id'])
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'selection_kind') <> 'string'
            OR task_input->'execution_target'->'deployment'->'route'->>'selection_kind' <> 'capability_profile'
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'profile_id') <> 'number'
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'profile_revision') <> 'number'
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'route_id') <> 'number'
            OR task_input->'execution_target'->'deployment'->'route'->>'profile_id' !~ '^[1-9][0-9]*$'
            OR task_input->'execution_target'->'deployment'->'route'->>'profile_revision' !~ '^[1-9][0-9]*$'
            OR task_input->'execution_target'->'deployment'->'route'->>'route_id' !~ '^[1-9][0-9]*$'
            OR length(task_input->'execution_target'->'deployment'->'route'->>'profile_id') > 19
            OR length(task_input->'execution_target'->'deployment'->'route'->>'profile_revision') > 19
            OR length(task_input->'execution_target'->'deployment'->'route'->>'route_id') > 19
            OR (length(task_input->'execution_target'->'deployment'->'route'->>'profile_id') = 19 AND task_input->'execution_target'->'deployment'->'route'->>'profile_id' > '9223372036854775807')
            OR (length(task_input->'execution_target'->'deployment'->'route'->>'profile_revision') = 19 AND task_input->'execution_target'->'deployment'->'route'->>'profile_revision' > '9223372036854775807')
            OR (length(task_input->'execution_target'->'deployment'->'route'->>'route_id') = 19 AND task_input->'execution_target'->'deployment'->'route'->>'route_id' > '9223372036854775807')
        ))
       OR (task_input->'execution_target')::text ~* 'secret|token|password|api_key|credential|endpoint|url|response|raw|path|object_key|bucket|storage|location|[a-z][a-z0-9+.-]*://|(^|[^[:alnum:]])(/|\\\\|~|\.\.?/)'
       OR (task_input->'execution_target'->'provider'->>'id')::bigint IS DISTINCT FROM NEW.provider_id
       OR (task_input->'execution_target'->'model'->>'id')::bigint IS DISTINCT FROM NEW.model_id
       OR (task_input->'execution_target'->'deployment'->>'id')::bigint IS DISTINCT FROM NEW.deployment_id
       OR task_input->'execution_target'->'provider' IS DISTINCT FROM NEW.provider_snapshot
       OR task_input->'execution_target'->'model' IS DISTINCT FROM NEW.model_snapshot
       OR task_input->'execution_target'->'deployment' IS DISTINCT FROM NEW.deployment_snapshot THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_USAGE_TASK_BINDING_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_audio_usage_task_guard_v029 BEFORE INSERT ON sand_ai_usage_event
FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_usage_task_guard_v029();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609090029_audio_seconds_usage', 'c460b8e22192f8a758ea99fa716e749034a03b41a2e3922de60c9f2661503430', CURRENT_TIMESTAMP(0));

-- Migration 202609090030_local_audio_task (sha256 abfa549342d406a190cedd8bad5da41fd575ada7fc56ba99472c3f72d42c7cd3)
-- SAND-121C source-only migration. Do not execute without separate database authorization.
ALTER TABLE sand_ai_file ADD COLUMN IF NOT EXISTS file_ref varchar(128) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_file_file_ref_v030
    ON sand_ai_file(file_ref) WHERE file_ref IS NOT NULL AND delete_time IS NULL;
ALTER TABLE sand_ai_file ADD CONSTRAINT ck_sand_ai_file_file_ref_v030
    CHECK (file_ref IS NULL OR file_ref ~ '^saf_[A-Za-z0-9_-]{16,120}$') NOT VALID;

-- Existing profiles may not turn audio into a model/external route. The application
-- performs the code-owned driver and local-only checks before TaskWorker dispatch.
ALTER TABLE sand_ai_capability_route ADD CONSTRAINT ck_sand_ai_capability_route_audio_v030
    CHECK (capability_code <> 'audio_transcription' OR (driver_code = 'local_whisper_cpp' AND deployment_id IS NOT NULL)) NOT VALID;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609090030_local_audio_task', 'abfa549342d406a190cedd8bad5da41fd575ada7fc56ba99472c3f72d42c7cd3', CURRENT_TIMESTAMP(0));

-- Migration 202609090031_external_audio_dispatch (sha256 766fe15b60f644b6a1d5c1fcb74862c4b8bf61a1ae27582f5bd41ceb7897d444)
-- SAND-121D source-only migration. Do not execute without separate database authorization.
-- It is intentionally additive: 029/030 and the local audio task path remain untouched.
CREATE TABLE sand_ai_external_audio_dispatch (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    task_run_id bigint NOT NULL,
    logical_execution_id varchar(192) NOT NULL,
    request_fingerprint char(64) NOT NULL,
    frozen_request jsonb NOT NULL,
    state varchar(32) NOT NULL DEFAULT 'prepared',
    lease_owner varchar(64) NULL,
    lease_token char(64) NULL,
    lease_fence bigint NULL,
    accepted_provider_job_id varchar(192) NULL,
    accepted_at timestamp(0) without time zone NULL,
    terminal_snapshot jsonb NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sand_ai_external_audio_dispatch_task_environment_v031 FOREIGN KEY (task_run_id, environment_id) REFERENCES sand_ai_task_run(id, environment_id) NOT VALID,
    CONSTRAINT uq_sand_ai_external_audio_dispatch_execution_v031 UNIQUE (environment_id, logical_execution_id),
    CONSTRAINT uq_sand_ai_external_audio_dispatch_task_v031 UNIQUE (environment_id, task_run_id),
    CONSTRAINT ck_sand_ai_external_audio_dispatch_fingerprint_v031 CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_external_audio_dispatch_state_v031 CHECK (state IN ('prepared','dispatching','accepted','succeeded','not_dispatched','failed','ambiguous','recovery_pending')),
    CONSTRAINT ck_sand_ai_external_audio_dispatch_terminal_v031 CHECK ((state IN ('prepared','dispatching','accepted','recovery_pending') AND terminal_snapshot IS NULL) OR (state IN ('succeeded','not_dispatched','failed','ambiguous') AND terminal_snapshot IS NOT NULL)),
    CONSTRAINT ck_sand_ai_external_audio_dispatch_accepted_v031 CHECK ((state IN ('accepted','recovery_pending') AND accepted_provider_job_id IS NOT NULL AND accepted_at IS NOT NULL) OR (state IN ('succeeded','failed','ambiguous') AND ((accepted_provider_job_id IS NOT NULL AND accepted_at IS NOT NULL) OR (accepted_provider_job_id IS NULL AND accepted_at IS NULL))) OR (state IN ('prepared','dispatching','not_dispatched') AND accepted_provider_job_id IS NULL AND accepted_at IS NULL)),
    CONSTRAINT ck_sand_ai_external_audio_dispatch_frozen_v031 CHECK (jsonb_typeof(frozen_request) = 'object' AND frozen_request ?& ARRAY['logical_execution_id','trusted_scope','file_ref','file_sha256','media_type','parameters','driver_code','driver_version','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','egress_policy','credential_revision_id','credential_digest','price_snapshot','usage_attribution','execution_target'] AND (frozen_request - ARRAY['logical_execution_id','trusted_scope','file_ref','file_sha256','media_type','parameters','driver_code','driver_version','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','egress_policy','credential_revision_id','credential_digest','price_snapshot','usage_attribution','execution_target']::text[]) = '{}'::jsonb AND frozen_request->>'egress_policy' = 'approved_cloud' AND jsonb_typeof(frozen_request->'price_snapshot') = 'object' AND jsonb_typeof(frozen_request->'usage_attribution') = 'object' AND jsonb_typeof(frozen_request->'execution_target') = 'object')
);
CREATE INDEX ix_sand_ai_external_audio_dispatch_task_v031 ON sand_ai_external_audio_dispatch(task_run_id, environment_id);

CREATE TABLE sand_ai_external_audio_receipt (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    external_audio_dispatch_id bigint NOT NULL REFERENCES sand_ai_external_audio_dispatch(id),
    receipt_kind varchar(16) NOT NULL,
    receipt_version varchar(32) NOT NULL,
    key_id varchar(64) NOT NULL,
    nonce bytea NOT NULL,
    tag bytea NOT NULL,
    ciphertext bytea NOT NULL,
    plaintext_bytes integer NOT NULL,
    content_hmac bytea NOT NULL,
    expire_time timestamp(0) without time zone NOT NULL,
    retain_until timestamp(0) without time zone NOT NULL,
    acknowledged_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sand_ai_external_audio_receipt_kind_v031 UNIQUE (external_audio_dispatch_id, receipt_kind),
    CONSTRAINT ck_sand_ai_external_audio_receipt_kind_v031 CHECK (receipt_kind IN ('accepted','result')),
    CONSTRAINT ck_sand_ai_external_audio_receipt_format_v031 CHECK (receipt_version = 'external_audio_receipt/v1' AND nonce IS NOT NULL AND octet_length(nonce) = 12 AND octet_length(tag) = 16 AND octet_length(content_hmac) = 32 AND plaintext_bytes > 0 AND plaintext_bytes <= 262144 AND expire_time <= retain_until),
    CONSTRAINT ck_sand_ai_external_audio_receipt_ack_v031 CHECK (acknowledged_time IS NULL OR acknowledged_time >= create_time)
);

CREATE OR REPLACE FUNCTION sand_ai_external_audio_dispatch_immutable_v031() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.state IN ('succeeded','not_dispatched','failed','ambiguous') THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_IMMUTABLE';
    END IF;
    IF NEW.environment_id <> OLD.environment_id OR NEW.task_run_id <> OLD.task_run_id OR NEW.logical_execution_id <> OLD.logical_execution_id OR NEW.request_fingerprint <> OLD.request_fingerprint OR NEW.frozen_request <> OLD.frozen_request THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_FROZEN';
    END IF;
    IF OLD.accepted_provider_job_id IS NOT NULL AND (NEW.accepted_provider_job_id IS DISTINCT FROM OLD.accepted_provider_job_id OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at) THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_ACCEPTED_EVIDENCE_IMMUTABLE';
    END IF;
    IF NOT ((OLD.state = 'prepared' AND NEW.state IN ('prepared','dispatching','not_dispatched'))
        OR (OLD.state = 'dispatching' AND NEW.state IN ('dispatching','accepted','succeeded','failed','ambiguous'))
        OR (OLD.state = 'accepted' AND NEW.state IN ('accepted','recovery_pending','succeeded','failed','ambiguous'))
        OR (OLD.state = 'recovery_pending' AND NEW.state IN ('recovery_pending','succeeded','failed','ambiguous'))) THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_TRANSITION';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_external_audio_dispatch_immutable_trigger_v031 BEFORE UPDATE ON sand_ai_external_audio_dispatch FOR EACH ROW EXECUTE FUNCTION sand_ai_external_audio_dispatch_immutable_v031();

CREATE OR REPLACE FUNCTION sand_ai_external_audio_receipt_immutable_v031() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_RECEIPT_IMMUTABLE'; END IF;
    IF OLD.acknowledged_time IS NULL AND NEW.acknowledged_time IS NOT NULL AND NEW.acknowledged_time <= OLD.retain_until
       AND NEW.external_audio_dispatch_id = OLD.external_audio_dispatch_id AND NEW.receipt_kind = OLD.receipt_kind
       AND NEW.receipt_version = OLD.receipt_version AND NEW.key_id = OLD.key_id AND NEW.nonce = OLD.nonce
       AND NEW.tag = OLD.tag AND NEW.ciphertext = OLD.ciphertext AND NEW.plaintext_bytes = OLD.plaintext_bytes
       AND NEW.content_hmac = OLD.content_hmac AND NEW.expire_time = OLD.expire_time AND NEW.retain_until = OLD.retain_until
       AND NEW.create_time = OLD.create_time THEN RETURN NEW;
    END IF;
    RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_RECEIPT_IMMUTABLE';
END $$;
CREATE TRIGGER sand_ai_external_audio_receipt_immutable_trigger_v031 BEFORE UPDATE OR DELETE ON sand_ai_external_audio_receipt FOR EACH ROW EXECUTE FUNCTION sand_ai_external_audio_receipt_immutable_v031();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609090031_external_audio_dispatch', '766fe15b60f644b6a1d5c1fcb74862c4b8bf61a1ae27582f5bd41ceb7897d444', CURRENT_TIMESTAMP(0));

-- Migration 202609090032_external_audio_sync_completion (sha256 d0cd367821bff62694f0b5a42ca526a014bad0d88f5bd8c758673f10f1dc7b4c)
-- SAND-121E. Incremental only: do not rewrite the historical 031 durable-dispatch migration.
-- A synchronous completion has no accepted provider job, but it must first append its unique result receipt.
ALTER TABLE sand_ai_external_audio_dispatch DROP CONSTRAINT ck_sand_ai_external_audio_dispatch_accepted_v031;
ALTER TABLE sand_ai_external_audio_dispatch ADD CONSTRAINT ck_sand_ai_external_audio_dispatch_accepted_v032 CHECK (
  (state = 'accepted' AND accepted_provider_job_id IS NOT NULL AND accepted_at IS NOT NULL)
  OR (state = 'recovery_pending' AND ((accepted_provider_job_id IS NOT NULL AND accepted_at IS NOT NULL) OR (accepted_provider_job_id IS NULL AND accepted_at IS NULL)))
  OR (state IN ('succeeded','failed','ambiguous') AND ((accepted_provider_job_id IS NOT NULL AND accepted_at IS NOT NULL) OR (accepted_provider_job_id IS NULL AND accepted_at IS NULL)))
  OR (state IN ('prepared','dispatching','not_dispatched') AND accepted_provider_job_id IS NULL AND accepted_at IS NULL)
) NOT VALID;

-- 031's trigger remains immutable; this incremental replacement only admits the
-- two new pre-terminal transitions required by a sync result receipt.
CREATE OR REPLACE FUNCTION sand_ai_external_audio_dispatch_immutable_v031() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.state IN ('succeeded','not_dispatched','failed','ambiguous') THEN RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_IMMUTABLE'; END IF;
  IF NEW.environment_id <> OLD.environment_id OR NEW.task_run_id <> OLD.task_run_id OR NEW.logical_execution_id <> OLD.logical_execution_id OR NEW.request_fingerprint <> OLD.request_fingerprint OR NEW.frozen_request <> OLD.frozen_request THEN RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_FROZEN'; END IF;
  IF OLD.accepted_provider_job_id IS NOT NULL AND (NEW.accepted_provider_job_id IS DISTINCT FROM OLD.accepted_provider_job_id OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at) THEN RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_ACCEPTED_EVIDENCE_IMMUTABLE'; END IF;
  IF NOT ((OLD.state = 'prepared' AND NEW.state IN ('prepared','dispatching','not_dispatched'))
    OR (OLD.state = 'dispatching' AND NEW.state IN ('dispatching','accepted','recovery_pending','not_dispatched','succeeded','failed','ambiguous'))
    OR (OLD.state = 'accepted' AND NEW.state IN ('accepted','recovery_pending','succeeded','failed','ambiguous'))
    OR (OLD.state = 'recovery_pending' AND NEW.state IN ('recovery_pending','succeeded','failed','ambiguous'))) THEN RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_TRANSITION'; END IF;
  RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_external_audio_sync_completion_v032() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.state = 'recovery_pending' AND OLD.state = 'dispatching' AND NEW.accepted_provider_job_id IS NULL
     AND NOT EXISTS (SELECT 1 FROM sand_ai_external_audio_receipt r WHERE r.external_audio_dispatch_id = NEW.id AND r.receipt_kind = 'result') THEN
    RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_SYNC_RESULT_RECEIPT_REQUIRED';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_external_audio_sync_completion_trigger_v032 BEFORE UPDATE ON sand_ai_external_audio_dispatch FOR EACH ROW EXECUTE FUNCTION sand_ai_external_audio_sync_completion_v032();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609090032_external_audio_sync_completion', 'd0cd367821bff62694f0b5a42ca526a014bad0d88f5bd8c758673f10f1dc7b4c', CURRENT_TIMESTAMP(0));

-- Migration 202609240033_workflow_authorization_pending (sha256 13f4219d6eab602cd1414d583af2a9098b83119ac4c285cd7123748e2757f3ac)
-- A persisted workflow must be visible to SandIAM before authorization.
-- Workers only claim queued/retrying tasks, never authorization_pending.
ALTER TABLE sand_ai_task_run DROP CONSTRAINT IF EXISTS ck_sand_ai_task_run_state;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_run_state
    CHECK (state IN ('authorization_pending', 'queued', 'running', 'retrying', 'recovery_pending', 'succeeded', 'failed', 'canceled'));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609240033_workflow_authorization_pending', '13f4219d6eab602cd1414d583af2a9098b83119ac4c285cd7123748e2757f3ac', CURRENT_TIMESTAMP(0));

-- Migration 202609240034_chat_invocation_facts (sha256 37582294262121526c6abf63b2ca36e8a7d3f190e6f24ad3a2411cf2b77e5efe)
-- Persist SandIAM resource facts for the public chat invocation before authorization.
-- Historical invocations remain nullable and fail closed when read through the new resolver.
ALTER TABLE sand_ai_invocation
    ADD COLUMN IF NOT EXISTS organization_id bigint NULL,
    ADD COLUMN IF NOT EXISTS application_id bigint NULL,
    ADD COLUMN IF NOT EXISTS workload_client_id bigint NULL,
    ADD COLUMN IF NOT EXISTS data_class varchar(32) NULL;

ALTER TABLE sand_ai_invocation
    ADD CONSTRAINT ck_sand_ai_invocation_iam_scope_v034 CHECK (
        (organization_id IS NULL OR organization_id > 0)
        AND (application_id IS NULL OR application_id > 0)
        AND (workload_client_id IS NULL OR workload_client_id > 0)
        AND (data_class IS NULL OR data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$')
    ) NOT VALID;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609240034_chat_invocation_facts', '37582294262121526c6abf63b2ca36e8a7d3f190e6f24ad3a2411cf2b77e5efe', CURRENT_TIMESTAMP(0));

-- Migration 202609240035_remove_legacy_table_msg_override (sha256 17cf552df5a0b2da02be2ab137a42731478a5bccb65d820a44e9a173f2430341)
-- The host owns ThinkORM metadata functions. Migration 000 created this
-- text overload before SandAI became a single installable product.
DO $sand_ai_table_msg_cleanup$
DECLARE
    function_body text;
    function_language name;
BEGIN
    SELECT procedure.prosrc, language.lanname INTO function_body, function_language
    FROM pg_proc AS procedure
    JOIN pg_language AS language ON language.oid = procedure.prolang
    WHERE procedure.oid = to_regprocedure(format('%I.table_msg(text)', current_schema()));

    IF function_body IS NOT NULL THEN
        IF function_language <> 'sql' OR md5(function_body) <> '1cdc07c5ec9541d7739fc6923977cfb7' THEN
            RAISE EXCEPTION 'table_msg(text) differs from the historical SandAI definition; manual ownership review required';
        END IF;
        EXECUTE format('DROP FUNCTION %I.table_msg(text) RESTRICT', current_schema());
    END IF;
END
$sand_ai_table_msg_cleanup$;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609240035_remove_legacy_table_msg_override', '17cf552df5a0b2da02be2ab137a42731478a5bccb65d820a44e9a173f2430341', CURRENT_TIMESTAMP(0));

-- Migration 202609240036_file_owner_scope (sha256 29db9bbefcfd1b9b3ac9b2ca1f6a65607cbb9573e5fa3f74a2811dc4d0a46f27)
-- New workload files bind to the authenticated SandIAM caller. Historical files
-- remain unowned and are unavailable to direct workload file/source APIs.
ALTER TABLE sand_ai_file
    ADD COLUMN IF NOT EXISTS owner_scope char(64) NULL,
    ADD COLUMN IF NOT EXISTS owner_attribution jsonb NULL;

ALTER TABLE sand_ai_file
    ADD CONSTRAINT ck_sand_ai_file_owner_scope_v036 CHECK (
        (owner_scope IS NULL AND owner_attribution IS NULL)
        OR (owner_scope ~ '^[a-f0-9]{64}$' AND jsonb_typeof(owner_attribution) = 'object')
    ) NOT VALID;

CREATE INDEX IF NOT EXISTS sand_ai_file_owner_scope_idx
    ON sand_ai_file (environment_id, owner_scope, status)
    WHERE delete_time IS NULL;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609240036_file_owner_scope', '29db9bbefcfd1b9b3ac9b2ca1f6a65607cbb9573e5fa3f74a2811dc4d0a46f27', CURRENT_TIMESTAMP(0));

-- Migration 202609250037_task_invocation_facts (sha256 8e6df0c4058f1d72160195a374bbdf9081e77684bec2120dc57f7058f1a2f8b5)
-- Persist SandIAM resource facts on asynchronous AI and agent tasks.
-- Historical rows stay nullable so the resolver can reject them without a guessed owner.
ALTER TABLE sand_ai_task_run
    ADD COLUMN IF NOT EXISTS organization_id bigint NULL,
    ADD COLUMN IF NOT EXISTS application_id bigint NULL,
    ADD COLUMN IF NOT EXISTS workload_client_id bigint NULL,
    ADD COLUMN IF NOT EXISTS data_class varchar(32) NULL;

ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_run_iam_scope_v037 CHECK (
        (organization_id IS NULL OR organization_id > 0)
        AND (application_id IS NULL OR application_id > 0)
        AND (workload_client_id IS NULL OR workload_client_id > 0)
        AND (data_class IS NULL OR data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$')
    ) NOT VALID;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250037_task_invocation_facts', '8e6df0c4058f1d72160195a374bbdf9081e77684bec2120dc57f7058f1a2f8b5', CURRENT_TIMESTAMP(0));

-- Migration 202609250038_knowledge_publication (sha256 ab30a893de3c4d0785161acec910d455d305dba1fb28dca1f1290cee1fb6e07d)
ALTER TABLE sand_ai_knowledge_base_version
    ADD COLUMN source_scope_sha256 CHAR(64) NULL
        CHECK (source_scope_sha256 IS NULL OR source_scope_sha256 ~ '^[a-f0-9]{64}$'),
    ADD COLUMN data_class VARCHAR(32) NULL
        CHECK (data_class IS NULL OR data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$');

CREATE TABLE sand_ai_knowledge_publication (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    knowledge_base_id BIGINT NOT NULL REFERENCES sand_ai_knowledge_base(id),
    owner_environment_id BIGINT NOT NULL,
    consumer_organization_id BIGINT NOT NULL,
    consumer_application_id BIGINT NOT NULL,
    consumer_environment_id BIGINT NOT NULL,
    code VARCHAR(64) NOT NULL CHECK (code ~ '^[a-z][a-z0-9_-]{0,63}$'),
    state VARCHAR(16) NOT NULL CHECK (state IN ('published', 'revoked')),
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sand_ai_knowledge_publication_consumer_code_uq UNIQUE (consumer_environment_id, code),
    CONSTRAINT sand_ai_knowledge_publication_base_consumer_uq UNIQUE (knowledge_base_id, consumer_environment_id)
);
CREATE INDEX sand_ai_knowledge_publication_base_state_idx
    ON sand_ai_knowledge_publication(knowledge_base_id, state);

CREATE TABLE sand_ai_knowledge_publication_operation (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    owner_environment_id BIGINT NOT NULL,
    operation_code VARCHAR(16) NOT NULL CHECK (operation_code IN ('publish', 'revoke')),
    idempotency_key VARCHAR(128) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    publication_id BIGINT NOT NULL REFERENCES sand_ai_knowledge_publication(id),
    result_state VARCHAR(16) NOT NULL CHECK (result_state IN ('published', 'revoked')),
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sand_ai_knowledge_publication_operation_key_uq
        UNIQUE (owner_environment_id, operation_code, idempotency_key)
);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250038_knowledge_publication', 'ab30a893de3c4d0785161acec910d455d305dba1fb28dca1f1290cee1fb6e07d', CURRENT_TIMESTAMP(0));

-- Migration 202609250039_skill_knowledge_publication (sha256 566e725d92c13a426aee0b54503aa0c0e537b67dc0c9117d198b20c2d73dcf3e)
CREATE OR REPLACE FUNCTION sand_ai_skill_published_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref record;
BEGIN
    IF NEW.state = 'published' THEN
        FOR ref IN SELECT * FROM sand_ai_skill_knowledge_ref WHERE skill_version_id = NEW.id LOOP
            IF NOT EXISTS (
                SELECT 1
                FROM sand_ai_skill skill
                JOIN sand_ai_knowledge_publication publication
                    ON publication.consumer_environment_id = skill.environment_id
                   AND publication.knowledge_base_id = ref.knowledge_base_id
                   AND publication.state = 'published'
                JOIN sand_ai_knowledge_base kb
                    ON kb.id = publication.knowledge_base_id
                   AND kb.environment_id = publication.owner_environment_id
                JOIN sand_ai_knowledge_base_version version
                    ON version.id = ref.knowledge_base_version_id
                   AND version.knowledge_base_id = kb.id
                WHERE skill.id = NEW.skill_id
                  AND kb.status = 1
                  AND kb.delete_time IS NULL
                  AND kb.state = 'published'
                  AND version.state = 'published'
                  AND version.snapshot_sha256 = ref.snapshot_sha256
                  AND version.source_scope_sha256 IS NOT NULL
            ) THEN
                RAISE EXCEPTION 'SAND_AI_SKILL_KNOWLEDGE_REFERENCE_INVALID';
            END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END $$;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250039_skill_knowledge_publication', '566e725d92c13a426aee0b54503aa0c0e537b67dc0c9117d198b20c2d73dcf3e', CURRENT_TIMESTAMP(0));

-- Migration 202609250040_tool_connection_data_class (sha256 c43f48937d7546beda2110a29d58a3fe446b937b9a1d9a6c969d6e74c334b867)
-- Existing connection revisions have no verified source classification.
-- Leave them unclassified and reject them at runtime; operators create a new
-- classified connection rather than guessing a value for historical sources.
ALTER TABLE sand_ai_tool_connection_revision
    ADD COLUMN data_class varchar(32) NULL;

ALTER TABLE sand_ai_tool_connection_revision
    ADD CONSTRAINT ck_sand_ai_tool_connection_data_class_v040 CHECK (
        data_class IS NOT NULL
        AND data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$'
    ) NOT VALID;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250040_tool_connection_data_class', 'c43f48937d7546beda2110a29d58a3fe446b937b9a1d9a6c969d6e74c334b867', CURRENT_TIMESTAMP(0));

-- Migration 202609250041_material_analysis_profile (sha256 38fa080a117d3062fa7a496db132c0cb8ead844f933d86f304037d8a26efb955)
CREATE TABLE IF NOT EXISTS sand_ai_material_analysis_profile (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    code varchar(64) NOT NULL,
    version varchar(32) NOT NULL,
    name varchar(160) NOT NULL,
    revision integer NOT NULL DEFAULT 1,
    config jsonb NOT NULL,
    state varchar(24) NOT NULL DEFAULT 'draft',
    status smallint NOT NULL DEFAULT 2,
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_ai_material_analysis_profile_environment_version UNIQUE (environment_id, code, version),
    CONSTRAINT ck_sand_ai_material_analysis_profile_code CHECK (code ~ '^[a-z][a-z0-9_]{0,63}$'),
    CONSTRAINT ck_sand_ai_material_analysis_profile_version CHECK (version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'),
    CONSTRAINT ck_sand_ai_material_analysis_profile_revision CHECK (revision > 0),
    CONSTRAINT ck_sand_ai_material_analysis_profile_state CHECK (state IN ('draft', 'tested', 'published', 'revoked')),
    CONSTRAINT ck_sand_ai_material_analysis_profile_status CHECK (status IN (1, 2))
);
CREATE INDEX IF NOT EXISTS sand_ai_material_analysis_profile_environment_state_idx
    ON sand_ai_material_analysis_profile (environment_id, state, status);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250041_material_analysis_profile', '38fa080a117d3062fa7a496db132c0cb8ead844f933d86f304037d8a26efb955', CURRENT_TIMESTAMP(0));

-- Migration 202609250042_material_analysis_profile_evidence (sha256 1c78f64d11729b5a621bdadc51a5ad5e1f54a147b385c0d5e87f493735817224)
ALTER TABLE sand_ai_material_analysis_profile
    ADD COLUMN sample_run_id bigint NULL REFERENCES sand_ai_workflow_run(id) ON DELETE RESTRICT,
    ADD COLUMN sample_artifact_id bigint NULL REFERENCES sand_ai_artifact(id) ON DELETE RESTRICT,
    ADD COLUMN sample_usage_event_id bigint NULL REFERENCES sand_ai_usage_event(id) ON DELETE RESTRICT,
    ADD COLUMN published_at timestamp(0) without time zone NULL,
    ADD COLUMN revoked_at timestamp(0) without time zone NULL;

CREATE UNIQUE INDEX sand_ai_material_analysis_profile_sample_run_uq
    ON sand_ai_material_analysis_profile (sample_run_id)
    WHERE sample_run_id IS NOT NULL;

ALTER TABLE sand_ai_material_analysis_profile
    ADD CONSTRAINT ck_sand_ai_material_analysis_profile_publication_evidence CHECK (
        (
            state IN ('draft', 'tested')
            AND sample_run_id IS NULL
            AND sample_artifact_id IS NULL
            AND sample_usage_event_id IS NULL
            AND published_at IS NULL
            AND revoked_at IS NULL
        )
        OR (
            state = 'published'
            AND sample_run_id IS NOT NULL
            AND sample_artifact_id IS NOT NULL
            AND sample_usage_event_id IS NOT NULL
            AND published_at IS NOT NULL
            AND revoked_at IS NULL
        )
        OR (
            state = 'revoked'
            AND sample_run_id IS NOT NULL
            AND sample_artifact_id IS NOT NULL
            AND sample_usage_event_id IS NOT NULL
            AND published_at IS NOT NULL
            AND revoked_at IS NOT NULL
        )
    );
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250042_material_analysis_profile_evidence', '1c78f64d11729b5a621bdadc51a5ad5e1f54a147b385c0d5e87f493735817224', CURRENT_TIMESTAMP(0));
-- Migration 202609250043_material_file_result (sha256 d328bca79547cd2ee6160f524b831872c3e4eec084665f2eef065f6e8db44b50)
CREATE TABLE sand_ai_workflow_file_result (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    workflow_run_file_id bigint NOT NULL REFERENCES sand_ai_workflow_run_file(id) ON DELETE RESTRICT,
    attempt_no integer NOT NULL,
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT,
    execution_snapshot_sha256 char(64) NOT NULL,
    schema_code varchar(128) NOT NULL,
    schema_version varchar(32) NOT NULL,
    payload jsonb NOT NULL,
    payload_sha256 char(64) NOT NULL,
    field_source_count integer NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_workflow_file_result_file UNIQUE (workflow_run_file_id),
    CONSTRAINT uk_sand_ai_workflow_file_result_invocation UNIQUE (invocation_id),
    CONSTRAINT ck_sand_ai_workflow_file_result_attempt CHECK (attempt_no > 0),
    CONSTRAINT ck_sand_ai_workflow_file_result_snapshot CHECK (execution_snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_workflow_file_result_payload_hash CHECK (payload_sha256 ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_workflow_file_result_source_count CHECK (field_source_count > 0),
    CONSTRAINT ck_sand_ai_workflow_file_result_schema_version CHECK (schema_version ~ '^[0-9]+\.[0-9]+\.[0-9]+$')
);

CREATE TABLE sand_ai_workflow_file_result_source (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    result_id bigint NOT NULL REFERENCES sand_ai_workflow_file_result(id) ON DELETE RESTRICT,
    field_path varchar(1024) NOT NULL,
    source_block_id bigint NOT NULL REFERENCES sand_ai_source_block(id) ON DELETE RESTRICT,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_workflow_file_result_source UNIQUE (result_id, field_path, source_block_id),
    CONSTRAINT ck_sand_ai_workflow_file_result_source_path CHECK (left(field_path, 1) = '/')
);

CREATE INDEX idx_sand_ai_workflow_file_result_source_block
    ON sand_ai_workflow_file_result_source (source_block_id);

CREATE FUNCTION sand_ai_workflow_file_result_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'SandAI workflow file result evidence is immutable';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER sand_ai_workflow_file_result_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_workflow_file_result
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_file_result_immutable();

CREATE TRIGGER sand_ai_workflow_file_result_source_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_workflow_file_result_source
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_file_result_immutable();

CREATE FUNCTION sand_ai_workflow_file_result_source_count_guard() RETURNS trigger AS $$
DECLARE
    expected_count integer;
    actual_count bigint;
    target_result_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'sand_ai_workflow_file_result' THEN
        target_result_id := NEW.id;
    ELSE
        target_result_id := NEW.result_id;
    END IF;
    SELECT field_source_count INTO expected_count
    FROM sand_ai_workflow_file_result WHERE id = target_result_id;
    SELECT count(*) INTO actual_count
    FROM sand_ai_workflow_file_result_source WHERE result_id = target_result_id;
    IF expected_count IS NULL OR actual_count <> expected_count THEN
        RAISE EXCEPTION 'SandAI workflow file result source count does not match sealed evidence';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER sand_ai_workflow_file_result_source_count
    AFTER INSERT ON sand_ai_workflow_file_result
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_file_result_source_count_guard();

CREATE CONSTRAINT TRIGGER sand_ai_workflow_file_result_source_count
    AFTER INSERT ON sand_ai_workflow_file_result_source
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_file_result_source_count_guard();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250043_material_file_result', 'd328bca79547cd2ee6160f524b831872c3e4eec084665f2eef065f6e8db44b50', CURRENT_TIMESTAMP(0));
-- Migration 202609250044_material_cross_file_review (sha256 df91e5a11358f9eed85c440f3760e6d03da0a3927c24bab14393e341fee8c620)
CREATE TABLE sand_ai_workflow_cross_file_review (
    workflow_run_id bigint PRIMARY KEY REFERENCES sand_ai_workflow_run(id) ON DELETE RESTRICT,
    state varchar(24) NOT NULL DEFAULT 'pending',
    attempt_count integer NOT NULL DEFAULT 0,
    last_invocation_id bigint NULL REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT,
    error_code varchar(128) NULL,
    failure_summary varchar(512) NULL,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_state
        CHECK (state IN ('pending', 'running', 'recovery_pending', 'succeeded', 'failed', 'ambiguous', 'canceled')),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_attempt CHECK (attempt_count >= 0)
);

CREATE INDEX idx_sand_ai_workflow_cross_file_review_state
    ON sand_ai_workflow_cross_file_review (state, update_time);

CREATE TABLE sand_ai_workflow_cross_file_review_result (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    workflow_run_id bigint NOT NULL REFERENCES sand_ai_workflow_cross_file_review(workflow_run_id) ON DELETE RESTRICT,
    attempt_no integer NOT NULL,
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT,
    execution_snapshot_sha256 char(64) NOT NULL,
    input_sha256 char(64) NOT NULL,
    payload jsonb NOT NULL,
    payload_sha256 char(64) NOT NULL,
    field_source_count integer NOT NULL,
    withheld_candidate_count integer NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_workflow_cross_file_review_result_run UNIQUE (workflow_run_id),
    CONSTRAINT uk_sand_ai_workflow_cross_file_review_result_invocation UNIQUE (invocation_id),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_attempt CHECK (attempt_no > 0),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_snapshot
        CHECK (execution_snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_input
        CHECK (input_sha256 ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_payload_hash
        CHECK (payload_sha256 ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_source_count
        CHECK (field_source_count >= 0),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_withheld_count
        CHECK (withheld_candidate_count >= 0),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_payload
        CHECK (COALESCE(
            jsonb_typeof(payload) = 'object'
            AND jsonb_typeof(payload->'conflicts') = 'array'
            AND jsonb_typeof(payload->'items_to_verify') = 'array',
            false
        ))
);

CREATE TABLE sand_ai_workflow_cross_file_review_source (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    result_id bigint NOT NULL REFERENCES sand_ai_workflow_cross_file_review_result(id) ON DELETE RESTRICT,
    field_path varchar(1024) NOT NULL,
    source_block_id bigint NOT NULL REFERENCES sand_ai_source_block(id) ON DELETE RESTRICT,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_workflow_cross_file_review_source
        UNIQUE (result_id, field_path, source_block_id),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_source_path CHECK (left(field_path, 1) = '/')
);

CREATE INDEX idx_sand_ai_workflow_cross_file_review_source_block
    ON sand_ai_workflow_cross_file_review_source (source_block_id);

CREATE TABLE sand_ai_workflow_cross_file_review_withheld (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    result_id bigint NOT NULL REFERENCES sand_ai_workflow_cross_file_review_result(id) ON DELETE RESTRICT,
    workflow_run_file_id bigint NOT NULL REFERENCES sand_ai_workflow_run_file(id) ON DELETE RESTRICT,
    field_path varchar(1024) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_workflow_cross_file_review_withheld
        UNIQUE (result_id, workflow_run_file_id, field_path),
    CONSTRAINT ck_sand_ai_workflow_cross_file_review_withheld_path CHECK (left(field_path, 1) = '/')
);

CREATE INDEX idx_sand_ai_workflow_cross_file_review_withheld_file
    ON sand_ai_workflow_cross_file_review_withheld (workflow_run_file_id);

CREATE FUNCTION sand_ai_workflow_cross_file_review_evidence_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'SandAI cross-file review evidence is immutable';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER sand_ai_workflow_cross_file_review_result_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_workflow_cross_file_review_result
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_cross_file_review_evidence_immutable();

CREATE TRIGGER sand_ai_workflow_cross_file_review_source_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_workflow_cross_file_review_source
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_cross_file_review_evidence_immutable();

CREATE TRIGGER sand_ai_workflow_cross_file_review_withheld_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_workflow_cross_file_review_withheld
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_cross_file_review_evidence_immutable();

CREATE FUNCTION sand_ai_workflow_cross_file_review_count_guard() RETURNS trigger AS $$
DECLARE
    target_result_id bigint;
    expected_sources integer;
    expected_withheld integer;
    actual_sources bigint;
    actual_withheld bigint;
BEGIN
    IF TG_TABLE_NAME = 'sand_ai_workflow_cross_file_review_result' THEN
        target_result_id := NEW.id;
    ELSE
        target_result_id := NEW.result_id;
    END IF;
    SELECT field_source_count, withheld_candidate_count INTO expected_sources, expected_withheld
    FROM sand_ai_workflow_cross_file_review_result WHERE id = target_result_id;
    SELECT count(*) INTO actual_sources
    FROM sand_ai_workflow_cross_file_review_source WHERE result_id = target_result_id;
    SELECT count(*) INTO actual_withheld
    FROM sand_ai_workflow_cross_file_review_withheld WHERE result_id = target_result_id;
    IF expected_sources IS NULL
        OR actual_sources <> expected_sources
        OR actual_withheld <> expected_withheld THEN
        RAISE EXCEPTION 'SandAI cross-file review evidence count does not match sealed result';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER sand_ai_workflow_cross_file_review_result_count
    AFTER INSERT ON sand_ai_workflow_cross_file_review_result
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_cross_file_review_count_guard();

CREATE CONSTRAINT TRIGGER sand_ai_workflow_cross_file_review_source_count
    AFTER INSERT ON sand_ai_workflow_cross_file_review_source
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_cross_file_review_count_guard();

CREATE CONSTRAINT TRIGGER sand_ai_workflow_cross_file_review_withheld_count
    AFTER INSERT ON sand_ai_workflow_cross_file_review_withheld
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_cross_file_review_count_guard();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250044_material_cross_file_review', 'df91e5a11358f9eed85c440f3760e6d03da0a3927c24bab14393e341fee8c620', CURRENT_TIMESTAMP(0));
-- Migration 202609250045_material_result_receipt_consumers (sha256 5dd11b388bc155bc7b4e25209de355c74b8bea5e41d224ab4466e72cacfced78)
ALTER TABLE sand_ai_invocation_outcome_receipt
    DROP CONSTRAINT sand_ai_outcome_receipt_consumer_ack_check;

ALTER TABLE sand_ai_invocation_outcome_receipt
    ADD CONSTRAINT sand_ai_outcome_receipt_consumer_ack_check CHECK (
        (consumer_ack_time IS NULL AND consumer_ref IS NULL AND retain_until IS NULL)
        OR
        (consumer_ack_time IS NOT NULL
            AND consumer_ref IS NOT NULL
            AND consumer_ref ~ '^(artifact|file_result|cross_file_review):[1-9][0-9]*$'
            AND retain_until IS NOT NULL
            AND retain_until > consumer_ack_time)
    );
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250045_material_result_receipt_consumers', '5dd11b388bc155bc7b4e25209de355c74b8bea5e41d224ab4466e72cacfced78', CURRENT_TIMESTAMP(0));
-- Migration 202609250046_cross_file_review_request_options (sha256 7306357f34cb997005cdd02184db6329fe09e30a24863d1f2ce63a6286007163)
ALTER TABLE sand_ai_workflow_cross_file_review
    ADD COLUMN max_output_tokens integer NULL,
    ADD COLUMN output_token_parameter varchar(32) NULL;

ALTER TABLE sand_ai_workflow_cross_file_review
    ADD CONSTRAINT ck_sand_ai_workflow_cross_file_review_request_options
        CHECK ((max_output_tokens IS NULL AND output_token_parameter IS NULL)
            OR (max_output_tokens IS NOT NULL
                AND output_token_parameter IS NOT NULL
                AND max_output_tokens BETWEEN 1 AND 131072
                AND output_token_parameter IN ('max_tokens', 'max_completion_tokens')));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250046_cross_file_review_request_options', '7306357f34cb997005cdd02184db6329fe09e30a24863d1f2ce63a6286007163', CURRENT_TIMESTAMP(0));
-- Migration 202609250047_audio_segment_review (sha256 130b7226a689773eedd95f5e201ae7136a36763a5a8d44768b4860f7e5da6ce2)
-- Source-only migration. Apply only after explicit authorization for sandai.
CREATE TABLE sand_ai_audio_segment_review (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    audio_task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    revision integer NOT NULL,
    expected_revision integer NOT NULL,
    segment_index integer NOT NULL,
    base_sha256 char(64) NOT NULL,
    review_text varchar(10000) NOT NULL,
    owner_scope char(64) NOT NULL,
    request_id varchar(96) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_audio_segment_review_revision UNIQUE (audio_task_id, revision),
    CONSTRAINT uk_sand_ai_audio_segment_review_request UNIQUE (request_id),
    CONSTRAINT ck_sand_ai_audio_segment_review_revision CHECK (revision > 0 AND expected_revision = revision - 1),
    CONSTRAINT ck_sand_ai_audio_segment_review_index CHECK (segment_index >= 0),
    CONSTRAINT ck_sand_ai_audio_segment_review_base CHECK (base_sha256 ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_audio_segment_review_owner CHECK (owner_scope ~ '^[a-f0-9]{64}$')
);

CREATE FUNCTION sand_ai_audio_segment_review_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'SandAI audio segment review evidence is immutable';
END;
$$ LANGUAGE plpgsql;

-- Revisions cannot be rewritten. A future authorized lifecycle cleanup may
-- delete derived review text before deleting the underlying audio task.
CREATE TRIGGER sand_ai_audio_segment_review_immutable
    BEFORE UPDATE ON sand_ai_audio_segment_review
    FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_segment_review_immutable();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250047_audio_segment_review', '130b7226a689773eedd95f5e201ae7136a36763a5a8d44768b4860f7e5da6ce2', CURRENT_TIMESTAMP(0));
-- Migration 202609250048_skill_case_evaluation (sha256 17aa7e1a28db5aa9d6a0805a33f31f1c1431bfe45c255375cb3f6ac62f0b7dcf)
-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_skill_case (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    skill_version_id bigint NOT NULL REFERENCES sand_ai_skill_version(id),
    case_no integer NOT NULL CHECK (case_no BETWEEN 1 AND 100),
    name varchar(100) NOT NULL CHECK (length(btrim(name)) > 0),
    input jsonb NOT NULL CHECK (jsonb_typeof(input) = 'object'),
    expected_criteria varchar(1000) NOT NULL CHECK (length(btrim(expected_criteria)) > 0),
    state varchar(16) NOT NULL DEFAULT 'active' CHECK (state IN ('active', 'retired')),
    create_request_id varchar(128) NOT NULL,
    created_by varchar(64) NOT NULL,
    retired_by varchar(64) NULL,
    retire_reason varchar(1000) NULL,
    retire_request_id varchar(128) NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    retire_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_skill_case_version_no_uq UNIQUE (skill_version_id, case_no),
    CONSTRAINT sand_ai_skill_case_create_request_uq UNIQUE (skill_version_id, create_request_id),
    CONSTRAINT sand_ai_skill_case_retire_fields_ck CHECK (
        (state = 'active' AND retired_by IS NULL AND retire_reason IS NULL AND retire_request_id IS NULL AND retire_time IS NULL)
        OR (state = 'retired' AND retired_by IS NOT NULL AND retire_reason IS NOT NULL
            AND retire_request_id IS NOT NULL AND retire_time IS NOT NULL)
    )
);

CREATE FUNCTION sand_ai_skill_case_guard() RETURNS trigger AS $$
DECLARE
    version_state varchar(16);
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'SandAI Skill case cannot be deleted';
    END IF;
    SELECT state INTO version_state FROM sand_ai_skill_version
    WHERE id = NEW.skill_version_id FOR UPDATE;
    IF version_state IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'SandAI Skill case belongs to a non-draft version';
    END IF;
    IF TG_OP = 'INSERT' THEN
        RETURN NEW;
    END IF;
    IF OLD.state <> 'active' OR NEW.state <> 'retired'
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.skill_version_id IS DISTINCT FROM OLD.skill_version_id
        OR NEW.case_no IS DISTINCT FROM OLD.case_no
        OR NEW.name IS DISTINCT FROM OLD.name
        OR NEW.input IS DISTINCT FROM OLD.input
        OR NEW.expected_criteria IS DISTINCT FROM OLD.expected_criteria
        OR NEW.create_request_id IS DISTINCT FROM OLD.create_request_id
        OR NEW.created_by IS DISTINCT FROM OLD.created_by
        OR NEW.create_time IS DISTINCT FROM OLD.create_time THEN
        RAISE EXCEPTION 'SandAI Skill case content is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER sand_ai_skill_case_guard
    BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_skill_case
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_case_guard();

CREATE TABLE sand_ai_skill_case_evaluation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    case_id bigint NOT NULL REFERENCES sand_ai_skill_case(id),
    skill_run_id bigint NOT NULL REFERENCES sand_ai_skill_run(id),
    verdict varchar(8) NOT NULL CHECK (verdict IN ('pass', 'fail')),
    note varchar(1000) NOT NULL DEFAULT '',
    reviewer_ref varchar(64) NOT NULL,
    request_id varchar(128) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_skill_case_evaluation_request_uq UNIQUE (case_id, request_id)
);
CREATE INDEX sand_ai_skill_case_evaluation_latest_idx
    ON sand_ai_skill_case_evaluation(case_id, id DESC);

CREATE FUNCTION sand_ai_skill_case_evaluation_guard() RETURNS trigger AS $$
DECLARE
    fixed_version_id bigint;
    fixed_skill_id bigint;
    fixed_input jsonb;
    case_create_time timestamp(0) without time zone;
    actual_version_id bigint;
    actual_skill_id bigint;
    actual_input jsonb;
    actual_kind varchar(16);
    actual_state varchar(16);
    actual_create_time timestamp(0) without time zone;
    version_state varchar(16);
    case_state varchar(16);
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'SandAI Skill case evaluation is immutable';
    END IF;
    SELECT skill_version_id, input, state, create_time
      INTO fixed_version_id, fixed_input, case_state, case_create_time
    FROM sand_ai_skill_case WHERE id = NEW.case_id;
    SELECT state, skill_id INTO version_state, fixed_skill_id FROM sand_ai_skill_version
    WHERE id = fixed_version_id FOR UPDATE;
    SELECT skill_version_id, skill_id, input, run_kind, state, create_time
      INTO actual_version_id, actual_skill_id, actual_input, actual_kind, actual_state, actual_create_time
    FROM sand_ai_skill_run WHERE id = NEW.skill_run_id;
    IF fixed_version_id IS NULL OR version_state IS DISTINCT FROM 'draft'
        OR case_state IS DISTINCT FROM 'active'
        OR actual_version_id IS DISTINCT FROM fixed_version_id
        OR actual_skill_id IS DISTINCT FROM fixed_skill_id
        OR actual_input IS DISTINCT FROM fixed_input
        OR actual_kind IS DISTINCT FROM 'test'
        OR actual_state IS DISTINCT FROM 'succeeded'
        OR actual_create_time < case_create_time THEN
        RAISE EXCEPTION 'SandAI Skill case evaluation requires the matching successful test';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER sand_ai_skill_case_evaluation_guard
    BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_skill_case_evaluation
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_case_evaluation_guard();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250048_skill_case_evaluation', '17aa7e1a28db5aa9d6a0805a33f31f1c1431bfe45c255375cb3f6ac62f0b7dcf', CURRENT_TIMESTAMP(0));
-- Migration 202609250049_knowledge_answer_invocation (sha256 f03c1c4bb223a55df752e3ce6a73e832fb53f5f2477006a56c7c16550a2035d5)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_invocation
    ADD COLUMN knowledge_publication_id bigint NULL REFERENCES sand_ai_knowledge_publication(id),
    ADD COLUMN knowledge_base_version_id bigint NULL REFERENCES sand_ai_knowledge_base_version(id),
    ADD COLUMN knowledge_source_scope_sha256 char(64) NULL,
    ADD CONSTRAINT sand_ai_invocation_knowledge_answer_ck CHECK (
        (knowledge_publication_id IS NULL AND knowledge_base_version_id IS NULL AND knowledge_source_scope_sha256 IS NULL)
        OR (knowledge_publication_id IS NOT NULL AND knowledge_base_version_id IS NOT NULL
            AND knowledge_source_scope_sha256 ~ '^[a-f0-9]{64}$')
    );

CREATE INDEX sand_ai_invocation_knowledge_answer_idx
    ON sand_ai_invocation (knowledge_publication_id, knowledge_base_version_id, id)
    WHERE knowledge_publication_id IS NOT NULL;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250049_knowledge_answer_invocation', 'f03c1c4bb223a55df752e3ce6a73e832fb53f5f2477006a56c7c16550a2035d5', CURRENT_TIMESTAMP(0));
-- Migration 202609250050_knowledge_conversation (sha256 2588b6f01d25d60764f73d77f57d07292e974a0e1ca35a43f5cc8183f2272aca)
-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_knowledge_conversation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    public_id char(32) NOT NULL UNIQUE CHECK (public_id ~ '^[a-f0-9]{32}$'),
    environment_id bigint NOT NULL,
    owner_scope char(64) NOT NULL CHECK (owner_scope ~ '^[a-f0-9]{64}$'),
    publication_id bigint NOT NULL REFERENCES sand_ai_knowledge_publication(id),
    knowledge_base_version_id bigint NOT NULL REFERENCES sand_ai_knowledge_base_version(id),
    source_scope_sha256 char(64) NOT NULL CHECK (source_scope_sha256 ~ '^[a-f0-9]{64}$'),
    data_class varchar(32) NULL CHECK (data_class IS NULL OR data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$'),
    create_request_id varchar(96) NOT NULL UNIQUE,
    state varchar(16) NOT NULL DEFAULT 'active' CHECK (state IN ('active', 'closed')),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);
CREATE INDEX sand_ai_knowledge_conversation_owner_idx
    ON sand_ai_knowledge_conversation(environment_id, owner_scope, id DESC);

CREATE TABLE sand_ai_knowledge_conversation_turn (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    conversation_id bigint NOT NULL REFERENCES sand_ai_knowledge_conversation(id),
    sequence_no integer NOT NULL CHECK (sequence_no > 0),
    request_id varchar(96) NOT NULL UNIQUE,
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    query varchar(2000) NOT NULL CHECK (length(btrim(query)) > 0),
    model varchar(128) NOT NULL CHECK (length(btrim(model)) > 0),
    max_output_tokens integer NOT NULL CHECK (max_output_tokens BETWEEN 1 AND 2048),
    state varchar(16) NOT NULL DEFAULT 'pending'
        CHECK (state IN ('pending', 'answered', 'needs_review', 'no_sources', 'failed')),
    answer varchar(16000) NULL,
    result jsonb NULL CHECK (result IS NULL OR jsonb_typeof(result) = 'object'),
    invocation_request_id varchar(96) NULL,
    claim_token char(32) NULL CHECK (claim_token IS NULL OR claim_token ~ '^[a-f0-9]{32}$'),
    claim_until timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    complete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_knowledge_conversation_turn_no_uq UNIQUE (conversation_id, sequence_no),
    CONSTRAINT sand_ai_knowledge_conversation_turn_state_ck CHECK (
        (state = 'pending' AND answer IS NULL AND result IS NULL AND complete_time IS NULL
            AND claim_token IS NOT NULL AND claim_until IS NOT NULL)
        OR (state <> 'pending' AND result IS NOT NULL AND complete_time IS NOT NULL
            AND claim_token IS NULL AND claim_until IS NULL)
    )
);
CREATE INDEX sand_ai_knowledge_conversation_turn_page_idx
    ON sand_ai_knowledge_conversation_turn(conversation_id, sequence_no DESC);
CREATE UNIQUE INDEX sand_ai_knowledge_conversation_one_pending_idx
    ON sand_ai_knowledge_conversation_turn(conversation_id) WHERE state='pending';
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250050_knowledge_conversation', '2588b6f01d25d60764f73d77f57d07292e974a0e1ca35a43f5cc8183f2272aca', CURRENT_TIMESTAMP(0));
-- Migration 202609250051_agent_definition (sha256 4151b4add345d3c2fb5299497163dc13e88459af75012153794d3dd7a7146b5b)
-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_agent_definition (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    code varchar(64) NOT NULL CHECK (code ~ '^[a-z][a-z0-9_-]{0,63}$'),
    name varchar(128) NOT NULL CHECK (length(btrim(name)) > 0),
    state varchar(16) NOT NULL DEFAULT 'draft' CHECK (state IN ('draft', 'published')),
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    active_version_id bigint NULL,
    created_by varchar(64) NOT NULL,
    updated_by varchar(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_agent_definition_environment_code_uq UNIQUE (environment_id, code),
    CONSTRAINT sand_ai_agent_definition_state_ck CHECK (
        (state = 'draft' AND active_version_id IS NULL)
        OR (state = 'published' AND active_version_id IS NOT NULL)
    )
);

CREATE TABLE sand_ai_agent_version (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    agent_definition_id bigint NOT NULL REFERENCES sand_ai_agent_definition(id),
    version_no integer NOT NULL CHECK (version_no > 0),
    state varchar(16) NOT NULL DEFAULT 'draft' CHECK (state IN ('draft', 'published')),
    model varchar(128) NOT NULL CHECK (length(btrim(model)) > 0),
    instructions text NOT NULL CHECK (length(btrim(instructions)) > 0 AND length(instructions) <= 16000),
    tools jsonb NOT NULL CHECK (jsonb_typeof(tools) = 'array'),
    review_required boolean NOT NULL DEFAULT false,
    max_output_tokens integer NULL CHECK (max_output_tokens BETWEEN 1 AND 2048),
    snapshot_sha256 char(64) NOT NULL CHECK (snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    created_by varchar(64) NOT NULL,
    updated_by varchar(64) NOT NULL,
    published_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_agent_version_definition_no_uq UNIQUE (agent_definition_id, version_no),
    CONSTRAINT sand_ai_agent_version_definition_id_uq UNIQUE (agent_definition_id, id),
    CONSTRAINT sand_ai_agent_version_published_time_ck CHECK (
        (state = 'draft' AND published_time IS NULL)
        OR (state = 'published' AND published_time IS NOT NULL)
    )
);
CREATE UNIQUE INDEX sand_ai_agent_version_one_draft_idx
    ON sand_ai_agent_version(agent_definition_id) WHERE state = 'draft';
ALTER TABLE sand_ai_agent_definition ADD CONSTRAINT sand_ai_agent_definition_active_version_fk
    FOREIGN KEY (id, active_version_id)
    REFERENCES sand_ai_agent_version(agent_definition_id, id);

CREATE TABLE sand_ai_agent_operation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    operation_code varchar(24) NOT NULL CHECK (operation_code IN ('draft', 'publish', 'disable')),
    idempotency_key varchar(128) NOT NULL CHECK (length(btrim(idempotency_key)) > 0),
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    agent_definition_id bigint NOT NULL REFERENCES sand_ai_agent_definition(id),
    agent_version_id bigint NULL REFERENCES sand_ai_agent_version(id),
    response_snapshot jsonb NOT NULL CHECK (jsonb_typeof(response_snapshot) = 'object'),
    created_by varchar(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_agent_operation_key_uq UNIQUE (environment_id, operation_code, idempotency_key)
);
CREATE INDEX sand_ai_agent_operation_definition_idx
    ON sand_ai_agent_operation(agent_definition_id, id DESC);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250051_agent_definition', '4151b4add345d3c2fb5299497163dc13e88459af75012153794d3dd7a7146b5b', CURRENT_TIMESTAMP(0));
-- Migration 202609250052_channel_binding (sha256 3972d8db2b5025b7beeff4fa659ce48ae52760c2c39aae46befb0e307f0ff2ae)
-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_channel_binding (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    code varchar(64) NOT NULL CHECK (code ~ '^[a-z][a-z0-9_-]{0,63}$'),
    name varchar(128) NOT NULL CHECK (length(btrim(name)) > 0),
    agent_definition_id bigint NOT NULL REFERENCES sand_ai_agent_definition(id),
    application_id varchar(64) NOT NULL CHECK (length(btrim(application_id)) > 0),
    workload_client_id varchar(64) NOT NULL CHECK (length(btrim(workload_client_id)) > 0),
    revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by varchar(64) NOT NULL,
    updated_by varchar(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_channel_binding_environment_code_uq UNIQUE (environment_id, code)
);
CREATE INDEX sand_ai_channel_binding_definition_idx ON sand_ai_channel_binding(agent_definition_id);

CREATE TABLE sand_ai_channel_operation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    operation_code varchar(16) NOT NULL CHECK (operation_code IN ('save', 'disable')),
    idempotency_key varchar(128) NOT NULL CHECK (length(btrim(idempotency_key)) > 0),
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    channel_binding_id bigint NOT NULL REFERENCES sand_ai_channel_binding(id),
    response_snapshot jsonb NOT NULL CHECK (jsonb_typeof(response_snapshot) = 'object'),
    created_by varchar(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_channel_operation_key_uq UNIQUE (environment_id, operation_code, idempotency_key)
);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250052_channel_binding', '3972d8db2b5025b7beeff4fa659ce48ae52760c2c39aae46befb0e307f0ff2ae', CURRENT_TIMESTAMP(0));

-- Migration 202609250053_feishu_delivery (sha256 47bfd02f424c0b9f1ab6690a7d2d80ea6eaab2ea53c05593f70d465674325734)
-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_feishu_delivery (
    task_id bigint PRIMARY KEY REFERENCES sand_ai_task_run(id),
    environment_id bigint NOT NULL,
    channel_binding_id bigint NOT NULL REFERENCES sand_ai_channel_binding(id),
    channel_code varchar(64) NOT NULL,
    channel_revision integer NOT NULL CHECK (channel_revision > 0),
    message_scope char(64) NOT NULL CHECK (message_scope ~ '^[a-f0-9]{64}$'),
    chat_envelope jsonb NOT NULL CHECK (jsonb_typeof(chat_envelope) = 'object'),
    state varchar(16) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','sending','sent','failed')),
    attempt_count smallint NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 5),
    available_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    lease_token varchar(64) NULL,
    lease_until timestamp(0) without time zone NULL,
    sent_message_scope char(64) NULL CHECK (sent_message_scope ~ '^[a-f0-9]{64}$'),
    error_code varchar(64) NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);
CREATE INDEX sand_ai_feishu_delivery_ready_idx ON sand_ai_feishu_delivery(state,available_at,task_id)
    WHERE state IN ('pending','sending');
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250053_feishu_delivery', '47bfd02f424c0b9f1ab6690a7d2d80ea6eaab2ea53c05593f70d465674325734', CURRENT_TIMESTAMP(0));
-- Migration 202609250054_agent_knowledge_binding (sha256 27bf37d74af79d46683d34844edaebd2f445c910b5dbaf701d1e35da4df2fc3a)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_agent_version
    ADD COLUMN knowledge_base_code varchar(64) NULL
    CONSTRAINT sand_ai_agent_version_knowledge_code_ck
        CHECK (knowledge_base_code ~ '^[a-z][a-z0-9_-]{0,63}$');
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250054_agent_knowledge_binding', '27bf37d74af79d46683d34844edaebd2f445c910b5dbaf701d1e35da4df2fc3a', CURRENT_TIMESTAMP(0));
-- Migration 202609250055_channel_delivery_retry (sha256 8b7a1dd9a24d68c1a0bc38cf1e639eaf39668a0f5213d5d6b8827a417f7e3550)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_channel_operation DROP CONSTRAINT sand_ai_channel_operation_operation_code_check;
ALTER TABLE sand_ai_channel_operation ADD CONSTRAINT sand_ai_channel_operation_operation_code_check
    CHECK (operation_code IN ('save', 'disable', 'retry_delivery'));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609250055_channel_delivery_retry', '8b7a1dd9a24d68c1a0bc38cf1e639eaf39668a0f5213d5d6b8827a417f7e3550', CURRENT_TIMESTAMP(0));
-- Migration 202609260056_material_missing_source (sha256 f73b6c9f71dc268ef888098611adc02560adb7a4c023f8ebf8c59bde833bfbfe)
-- A parsed file can have no supported factual claim. Preserve its model call,
-- immutable result and usage without manufacturing a field citation.
ALTER TABLE sand_ai_workflow_file_result
    DROP CONSTRAINT ck_sand_ai_workflow_file_result_source_count;

ALTER TABLE sand_ai_workflow_file_result
    ADD CONSTRAINT ck_sand_ai_workflow_file_result_source_count
    CHECK (field_source_count >= 0);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260056_material_missing_source', 'f73b6c9f71dc268ef888098611adc02560adb7a4c023f8ebf8c59bde833bfbfe', CURRENT_TIMESTAMP(0));
-- Migration 202609260057_audio_review_speaker (sha256 dda646d3675ec8fc58382b4350461f3eafcda2d159524209dafdb3d7922f27a9)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_audio_segment_review
    ADD COLUMN speaker_changed boolean NOT NULL DEFAULT false,
    ADD COLUMN review_speaker varchar(16) NULL;

ALTER TABLE sand_ai_audio_segment_review
    ADD CONSTRAINT ck_sand_ai_audio_segment_review_speaker
    CHECK (
        (speaker_changed AND (review_speaker IS NULL OR review_speaker ~ '^speaker_[1-9][0-9]{0,3}$'))
        OR (NOT speaker_changed AND review_speaker IS NULL)
    );
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260057_audio_review_speaker', 'dda646d3675ec8fc58382b4350461f3eafcda2d159524209dafdb3d7922f27a9', CURRENT_TIMESTAMP(0));
-- Migration 202609260058_speech_run (sha256 cc989be2894849a8adc9e644a5708d28835e057a83b1d77c078e2b7805e4aa31)
-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_speech_run (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    public_id char(32) NOT NULL UNIQUE CHECK (public_id ~ '^[a-f0-9]{32}$'),
    environment_id bigint NOT NULL,
    owner_scope char(64) NOT NULL CHECK (owner_scope ~ '^[a-f0-9]{64}$'),
    identity_snapshot jsonb NOT NULL CHECK (jsonb_typeof(identity_snapshot) = 'object'),
    request_id varchar(96) NOT NULL UNIQUE,
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    text_sha256 char(64) NOT NULL CHECK (text_sha256 ~ '^[a-f0-9]{64}$'),
    input_characters integer NOT NULL CHECK (input_characters BETWEEN 1 AND 4096),
    voice varchar(64) NOT NULL,
    provider_id bigint NOT NULL REFERENCES sand_ai_provider(id),
    model_id bigint NOT NULL REFERENCES sand_ai_model(id),
    deployment_id bigint NOT NULL REFERENCES sand_ai_model_deployment(id),
    profile_id bigint NOT NULL,
    profile_revision integer NOT NULL CHECK (profile_revision > 0),
    route_id bigint NOT NULL,
    credential_digest char(64) NOT NULL CHECK (credential_digest ~ '^[a-f0-9]{64}$'),
    storage_driver varchar(32) NOT NULL,
    object_key text NOT NULL CHECK (length(object_key) BETWEEN 1 AND 512),
    state varchar(16) NOT NULL DEFAULT 'prepared'
        CHECK (state IN ('prepared', 'dispatching', 'awaiting_usage', 'succeeded', 'failed', 'ambiguous')),
    audio_sha256 char(64) NULL CHECK (audio_sha256 IS NULL OR audio_sha256 ~ '^[a-f0-9]{64}$'),
    audio_bytes integer NULL CHECK (audio_bytes IS NULL OR audio_bytes BETWEEN 32 AND 8388608),
    error_code varchar(96) NULL,
    dispatch_started_at timestamp(0) without time zone NULL,
    complete_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT ck_sand_ai_speech_run_result CHECK (
        (state = 'prepared' AND dispatch_started_at IS NULL AND complete_time IS NULL
            AND audio_sha256 IS NULL AND audio_bytes IS NULL AND error_code IS NULL)
        OR (state = 'dispatching' AND dispatch_started_at IS NOT NULL AND complete_time IS NULL
            AND audio_sha256 IS NULL AND audio_bytes IS NULL AND error_code IS NULL)
        OR (state = 'awaiting_usage' AND dispatch_started_at IS NOT NULL AND complete_time IS NULL
            AND audio_sha256 IS NOT NULL AND audio_bytes IS NOT NULL AND error_code IS NULL)
        OR (state = 'succeeded' AND dispatch_started_at IS NOT NULL AND complete_time IS NOT NULL
            AND audio_sha256 IS NOT NULL AND audio_bytes IS NOT NULL AND error_code IS NULL)
        OR (state IN ('failed', 'ambiguous') AND complete_time IS NOT NULL
            AND audio_sha256 IS NULL AND audio_bytes IS NULL AND error_code IS NOT NULL)
    )
);
CREATE INDEX sand_ai_speech_run_owner_idx
    ON sand_ai_speech_run(environment_id, owner_scope, id DESC);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260058_speech_run', 'cc989be2894849a8adc9e644a5708d28835e057a83b1d77c078e2b7805e4aa31', CURRENT_TIMESTAMP(0));
-- Migration 202609260059_speech_price_meter (sha256 0afbe12c952d9f8c3b924c6c6f565081b7f26c58598bf8cff4fa71458ad92570)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_price_rate
    DROP CONSTRAINT ck_sand_ai_price_rate_meter;
ALTER TABLE sand_ai_price_rate
    ADD CONSTRAINT ck_sand_ai_price_rate_meter
    CHECK (meter_code IN ('input_tokens', 'output_tokens', 'audio_seconds', 'input_characters'));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260059_speech_price_meter', '0afbe12c952d9f8c3b924c6c6f565081b7f26c58598bf8cff4fa71458ad92570', CURRENT_TIMESTAMP(0));
-- Migration 202609260060_speech_call_start_price (sha256 445b8244778a530ca671071b196fd018aa678af687b8a4a4dc2de04ca40c2ac3)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_speech_run
    ADD COLUMN called_at timestamp(0) without time zone NULL,
    ADD COLUMN pricing_state varchar(16) NULL,
    ADD COLUMN price_revision_id bigint NULL REFERENCES sand_ai_price_revision(id),
    ADD COLUMN price_snapshot jsonb NULL;
ALTER TABLE sand_ai_speech_run
    ADD CONSTRAINT ck_sand_ai_speech_run_call_start_price CHECK (
        (dispatch_started_at IS NULL AND called_at IS NULL AND pricing_state IS NULL
            AND price_revision_id IS NULL AND price_snapshot IS NULL)
        OR (dispatch_started_at IS NOT NULL AND called_at IS NOT NULL
            AND jsonb_typeof(price_snapshot) = 'object'
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL)))
    );
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260060_speech_call_start_price', '445b8244778a530ca671071b196fd018aa678af687b8a4a4dc2de04ca40c2ac3', CURRENT_TIMESTAMP(0));
-- Migration 202609260061_speech_usage_event (sha256 f0b1168aa0ae936ed67398373c573c1032000fd22e6cad15d413ed2b4704a7ab)
-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE UNIQUE INDEX uq_sand_ai_speech_run_environment_v061 ON sand_ai_speech_run(id, environment_id);
ALTER TABLE sand_ai_usage_event ADD COLUMN speech_run_id bigint NULL;
ALTER TABLE sand_ai_usage_event ADD CONSTRAINT fk_sand_ai_usage_event_speech_environment_v061
    FOREIGN KEY (speech_run_id, environment_id) REFERENCES sand_ai_speech_run(id, environment_id) NOT VALID;
CREATE UNIQUE INDEX uq_sand_ai_usage_event_speech_run_v061
    ON sand_ai_usage_event(speech_run_id) WHERE speech_run_id IS NOT NULL;

ALTER TABLE sand_ai_usage_event DROP CONSTRAINT IF EXISTS ck_sand_ai_usage_event_tool_metering;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT ck_sand_ai_usage_event_tool_metering CHECK ((
        (speech_run_id IS NULL AND audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND metering_unit IS NULL AND metering_quantity IS NULL)
        OR (speech_run_id IS NULL AND audio_task_run_id IS NULL AND tool_run_id IS NOT NULL AND mcp_snapshot_id IS NULL AND tool_id IS NOT NULL AND tool_version_id IS NOT NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND btrim(deployment_snapshot->>'adapter') <> ''
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'runtime'
            AND ((deployment_snapshot->>'adapter' = 'standard_readonly_http' AND metering_quantity = 1)
                OR (deployment_snapshot->>'adapter' = 'mcp_streamable_http' AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity)))
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (speech_run_id IS NULL AND audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NOT NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity) AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND deployment_snapshot->>'adapter' = 'mcp_streamable_http'
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'discovery'
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (speech_run_id IS NULL AND audio_task_run_id IS NOT NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND invocation_id IS NULL
            AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
            AND service_action = 'sand_ai.audio.transcription' AND source_kind = 'capability' AND capability_code = 'audio_transcription'
            AND metering_unit = 'audio_seconds' AND metering_quantity > 0
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0 AND usage_state = 'measured'
            AND reconciliation_state = 'unreconciled' AND reconciled_amount IS NULL
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL AND metered_amount IS NOT NULL AND currency IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))
        )
        OR (speech_run_id IS NOT NULL AND audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL
            AND tool_id IS NULL AND tool_version_id IS NULL AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL
            AND invocation_id IS NULL AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
            AND service_action = 'sand_ai.audio.speech' AND source_kind = 'capability' AND capability_code = 'audio_speech'
            AND metering_unit = 'input_characters' AND metering_quantity BETWEEN 1 AND 4096
            AND metering_quantity = trunc(metering_quantity)
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0 AND usage_state = 'measured'
            AND reconciliation_state = 'unreconciled' AND reconciled_amount IS NULL
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL AND metered_amount IS NOT NULL AND currency IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))
        )
    ) IS TRUE) NOT VALID;

-- The immutable event must exactly match the completed private speech run.
CREATE FUNCTION sand_ai_speech_usage_guard_v061() RETURNS trigger AS $$
DECLARE
    speech sand_ai_speech_run%ROWTYPE;
    rate jsonb;
BEGIN
    IF NEW.speech_run_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO speech FROM sand_ai_speech_run
        WHERE id = NEW.speech_run_id AND environment_id = NEW.environment_id FOR UPDATE;
    IF NOT FOUND OR speech.state <> 'awaiting_usage'
       OR speech.audio_sha256 IS NULL OR speech.audio_bytes IS NULL
       OR NEW.organization_id IS DISTINCT FROM (speech.identity_snapshot->>'organization_id')::bigint
       OR NEW.application_id IS DISTINCT FROM (speech.identity_snapshot->>'application_id')::bigint
       OR NEW.workload_client_id IS DISTINCT FROM (speech.identity_snapshot->>'workload_client_id')::bigint
       OR NEW.identity_snapshot IS DISTINCT FROM speech.identity_snapshot->'identity_snapshot'
       OR NEW.provider_id IS DISTINCT FROM speech.provider_id
       OR NEW.model_id IS DISTINCT FROM speech.model_id
       OR NEW.deployment_id IS DISTINCT FROM speech.deployment_id
       OR NEW.provider_snapshot IS DISTINCT FROM jsonb_build_object('id', speech.provider_id)
       OR NEW.model_snapshot IS DISTINCT FROM jsonb_build_object('id', speech.model_id)
       OR NEW.deployment_snapshot IS DISTINCT FROM jsonb_build_object(
           'id', speech.deployment_id, 'profile_id', speech.profile_id,
           'profile_revision', speech.profile_revision, 'route_id', speech.route_id)
       OR NEW.metering_quantity IS DISTINCT FROM speech.input_characters::numeric
       OR NEW.price_revision_id IS DISTINCT FROM speech.price_revision_id
       OR NEW.pricing_state IS DISTINCT FROM speech.pricing_state
       OR NEW.price_snapshot IS DISTINCT FROM speech.price_snapshot
       OR NEW.create_time IS DISTINCT FROM speech.called_at
       OR NEW.idempotency_key IS DISTINCT FROM 'speech-run:' || speech.id::text THEN
        RAISE EXCEPTION 'SAND_AI_SPEECH_USAGE_BINDING_INVALID';
    END IF;
    IF speech.pricing_state = 'priced' THEN
        rate := speech.price_snapshot->'rates'->'input_characters';
        IF jsonb_typeof(rate) IS DISTINCT FROM 'object'
           OR COALESCE(rate->>'unit_price', '') !~ '^(0|[1-9][0-9]*)(\.[0-9]{1,8})?$'
           OR COALESCE(rate->>'per_quantity', '') !~ '^[1-9][0-9]*$'
           OR NEW.currency IS DISTINCT FROM speech.price_snapshot->>'currency'
           OR NEW.metered_amount IS DISTINCT FROM round(
               speech.input_characters::numeric * (rate->>'unit_price')::numeric
               / (rate->>'per_quantity')::numeric, 6) THEN
            RAISE EXCEPTION 'SAND_AI_SPEECH_USAGE_PRICE_INVALID';
        END IF;
    ELSIF speech.pricing_state = 'unpriced' THEN
        IF NEW.currency IS NOT NULL OR NEW.metered_amount IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_SPEECH_USAGE_PRICE_INVALID';
        END IF;
    ELSE
        RAISE EXCEPTION 'SAND_AI_SPEECH_USAGE_PRICE_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_speech_usage_guard_v061 BEFORE INSERT ON sand_ai_usage_event
    FOR EACH ROW EXECUTE FUNCTION sand_ai_speech_usage_guard_v061();
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT fk_sand_ai_usage_event_speech_environment_v061;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260061_speech_usage_event', 'f0b1168aa0ae936ed67398373c573c1032000fd22e6cad15d413ed2b4704a7ab', CURRENT_TIMESTAMP(0));
-- Migration 202609260062_speech_budget_owner (sha256 5d5a5962f8b9c1debc620bee30fdaba26dc7073ad7de01170036ba08ff229dcd)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_budget_reservation
    ADD COLUMN speech_run_id bigint NULL REFERENCES sand_ai_speech_run(id);
CREATE UNIQUE INDEX uq_sand_ai_budget_reservation_speech_run_v062
    ON sand_ai_budget_reservation(speech_run_id) WHERE speech_run_id IS NOT NULL;
ALTER TABLE sand_ai_budget_reservation
    ADD CONSTRAINT ck_sand_ai_budget_reservation_owner_v062
    CHECK (speech_run_id IS NULL OR invocation_id IS NULL) NOT VALID;
ALTER TABLE sand_ai_budget_event
    ADD COLUMN speech_run_id bigint NULL REFERENCES sand_ai_speech_run(id);
ALTER TABLE sand_ai_budget_event
    ADD CONSTRAINT ck_sand_ai_budget_event_owner_v062
    CHECK (invocation_id IS NULL OR speech_run_id IS NULL) NOT VALID;
ALTER TABLE sand_ai_speech_run
    ADD COLUMN budget_reservation_id bigint NULL REFERENCES sand_ai_budget_reservation(id);
CREATE UNIQUE INDEX uq_sand_ai_speech_run_budget_reservation_v062
    ON sand_ai_speech_run(budget_reservation_id) WHERE budget_reservation_id IS NOT NULL;

CREATE FUNCTION sand_ai_speech_budget_link_guard_v062() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.budget_reservation_id IS NOT NULL
       AND NEW.budget_reservation_id IS DISTINCT FROM OLD.budget_reservation_id THEN
        RAISE EXCEPTION 'SAND_AI_SPEECH_BUDGET_LINK_IMMUTABLE';
    END IF;
    IF NEW.budget_reservation_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM sand_ai_budget_reservation r
           JOIN sand_ai_budget_policy p ON p.id = r.policy_id
           WHERE r.id = NEW.budget_reservation_id AND r.speech_run_id = NEW.id
             AND r.invocation_id IS NULL AND p.environment_id = NEW.environment_id) THEN
        RAISE EXCEPTION 'SAND_AI_SPEECH_BUDGET_LINK_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_speech_budget_link_v062
    BEFORE UPDATE OF budget_reservation_id ON sand_ai_speech_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_speech_budget_link_guard_v062();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260062_speech_budget_owner', '5d5a5962f8b9c1debc620bee30fdaba26dc7073ad7de01170036ba08ff229dcd', CURRENT_TIMESTAMP(0));
-- Migration 202609260063_knowledge_rerank (sha256 e914eb1a4c5bc41235a5d675f5a3dcc109e6cfe79496a830c332b7db0a79f3f2)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_conversation_turn
    ADD COLUMN rerank boolean NOT NULL DEFAULT false;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260063_knowledge_rerank', 'e914eb1a4c5bc41235a5d675f5a3dcc109e6cfe79496a830c332b7db0a79f3f2', CURRENT_TIMESTAMP(0));
-- Migration 202609260064_knowledge_draft_replacement (sha256 78d429a223bd0f327fe795fcd056ed518b78098b5b3f15ff1b3f7f85329ae53c)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_base_version
    DROP CONSTRAINT sand_ai_knowledge_base_version_state_check;
ALTER TABLE sand_ai_knowledge_base_version
    ADD CONSTRAINT sand_ai_knowledge_base_version_state_check
    CHECK (state IN ('draft', 'published', 'abandoned'));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260064_knowledge_draft_replacement', '78d429a223bd0f327fe795fcd056ed518b78098b5b3f15ff1b3f7f85329ae53c', CURRENT_TIMESTAMP(0));
-- Migration 202609260065_knowledge_embedding_index (sha256 8f2e4bdec21747b1295f160b38456156aa172ecca8237c0c802d2342cccf7b7f)
-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_knowledge_embedding_index (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    knowledge_base_version_id bigint NOT NULL REFERENCES sand_ai_knowledge_base_version(id),
    owner_environment_id bigint NOT NULL,
    profile_id bigint NOT NULL REFERENCES sand_ai_capability_profile(id),
    profile_revision integer NOT NULL CHECK (profile_revision > 0),
    route_id bigint NOT NULL REFERENCES sand_ai_capability_route(id),
    deployment_id bigint NOT NULL REFERENCES sand_ai_model_deployment(id),
    deployment_revision integer NOT NULL CHECK (deployment_revision > 0),
    remote_model varchar(256) NOT NULL,
    provider_id bigint NOT NULL REFERENCES sand_ai_provider(id),
    provider_config_sha256 char(64) NOT NULL CHECK (provider_config_sha256 ~ '^[a-f0-9]{64}$'),
    model_id bigint NOT NULL REFERENCES sand_ai_model(id),
    request_id varchar(160) NOT NULL UNIQUE,
    source_snapshot_sha256 char(64) NOT NULL CHECK (source_snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    source_scope_sha256 char(64) NOT NULL CHECK (source_scope_sha256 ~ '^[a-f0-9]{64}$'),
    state varchar(24) NOT NULL DEFAULT 'building'
        CHECK (state IN ('building', 'ready', 'failed', 'outcome_unknown')),
    dimensions integer NULL CHECK (dimensions BETWEEN 1 AND 4096),
    source_count integer NOT NULL CHECK (source_count > 0),
    error_code varchar(96) NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    ready_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_embedding_index_ready_shape CHECK (
        state <> 'ready' OR (dimensions IS NOT NULL AND ready_time IS NOT NULL)
    )
);
CREATE INDEX sand_ai_knowledge_embedding_index_version_idx
    ON sand_ai_knowledge_embedding_index (knowledge_base_version_id, id DESC);

CREATE TABLE sand_ai_knowledge_embedding (
    index_id bigint NOT NULL REFERENCES sand_ai_knowledge_embedding_index(id) ON DELETE CASCADE,
    source_block_id bigint NOT NULL REFERENCES sand_ai_source_block(id),
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id),
    content_sha256 char(64) NOT NULL CHECK (content_sha256 ~ '^[a-f0-9]{64}$'),
    embedding_sha256 char(64) NOT NULL CHECK (embedding_sha256 ~ '^[a-f0-9]{64}$'),
    embedding real[] NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    PRIMARY KEY (index_id, source_block_id),
    CONSTRAINT sand_ai_embedding_vector_shape CHECK (
        array_ndims(embedding) = 1 AND cardinality(embedding) BETWEEN 1 AND 4096
    )
);

CREATE TABLE sand_ai_knowledge_embedding_active (
    knowledge_base_version_id bigint PRIMARY KEY REFERENCES sand_ai_knowledge_base_version(id),
    index_id bigint NOT NULL UNIQUE REFERENCES sand_ai_knowledge_embedding_index(id),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);

CREATE OR REPLACE FUNCTION sand_ai_guard_knowledge_embedding_index() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    version_row record;
    deployment_row record;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF (NEW.knowledge_base_version_id,NEW.owner_environment_id,NEW.profile_id,
            NEW.profile_revision,NEW.route_id,NEW.deployment_id,NEW.deployment_revision,
            NEW.remote_model,NEW.provider_id,NEW.provider_config_sha256,NEW.model_id,
            NEW.request_id,NEW.source_snapshot_sha256,NEW.source_scope_sha256,NEW.source_count)
           IS DISTINCT FROM
           (OLD.knowledge_base_version_id,OLD.owner_environment_id,OLD.profile_id,
            OLD.profile_revision,OLD.route_id,OLD.deployment_id,OLD.deployment_revision,
            OLD.remote_model,OLD.provider_id,OLD.provider_config_sha256,OLD.model_id,
            OLD.request_id,OLD.source_snapshot_sha256,OLD.source_scope_sha256,OLD.source_count)
           OR OLD.state <> 'building'
           OR (OLD.dimensions IS NOT NULL AND NEW.dimensions IS DISTINCT FROM OLD.dimensions)
           OR NEW.state NOT IN ('building','ready','failed','outcome_unknown') THEN
            RAISE EXCEPTION 'SAND_AI_EMBEDDING_INDEX_IMMUTABLE';
        END IF;
    END IF;
    SELECT v.state,v.snapshot_sha256,v.source_scope_sha256,v.source_count,kb.environment_id
      INTO version_row
      FROM sand_ai_knowledge_base_version v
      JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id
     WHERE v.id=NEW.knowledge_base_version_id;
    IF NOT FOUND OR version_row.state <> 'published'
       OR version_row.environment_id <> NEW.owner_environment_id
       OR version_row.snapshot_sha256 <> NEW.source_snapshot_sha256
       OR version_row.source_scope_sha256 IS DISTINCT FROM NEW.source_scope_sha256
       OR version_row.source_count <> NEW.source_count THEN
        RAISE EXCEPTION 'SAND_AI_EMBEDDING_SOURCE_CHANGED';
    END IF;
    SELECT d.model_id,d.provider_id,d.revision,d.remote_model,m.type INTO deployment_row
      FROM sand_ai_model_deployment d
      JOIN sand_ai_model m ON m.id=d.model_id
     WHERE d.id=NEW.deployment_id;
    IF NOT FOUND OR deployment_row.model_id <> NEW.model_id
       OR deployment_row.provider_id <> NEW.provider_id
       OR deployment_row.revision <> NEW.deployment_revision
       OR deployment_row.remote_model <> NEW.remote_model
       OR deployment_row.type <> 'embedding' THEN
        RAISE EXCEPTION 'SAND_AI_EMBEDDING_MODEL_INVALID';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM sand_ai_capability_route r
        JOIN sand_ai_capability_profile p ON p.id=r.profile_id
        WHERE r.id=NEW.route_id AND r.profile_id=NEW.profile_id
          AND r.capability_code='embedding' AND r.deployment_id=NEW.deployment_id
          AND r.status=1 AND p.environment_id=NEW.owner_environment_id
          AND p.revision=NEW.profile_revision AND p.state='published' AND p.status=1
    ) THEN
        RAISE EXCEPTION 'SAND_AI_EMBEDDING_ROUTE_INVALID';
    END IF;
    IF NEW.state = 'ready' THEN
        IF NEW.dimensions IS NULL OR NEW.ready_time IS NULL OR
           (SELECT count(*) FROM sand_ai_knowledge_embedding e WHERE e.index_id=NEW.id) <> NEW.source_count THEN
            RAISE EXCEPTION 'SAND_AI_EMBEDDING_INDEX_INCOMPLETE';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_knowledge_embedding_index_guard
BEFORE INSERT OR UPDATE ON sand_ai_knowledge_embedding_index
FOR EACH ROW EXECUTE FUNCTION sand_ai_guard_knowledge_embedding_index();

CREATE OR REPLACE FUNCTION sand_ai_guard_knowledge_embedding_vector() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    index_row record;
    source_row record;
    invocation_row record;
    component real;
    norm_squared double precision := 0;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM sand_ai_knowledge_embedding_active a WHERE a.index_id=OLD.index_id) THEN
            RAISE EXCEPTION 'SAND_AI_EMBEDDING_VECTOR_ACTIVE';
        END IF;
        RETURN OLD;
    END IF;
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'SAND_AI_EMBEDDING_VECTOR_IMMUTABLE';
    END IF;
    SELECT state,knowledge_base_version_id,owner_environment_id,dimensions INTO index_row
      FROM sand_ai_knowledge_embedding_index WHERE id=NEW.index_id FOR UPDATE;
    SELECT s.content_sha256,b.content INTO source_row
      FROM sand_ai_knowledge_base_source s
      JOIN sand_ai_source_block b ON b.id=s.source_block_id
      JOIN sand_ai_file f ON f.id=s.file_id
      JOIN sand_ai_file_parse p ON p.id=s.parse_id AND p.file_id=f.id
     WHERE s.knowledge_base_version_id=index_row.knowledge_base_version_id
       AND s.source_block_id=NEW.source_block_id
       AND f.environment_id=index_row.owner_environment_id
       AND f.state='parsed' AND f.status=1 AND f.delete_time IS NULL
       AND p.state='succeeded' AND p.status=1 AND p.delete_time IS NULL
       AND b.status=1 AND b.delete_time IS NULL AND b.file_id=f.id AND b.parse_id=p.id
       AND NOT EXISTS (
           SELECT 1 FROM sand_ai_file_parse latest
            WHERE latest.file_id=f.id AND latest.state='succeeded'
              AND latest.status=1 AND latest.delete_time IS NULL AND latest.id>p.id
       );
    IF index_row.state <> 'building' OR index_row.dimensions IS NULL
       OR cardinality(NEW.embedding) <> index_row.dimensions
       OR NOT FOUND
       OR source_row.content_sha256 <> NEW.content_sha256
       OR encode(sha256(convert_to(source_row.content,'UTF8')),'hex') <> NEW.content_sha256 THEN
        RAISE EXCEPTION 'SAND_AI_EMBEDDING_VECTOR_INVALID';
    END IF;
    SELECT invocation.id INTO invocation_row
      FROM sand_ai_invocation invocation
      JOIN sand_ai_usage_event usage ON usage.invocation_id=invocation.id
     WHERE invocation.id=NEW.invocation_id AND invocation.state='succeeded'
       AND invocation.environment_id=index_row.owner_environment_id
       AND invocation.model_id=(SELECT model_id FROM sand_ai_knowledge_embedding_index WHERE id=NEW.index_id)
       AND invocation.deployment_id=(SELECT deployment_id FROM sand_ai_knowledge_embedding_index WHERE id=NEW.index_id)
       AND usage.capability_code='embedding';
    IF NOT FOUND THEN
        RAISE EXCEPTION 'SAND_AI_EMBEDDING_USAGE_MISSING';
    END IF;
    FOREACH component IN ARRAY NEW.embedding LOOP
        IF component IS NULL OR NOT (component BETWEEN -1.000001 AND 1.000001) THEN
            RAISE EXCEPTION 'SAND_AI_EMBEDDING_VECTOR_INVALID';
        END IF;
        norm_squared := norm_squared + component::double precision * component::double precision;
    END LOOP;
    IF norm_squared NOT BETWEEN 0.999 AND 1.001 THEN
        RAISE EXCEPTION 'SAND_AI_EMBEDDING_VECTOR_INVALID';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_knowledge_embedding_vector_guard
BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_knowledge_embedding
FOR EACH ROW EXECUTE FUNCTION sand_ai_guard_knowledge_embedding_vector();

CREATE OR REPLACE FUNCTION sand_ai_guard_knowledge_embedding_active() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM sand_ai_knowledge_embedding_index i
         WHERE i.id=NEW.index_id AND i.knowledge_base_version_id=NEW.knowledge_base_version_id
           AND i.state='ready'
    ) THEN
        RAISE EXCEPTION 'SAND_AI_EMBEDDING_INDEX_NOT_READY';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_knowledge_embedding_active_guard
BEFORE INSERT OR UPDATE ON sand_ai_knowledge_embedding_active
FOR EACH ROW EXECUTE FUNCTION sand_ai_guard_knowledge_embedding_active();

CREATE OR REPLACE FUNCTION sand_ai_embedding_cosine(left_vector real[], right_vector real[])
RETURNS double precision
LANGUAGE sql IMMUTABLE STRICT AS $$
    SELECT CASE
        WHEN cardinality(left_vector) = cardinality(right_vector)
         AND cardinality(left_vector) BETWEEN 1 AND 4096
        THEN (SELECT sum(a::double precision * b::double precision)
                FROM unnest(left_vector,right_vector) AS components(a,b))
        ELSE NULL
    END
$$;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260065_knowledge_embedding_index', '8f2e4bdec21747b1295f160b38456156aa172ecca8237c0c802d2342cccf7b7f', CURRENT_TIMESTAMP(0));
-- Migration 202609260066_embedding_dispatch_scope (sha256 31baddffb4234d2061246182f53796c3e045f6afb2c7ca94bfbc34c2a73c2823)
-- Embedding uses the existing invocation, dispatch and rejection ledgers.
ALTER TABLE sand_ai_invocation_dispatch
    DROP CONSTRAINT ck_sand_ai_invocation_dispatch_scope_v026;
ALTER TABLE sand_ai_invocation_dispatch
    ADD CONSTRAINT ck_sand_ai_invocation_dispatch_scope_v066
    CHECK (execution_scope IN ('legacy_unverified', 'chat', 'ai_task', 'agent', 'structured_workflow', 'skill', 'embedding')
        AND (execution_scope = 'legacy_unverified' OR (
            (execution_scope IN ('ai_task', 'agent', 'structured_workflow')) = (parent_task_run_id IS NOT NULL AND parent_task_step_id IS NOT NULL AND workflow_node_code IS NOT NULL)
            AND (execution_scope IN ('ai_task', 'agent', 'structured_workflow') OR (parent_task_run_id IS NULL AND parent_task_step_id IS NULL AND workflow_node_code IS NULL))
        ))) NOT VALID;

ALTER TABLE sand_ai_pre_dispatch_rejection
    DROP CONSTRAINT ck_sand_ai_pre_dispatch_rejection_scope;
ALTER TABLE sand_ai_pre_dispatch_rejection
    ADD CONSTRAINT ck_sand_ai_pre_dispatch_rejection_scope_v066 CHECK (
        execution_scope IN ('chat', 'ai_task', 'agent', 'structured_workflow', 'skill', 'embedding')
        AND attempt_no > 0
        AND ((execution_scope IN ('ai_task', 'agent', 'structured_workflow')) =
             (parent_task_run_id IS NOT NULL AND parent_task_step_id IS NOT NULL AND workflow_node_code IS NOT NULL))
        AND (execution_scope IN ('ai_task', 'agent', 'structured_workflow') OR
             (parent_task_run_id IS NULL AND parent_task_step_id IS NULL AND workflow_node_code IS NULL))
    );
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260066_embedding_dispatch_scope', '31baddffb4234d2061246182f53796c3e045f6afb2c7ca94bfbc34c2a73c2823', CURRENT_TIMESTAMP(0));
-- Migration 202609260067_embedding_usage_source (sha256 8cfa663a5587c4417769e4bb926d43bb60f76c29dd6b433f94e01cbf50d796cd)
-- Private knowledge indexing is an environment-owned Embedding usage source.
ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT sand_ai_usage_event_source_kind_ck;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT sand_ai_usage_event_source_kind_ck_v067
    CHECK (source_kind IN (
        'chat','ai_task','agent','capability','workflow','structured_workflow',
        'external_service','onboarding_verification','skill','skill_test','tool','knowledge_index'
    ));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260067_embedding_usage_source', '8cfa663a5587c4417769e4bb926d43bb60f76c29dd6b433f94e01cbf50d796cd', CURRENT_TIMESTAMP(0));
-- Migration 202609260068_channel_conversation_close (sha256 c113ce85056baf9e8dc68fca24f350ef66bd47880a200b3fa53bc1ae7bedeba4)
-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_channel_conversation_close (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    channel_binding_id bigint NOT NULL REFERENCES sand_ai_channel_binding(id),
    conversation_scope char(64) NOT NULL CHECK (conversation_scope ~ '^[a-f0-9]{64}$'),
    owner_scope char(64) NOT NULL CHECK (owner_scope ~ '^[a-f0-9]{64}$'),
    request_id varchar(96) NOT NULL UNIQUE,
    closed_after_task_id bigint NOT NULL CHECK (closed_after_task_id >= 0),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);
CREATE INDEX sand_ai_channel_conversation_close_latest_idx
    ON sand_ai_channel_conversation_close
    (environment_id, channel_binding_id, conversation_scope, owner_scope, id DESC);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260068_channel_conversation_close', 'c113ce85056baf9e8dc68fca24f350ef66bd47880a200b3fa53bc1ae7bedeba4', CURRENT_TIMESTAMP(0));
-- Migration 202609260069_knowledge_vector_purge (sha256 f2ac82b9c7f17f69386143d15c040e3898471504dcc2c654591144053d8bd1c5)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_operation
    DROP CONSTRAINT sand_ai_knowledge_operation_operation_code_check;
ALTER TABLE sand_ai_knowledge_operation
    ADD CONSTRAINT sand_ai_knowledge_operation_operation_code_check
    CHECK (operation_code IN ('create_draft', 'publish', 'disable', 'purge_vectors'));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260069_knowledge_vector_purge', 'f2ac82b9c7f17f69386143d15c040e3898471504dcc2c654591144053d8bd1c5', CURRENT_TIMESTAMP(0));
-- Migration 202609260070_knowledge_source_purge (sha256 ad923716b614275dc273400611a2714432eb258e306b217f207a8849114e1116)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_operation
    DROP CONSTRAINT sand_ai_knowledge_operation_operation_code_check;
ALTER TABLE sand_ai_knowledge_operation
    ADD CONSTRAINT sand_ai_knowledge_operation_operation_code_check
    CHECK (operation_code IN ('create_draft', 'publish', 'disable', 'purge_vectors', 'purge_sources'));

CREATE OR REPLACE FUNCTION sand_ai_knowledge_source_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' AND EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version v
        JOIN sand_ai_knowledge_base kb ON kb.id = v.knowledge_base_id
        WHERE v.id = OLD.knowledge_base_version_id
          AND v.state = 'published'
          AND kb.state = 'disabled'
          AND kb.status = 2
          AND NOT EXISTS (
              SELECT 1 FROM sand_ai_knowledge_publication p
              WHERE p.knowledge_base_id = kb.id AND p.state = 'published'
          )
          AND NOT EXISTS (
              SELECT 1 FROM sand_ai_knowledge_embedding_index i
              JOIN sand_ai_knowledge_base_version iv ON iv.id = i.knowledge_base_version_id
              WHERE iv.knowledge_base_id = kb.id
          )
          AND NOT EXISTS (
              SELECT 1 FROM sand_ai_knowledge_conversation_turn t
              JOIN sand_ai_knowledge_conversation c ON c.id = t.conversation_id
              JOIN sand_ai_knowledge_publication p ON p.id = c.publication_id
              WHERE p.knowledge_base_id = kb.id AND t.state = 'pending'
          )
    ) THEN
        RETURN OLD;
    END IF;

    IF EXISTS (
        SELECT 1 FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(NEW.knowledge_base_version_id, OLD.knowledge_base_version_id)
          AND state = 'published'
    ) OR EXISTS (
        SELECT 1 FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(OLD.knowledge_base_version_id, NEW.knowledge_base_version_id)
          AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'published knowledge sources are immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260070_knowledge_source_purge', 'ad923716b614275dc273400611a2714432eb258e306b217f207a8849114e1116', CURRENT_TIMESTAMP(0));
-- Migration 202609260071_workflow_result_purge (sha256 65876ce366dea4717298237b4b54f3edee0c0e1bfefa553b775abeb893c4c670)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_workflow_run
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_artifact
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_workflow_file_result
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_workflow_cross_file_review_result
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;

ALTER TABLE sand_ai_workflow_cross_file_review_result
    DROP CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_payload;
ALTER TABLE sand_ai_workflow_cross_file_review_result
    ADD CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_payload
    CHECK (
        (content_purged_time IS NOT NULL AND payload = '{}'::jsonb)
        OR (content_purged_time IS NULL AND COALESCE(
            jsonb_typeof(payload) = 'object'
            AND jsonb_typeof(payload->'conflicts') = 'array'
            AND jsonb_typeof(payload->'items_to_verify') = 'array',
            false
        ))
    );

CREATE OR REPLACE FUNCTION sand_ai_workflow_file_result_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'sand_ai_workflow_file_result' AND TG_OP = 'UPDATE' THEN
        IF OLD.content_purged_time IS NULL
           AND NEW.content_purged_time IS NOT NULL
           AND NEW.payload = '{}'::jsonb
           AND to_jsonb(NEW) - 'payload' - 'content_purged_time'
               = to_jsonb(OLD) - 'payload' - 'content_purged_time'
           AND EXISTS (
               SELECT 1
               FROM sand_ai_workflow_run_file rf
               JOIN sand_ai_workflow_run r ON r.id = rf.workflow_run_id
               WHERE rf.id = OLD.workflow_run_file_id
                 AND r.content_purged_time IS NOT NULL
           ) THEN
            RETURN NEW;
        END IF;
    END IF;
    RAISE EXCEPTION 'SandAI workflow file result evidence is immutable';
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION sand_ai_workflow_cross_file_review_evidence_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'sand_ai_workflow_cross_file_review_result' AND TG_OP = 'UPDATE' THEN
        IF OLD.content_purged_time IS NULL
           AND NEW.content_purged_time IS NOT NULL
           AND NEW.payload = '{}'::jsonb
           AND to_jsonb(NEW) - 'payload' - 'content_purged_time'
               = to_jsonb(OLD) - 'payload' - 'content_purged_time'
           AND EXISTS (
               SELECT 1 FROM sand_ai_workflow_run r
               WHERE r.id = OLD.workflow_run_id
                 AND r.content_purged_time IS NOT NULL
           ) THEN
            RETURN NEW;
        END IF;
    END IF;
    RAISE EXCEPTION 'SandAI cross-file review evidence is immutable';
END;
$$ LANGUAGE plpgsql;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260071_workflow_result_purge', '65876ce366dea4717298237b4b54f3edee0c0e1bfefa553b775abeb893c4c670', CURRENT_TIMESTAMP(0));

-- Migration 202609260072_audio_content_purge (sha256 73c2f37f797664619fbf6cb8265e949d85f232fd5bad75df2baf3d2f2e64b407)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_task_run
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_audio_task_content_purged_v072
    CHECK (
        content_purged_time IS NULL
        OR (
            state IN ('succeeded', 'failed', 'canceled')
            AND result IS NULL
            AND (
                (task_type = 'audio_transcription'
                    AND COALESCE(input #> '{request,glossary}' = '[]'::jsonb, false))
                OR (task_type = 'ai_completion' AND input ? '_meeting_summary')
            )
        )
    );

ALTER TABLE sand_ai_external_audio_dispatch
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;

ALTER TABLE sand_ai_audio_segment_review
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;

CREATE OR REPLACE FUNCTION sand_ai_audio_segment_review_immutable()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'SandAI audio segment review evidence is immutable';
    END IF;
    IF OLD.content_purged_time IS NULL
       AND NEW.content_purged_time IS NOT NULL
       AND NEW.review_text = ''
       AND to_jsonb(NEW) - 'review_text' - 'content_purged_time'
           = to_jsonb(OLD) - 'review_text' - 'content_purged_time'
       AND EXISTS (
           SELECT 1 FROM sand_ai_task_run task
           WHERE task.id = OLD.audio_task_id
             AND task.content_purged_time IS NOT NULL
       ) THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'SandAI audio segment review evidence is immutable';
END $$;
DROP TRIGGER sand_ai_audio_segment_review_immutable ON sand_ai_audio_segment_review;
CREATE TRIGGER sand_ai_audio_segment_review_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_audio_segment_review
    FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_segment_review_immutable();

ALTER TABLE sand_ai_external_audio_receipt
    ADD COLUMN destroy_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_external_audio_receipt
    ALTER COLUMN key_id DROP NOT NULL,
    ALTER COLUMN nonce DROP NOT NULL,
    ALTER COLUMN tag DROP NOT NULL,
    ALTER COLUMN ciphertext DROP NOT NULL,
    ALTER COLUMN plaintext_bytes DROP NOT NULL,
    ALTER COLUMN content_hmac DROP NOT NULL;
ALTER TABLE sand_ai_external_audio_receipt
    DROP CONSTRAINT ck_sand_ai_external_audio_receipt_format_v031;
ALTER TABLE sand_ai_external_audio_receipt
    ADD CONSTRAINT ck_sand_ai_external_audio_receipt_format_v072
    CHECK (
        receipt_version = 'external_audio_receipt/v1'
        AND expire_time <= retain_until
        AND (
            (destroy_time IS NULL AND key_id IS NOT NULL
                AND nonce IS NOT NULL AND octet_length(nonce) = 12
                AND tag IS NOT NULL AND octet_length(tag) = 16
                AND content_hmac IS NOT NULL AND octet_length(content_hmac) = 32
                AND ciphertext IS NOT NULL AND plaintext_bytes IS NOT NULL
                AND plaintext_bytes > 0
                AND plaintext_bytes <= 262144)
            OR (destroy_time IS NOT NULL AND key_id IS NULL
                AND nonce IS NULL AND tag IS NULL AND ciphertext IS NULL
                AND plaintext_bytes IS NULL AND content_hmac IS NULL)
        )
    );

CREATE OR REPLACE FUNCTION sand_ai_external_audio_dispatch_immutable_v031()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    task_state text;
    task_purged_time timestamp(0) without time zone;
BEGIN
    IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL THEN
        SELECT state,content_purged_time INTO task_state,task_purged_time
        FROM sand_ai_task_run
        WHERE id = OLD.task_run_id AND environment_id = OLD.environment_id;
        IF task_state IS NULL OR task_state NOT IN ('succeeded', 'failed', 'canceled')
           OR task_purged_time IS NULL
           OR NEW.terminal_snapshot IS DISTINCT FROM
              (CASE WHEN OLD.terminal_snapshot IS NULL THEN NULL::jsonb
                    ELSE '{"content_purged":true}'::jsonb END)
           OR NEW.frozen_request IS DISTINCT FROM jsonb_set(
               OLD.frozen_request, '{parameters,glossary}', '[]'::jsonb, false)
           OR to_jsonb(NEW) - 'terminal_snapshot' - 'frozen_request'
               - 'content_purged_time' - 'update_time'
              IS DISTINCT FROM
              to_jsonb(OLD) - 'terminal_snapshot' - 'frozen_request'
               - 'content_purged_time' - 'update_time' THEN
            RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_PURGE_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.content_purged_time IS NOT NULL OR NEW.content_purged_time IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_PURGED';
    END IF;
    IF OLD.state IN ('succeeded','not_dispatched','failed','ambiguous') THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_IMMUTABLE';
    END IF;
    IF NEW.environment_id <> OLD.environment_id OR NEW.task_run_id <> OLD.task_run_id
       OR NEW.logical_execution_id <> OLD.logical_execution_id
       OR NEW.request_fingerprint <> OLD.request_fingerprint
       OR NEW.frozen_request <> OLD.frozen_request THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_FROZEN';
    END IF;
    IF OLD.accepted_provider_job_id IS NOT NULL
       AND (NEW.accepted_provider_job_id IS DISTINCT FROM OLD.accepted_provider_job_id
            OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at) THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_ACCEPTED_EVIDENCE_IMMUTABLE';
    END IF;
    IF NOT ((OLD.state = 'prepared' AND NEW.state IN ('prepared','dispatching','not_dispatched'))
        OR (OLD.state = 'dispatching' AND NEW.state IN ('dispatching','accepted','recovery_pending','not_dispatched','succeeded','failed','ambiguous'))
        OR (OLD.state = 'accepted' AND NEW.state IN ('accepted','recovery_pending','succeeded','failed','ambiguous'))
        OR (OLD.state = 'recovery_pending' AND NEW.state IN ('recovery_pending','succeeded','failed','ambiguous'))) THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_TRANSITION';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_external_audio_receipt_immutable_v031()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_RECEIPT_IMMUTABLE';
    END IF;
    IF OLD.destroy_time IS NULL AND NEW.destroy_time IS NOT NULL
       AND NEW.key_id IS NULL AND NEW.nonce IS NULL AND NEW.tag IS NULL
       AND NEW.ciphertext IS NULL AND NEW.plaintext_bytes IS NULL
       AND NEW.content_hmac IS NULL
       AND to_jsonb(NEW) - 'key_id' - 'nonce' - 'tag' - 'ciphertext'
           - 'plaintext_bytes' - 'content_hmac' - 'destroy_time'
           = to_jsonb(OLD) - 'key_id' - 'nonce' - 'tag' - 'ciphertext'
           - 'plaintext_bytes' - 'content_hmac' - 'destroy_time'
       AND EXISTS (
            SELECT 1 FROM sand_ai_external_audio_dispatch dispatch
            JOIN sand_ai_task_run task ON task.id = dispatch.task_run_id
            WHERE dispatch.id = OLD.external_audio_dispatch_id
              AND dispatch.content_purged_time IS NOT NULL
              AND task.state IN ('succeeded', 'failed', 'canceled')
              AND task.content_purged_time IS NOT NULL
       ) THEN
        RETURN NEW;
    END IF;
    IF OLD.destroy_time IS NULL AND NEW.destroy_time IS NULL
       AND OLD.acknowledged_time IS NULL AND NEW.acknowledged_time IS NOT NULL
       AND NEW.acknowledged_time <= OLD.retain_until
       AND NEW.external_audio_dispatch_id = OLD.external_audio_dispatch_id
       AND NEW.receipt_kind = OLD.receipt_kind
       AND NEW.receipt_version = OLD.receipt_version
       AND NEW.key_id = OLD.key_id AND NEW.nonce = OLD.nonce
       AND NEW.tag = OLD.tag AND NEW.ciphertext = OLD.ciphertext
       AND NEW.plaintext_bytes = OLD.plaintext_bytes
       AND NEW.content_hmac = OLD.content_hmac
       AND NEW.expire_time = OLD.expire_time
       AND NEW.retain_until = OLD.retain_until
       AND NEW.create_time = OLD.create_time THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_RECEIPT_IMMUTABLE';
END $$;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260072_audio_content_purge', '73c2f37f797664619fbf6cb8265e949d85f232fd5bad75df2baf3d2f2e64b407', CURRENT_TIMESTAMP(0));

-- Migration 202609260073_knowledge_query_embedding_usage (sha256 66e1c21d2dac8a1641ae8fe55d9257cdd3bb999d11e703bb194674af5689b04d)

-- Consumer-owned query Embedding usage is separate from owner-owned index building.
ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT sand_ai_usage_event_source_kind_ck_v067;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT sand_ai_usage_event_source_kind_ck_v073
    CHECK (source_kind IN (
        'chat','ai_task','agent','capability','workflow','structured_workflow',
        'external_service','onboarding_verification','skill','skill_test','tool',
        'knowledge_index','knowledge_query'
    ));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260073_knowledge_query_embedding_usage', '66e1c21d2dac8a1641ae8fe55d9257cdd3bb999d11e703bb194674af5689b04d', CURRENT_TIMESTAMP(0));

-- Migration 202609260074_file_deletion_recovery (sha256 cbc1e05620ca75a9deda9e5b82bf82cfc6c154376192186e86ae17464361e864)

-- Recover authorized private-file deletions after a worker or request exits.
CREATE INDEX IF NOT EXISTS sand_ai_file_deleting_recovery_idx
    ON sand_ai_file (id)
    WHERE state = 'deleting' AND status = 1 AND delete_time IS NULL;
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260074_file_deletion_recovery', 'cbc1e05620ca75a9deda9e5b82bf82cfc6c154376192186e86ae17464361e864', CURRENT_TIMESTAMP(0));

-- Migration 202609260075_feishu_managed_credential (sha256 abec7322749eb8ee85a80169eab616a391c1016d17ad11644e8f459860cec909)

-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_feishu_channel_credential (
    channel_binding_id bigint PRIMARY KEY REFERENCES sand_ai_channel_binding(id),
    environment_id bigint NOT NULL,
    code varchar(64) NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_-]{0,63}$'),
    credential_envelope jsonb NOT NULL CHECK (jsonb_typeof(credential_envelope) = 'object'),
    updated_by varchar(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);
CREATE INDEX sand_ai_feishu_channel_credential_environment_idx ON sand_ai_feishu_channel_credential(environment_id);

CREATE TABLE sand_ai_feishu_credential_operation (
    environment_id bigint NOT NULL,
    idempotency_key varchar(128) NOT NULL,
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    channel_binding_id bigint NOT NULL REFERENCES sand_ai_channel_binding(id),
    response_snapshot jsonb NOT NULL CHECK (jsonb_typeof(response_snapshot) = 'object'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    PRIMARY KEY (environment_id, idempotency_key)
);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260075_feishu_managed_credential', 'abec7322749eb8ee85a80169eab616a391c1016d17ad11644e8f459860cec909', CURRENT_TIMESTAMP(0));

-- Migration 202609260076_feishu_channel_status (sha256 91c80de6be63abb9b1ba9d058217341e7e94d87d8eec0b2bf2190b1669679894)

-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_feishu_channel_status (
    channel_binding_id bigint PRIMARY KEY REFERENCES sand_ai_channel_binding(id),
    environment_id bigint NOT NULL,
    last_verified_callback_at timestamp(0) without time zone NULL,
    last_accepted_message_at timestamp(0) without time zone NULL,
    last_callback_error_code varchar(64) NULL,
    last_callback_error_at timestamp(0) without time zone NULL,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CHECK ((last_callback_error_code IS NULL) = (last_callback_error_at IS NULL))
);
CREATE INDEX sand_ai_feishu_channel_status_environment_idx ON sand_ai_feishu_channel_status(environment_id);
CREATE INDEX sand_ai_feishu_delivery_binding_latest_idx
    ON sand_ai_feishu_delivery(environment_id,channel_binding_id,task_id DESC);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260076_feishu_channel_status', '91c80de6be63abb9b1ba9d058217341e7e94d87d8eec0b2bf2190b1669679894', CURRENT_TIMESTAMP(0));

-- Migration 202609260077_feishu_delivery_error_history (sha256 50e56c35001b8b2b2dbbab774deb0ce90777aea2a89aeeb024e26f23a8ea584f)

-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_feishu_channel_status
    ADD COLUMN last_delivery_error_code varchar(64) NULL,
    ADD COLUMN last_delivery_error_at timestamp(0) without time zone NULL,
    ADD CONSTRAINT sand_ai_feishu_channel_status_delivery_error_pair_ck
        CHECK ((last_delivery_error_code IS NULL) = (last_delivery_error_at IS NULL));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260077_feishu_delivery_error_history', '50e56c35001b8b2b2dbbab774deb0ce90777aea2a89aeeb024e26f23a8ea584f', CURRENT_TIMESTAMP(0));

-- Migration 202609260078_wecom_delivery (sha256 7f4ca4c92848509b720b0f0030f4ccf2aac20bec4c133644a6fb2a49a198a0f8)

-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_wecom_delivery (
    task_id bigint PRIMARY KEY REFERENCES sand_ai_task_run(id),
    environment_id bigint NOT NULL,
    channel_binding_id bigint NOT NULL REFERENCES sand_ai_channel_binding(id),
    channel_code varchar(64) NOT NULL,
    channel_revision integer NOT NULL CHECK (channel_revision > 0),
    message_scope char(64) NOT NULL CHECK (message_scope ~ '^[a-f0-9]{64}$'),
    receiver_envelope jsonb NOT NULL CHECK (jsonb_typeof(receiver_envelope) = 'object'),
    state varchar(16) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','sending','sent','failed','uncertain')),
    attempt_count smallint NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 5),
    available_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    lease_token varchar(64) NULL,
    lease_until timestamp(0) without time zone NULL,
    error_code varchar(64) NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);
CREATE INDEX sand_ai_wecom_delivery_ready_idx ON sand_ai_wecom_delivery(state,available_at,task_id)
    WHERE state IN ('pending','sending');
CREATE INDEX sand_ai_wecom_delivery_binding_idx ON sand_ai_wecom_delivery(environment_id,channel_binding_id,task_id DESC);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260078_wecom_delivery', '7f4ca4c92848509b720b0f0030f4ccf2aac20bec4c133644a6fb2a49a198a0f8', CURRENT_TIMESTAMP(0));

-- Migration 202609260079_wecom_managed_credential (sha256 5d031b80fcd24c0f907f929249d409640f33739f2fc3901607a0790cefb003a1)

-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_wecom_channel_credential (
    channel_binding_id bigint PRIMARY KEY REFERENCES sand_ai_channel_binding(id),
    environment_id bigint NOT NULL,
    code varchar(64) NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_-]{0,63}$'),
    credential_envelope jsonb NOT NULL CHECK (jsonb_typeof(credential_envelope) = 'object'),
    updated_by varchar(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);
CREATE INDEX sand_ai_wecom_channel_credential_environment_idx ON sand_ai_wecom_channel_credential(environment_id);

CREATE TABLE sand_ai_wecom_credential_operation (
    environment_id bigint NOT NULL,
    idempotency_key varchar(128) NOT NULL,
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    channel_binding_id bigint NOT NULL REFERENCES sand_ai_channel_binding(id),
    response_snapshot jsonb NOT NULL CHECK (jsonb_typeof(response_snapshot) = 'object'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    PRIMARY KEY (environment_id, idempotency_key)
);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260079_wecom_managed_credential', '5d031b80fcd24c0f907f929249d409640f33739f2fc3901607a0790cefb003a1', CURRENT_TIMESTAMP(0));

-- Migration 202609260080_wecom_channel_status (sha256 5b9e32f5982bc14942df0bf01d74684a8ab85e82b886b59d38b022fcc2220a4e)

-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_wecom_channel_status (
    channel_binding_id bigint PRIMARY KEY REFERENCES sand_ai_channel_binding(id),
    environment_id bigint NOT NULL,
    last_verified_callback_at timestamp(0) without time zone NULL,
    last_accepted_message_at timestamp(0) without time zone NULL,
    last_callback_error_code varchar(64) NULL,
    last_callback_error_at timestamp(0) without time zone NULL,
    last_delivery_error_code varchar(64) NULL,
    last_delivery_error_at timestamp(0) without time zone NULL,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CHECK ((last_callback_error_code IS NULL) = (last_callback_error_at IS NULL)),
    CHECK ((last_delivery_error_code IS NULL) = (last_delivery_error_at IS NULL))
);
CREATE INDEX sand_ai_wecom_channel_status_environment_idx ON sand_ai_wecom_channel_status(environment_id);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260080_wecom_channel_status', '5b9e32f5982bc14942df0bf01d74684a8ab85e82b886b59d38b022fcc2220a4e', CURRENT_TIMESTAMP(0));
-- Migration 202609260081_wecom_delivery_parts (sha256 0b77c49b4bec04f5e73a7198a4763e132c7ceb9b8f6f665601adfdc73d23b501)

-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_wecom_delivery
    ADD COLUMN response_sha256 char(64) NULL
        CONSTRAINT sand_ai_wecom_delivery_response_sha_ck
            CHECK (response_sha256 ~ '^[a-f0-9]{64}$'),
    ADD COLUMN part_count smallint NULL
        CONSTRAINT sand_ai_wecom_delivery_part_count_ck
            CHECK (part_count BETWEEN 1 AND 8),
    ADD COLUMN next_part smallint NOT NULL DEFAULT 1
        CONSTRAINT sand_ai_wecom_delivery_next_part_ck
            CHECK (next_part BETWEEN 1 AND 9),
    ADD CONSTRAINT sand_ai_wecom_delivery_parts_pair_ck
        CHECK ((response_sha256 IS NULL) = (part_count IS NULL)),
    ADD CONSTRAINT sand_ai_wecom_delivery_parts_progress_ck
        CHECK (part_count IS NULL OR next_part <= part_count + 1);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260081_wecom_delivery_parts', '0b77c49b4bec04f5e73a7198a4763e132c7ceb9b8f6f665601adfdc73d23b501', CURRENT_TIMESTAMP(0));
-- Migration 202609260082_wecom_delivery_retry (sha256 6404574a0a870d73fd26dfaa80e62f1c0ce5c9deb6472281258149f1b7432897)

-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_channel_operation DROP CONSTRAINT sand_ai_channel_operation_operation_code_check;
ALTER TABLE sand_ai_channel_operation ALTER COLUMN operation_code TYPE varchar(32);
ALTER TABLE sand_ai_channel_operation ADD CONSTRAINT sand_ai_channel_operation_operation_code_check
    CHECK (operation_code IN ('save', 'disable', 'retry_delivery', 'retry_wecom_delivery'));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260082_wecom_delivery_retry', '6404574a0a870d73fd26dfaa80e62f1c0ce5c9deb6472281258149f1b7432897', CURRENT_TIMESTAMP(0));
-- Migration 202609260083_knowledge_conversation_cancel (sha256 77cda6cec070081c4295149d11a1a06c922b4a24a81ae900a0237814d815a5b7)

-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_conversation_turn
    DROP CONSTRAINT sand_ai_knowledge_conversation_turn_state_check;
ALTER TABLE sand_ai_knowledge_conversation_turn
    ADD CONSTRAINT sand_ai_knowledge_conversation_turn_state_check
    CHECK (state IN ('pending', 'answered', 'needs_review', 'no_sources', 'failed', 'cancelled'));
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260083_knowledge_conversation_cancel', '77cda6cec070081c4295149d11a1a06c922b4a24a81ae900a0237814d815a5b7', CURRENT_TIMESTAMP(0));
-- Migration 202609260084_agent_context_turn_limit (sha256 8590645411d8bf4ab7b6451bff0317ccd965789e81a0840b0effc7cef87558e8)

-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_agent_version
    ADD COLUMN context_turn_limit smallint NOT NULL DEFAULT 2
    CONSTRAINT sand_ai_agent_version_context_turn_limit_ck
    CHECK (context_turn_limit BETWEEN 0 AND 4);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260084_agent_context_turn_limit', '8590645411d8bf4ab7b6451bff0317ccd965789e81a0840b0effc7cef87558e8', CURRENT_TIMESTAMP(0));
-- Migration 202609260085_legal_analysis_profile (sha256 42801d5aa5b0e71fa512b8087954ee8e7fd6db20b2fd77c8432f012cd413b193)
-- Source-only migration. Apply to the existing sandai database only after explicit authorization.
CREATE TABLE sand_ai_legal_analysis_profile (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    code varchar(64) NOT NULL,
    version varchar(32) NOT NULL,
    name varchar(160) NOT NULL,
    revision integer NOT NULL DEFAULT 1,
    config jsonb NOT NULL,
    state varchar(24) NOT NULL DEFAULT 'draft',
    status smallint NOT NULL DEFAULT 2,
    created_by bigint NULL,
    updated_by bigint NULL,
    published_time timestamp(0) without time zone NULL,
    revoked_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT sand_ai_legal_analysis_profile_environment_version_uk UNIQUE (environment_id, code, version),
    CONSTRAINT sand_ai_legal_analysis_profile_code_ck CHECK (code IN ('legal_research', 'litigation_preparation', 'judgment_analysis')),
    CONSTRAINT sand_ai_legal_analysis_profile_version_ck CHECK (version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'),
    CONSTRAINT sand_ai_legal_analysis_profile_revision_ck CHECK (revision > 0),
    CONSTRAINT sand_ai_legal_analysis_profile_state_ck CHECK (state IN ('draft', 'published', 'revoked')),
    CONSTRAINT sand_ai_legal_analysis_profile_status_ck CHECK (status IN (1, 2)),
    CONSTRAINT sand_ai_legal_analysis_profile_state_status_ck CHECK (
        (state = 'published' AND status = 1) OR (state IN ('draft', 'revoked') AND status = 2)
    )
);
CREATE INDEX sand_ai_legal_analysis_profile_environment_state_idx
    ON sand_ai_legal_analysis_profile (environment_id, state, status);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609260085_legal_analysis_profile', '42801d5aa5b0e71fa512b8087954ee8e7fd6db20b2fd77c8432f012cd413b193', CURRENT_TIMESTAMP(0));
-- Migration 202609270086_agent_file_content_purge (sha256 5d832b911f10b47581611d24b334945d5ffd407831fb09bc7a04e0943c7985a1)
-- Source-only migration. Apply to the existing sandai database only after explicit authorization.
CREATE TABLE sand_ai_agent_run_file (
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id),
    environment_id bigint NOT NULL,
    file_id bigint NOT NULL REFERENCES sand_ai_file(id),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    PRIMARY KEY (task_id, file_id)
);
CREATE INDEX sand_ai_agent_run_file_file_idx ON sand_ai_agent_run_file(file_id, task_id);

ALTER TABLE sand_ai_task_run DROP CONSTRAINT ck_sand_ai_audio_task_content_purged_v072;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_content_purged_v086
    CHECK (
        content_purged_time IS NULL
        OR (
            state IN ('succeeded', 'failed', 'canceled')
            AND result IS NULL
            AND (
                (task_type = 'audio_transcription'
                    AND COALESCE(input #> '{request,glossary}' = '[]'::jsonb, false))
                OR (task_type = 'ai_completion' AND input ? '_meeting_summary')
                OR task_type = 'agent_run'
            )
        )
    );
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609270086_agent_file_content_purge', '5d832b911f10b47581611d24b334945d5ffd407831fb09bc7a04e0943c7985a1', CURRENT_TIMESTAMP(0));
-- Migration 202609270087_knowledge_conversation_summary (sha256 d26398c024ab76f7fa128088ed7e3fa21266c2bcba7748317e7b560f4cb4a229)
-- Source-only migration. Apply to the existing sandai database only after explicit authorization.
ALTER TABLE sand_ai_knowledge_conversation_turn
    ADD COLUMN summary_started boolean NOT NULL DEFAULT false,
    ADD COLUMN summary_request_id varchar(96) NULL,
    ADD COLUMN summary_context jsonb NULL
        CHECK (summary_context IS NULL OR jsonb_typeof(summary_context) = 'object');

CREATE TABLE sand_ai_knowledge_conversation_summary (
    conversation_id bigint PRIMARY KEY REFERENCES sand_ai_knowledge_conversation(id) ON DELETE CASCADE,
    through_sequence_no integer NOT NULL CHECK (through_sequence_no > 0),
    answered_count integer NOT NULL CHECK (answered_count > 0),
    summary text NOT NULL CHECK (length(btrim(summary)) > 0 AND length(summary) <= 8000),
    invocation_request_id varchar(96) NOT NULL,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);

CREATE TABLE sand_ai_knowledge_conversation_summary_call (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    turn_id bigint NOT NULL REFERENCES sand_ai_knowledge_conversation_turn(id) ON DELETE CASCADE,
    request_id varchar(96) NOT NULL,
    invocation jsonb NOT NULL CHECK (jsonb_typeof(invocation) = 'object'),
    usage jsonb NULL CHECK (usage IS NULL OR jsonb_typeof(usage) = 'object'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_knowledge_conversation_summary_call_uq UNIQUE (turn_id, request_id)
);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609270087_knowledge_conversation_summary', 'd26398c024ab76f7fa128088ed7e3fa21266c2bcba7748317e7b560f4cb4a229', CURRENT_TIMESTAMP(0));
-- Migration 202609270088_meeting_summary_node (sha256 19d6a4803052e2461d971f22e24e934e18551db4f0df2b04536cfc9aea22ba46)
-- Source-only migration. Apply to the existing sandai database only after explicit authorization.
ALTER TABLE sand_ai_invocation_dispatch
    ADD CONSTRAINT sand_ai_invocation_dispatch_meeting_node_owner_uniq
    UNIQUE (invocation_id, parent_task_run_id, parent_task_step_id, workflow_node_code, execution_scope);

CREATE TABLE sand_ai_meeting_summary_node (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE CASCADE,
    task_step_id bigint NOT NULL,
    node_code varchar(96) NOT NULL,
    parent_node_code varchar(96) NULL,
    node_kind varchar(16) NOT NULL,
    level_no integer NOT NULL,
    sequence_no integer NOT NULL,
    segment_start_index integer NOT NULL,
    segment_end_index integer NOT NULL,
    transcript_sha256 char(64) NOT NULL,
    review_revision integer NOT NULL,
    planner_version varchar(32) NOT NULL,
    input_sha256 char(64) NOT NULL,
    input_snapshot jsonb NULL,
    result_snapshot jsonb NULL,
    result_sha256 char(64) NULL,
    last_invocation_id bigint NULL,
    dispatch_scope varchar(32) NOT NULL DEFAULT 'ai_task',
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_meeting_summary_node_step_uniq UNIQUE (task_step_id),
    CONSTRAINT sand_ai_meeting_summary_node_task_code_uniq UNIQUE (task_id, node_code),
    CONSTRAINT sand_ai_meeting_summary_node_level_sequence_uniq UNIQUE (task_id, level_no, sequence_no),
    CONSTRAINT sand_ai_meeting_summary_node_step_fk
        FOREIGN KEY (task_step_id, task_id) REFERENCES sand_ai_task_step(id, task_id) ON DELETE CASCADE,
    CONSTRAINT sand_ai_meeting_summary_node_invocation_owner_fk
        FOREIGN KEY (last_invocation_id, task_id, task_step_id, node_code, dispatch_scope)
        REFERENCES sand_ai_invocation_dispatch
            (invocation_id, parent_task_run_id, parent_task_step_id, workflow_node_code, execution_scope)
        ON DELETE RESTRICT,
    CONSTRAINT sand_ai_meeting_summary_node_parent_fk
        FOREIGN KEY (task_id, parent_node_code) REFERENCES sand_ai_meeting_summary_node(task_id, node_code)
        DEFERRABLE INITIALLY DEFERRED,
    CONSTRAINT sand_ai_meeting_summary_node_code_ck CHECK (node_code ~ '^[a-z0-9][a-z0-9._-]{0,95}$'),
    CONSTRAINT sand_ai_meeting_summary_node_kind_ck CHECK (node_kind IN ('chunk', 'merge')),
    CONSTRAINT sand_ai_meeting_summary_node_scope_ck CHECK (dispatch_scope = 'ai_task'),
    CONSTRAINT sand_ai_meeting_summary_node_level_ck CHECK (level_no >= 0 AND sequence_no > 0),
    CONSTRAINT sand_ai_meeting_summary_node_range_ck CHECK (
        segment_start_index >= 0 AND segment_end_index >= segment_start_index AND segment_end_index < 10000
    ),
    CONSTRAINT sand_ai_meeting_summary_node_review_ck CHECK (review_revision >= 0),
    CONSTRAINT sand_ai_meeting_summary_node_planner_ck CHECK (length(btrim(planner_version)) > 0),
    CONSTRAINT sand_ai_meeting_summary_node_hash_ck CHECK (
        transcript_sha256 ~ '^[a-f0-9]{64}$'
        AND input_sha256 ~ '^[a-f0-9]{64}$'
        AND (result_sha256 IS NULL OR result_sha256 ~ '^[a-f0-9]{64}$')
    ),
    CONSTRAINT sand_ai_meeting_summary_node_snapshot_ck CHECK (
        (input_snapshot IS NULL OR jsonb_typeof(input_snapshot) = 'object')
        AND (result_snapshot IS NULL OR jsonb_typeof(result_snapshot) = 'object')
        AND (result_snapshot IS NULL OR result_sha256 IS NOT NULL)
    )
);
CREATE INDEX sand_ai_meeting_summary_node_task_range_idx
    ON sand_ai_meeting_summary_node (task_id, level_no, segment_start_index, segment_end_index);
CREATE INDEX sand_ai_meeting_summary_node_invocation_idx
    ON sand_ai_meeting_summary_node (last_invocation_id) WHERE last_invocation_id IS NOT NULL;

CREATE FUNCTION sand_ai_meeting_summary_node_guard() RETURNS trigger AS $$
DECLARE
    purged_time timestamp(0) without time zone;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF ROW(NEW.task_id, NEW.task_step_id, NEW.node_code, NEW.node_kind,
               NEW.level_no, NEW.sequence_no, NEW.segment_start_index, NEW.segment_end_index)
           IS DISTINCT FROM
           ROW(OLD.task_id, OLD.task_step_id, OLD.node_code, OLD.node_kind,
               OLD.level_no, OLD.sequence_no, OLD.segment_start_index, OLD.segment_end_index) THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_SUMMARY_NODE_PLAN_IMMUTABLE';
        END IF;
    END IF;
    SELECT content_purged_time INTO purged_time
    FROM sand_ai_task_run WHERE id = NEW.task_id FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_SUMMARY_TASK_MISSING';
    END IF;
    IF purged_time IS NOT NULL
       AND (NEW.input_snapshot IS NOT NULL OR NEW.result_snapshot IS NOT NULL) THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_SUMMARY_CONTENT_PURGED';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER sand_ai_meeting_summary_node_guard
    BEFORE INSERT OR UPDATE ON sand_ai_meeting_summary_node
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_summary_node_guard();

CREATE FUNCTION sand_ai_meeting_summary_node_parent_guard() RETURNS trigger AS $$
DECLARE
    child sand_ai_meeting_summary_node%ROWTYPE;
    parent sand_ai_meeting_summary_node%ROWTYPE;
BEGIN
    SELECT * INTO child FROM sand_ai_meeting_summary_node WHERE id = NEW.id;
    IF NOT FOUND OR child.parent_node_code IS NULL THEN
        RETURN NULL;
    END IF;
    SELECT * INTO parent FROM sand_ai_meeting_summary_node
    WHERE task_id = child.task_id AND node_code = child.parent_node_code;
    IF NOT FOUND OR parent.node_kind <> 'merge'
       OR parent.level_no <= child.level_no
       OR parent.segment_start_index > child.segment_start_index
       OR parent.segment_end_index < child.segment_end_index THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_SUMMARY_NODE_PARENT_INVALID';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE CONSTRAINT TRIGGER sand_ai_meeting_summary_node_parent_guard
    AFTER INSERT OR UPDATE ON sand_ai_meeting_summary_node
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_summary_node_parent_guard();

CREATE FUNCTION sand_ai_meeting_summary_node_purge() RETURNS trigger AS $$
BEGIN
    IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL THEN
        UPDATE sand_ai_meeting_summary_node
        SET input_snapshot = NULL, result_snapshot = NULL, update_time = CURRENT_TIMESTAMP(0)
        WHERE task_id = NEW.id AND (input_snapshot IS NOT NULL OR result_snapshot IS NOT NULL);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER sand_ai_meeting_summary_node_purge
    AFTER UPDATE OF content_purged_time ON sand_ai_task_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_summary_node_purge();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609270088_meeting_summary_node', '19d6a4803052e2461d971f22e24e934e18551db4f0df2b04536cfc9aea22ba46', CURRENT_TIMESTAMP(0));
-- Migration 202609270089_knowledge_conversation_segments (sha256 0110d9b7f2bceaa1352cea0813858c764c28db5894012f8812cf6e602a9a5cb0)
-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_conversation_turn
    ADD COLUMN stream_segments boolean NOT NULL DEFAULT false;

CREATE TABLE sand_ai_knowledge_conversation_segment (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    turn_id bigint NOT NULL REFERENCES sand_ai_knowledge_conversation_turn(id) ON DELETE CASCADE,
    segment_no integer NOT NULL CHECK (segment_no BETWEEN 1 AND 16),
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id),
    dispatch_id bigint NOT NULL REFERENCES sand_ai_invocation_dispatch(id),
    claim_token_sha256 char(64) NOT NULL CHECK (claim_token_sha256 ~ '^[a-f0-9]{64}$'),
    text varchar(4000) NOT NULL CHECK (length(btrim(text)) > 0),
    source_block_ids jsonb NOT NULL CHECK (jsonb_typeof(source_block_ids) = 'array'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_knowledge_conversation_segment_uq UNIQUE (turn_id, segment_no)
);
CREATE INDEX sand_ai_knowledge_conversation_segment_dispatch_idx
    ON sand_ai_knowledge_conversation_segment(dispatch_id, turn_id);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609270089_knowledge_conversation_segments', '0110d9b7f2bceaa1352cea0813858c764c28db5894012f8812cf6e602a9a5cb0', CURRENT_TIMESTAMP(0));
-- Migration 202609270090_billing_fact_identity (sha256 281798ab8d0b503e354e01c1f08273adb42180382261eebb387cdf3b5cf9172c)
ALTER TABLE sand_ai_billing_line
    ADD COLUMN identity_namespace VARCHAR(64),
    ADD COLUMN account_scope VARCHAR(128),
    ADD COLUMN stable_key CHAR(64),
    ADD COLUMN content_digest CHAR(64),
    ADD COLUMN identity_state VARCHAR(24) NOT NULL DEFAULT 'identity_unverified',
    ADD CONSTRAINT ck_sand_ai_billing_line_identity
        CHECK (
            (identity_state = 'verified'
                AND identity_namespace IS NOT NULL AND account_scope IS NOT NULL
                AND stable_key IS NOT NULL AND content_digest IS NOT NULL)
            OR (identity_state = 'identity_unverified'
                AND identity_namespace IS NULL AND account_scope IS NULL AND stable_key IS NULL)
        );

CREATE UNIQUE INDEX uk_sand_ai_billing_line_verified_fact
    ON sand_ai_billing_line (identity_namespace, account_scope, stable_key)
    WHERE identity_state = 'verified';

CREATE TABLE sand_ai_billing_import_line (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    billing_import_id BIGINT NOT NULL REFERENCES sand_ai_billing_import(id) ON DELETE RESTRICT,
    billing_line_id BIGINT NOT NULL REFERENCES sand_ai_billing_line(id) ON DELETE RESTRICT,
    external_line_id VARCHAR(128) NOT NULL,
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_billing_import_line_external UNIQUE (billing_import_id, external_line_id),
    CONSTRAINT uk_sand_ai_billing_import_line_fact UNIQUE (billing_import_id, billing_line_id)
);

INSERT INTO sand_ai_billing_import_line (billing_import_id, billing_line_id, external_line_id)
SELECT billing_import_id, id, external_line_id FROM sand_ai_billing_line;

CREATE TRIGGER sand_ai_billing_import_line_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_billing_import_line
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609270090_billing_fact_identity', '281798ab8d0b503e354e01c1f08273adb42180382261eebb387cdf3b5cf9172c', CURRENT_TIMESTAMP(0));
-- Migration 202609270091_workflow_state_revision (sha256 59bb9d0f0cd53a8e447b94a25ad6502da35eae67810008043dadc656b4dae13f)
-- Source-only migration. Apply only after explicit database authorization.
ALTER TABLE sand_ai_workflow_run
    ADD COLUMN state_version bigint NOT NULL DEFAULT 1,
    ADD COLUMN state_revision_txid bigint NULL;
UPDATE sand_ai_workflow_run SET state_revision_txid = txid_current();
ALTER TABLE sand_ai_workflow_run
    ALTER COLUMN state_revision_txid SET DEFAULT txid_current(),
    ALTER COLUMN state_revision_txid SET NOT NULL,
    ADD CONSTRAINT ck_sand_ai_workflow_run_state_version CHECK (state_version > 0);
INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202609270091_workflow_state_revision', '59bb9d0f0cd53a8e447b94a25ad6502da35eae67810008043dadc656b4dae13f', CURRENT_TIMESTAMP(0));

-- Migration 202609280092_consumer_skill_scope (sha256 702dfe452773353b66ad1f2202904e775f165ba2a2cbffba41ff0610008b21ae)
-- Source-only: consumer-declared Skill content scope; does not create IAM identities or grants.
ALTER TABLE sand_ai_skill
    ADD COLUMN organization_id bigint NULL,
    ADD COLUMN application_id bigint NULL,
    ADD COLUMN created_workload_client_id bigint NULL,
    ADD COLUMN scope_type varchar(16) NOT NULL DEFAULT 'legacy',
    ADD COLUMN scope_ref varchar(128) NULL,
    ADD COLUMN scope_source varchar(32) NOT NULL DEFAULT 'legacy',
    ADD COLUMN created_actor_ref varchar(128) NULL,
    ADD CONSTRAINT sand_ai_skill_consumer_scope_ck CHECK (
        (scope_type='legacy' AND scope_source='legacy' AND scope_ref IS NULL
         AND organization_id IS NULL AND application_id IS NULL
         AND created_workload_client_id IS NULL AND created_actor_ref IS NULL)
        OR (scope_type IN ('personal','team','organization') AND scope_source='consumer_declared'
            AND organization_id IS NOT NULL AND application_id IS NOT NULL AND created_workload_client_id IS NOT NULL
            AND organization_id>0 AND application_id>0 AND created_workload_client_id>0
            AND scope_ref IS NOT NULL AND octet_length(scope_ref) BETWEEN 1 AND 128
            AND created_actor_ref IS NOT NULL AND octet_length(created_actor_ref) BETWEEN 1 AND 128
            AND (scope_type<>'organization' OR scope_ref=organization_id::text))
    );
CREATE INDEX sand_ai_skill_consumer_scope_idx
    ON sand_ai_skill(organization_id,application_id,environment_id,scope_type,scope_ref,id)
    WHERE scope_source='consumer_declared' AND delete_time IS NULL;
ALTER TABLE sand_ai_skill_version
    ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object'),
    ADD COLUMN published_consumer_context jsonb NULL CHECK (published_consumer_context IS NULL OR jsonb_typeof(published_consumer_context)='object');
ALTER TABLE sand_ai_skill_run ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object');
ALTER TABLE sand_ai_skill_operation ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object');
ALTER TABLE sand_ai_skill_case ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object');
ALTER TABLE sand_ai_skill_case_evaluation ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object');
DO $$ DECLARE constraint_name text;
BEGIN
    FOR constraint_name IN SELECT conname FROM pg_constraint
        WHERE conrelid='sand_ai_skill_operation'::regclass AND contype='c'
          AND pg_get_constraintdef(oid) LIKE '%operation%create_draft%' LOOP
        EXECUTE format('ALTER TABLE sand_ai_skill_operation DROP CONSTRAINT %I',constraint_name);
    END LOOP;
END $$;
ALTER TABLE sand_ai_skill_operation ADD CONSTRAINT sand_ai_skill_operation_kind_v092_ck
    CHECK (operation IN ('create_draft','publish','revoke','rollback'));

CREATE FUNCTION sand_ai_skill_scope_immutable_v092() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ROW(NEW.environment_id,NEW.code,NEW.organization_id,NEW.application_id,
           NEW.created_workload_client_id,NEW.scope_type,NEW.scope_ref,NEW.scope_source,NEW.created_actor_ref)
       IS DISTINCT FROM
       ROW(OLD.environment_id,OLD.code,OLD.organization_id,OLD.application_id,
           OLD.created_workload_client_id,OLD.scope_type,OLD.scope_ref,OLD.scope_source,OLD.created_actor_ref)
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_CONSUMER_SCOPE_IMMUTABLE'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_scope_immutable_v092 BEFORE UPDATE ON sand_ai_skill
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_scope_immutable_v092();

CREATE FUNCTION sand_ai_skill_consumer_context_guard_v092() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_row sand_ai_skill%ROWTYPE; fixed_skill_id bigint;
BEGIN
    IF TG_OP='UPDATE' AND NEW.consumer_context IS DISTINCT FROM OLD.consumer_context
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_CONSUMER_CONTEXT_IMMUTABLE'; END IF;
    IF TG_TABLE_NAME IN ('sand_ai_skill_run','sand_ai_skill_operation','sand_ai_skill_version') THEN
        fixed_skill_id := NEW.skill_id;
    ELSIF TG_TABLE_NAME='sand_ai_skill_case' THEN
        SELECT skill_id INTO fixed_skill_id FROM sand_ai_skill_version WHERE id=NEW.skill_version_id;
    ELSE
        SELECT v.skill_id INTO fixed_skill_id FROM sand_ai_skill_case c
            JOIN sand_ai_skill_version v ON v.id=c.skill_version_id WHERE c.id=NEW.case_id;
    END IF;
    SELECT * INTO owner_row FROM sand_ai_skill WHERE id=fixed_skill_id;
    IF owner_row.scope_type='legacy' THEN
        IF NEW.consumer_context IS NOT NULL THEN RAISE EXCEPTION 'SAND_AI_SKILL_LEGACY_CONTEXT_INVALID'; END IF;
    ELSIF NEW.consumer_context IS NULL
        OR NEW.consumer_context->>'scope_source' IS DISTINCT FROM 'consumer_declared'
        OR NEW.consumer_context->>'actor_source' IS DISTINCT FROM 'consumer_declared'
        OR NEW.consumer_context->'scope'->>'type' IS DISTINCT FROM owner_row.scope_type
        OR NEW.consumer_context->'scope'->>'ref' IS DISTINCT FROM owner_row.scope_ref
        OR NEW.consumer_context->>'organization_id' IS DISTINCT FROM owner_row.organization_id::text
        OR NEW.consumer_context->>'application_id' IS DISTINCT FROM owner_row.application_id::text
        OR NEW.consumer_context->>'environment_id' IS DISTINCT FROM owner_row.environment_id::text
        OR COALESCE(NEW.consumer_context->>'workload_client_id','') !~ '^[1-9][0-9]*$'
        OR COALESCE(octet_length(NEW.consumer_context->>'actor_ref'),0) NOT BETWEEN 1 AND 128
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_CONSUMER_CONTEXT_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_version_consumer_v092 BEFORE INSERT OR UPDATE ON sand_ai_skill_version
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();
CREATE TRIGGER sand_ai_skill_run_consumer_v092 BEFORE INSERT OR UPDATE ON sand_ai_skill_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();
CREATE TRIGGER sand_ai_skill_operation_consumer_v092 BEFORE INSERT ON sand_ai_skill_operation
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();
CREATE TRIGGER sand_ai_skill_case_consumer_v092 BEFORE INSERT OR UPDATE ON sand_ai_skill_case
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();
CREATE TRIGGER sand_ai_skill_case_evaluation_consumer_v092 BEFORE INSERT ON sand_ai_skill_case_evaluation
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();

CREATE FUNCTION sand_ai_skill_publish_consumer_guard_v092() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_row sand_ai_skill%ROWTYPE; context jsonb;
BEGIN
    SELECT * INTO owner_row FROM sand_ai_skill WHERE id=NEW.skill_id;
    context := NEW.published_consumer_context;
    IF NEW.state='published' AND owner_row.scope_type<>'legacy' THEN
        IF context IS NULL
            OR context->>'scope_source' IS DISTINCT FROM 'consumer_declared'
            OR context->>'actor_source' IS DISTINCT FROM 'consumer_declared'
            OR context->'scope'->>'type' IS DISTINCT FROM owner_row.scope_type
            OR context->'scope'->>'ref' IS DISTINCT FROM owner_row.scope_ref
            OR context->>'organization_id' IS DISTINCT FROM owner_row.organization_id::text
            OR context->>'application_id' IS DISTINCT FROM owner_row.application_id::text
            OR context->>'environment_id' IS DISTINCT FROM owner_row.environment_id::text
            OR COALESCE(context->>'workload_client_id','') !~ '^[1-9][0-9]*$'
            OR COALESCE(octet_length(context->>'actor_ref'),0) NOT BETWEEN 1 AND 128
        THEN RAISE EXCEPTION 'SAND_AI_SKILL_PUBLISH_CONSUMER_INVALID'; END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_publish_consumer_guard_v092 BEFORE INSERT OR UPDATE ON sand_ai_skill_version
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_publish_consumer_guard_v092();

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202609280092_consumer_skill_scope','702dfe452773353b66ad1f2202904e775f165ba2a2cbffba41ff0610008b21ae',CURRENT_TIMESTAMP(0));

-- Migration 202609280093_multiscope_budget_groups (sha256 4d16724d5abf82a7a23c603b565b8d1616baee96aa43dd1ebc219cf81a8f7c3c)
-- Source-only: explicit database authorization is required before execution.
ALTER TABLE sand_ai_budget_policy ADD COLUMN scope_ref varchar(191);
ALTER TABLE sand_ai_budget_policy DISABLE TRIGGER trg_sand_ai_budget_policy_immutable;
UPDATE sand_ai_budget_policy SET scope_ref = environment_id::text;
ALTER TABLE sand_ai_budget_policy ENABLE TRIGGER trg_sand_ai_budget_policy_immutable;
ALTER TABLE sand_ai_budget_policy ALTER COLUMN scope_ref SET NOT NULL, ALTER COLUMN environment_id DROP NOT NULL;
ALTER TABLE sand_ai_budget_policy DROP CONSTRAINT ck_sand_ai_budget_policy_scope;
ALTER TABLE sand_ai_budget_policy ADD CONSTRAINT ck_sand_ai_budget_policy_scope CHECK (
    scope_kind IN ('organization','application','environment','service','capability','model')
    AND length(scope_ref) > 0 AND btrim(scope_ref) = scope_ref
    AND ((scope_kind = 'environment' AND environment_id IS NOT NULL AND scope_ref = environment_id::text)
         OR (scope_kind <> 'environment' AND environment_id IS NULL)));
DROP INDEX uk_sand_ai_budget_policy_revision;
CREATE UNIQUE INDEX uk_sand_ai_budget_policy_revision ON sand_ai_budget_policy(scope_kind,scope_ref,revision) WHERE delete_time IS NULL;
DROP INDEX idx_sand_ai_budget_policy_active;
CREATE INDEX idx_sand_ai_budget_policy_active ON sand_ai_budget_policy(scope_kind,scope_ref,period_start,period_end) WHERE state='published' AND delete_time IS NULL;
CREATE OR REPLACE FUNCTION sand_ai_reject_published_budget_policy_mutation() RETURNS trigger AS $$
BEGIN
    IF TG_OP='DELETE' OR OLD.state='archived' THEN RAISE EXCEPTION 'Published SandAI budget policy revisions are immutable'; END IF;
    IF OLD.state='published' AND (NEW.state <> 'archived'
       OR to_jsonb(NEW)-'state'-'update_time' IS DISTINCT FROM to_jsonb(OLD)-'state'-'update_time') THEN
        RAISE EXCEPTION 'Published SandAI budget policy revisions are immutable';
    END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;

ALTER TABLE sand_ai_budget_reservation
    ADD COLUMN external_audio_dispatch_id bigint REFERENCES sand_ai_external_audio_dispatch(id),
    ADD COLUMN audio_task_run_id bigint REFERENCES sand_ai_task_run(id),
    ADD COLUMN audio_execution_id varchar(191);
ALTER TABLE sand_ai_budget_reservation DROP CONSTRAINT ck_sand_ai_budget_reservation_owner_v062;
ALTER TABLE sand_ai_budget_reservation ADD CONSTRAINT ck_sand_ai_budget_reservation_owner_v093 CHECK (
    num_nonnulls(invocation_id,speech_run_id,external_audio_dispatch_id,audio_task_run_id)=1
    AND ((audio_task_run_id IS NULL)=(audio_execution_id IS NULL))
    AND (audio_execution_id IS NULL OR length(audio_execution_id)>0)) NOT VALID;
DROP INDEX uk_sand_ai_budget_reservation_invocation;
CREATE UNIQUE INDEX uk_sand_ai_budget_reservation_invocation ON sand_ai_budget_reservation(policy_id,invocation_id) WHERE invocation_id IS NOT NULL AND delete_time IS NULL;
DROP INDEX uq_sand_ai_budget_reservation_speech_run_v062;
CREATE UNIQUE INDEX uq_sand_ai_budget_reservation_speech_run_v062 ON sand_ai_budget_reservation(policy_id,speech_run_id) WHERE speech_run_id IS NOT NULL AND delete_time IS NULL;
CREATE UNIQUE INDEX uq_sand_ai_budget_external_audio_v093 ON sand_ai_budget_reservation(policy_id,external_audio_dispatch_id) WHERE external_audio_dispatch_id IS NOT NULL AND delete_time IS NULL;
CREATE UNIQUE INDEX uq_sand_ai_budget_local_audio_v093 ON sand_ai_budget_reservation(policy_id,audio_task_run_id,audio_execution_id) WHERE audio_task_run_id IS NOT NULL AND delete_time IS NULL;
ALTER TABLE sand_ai_budget_event
    ADD COLUMN external_audio_dispatch_id bigint REFERENCES sand_ai_external_audio_dispatch(id),
    ADD COLUMN audio_task_run_id bigint REFERENCES sand_ai_task_run(id),
    ADD COLUMN audio_execution_id varchar(191);
ALTER TABLE sand_ai_budget_event DROP CONSTRAINT ck_sand_ai_budget_event_owner_v062;
ALTER TABLE sand_ai_budget_event ADD CONSTRAINT ck_sand_ai_budget_event_owner_v093 CHECK (
    num_nonnulls(invocation_id,speech_run_id,external_audio_dispatch_id,audio_task_run_id)<=1
    AND ((audio_task_run_id IS NULL)=(audio_execution_id IS NULL))) NOT VALID;

CREATE FUNCTION sand_ai_budget_scope_matches_v093(kind text,ref text,identity jsonb,environment bigint,model bigint,capability text)
RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(CASE kind
        WHEN 'organization' THEN ref=identity->>'organization_ref'
        WHEN 'application' THEN ref=identity->>'application_ref'
        WHEN 'environment' THEN ref=environment::text
        WHEN 'service' THEN ref='sand_ai'
        WHEN 'capability' THEN ref=capability
        WHEN 'model' THEN ref=model::text
        ELSE false END,false);
$$;
CREATE FUNCTION sand_ai_budget_owner_guard_v093() RETURNS trigger AS $$
DECLARE policy sand_ai_budget_policy%ROWTYPE; identity jsonb; environment bigint; model bigint; capability text; fingerprint text; price bigint; task_input jsonb; execution_fence bigint;
BEGIN
    IF TG_OP='UPDATE' THEN
        IF to_jsonb(NEW)-'state'-'metered_amount'-'reason'-'expires_at'-'update_time'
           IS DISTINCT FROM to_jsonb(OLD)-'state'-'metered_amount'-'reason'-'expires_at'-'update_time' THEN
            RAISE EXCEPTION 'SAND_AI_BUDGET_OWNER_IMMUTABLE';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO policy FROM sand_ai_budget_policy WHERE id=NEW.policy_id;
    IF NEW.invocation_id IS NOT NULL THEN
        SELECT jsonb_build_object('organization_ref',organization_id::text,'application_ref',application_id::text),
            environment_id,model_id,request_fingerprint,call_start_price_revision_id
            INTO identity,environment,model,fingerprint,price FROM sand_ai_invocation WHERE id=NEW.invocation_id;
        SELECT capability_code INTO capability FROM sand_ai_price_revision WHERE id=NEW.price_revision_id;
    ELSIF NEW.speech_run_id IS NOT NULL THEN
        SELECT identity_snapshot->'identity_snapshot',environment_id,model_id,request_fingerprint,price_revision_id
            INTO identity,environment,model,fingerprint,price FROM sand_ai_speech_run WHERE id=NEW.speech_run_id;
        capability := 'audio_speech';
    ELSIF NEW.external_audio_dispatch_id IS NOT NULL THEN
        SELECT frozen_request->'usage_attribution'->'identity_snapshot',environment_id,
            (frozen_request->'execution_target'->'model'->>'id')::bigint,request_fingerprint,
            (frozen_request->'price_snapshot'->>'price_revision_id')::bigint
            INTO identity,environment,model,fingerprint,price FROM sand_ai_external_audio_dispatch WHERE id=NEW.external_audio_dispatch_id;
        capability := 'audio_transcription';
    ELSE
        SELECT input,environment_id,lease_fence INTO task_input,environment,execution_fence
            FROM sand_ai_task_run WHERE id=NEW.audio_task_run_id AND task_type='audio_transcription';
        identity := task_input->'usage_attribution'->'identity_snapshot';
        model := (task_input->'execution_target'->'model'->>'id')::bigint;
        price := (task_input->'pricing_snapshot'->>'price_revision_id')::bigint;
        capability := 'audio_transcription';
        IF NEW.audio_execution_id IS DISTINCT FROM 'task:'||NEW.audio_task_run_id::text||':attempt:'||execution_fence::text THEN
            RAISE EXCEPTION 'SAND_AI_BUDGET_AUDIO_EXECUTION_INVALID';
        END IF;
        -- Fingerprint verification uses the existing PHP canonical frozen-input digest at the write boundary.
        fingerprint := NEW.request_fingerprint;
    END IF;
    IF environment IS NULL OR identity IS NULL OR fingerprint IS DISTINCT FROM NEW.request_fingerprint
       OR price IS DISTINCT FROM NEW.price_revision_id OR policy.currency IS DISTINCT FROM NEW.currency
       OR NOT sand_ai_budget_scope_matches_v093(policy.scope_kind,policy.scope_ref,identity,environment,model,capability) THEN
        RAISE EXCEPTION 'SAND_AI_BUDGET_OWNER_SCOPE_INVALID';
    END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_budget_owner_v093 BEFORE INSERT OR UPDATE ON sand_ai_budget_reservation FOR EACH ROW EXECUTE FUNCTION sand_ai_budget_owner_guard_v093();
CREATE FUNCTION sand_ai_budget_event_owner_guard_v093() RETURNS trigger AS $$
BEGIN
    IF NEW.reservation_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM sand_ai_budget_reservation r WHERE r.id=NEW.reservation_id AND r.policy_id=NEW.policy_id
          AND r.invocation_id IS NOT DISTINCT FROM NEW.invocation_id AND r.speech_run_id IS NOT DISTINCT FROM NEW.speech_run_id
          AND r.external_audio_dispatch_id IS NOT DISTINCT FROM NEW.external_audio_dispatch_id
          AND r.audio_task_run_id IS NOT DISTINCT FROM NEW.audio_task_run_id AND r.audio_execution_id IS NOT DISTINCT FROM NEW.audio_execution_id
          AND r.currency=NEW.currency) THEN RAISE EXCEPTION 'SAND_AI_BUDGET_EVENT_OWNER_INVALID'; END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_budget_event_owner_v093 BEFORE INSERT ON sand_ai_budget_event FOR EACH ROW EXECUTE FUNCTION sand_ai_budget_event_owner_guard_v093();
CREATE OR REPLACE FUNCTION sand_ai_speech_budget_link_guard_v062() RETURNS trigger AS $$
BEGIN
    IF TG_OP='UPDATE' AND OLD.budget_reservation_id IS NOT NULL AND NEW.budget_reservation_id IS DISTINCT FROM OLD.budget_reservation_id THEN RAISE EXCEPTION 'SAND_AI_SPEECH_BUDGET_LINK_IMMUTABLE'; END IF;
    IF NEW.budget_reservation_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM sand_ai_budget_reservation r JOIN sand_ai_budget_policy p ON p.id=r.policy_id
        WHERE r.id=NEW.budget_reservation_id AND r.speech_run_id=NEW.id
          AND sand_ai_budget_scope_matches_v093(p.scope_kind,p.scope_ref,NEW.identity_snapshot->'identity_snapshot',NEW.environment_id,NEW.model_id,'audio_speech')) THEN
        RAISE EXCEPTION 'SAND_AI_SPEECH_BUDGET_LINK_INVALID';
    END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;

ALTER TABLE sand_ai_external_audio_dispatch ADD COLUMN budget_audio_seconds numeric(20,6) CHECK (budget_audio_seconds>0);
ALTER TABLE sand_ai_external_audio_dispatch DROP CONSTRAINT uq_sand_ai_external_audio_dispatch_task_v031;
ALTER TABLE sand_ai_usage_event ADD COLUMN audio_execution_id varchar(191), ADD COLUMN external_audio_dispatch_id bigint REFERENCES sand_ai_external_audio_dispatch(id);
DROP INDEX uq_sand_ai_usage_event_audio_task_run_v029;
CREATE UNIQUE INDEX uq_sand_ai_usage_audio_legacy_v093 ON sand_ai_usage_event(audio_task_run_id) WHERE audio_task_run_id IS NOT NULL AND audio_execution_id IS NULL AND external_audio_dispatch_id IS NULL;
CREATE UNIQUE INDEX uq_sand_ai_usage_audio_local_v093 ON sand_ai_usage_event(audio_task_run_id,audio_execution_id) WHERE audio_execution_id IS NOT NULL;
CREATE UNIQUE INDEX uq_sand_ai_usage_audio_external_v093 ON sand_ai_usage_event(external_audio_dispatch_id) WHERE external_audio_dispatch_id IS NOT NULL;
ALTER TABLE sand_ai_usage_event ADD CONSTRAINT ck_sand_ai_usage_audio_execution_v093 CHECK (
    ((audio_task_run_id IS NULL AND audio_execution_id IS NULL AND external_audio_dispatch_id IS NULL)
     OR (audio_task_run_id IS NOT NULL AND num_nonnulls(audio_execution_id,external_audio_dispatch_id)<=1))
    AND (audio_execution_id IS NULL OR length(audio_execution_id)>0));

CREATE OR REPLACE FUNCTION sand_ai_external_audio_dispatch_immutable_v031()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    task_state text;
    task_purged_time timestamp(0) without time zone;
BEGIN
    IF NEW.budget_audio_seconds IS DISTINCT FROM OLD.budget_audio_seconds THEN
        IF OLD.budget_audio_seconds IS NOT NULL OR NEW.budget_audio_seconds IS NULL
           OR OLD.state <> 'prepared' OR NEW.state <> 'prepared'
           OR to_jsonb(NEW)-'budget_audio_seconds'-'update_time' IS DISTINCT FROM to_jsonb(OLD)-'budget_audio_seconds'-'update_time' THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_BUDGET_DURATION_IMMUTABLE';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL THEN
        SELECT state,content_purged_time INTO task_state,task_purged_time
        FROM sand_ai_task_run
        WHERE id = OLD.task_run_id AND environment_id = OLD.environment_id;
        IF task_state IS NULL OR task_state NOT IN ('succeeded', 'failed', 'canceled')
           OR task_purged_time IS NULL
           OR NEW.terminal_snapshot IS DISTINCT FROM
              (CASE WHEN OLD.terminal_snapshot IS NULL THEN NULL::jsonb
                    ELSE '{"content_purged":true}'::jsonb END)
           OR NEW.frozen_request IS DISTINCT FROM jsonb_set(
               OLD.frozen_request, '{parameters,glossary}', '[]'::jsonb, false)
           OR to_jsonb(NEW) - 'terminal_snapshot' - 'frozen_request'
               - 'content_purged_time' - 'update_time'
              IS DISTINCT FROM
              to_jsonb(OLD) - 'terminal_snapshot' - 'frozen_request'
               - 'content_purged_time' - 'update_time' THEN
            RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_PURGE_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.content_purged_time IS NOT NULL OR NEW.content_purged_time IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_PURGED';
    END IF;
    IF OLD.state IN ('succeeded','not_dispatched','failed','ambiguous') THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_IMMUTABLE';
    END IF;
    IF NEW.environment_id <> OLD.environment_id OR NEW.task_run_id <> OLD.task_run_id
       OR NEW.logical_execution_id <> OLD.logical_execution_id
       OR NEW.request_fingerprint <> OLD.request_fingerprint
       OR NEW.frozen_request <> OLD.frozen_request THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_FROZEN';
    END IF;
    IF OLD.accepted_provider_job_id IS NOT NULL
       AND (NEW.accepted_provider_job_id IS DISTINCT FROM OLD.accepted_provider_job_id
            OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at) THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_ACCEPTED_EVIDENCE_IMMUTABLE';
    END IF;
    IF NOT ((OLD.state = 'prepared' AND NEW.state IN ('prepared','dispatching','not_dispatched'))
        OR (OLD.state = 'dispatching' AND NEW.state IN ('dispatching','accepted','recovery_pending','not_dispatched','succeeded','failed','ambiguous'))
        OR (OLD.state = 'accepted' AND NEW.state IN ('accepted','recovery_pending','succeeded','failed','ambiguous'))
        OR (OLD.state = 'recovery_pending' AND NEW.state IN ('recovery_pending','succeeded','failed','ambiguous'))) THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_TRANSITION';
    END IF;
    RETURN NEW;
END $$;


CREATE OR REPLACE FUNCTION sand_ai_audio_usage_task_guard_v029() RETURNS trigger AS $$
DECLARE task_input jsonb; task_fence bigint; dispatch_request jsonb; dispatch_task bigint; dispatch_environment bigint;
BEGIN
    IF NEW.audio_task_run_id IS NULL THEN RETURN NEW; END IF;
    IF NEW.audio_execution_id IS NOT NULL THEN
        SELECT lease_fence INTO task_fence FROM sand_ai_task_run WHERE id=NEW.audio_task_run_id AND environment_id=NEW.environment_id;
        IF NEW.audio_execution_id IS DISTINCT FROM 'task:'||NEW.audio_task_run_id::text||':attempt:'||task_fence::text THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_USAGE_EXECUTION_INVALID';
        END IF;
    ELSIF NEW.external_audio_dispatch_id IS NOT NULL THEN
        SELECT frozen_request,task_run_id,environment_id INTO dispatch_request,dispatch_task,dispatch_environment
            FROM sand_ai_external_audio_dispatch WHERE id=NEW.external_audio_dispatch_id;
        IF dispatch_task IS DISTINCT FROM NEW.audio_task_run_id OR dispatch_environment IS DISTINCT FROM NEW.environment_id
           OR dispatch_request->'usage_attribution'->'identity_snapshot' IS DISTINCT FROM NEW.identity_snapshot
           OR (dispatch_request->>'model_id')::bigint IS DISTINCT FROM NEW.model_id
           OR (dispatch_request->>'provider_id')::bigint IS DISTINCT FROM NEW.provider_id
           OR (dispatch_request->>'deployment_id')::bigint IS DISTINCT FROM NEW.deployment_id
           OR (dispatch_request->'price_snapshot'->>'price_revision_id')::bigint IS DISTINCT FROM NEW.price_revision_id THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_USAGE_DISPATCH_INVALID';
        END IF;
    END IF;
    SELECT input INTO task_input FROM sand_ai_task_run WHERE id = NEW.audio_task_run_id AND environment_id = NEW.environment_id AND task_type = 'audio_transcription';
    IF task_input IS NULL OR jsonb_typeof(task_input) <> 'object'
       OR task_input->>'usage_attribution_version' <> 'audio_usage_attribution@1'
       OR task_input->>'execution_target_version' <> 'audio_execution_target@1'
       OR task_input->'usage_attribution' IS NULL OR task_input->'execution_target' IS NULL
       OR jsonb_typeof(task_input->'usage_attribution') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(task_input->'usage_attribution')) <> 7
       OR NOT (task_input->'usage_attribution' ?& ARRAY['format_version','environment_id','organization_id','application_id','workload_client_id','identity_snapshot','capability_code'])
       OR task_input->'usage_attribution'->>'format_version' <> '1'
       OR (task_input->'usage_attribution'->>'environment_id')::bigint IS DISTINCT FROM NEW.environment_id
       OR (task_input->'usage_attribution'->>'organization_id')::bigint IS DISTINCT FROM NEW.organization_id
       OR (task_input->'usage_attribution'->>'application_id')::bigint IS DISTINCT FROM NEW.application_id
       OR (task_input->'usage_attribution'->>'workload_client_id')::bigint IS DISTINCT FROM NEW.workload_client_id
       OR task_input->'usage_attribution'->>'capability_code' <> 'audio_transcription'
       OR jsonb_typeof(task_input->'usage_attribution'->'identity_snapshot') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(task_input->'usage_attribution'->'identity_snapshot')) <> 3
       OR NOT (task_input->'usage_attribution'->'identity_snapshot' ?& ARRAY['organization_ref','application_ref','workload_client_ref'])
       OR task_input->'usage_attribution'->'identity_snapshot'->>'organization_ref' IS DISTINCT FROM NEW.identity_snapshot->>'organization_ref'
       OR task_input->'usage_attribution'->'identity_snapshot'->>'application_ref' IS DISTINCT FROM NEW.identity_snapshot->>'application_ref'
       OR task_input->'usage_attribution'->'identity_snapshot'->>'workload_client_ref' IS DISTINCT FROM NEW.identity_snapshot->>'workload_client_ref'
       OR task_input->'usage_attribution'->'identity_snapshot' IS DISTINCT FROM NEW.identity_snapshot
       OR jsonb_typeof(task_input->'execution_target') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target')) <> 3
       OR NOT (task_input->'execution_target' ?& ARRAY['provider','model','deployment'])
       OR jsonb_typeof(task_input->'execution_target'->'provider') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'provider')) <> 3 OR NOT (task_input->'execution_target'->'provider' ?& ARRAY['id','code','name'])
       OR jsonb_typeof(task_input->'execution_target'->'model') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'model')) <> 3 OR NOT (task_input->'execution_target'->'model' ?& ARRAY['id','code','name'])
       OR jsonb_typeof(task_input->'execution_target'->'deployment') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'deployment')) <> 4 OR NOT (task_input->'execution_target'->'deployment' ?& ARRAY['id','remote_model','revision','route'])
       OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route') <> 'object'
       OR (task_input->'execution_target'->'deployment'->'route' <> '{}'::jsonb AND (
            (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'deployment'->'route')) <> 4
            OR NOT (task_input->'execution_target'->'deployment'->'route' ?& ARRAY['selection_kind','profile_id','profile_revision','route_id'])
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'selection_kind') <> 'string'
            OR task_input->'execution_target'->'deployment'->'route'->>'selection_kind' <> 'capability_profile'
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'profile_id') <> 'number'
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'profile_revision') <> 'number'
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'route_id') <> 'number'
            OR task_input->'execution_target'->'deployment'->'route'->>'profile_id' !~ '^[1-9][0-9]*$'
            OR task_input->'execution_target'->'deployment'->'route'->>'profile_revision' !~ '^[1-9][0-9]*$'
            OR task_input->'execution_target'->'deployment'->'route'->>'route_id' !~ '^[1-9][0-9]*$'
            OR length(task_input->'execution_target'->'deployment'->'route'->>'profile_id') > 19
            OR length(task_input->'execution_target'->'deployment'->'route'->>'profile_revision') > 19
            OR length(task_input->'execution_target'->'deployment'->'route'->>'route_id') > 19
            OR (length(task_input->'execution_target'->'deployment'->'route'->>'profile_id') = 19 AND task_input->'execution_target'->'deployment'->'route'->>'profile_id' > '9223372036854775807')
            OR (length(task_input->'execution_target'->'deployment'->'route'->>'profile_revision') = 19 AND task_input->'execution_target'->'deployment'->'route'->>'profile_revision' > '9223372036854775807')
            OR (length(task_input->'execution_target'->'deployment'->'route'->>'route_id') = 19 AND task_input->'execution_target'->'deployment'->'route'->>'route_id' > '9223372036854775807')
        ))
       OR (task_input->'execution_target')::text ~* 'secret|token|password|api_key|credential|endpoint|url|response|raw|path|object_key|bucket|storage|location|[a-z][a-z0-9+.-]*://|(^|[^[:alnum:]])(/|\\\\|~|\.\.?/)'
       OR (task_input->'execution_target'->'provider'->>'id')::bigint IS DISTINCT FROM NEW.provider_id
       OR (task_input->'execution_target'->'model'->>'id')::bigint IS DISTINCT FROM NEW.model_id
       OR (task_input->'execution_target'->'deployment'->>'id')::bigint IS DISTINCT FROM NEW.deployment_id
       OR task_input->'execution_target'->'provider' IS DISTINCT FROM NEW.provider_snapshot
       OR task_input->'execution_target'->'model' IS DISTINCT FROM NEW.model_snapshot
       OR task_input->'execution_target'->'deployment' IS DISTINCT FROM NEW.deployment_snapshot THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_USAGE_TASK_BINDING_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202609280093_multiscope_budget_groups','4d16724d5abf82a7a23c603b565b8d1616baee96aa43dd1ebc219cf81a8f7c3c',CURRENT_TIMESTAMP(0));

-- Migration 202609280094_skill_source_suggestion (sha256 721160a694f7e7ebd8c85aad79d5de26153e7bc785e9d5a2fd2223d7e2b5f2ab)
-- Source-only: human adoption required; no automatic Skill learning or publication.
ALTER TABLE sand_ai_skill_version ADD COLUMN provenance jsonb NULL CHECK (provenance IS NULL OR jsonb_typeof(provenance)='object');
CREATE TABLE sand_ai_skill_source_suggestion (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL CHECK (environment_id>0),
    request_id varchar(128) NOT NULL CHECK (length(request_id)>0),
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    consumer_context jsonb NOT NULL CHECK (jsonb_typeof(consumer_context)='object'),
    consumer_context_sha256 char(64) NOT NULL CHECK (consumer_context_sha256 ~ '^[a-f0-9]{64}$'),
    evidence_sha256 char(64) NOT NULL CHECK (evidence_sha256 ~ '^[a-f0-9]{64}$'),
    input jsonb NOT NULL CHECK (jsonb_typeof(input)='object'),
    evidence jsonb NOT NULL CHECK (jsonb_typeof(evidence)='array'),
    state varchar(16) NOT NULL CHECK (state IN ('generating','pending_review','adopted','failed')),
    suggestions jsonb NULL CHECK (suggestions IS NULL OR jsonb_typeof(suggestions)='array'),
    invocation_id bigint NULL REFERENCES sand_ai_invocation(id),
    usage jsonb NULL CHECK (usage IS NULL OR jsonb_typeof(usage)='object'),
    adoption jsonb NULL CHECK (adoption IS NULL OR jsonb_typeof(adoption)='object'),
    error_code varchar(64) NULL,
    sealed_at timestamp(0) without time zone NULL,
    content_purged_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_skill_source_suggestion_request_uq UNIQUE (environment_id,request_id),
    CONSTRAINT sand_ai_skill_source_suggestion_context_ck CHECK (
        consumer_context->>'scope_source' IS NOT DISTINCT FROM 'consumer_declared'
        AND consumer_context->>'actor_source' IS NOT DISTINCT FROM 'consumer_declared'
        AND consumer_context->>'environment_id' IS NOT DISTINCT FROM environment_id::text
        AND COALESCE(consumer_context->>'organization_id','') ~ '^[1-9][0-9]*$'
        AND COALESCE(consumer_context->>'application_id','') ~ '^[1-9][0-9]*$'
        AND COALESCE(consumer_context->>'workload_client_id','') ~ '^[1-9][0-9]*$'
        AND COALESCE(consumer_context->'scope'->>'type','') IN ('personal','team','organization')
        AND COALESCE(octet_length(consumer_context->'scope'->>'ref'),0) BETWEEN 1 AND 128
        AND COALESCE(octet_length(consumer_context->>'actor_ref'),0) BETWEEN 1 AND 128
        AND (consumer_context->'scope'->>'type'<>'organization' OR consumer_context->'scope'->>'ref'=consumer_context->>'organization_id')
    ),
    CONSTRAINT sand_ai_skill_source_suggestion_result_ck CHECK (
        (content_purged_time IS NOT NULL AND evidence='[]'::jsonb AND suggestions IS NULL)
        OR (content_purged_time IS NULL AND jsonb_array_length(evidence)>0 AND (
            (state='generating' AND suggestions IS NULL AND adoption IS NULL AND error_code IS NULL AND sealed_at IS NULL)
            OR (state='pending_review' AND suggestions IS NOT NULL AND jsonb_array_length(suggestions)>0 AND invocation_id IS NOT NULL AND usage IS NOT NULL AND adoption IS NULL AND error_code IS NULL AND sealed_at IS NOT NULL)
            OR (state='adopted' AND suggestions IS NOT NULL AND invocation_id IS NOT NULL AND usage IS NOT NULL AND adoption IS NOT NULL AND error_code IS NULL AND sealed_at IS NOT NULL)
            OR (state='failed' AND suggestions IS NULL AND adoption IS NULL AND error_code IS NOT NULL AND invocation_id IS NOT NULL AND usage IS NOT NULL AND sealed_at IS NOT NULL)
        ))
    )
);
CREATE UNIQUE INDEX sand_ai_skill_source_suggestion_invocation_uq
    ON sand_ai_skill_source_suggestion(invocation_id) WHERE invocation_id IS NOT NULL;
CREATE FUNCTION sand_ai_skill_source_suggestion_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_SUGGESTION_IMMUTABLE'; END IF;
    IF ROW(NEW.environment_id,NEW.request_id,NEW.request_fingerprint,NEW.consumer_context,
           NEW.consumer_context_sha256,NEW.evidence_sha256,NEW.create_time)
        IS DISTINCT FROM ROW(OLD.environment_id,OLD.request_id,OLD.request_fingerprint,OLD.consumer_context,
           OLD.consumer_context_sha256,OLD.evidence_sha256,OLD.create_time)
        OR (OLD.invocation_id IS NOT NULL AND NEW.invocation_id IS DISTINCT FROM OLD.invocation_id)
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_SUGGESTION_IMMUTABLE'; END IF;
    IF NEW.content_purged_time IS NOT NULL THEN
        IF OLD.content_purged_time IS NOT NULL OR NEW.evidence<>'[]'::jsonb OR NEW.suggestions IS NOT NULL
            OR NEW.input IS DISTINCT FROM (OLD.input-'query'-'reuse_declaration')
            OR ROW(NEW.state,NEW.usage,NEW.adoption,NEW.error_code,NEW.sealed_at)
                IS DISTINCT FROM ROW(OLD.state,OLD.usage,OLD.adoption,OLD.error_code,OLD.sealed_at)
        THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_PURGE_INVALID'; END IF;
        RETURN NEW;
    END IF;
    IF OLD.content_purged_time IS NOT NULL OR NEW.input IS DISTINCT FROM OLD.input OR NEW.evidence IS DISTINCT FROM OLD.evidence
        OR OLD.state IN ('adopted','failed')
        OR (OLD.sealed_at IS NOT NULL AND NEW.sealed_at IS DISTINCT FROM OLD.sealed_at)
        OR (OLD.state='pending_review' AND (NEW.state<>'adopted' OR NEW.suggestions IS DISTINCT FROM OLD.suggestions OR NEW.usage IS DISTINCT FROM OLD.usage))
        OR (OLD.state='generating' AND NEW.state NOT IN ('generating','pending_review','failed'))
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_SUGGESTION_IMMUTABLE'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_source_suggestion_guard BEFORE UPDATE OR DELETE ON sand_ai_skill_source_suggestion
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_source_suggestion_guard();
CREATE FUNCTION sand_ai_skill_provenance_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.provenance IS DISTINCT FROM OLD.provenance THEN RAISE EXCEPTION 'SAND_AI_SKILL_PROVENANCE_IMMUTABLE'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_provenance_immutable BEFORE UPDATE ON sand_ai_skill_version
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_provenance_immutable();

ALTER TABLE sand_ai_invocation_outcome_receipt DROP CONSTRAINT sand_ai_outcome_receipt_consumer_ack_check;
ALTER TABLE sand_ai_invocation_outcome_receipt ADD CONSTRAINT sand_ai_outcome_receipt_consumer_ack_check CHECK (
    (consumer_ack_time IS NULL AND consumer_ref IS NULL AND retain_until IS NULL)
    OR (consumer_ack_time IS NOT NULL AND consumer_ref IS NOT NULL
        AND consumer_ref ~ '^(artifact|file_result|cross_file_review|skill_source_suggestion):[1-9][0-9]*$'
        AND retain_until IS NOT NULL AND retain_until>consumer_ack_time)
);

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202609280094_skill_source_suggestion','721160a694f7e7ebd8c85aad79d5de26153e7bc785e9d5a2fd2223d7e2b5f2ab',CURRENT_TIMESTAMP(0));

-- Migration 202609280095_skill_source_content_lifecycle (sha256 937135871071deae0ecebf6cf1af98b24e86623cd03dab6350130aec002c30a2)
-- Source-only lifecycle upgrade after 094; do not replay or rewrite historical migrations.
ALTER TABLE sand_ai_skill_version ADD COLUMN source_provenance_purged_time timestamp(0) without time zone NULL,
    ADD COLUMN source_suggestion_id bigint NULL REFERENCES sand_ai_skill_source_suggestion(id);
ALTER TABLE sand_ai_skill_source_suggestion ADD COLUMN knowledge_base_id bigint REFERENCES sand_ai_knowledge_base(id),
    ADD COLUMN knowledge_base_version_id bigint REFERENCES sand_ai_knowledge_base_version(id),
    ADD COLUMN knowledge_publication_id bigint REFERENCES sand_ai_knowledge_publication(id),
    ADD COLUMN data_class varchar(32) NULL CHECK (data_class IS NULL OR data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$'),
    ADD COLUMN accounting_outcome jsonb NULL CHECK (accounting_outcome IS NULL OR (jsonb_typeof(accounting_outcome)='object'
        AND accounting_outcome - ARRAY['version','suggestion_id','invocation_id','request_fingerprint','consumer_context_sha256','price_snapshot_sha256','price_revision_id','input_tokens','output_tokens','usage_measured','latency_ms','attribution','provider','model','deployment','provider_adapter'] = '{}'::jsonb)),
    ADD COLUMN purge_request_id varchar(128) NULL,
    ADD COLUMN purge_request_fingerprint char(64) NULL CHECK (purge_request_fingerprint IS NULL OR purge_request_fingerprint ~ '^[a-f0-9]{64}$'),
    ADD COLUMN purged_actor_type varchar(32) NULL CHECK (purged_actor_type IS NULL OR purged_actor_type IN ('admin','api_credential')),
    ADD COLUMN purged_actor_ref varchar(128) NULL,
    ADD COLUMN purge_reason varchar(32) NULL CHECK (purge_reason IS NULL OR purge_reason IN ('consumer_requested','source_purged'));
-- No reachable pre-095 purge was supplied. Never invent the actor or dependencies of an unknown tombstone.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM sand_ai_skill_source_suggestion WHERE content_purged_time IS NOT NULL)
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_LEGACY_PURGE_REQUIRES_RECONCILIATION'; END IF;
END $$;
-- The transient migration exception permits only verified typed dependency backfill; no content mutation.
CREATE OR REPLACE FUNCTION sand_ai_skill_source_suggestion_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' AND OLD.knowledge_base_id IS NULL AND OLD.knowledge_base_version_id IS NULL AND OLD.knowledge_publication_id IS NULL
        AND to_jsonb(NEW)-ARRAY['knowledge_base_id','knowledge_base_version_id','knowledge_publication_id','data_class']
            = to_jsonb(OLD)-ARRAY['knowledge_base_id','knowledge_base_version_id','knowledge_publication_id','data_class']
        AND EXISTS (SELECT 1 FROM sand_ai_knowledge_base_version v JOIN sand_ai_knowledge_publication p ON p.knowledge_base_id=v.knowledge_base_id
            WHERE v.id=NEW.knowledge_base_version_id AND v.id::text=OLD.input->>'knowledge_base_version_id'
                AND v.knowledge_base_id=NEW.knowledge_base_id AND v.snapshot_sha256=OLD.input->>'snapshot_sha256'
                AND v.data_class IS NOT DISTINCT FROM NEW.data_class AND p.id=NEW.knowledge_publication_id
                AND p.consumer_environment_id=OLD.environment_id
                AND p.consumer_organization_id::text=OLD.consumer_context->>'organization_id'
                AND p.consumer_application_id::text=OLD.consumer_context->>'application_id') THEN RETURN NEW; END IF;
    RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_BACKFILL_INVALID';
END $$;
UPDATE sand_ai_skill_source_suggestion s SET knowledge_base_id=v.knowledge_base_id,knowledge_base_version_id=v.id,
    knowledge_publication_id=p.id,data_class=v.data_class
FROM sand_ai_knowledge_base_version v JOIN sand_ai_knowledge_publication p ON p.knowledge_base_id=v.knowledge_base_id
WHERE v.id::text=s.input->>'knowledge_base_version_id' AND v.snapshot_sha256=s.input->>'snapshot_sha256'
    AND p.consumer_environment_id=s.environment_id AND p.consumer_organization_id::text=s.consumer_context->>'organization_id'
    AND p.consumer_application_id::text=s.consumer_context->>'application_id';
ALTER TABLE sand_ai_skill_source_suggestion ALTER COLUMN knowledge_base_id SET NOT NULL,
    ALTER COLUMN knowledge_base_version_id SET NOT NULL, ALTER COLUMN knowledge_publication_id SET NOT NULL;
CREATE OR REPLACE FUNCTION sand_ai_skill_version_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' AND OLD.source_suggestion_id IS NULL AND NEW.source_suggestion_id IS NOT NULL
        AND to_jsonb(NEW)-'source_suggestion_id'=to_jsonb(OLD)-'source_suggestion_id'
        AND EXISTS (SELECT 1 FROM sand_ai_skill_source_suggestion s JOIN sand_ai_skill k ON k.id=OLD.skill_id
            WHERE s.id=NEW.source_suggestion_id AND s.id::text=OLD.provenance->>'source_suggestion_id'
                AND k.environment_id=s.environment_id AND k.organization_id::text=s.consumer_context->>'organization_id'
                AND k.application_id::text=s.consumer_context->>'application_id'
                AND k.scope_type=s.consumer_context->'scope'->>'type' AND k.scope_ref=s.consumer_context->'scope'->>'ref') THEN RETURN NEW; END IF;
    RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_BACKFILL_INVALID';
END $$;
UPDATE sand_ai_skill_version v SET source_suggestion_id=s.id FROM sand_ai_skill_source_suggestion s
WHERE v.provenance->>'source_suggestion_id'=s.id::text;
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM sand_ai_skill_version WHERE provenance ? 'source_suggestion_id' AND source_suggestion_id IS NULL)
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_BACKFILL_INVALID'; END IF;
END $$;
ALTER TABLE sand_ai_skill_source_suggestion DROP CONSTRAINT sand_ai_skill_source_suggestion_result_ck;
ALTER TABLE sand_ai_skill_source_suggestion ADD CONSTRAINT sand_ai_skill_source_suggestion_result_ck CHECK (
        (content_purged_time IS NOT NULL AND evidence='[]'::jsonb AND suggestions IS NULL
            AND NOT (input?'query') AND NOT (input?'reuse_declaration')
            AND purge_request_id IS NOT NULL AND purge_request_fingerprint IS NOT NULL AND purged_actor_type IS NOT NULL AND purged_actor_ref IS NOT NULL AND purge_reason IS NOT NULL)
        OR (content_purged_time IS NULL AND jsonb_array_length(evidence)>0 AND (
            (state='generating' AND suggestions IS NULL AND adoption IS NULL AND error_code IS NULL AND sealed_at IS NULL)
            OR (state='pending_review' AND suggestions IS NOT NULL AND jsonb_array_length(suggestions)>0 AND invocation_id IS NOT NULL AND usage IS NOT NULL AND adoption IS NULL AND error_code IS NULL AND sealed_at IS NOT NULL)
            OR (state='adopted' AND suggestions IS NOT NULL AND invocation_id IS NOT NULL AND usage IS NOT NULL AND adoption IS NOT NULL AND error_code IS NULL AND sealed_at IS NOT NULL)
            OR (state='failed' AND suggestions IS NULL AND adoption IS NULL AND error_code IS NOT NULL AND invocation_id IS NOT NULL AND usage IS NOT NULL AND sealed_at IS NOT NULL)
        ))
    );
CREATE INDEX sand_ai_skill_source_suggestion_base_idx ON sand_ai_skill_source_suggestion(knowledge_base_id,id);
CREATE INDEX sand_ai_skill_source_suggestion_version_idx ON sand_ai_skill_source_suggestion(knowledge_base_version_id,id);
CREATE UNIQUE INDEX sand_ai_skill_source_suggestion_purge_key_uq ON sand_ai_skill_source_suggestion(environment_id,purge_request_id) WHERE purge_request_id IS NOT NULL;
CREATE INDEX sand_ai_skill_version_source_suggestion_idx ON sand_ai_skill_version(source_suggestion_id) WHERE source_suggestion_id IS NOT NULL;

CREATE OR REPLACE FUNCTION sand_ai_skill_source_suggestion_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_SUGGESTION_IMMUTABLE'; END IF;
    IF ROW(NEW.environment_id,NEW.request_id,NEW.request_fingerprint,NEW.consumer_context,
           NEW.consumer_context_sha256,NEW.evidence_sha256,NEW.knowledge_base_id,NEW.knowledge_base_version_id,NEW.knowledge_publication_id,NEW.data_class,NEW.create_time)
        IS DISTINCT FROM ROW(OLD.environment_id,OLD.request_id,OLD.request_fingerprint,OLD.consumer_context,
           OLD.consumer_context_sha256,OLD.evidence_sha256,OLD.knowledge_base_id,OLD.knowledge_base_version_id,OLD.knowledge_publication_id,OLD.data_class,OLD.create_time)
        OR (OLD.invocation_id IS NOT NULL AND NEW.invocation_id IS DISTINCT FROM OLD.invocation_id)
        OR (OLD.accounting_outcome IS NOT NULL AND NEW.accounting_outcome IS DISTINCT FROM OLD.accounting_outcome)
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_SUGGESTION_IMMUTABLE'; END IF;
    IF OLD.content_purged_time IS NOT NULL THEN
        IF NEW.content_purged_time IS DISTINCT FROM OLD.content_purged_time
            OR to_jsonb(NEW)-ARRAY['accounting_outcome','usage','update_time'] IS DISTINCT FROM to_jsonb(OLD)-ARRAY['accounting_outcome','usage','update_time']
            OR (OLD.usage IS NOT NULL AND NEW.usage IS DISTINCT FROM OLD.usage)
        THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_PURGE_INVALID'; END IF;
        RETURN NEW;
    END IF;
    IF NEW.content_purged_time IS NOT NULL THEN
        IF NEW.invocation_id IS DISTINCT FROM OLD.invocation_id OR NEW.evidence<>'[]'::jsonb OR NEW.suggestions IS NOT NULL
            OR NEW.input IS DISTINCT FROM (OLD.input-'query'-'reuse_declaration')
            OR ROW(NEW.state,NEW.usage,NEW.adoption,NEW.error_code,NEW.sealed_at)
                IS DISTINCT FROM ROW(OLD.state,OLD.usage,OLD.adoption,OLD.error_code,OLD.sealed_at)
        THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_PURGE_INVALID'; END IF;
        RETURN NEW;
    END IF;
    IF to_jsonb(NEW)-ARRAY['accounting_outcome','update_time'] = to_jsonb(OLD)-ARRAY['accounting_outcome','update_time'] THEN RETURN NEW; END IF;
    IF NEW.input IS DISTINCT FROM OLD.input OR NEW.evidence IS DISTINCT FROM OLD.evidence
        OR ROW(NEW.purge_request_id,NEW.purge_request_fingerprint,NEW.purged_actor_type,NEW.purged_actor_ref,NEW.purge_reason)
            IS DISTINCT FROM ROW(OLD.purge_request_id,OLD.purge_request_fingerprint,OLD.purged_actor_type,OLD.purged_actor_ref,OLD.purge_reason)
        OR OLD.state IN ('adopted','failed')
        OR (OLD.sealed_at IS NOT NULL AND NEW.sealed_at IS DISTINCT FROM OLD.sealed_at)
        OR (OLD.state='pending_review' AND (NEW.state<>'adopted' OR NEW.suggestions IS DISTINCT FROM OLD.suggestions OR NEW.usage IS DISTINCT FROM OLD.usage))
        OR (OLD.state='generating' AND NEW.state NOT IN ('generating','pending_review','failed'))
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_SUGGESTION_IMMUTABLE'; END IF;
    RETURN NEW;
END $$;
CREATE FUNCTION sand_ai_skill_source_redaction_allowed(old_version sand_ai_skill_version,new_version sand_ai_skill_version) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE owner_row sand_ai_skill_source_suggestion%ROWTYPE; skill_row sand_ai_skill%ROWTYPE; tombstone jsonb;
BEGIN
    IF old_version.source_suggestion_id IS NULL OR new_version.source_suggestion_id IS DISTINCT FROM old_version.source_suggestion_id
        OR new_version.source_provenance_purged_time IS NULL THEN RETURN false; END IF;
    SELECT * INTO owner_row FROM sand_ai_skill_source_suggestion WHERE id=old_version.source_suggestion_id;
    SELECT * INTO skill_row FROM sand_ai_skill WHERE id=old_version.skill_id;
    IF owner_row.id IS NULL OR owner_row.content_purged_time IS NULL OR skill_row.id IS NULL
        OR new_version.source_provenance_purged_time IS DISTINCT FROM owner_row.content_purged_time
        OR skill_row.environment_id IS DISTINCT FROM owner_row.environment_id
        OR skill_row.organization_id::text IS DISTINCT FROM owner_row.consumer_context->>'organization_id'
        OR skill_row.application_id::text IS DISTINCT FROM owner_row.consumer_context->>'application_id'
        OR skill_row.scope_type IS DISTINCT FROM owner_row.consumer_context->'scope'->>'type'
        OR skill_row.scope_ref IS DISTINCT FROM owner_row.consumer_context->'scope'->>'ref'
        OR skill_row.scope_source IS DISTINCT FROM 'consumer_declared'
        OR (old_version.source_provenance_purged_time IS NOT NULL AND old_version.source_provenance_purged_time IS DISTINCT FROM new_version.source_provenance_purged_time)
    THEN RETURN false; END IF;
    tombstone := jsonb_build_object('source_suggestion_id',owner_row.id,'invocation_id',owner_row.invocation_id,
        'knowledge_base_id',owner_row.knowledge_base_id,'knowledge_base_version_id',owner_row.knowledge_base_version_id,
        'knowledge_publication_id',owner_row.knowledge_publication_id,'snapshot_sha256',owner_row.input->>'snapshot_sha256',
        'consumer_context_sha256',owner_row.consumer_context_sha256,'evidence_sha256',owner_row.evidence_sha256,'content_purged',true);
    RETURN new_version.provenance=tombstone
        AND to_jsonb(new_version)-ARRAY['provenance','source_provenance_purged_time','update_time']
            = to_jsonb(old_version)-ARRAY['provenance','source_provenance_purged_time','update_time'];
END $$;
CREATE OR REPLACE FUNCTION sand_ai_skill_version_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' AND sand_ai_skill_source_redaction_allowed(OLD,NEW) THEN RETURN NEW; END IF;
    IF TG_OP='DELETE' OR OLD.state IN ('published','revoked') THEN RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_IMMUTABLE'; END IF;
    IF NEW.state NOT IN ('draft','published','revoked') THEN RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_STATE_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION sand_ai_skill_provenance_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF sand_ai_skill_source_redaction_allowed(OLD,NEW) THEN RETURN NEW; END IF;
    IF NEW.provenance IS DISTINCT FROM OLD.provenance
        OR NEW.source_suggestion_id IS DISTINCT FROM OLD.source_suggestion_id
        OR NEW.source_provenance_purged_time IS DISTINCT FROM OLD.source_provenance_purged_time
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_PROVENANCE_IMMUTABLE'; END IF;
    RETURN NEW;
END $$;
-- Prevent a generation insert from racing the source owner's base lock and source mapping deletion.
CREATE FUNCTION sand_ai_skill_source_suggestion_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_row record;
BEGIN
    SELECT kb.id base_id,v.id version_id,p.id publication_id,v.snapshot_sha256,v.data_class,
        p.consumer_environment_id,p.consumer_organization_id,p.consumer_application_id
    INTO source_row FROM sand_ai_knowledge_base kb
        JOIN sand_ai_knowledge_base_version v ON v.knowledge_base_id=kb.id AND v.id=NEW.knowledge_base_version_id
        JOIN sand_ai_knowledge_publication p ON p.knowledge_base_id=kb.id AND p.id=NEW.knowledge_publication_id
    WHERE kb.id=NEW.knowledge_base_id AND kb.state='published' AND kb.status=1 AND kb.delete_time IS NULL
        AND v.state='published' AND p.state='published' FOR SHARE OF kb,v,p;
    IF source_row.base_id IS NULL
        OR source_row.consumer_environment_id IS DISTINCT FROM NEW.environment_id
        OR source_row.consumer_organization_id::text IS DISTINCT FROM NEW.consumer_context->>'organization_id'
        OR source_row.consumer_application_id::text IS DISTINCT FROM NEW.consumer_context->>'application_id'
        OR source_row.snapshot_sha256 IS DISTINCT FROM NEW.input->>'snapshot_sha256'
        OR source_row.version_id::text IS DISTINCT FROM NEW.input->>'knowledge_base_version_id'
        OR source_row.data_class IS DISTINCT FROM NEW.data_class
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_DEPENDENCY_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_source_suggestion_source_guard BEFORE INSERT ON sand_ai_skill_source_suggestion
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_source_suggestion_source_guard();
CREATE FUNCTION sand_ai_skill_adopted_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_row sand_ai_skill_source_suggestion%ROWTYPE; skill_row sand_ai_skill%ROWTYPE;
BEGIN
    IF NEW.source_suggestion_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO owner_row FROM sand_ai_skill_source_suggestion WHERE id=NEW.source_suggestion_id FOR SHARE;
    SELECT * INTO skill_row FROM sand_ai_skill WHERE id=NEW.skill_id;
    IF owner_row.id IS NULL OR owner_row.content_purged_time IS NOT NULL OR owner_row.state<>'pending_review'
        OR skill_row.environment_id IS DISTINCT FROM owner_row.environment_id
        OR skill_row.organization_id::text IS DISTINCT FROM owner_row.consumer_context->>'organization_id'
        OR skill_row.application_id::text IS DISTINCT FROM owner_row.consumer_context->>'application_id'
        OR skill_row.scope_type IS DISTINCT FROM owner_row.consumer_context->'scope'->>'type'
        OR skill_row.scope_ref IS DISTINCT FROM owner_row.consumer_context->'scope'->>'ref'
        OR (NEW.provenance->>'source_suggestion_id') IS DISTINCT FROM NEW.source_suggestion_id::text
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_ADOPTED_SOURCE_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_adopted_source_guard BEFORE INSERT ON sand_ai_skill_version
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_adopted_source_guard();

-- Every successful purge request, including an already purged owner's replay, binds its key.
CREATE TABLE sand_ai_skill_source_suggestion_purge (
    environment_id bigint NOT NULL CHECK (environment_id>0),
    request_id char(64) NOT NULL CHECK (request_id ~ '^[a-f0-9]{64}$'),
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    suggestion_id bigint NOT NULL REFERENCES sand_ai_skill_source_suggestion(id),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    PRIMARY KEY(environment_id,request_id)
);
CREATE FUNCTION sand_ai_skill_source_purge_request_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'SAND_AI_SKILL_SOURCE_PURGE_REQUEST_IMMUTABLE'; END $$;
CREATE TRIGGER sand_ai_skill_source_purge_request_immutable BEFORE UPDATE OR DELETE ON sand_ai_skill_source_suggestion_purge
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_source_purge_request_immutable();

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202609280095_skill_source_content_lifecycle','937135871071deae0ecebf6cf1af98b24e86623cd03dab6350130aec002c30a2',CURRENT_TIMESTAMP(0));

-- Migration 202609280096_billing_operator_review (sha256 016f4fbc3737b2db5d8db7e5a6722023d78ea9c891c0decac4915891acebb27f)
CREATE TABLE IF NOT EXISTS sand_ai_billing_evidence (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    provider_id BIGINT NOT NULL REFERENCES sand_ai_provider(id) ON DELETE RESTRICT,
    admin_id BIGINT NOT NULL CHECK (admin_id > 0),
    sha256 VARCHAR(64) NOT NULL CHECK (sha256 ~ '^[a-f0-9]{64}$'),
    byte_size BIGINT NOT NULL CHECK (byte_size BETWEEN 1 AND 10485760),
    media_type VARCHAR(64) NOT NULL CHECK (media_type IN ('application/pdf','text/plain','text/csv','application/csv')),
    storage_driver VARCHAR(32) NOT NULL CHECK (storage_driver IN ('local-private','oss-private')),
    object_key VARCHAR(256),
    state VARCHAR(16) NOT NULL DEFAULT 'upload_pending',
    cleared_time TIMESTAMP,
    create_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sand_ai_billing_evidence_file UNIQUE (provider_id,sha256),
    CONSTRAINT ck_sand_ai_billing_evidence_state CHECK (
        (state IN ('upload_pending','available','clearing') AND object_key IS NOT NULL AND cleared_time IS NULL)
        OR (state = 'cleared' AND object_key IS NULL AND cleared_time IS NOT NULL)
    )
);
COMMENT ON TABLE sand_ai_billing_evidence IS 'Operator supplied private billing evidence; hash establishes file consistency, not provider authentication';
COMMENT ON COLUMN sand_ai_billing_evidence.object_key IS 'Internal private storage key, never returned through DTOs';

CREATE TABLE IF NOT EXISTS sand_ai_billing_review (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    billing_line_id BIGINT NOT NULL REFERENCES sand_ai_billing_line(id) ON DELETE RESTRICT,
    evidence_id BIGINT NOT NULL REFERENCES sand_ai_billing_evidence(id) ON DELETE RESTRICT,
    admin_id BIGINT NOT NULL CHECK (admin_id > 0),
    idempotency_key VARCHAR(128) NOT NULL,
    fingerprint VARCHAR(64) NOT NULL CHECK (fingerprint ~ '^[a-f0-9]{64}$'),
    evidence_locator VARCHAR(256) NOT NULL,
    granularity VARCHAR(16) NOT NULL CHECK (granularity IN ('request','aggregate')),
    decision VARCHAR(16) NOT NULL CHECK (decision IN ('compare','reject','unresolved')),
    note VARCHAR(2000) NOT NULL CHECK (length(note) > 0),
    request_evidence_confirmed BOOLEAN NOT NULL,
    usage_event_id BIGINT REFERENCES sand_ai_usage_event(id) ON DELETE RESTRICT,
    environment_id BIGINT,
    comparison_state VARCHAR(32) NOT NULL CHECK (comparison_state IN ('reviewed_match','reviewed_discrepancy','unmatched','aggregate_only','rejected','unresolved')),
    reason VARCHAR(64) NOT NULL,
    quantity_difference NUMERIC(20,6),
    amount_difference NUMERIC(20,6),
    create_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sand_ai_billing_review_key UNIQUE (admin_id,billing_line_id,idempotency_key),
    CONSTRAINT ck_sand_ai_billing_review_event CHECK (
        (granularity = 'request' AND decision = 'compare' AND usage_event_id IS NOT NULL AND environment_id > 0 AND request_evidence_confirmed)
        OR (usage_event_id IS NULL AND environment_id IS NULL AND NOT request_evidence_confirmed AND (granularity = 'aggregate' OR decision <> 'compare'))
    ),
    CONSTRAINT ck_sand_ai_billing_review_difference CHECK (
        (comparison_state IN ('reviewed_match','reviewed_discrepancy') AND quantity_difference IS NOT NULL AND amount_difference IS NOT NULL)
        OR (comparison_state NOT IN ('reviewed_match','reviewed_discrepancy') AND quantity_difference IS NULL AND amount_difference IS NULL)
    )
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_billing_review_final_line ON sand_ai_billing_review(billing_line_id) WHERE decision IN ('compare','reject');
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_billing_review_event ON sand_ai_billing_review(usage_event_id) WHERE usage_event_id IS NOT NULL AND decision = 'compare';
CREATE INDEX IF NOT EXISTS idx_sand_ai_billing_review_line ON sand_ai_billing_review(billing_line_id,id);
COMMENT ON TABLE sand_ai_billing_review IS 'Append-only operator reviewed comparison, never provider verified actual cost';
COMMENT ON COLUMN sand_ai_billing_review.evidence_locator IS 'Operator declared page or row locator, not a parser certified record identity';

CREATE OR REPLACE FUNCTION sand_ai_billing_evidence_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.state <> 'upload_pending' THEN RAISE EXCEPTION 'Evidence owner must begin pending upload'; END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Billing evidence tombstones cannot be deleted';
    END IF;
    IF NOT ((OLD.state='upload_pending' AND NEW.state='available')
        OR (OLD.state IN ('upload_pending','available') AND NEW.state='clearing')
        OR (OLD.state='clearing' AND NEW.state='cleared'))
        OR (to_jsonb(NEW) - ARRAY['state','object_key','cleared_time']) IS DISTINCT FROM
           (to_jsonb(OLD) - ARRAY['state','object_key','cleared_time'])
        OR (NEW.state <> 'cleared' AND NEW.object_key IS DISTINCT FROM OLD.object_key) THEN
        RAISE EXCEPTION 'Invalid billing evidence lifecycle transition';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS sand_ai_billing_evidence_guard ON sand_ai_billing_evidence;
CREATE TRIGGER sand_ai_billing_evidence_guard BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_billing_evidence
    FOR EACH ROW EXECUTE FUNCTION sand_ai_billing_evidence_guard();
DROP TRIGGER IF EXISTS sand_ai_billing_review_immutable ON sand_ai_billing_review;
CREATE TRIGGER sand_ai_billing_review_immutable BEFORE UPDATE OR DELETE ON sand_ai_billing_review
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();

CREATE OR REPLACE FUNCTION sand_ai_billing_review_guard() RETURNS trigger AS $$
DECLARE
    line sand_ai_billing_line%ROWTYPE;
    evidence sand_ai_billing_evidence%ROWTYPE;
    event sand_ai_usage_event%ROWTYPE;
    owner sand_ai_invocation%ROWTYPE;
    line_provider bigint;
BEGIN
    SELECT * INTO STRICT line FROM sand_ai_billing_line WHERE id = NEW.billing_line_id;
    SELECT provider_id INTO STRICT line_provider FROM sand_ai_billing_import WHERE id = line.billing_import_id;
    SELECT * INTO STRICT evidence FROM sand_ai_billing_evidence WHERE id = NEW.evidence_id;
    IF evidence.provider_id IS DISTINCT FROM line_provider OR evidence.state <> 'available' THEN
        RAISE EXCEPTION 'Billing evidence must be available and match provider';
    END IF;
    IF NEW.decision = 'reject' AND NEW.comparison_state <> 'rejected'
        OR NEW.decision = 'unresolved' AND NEW.comparison_state <> 'unresolved'
        OR NEW.decision = 'compare' AND NEW.granularity = 'aggregate' AND NEW.comparison_state <> 'aggregate_only' THEN
        RAISE EXCEPTION 'Billing review decision differs from comparison';
    END IF;
    IF NEW.usage_event_id IS NOT NULL THEN
        SELECT * INTO STRICT event FROM sand_ai_usage_event WHERE id = NEW.usage_event_id;
        SELECT * INTO STRICT owner FROM sand_ai_invocation WHERE id = event.invocation_id;
        IF line.meter_code <> 'total_tokens' OR NEW.environment_id IS DISTINCT FROM event.environment_id
            OR event.provider_id IS DISTINCT FROM line_provider
            OR (event.pricing_state='priced' AND event.currency IS DISTINCT FROM line.currency)
            OR event.capability_code IS DISTINCT FROM line.capability_code
            OR (event.model_snapshot->>'code') IS DISTINCT FROM line.model_code
            OR event.create_time < line.occurred_start OR event.create_time >= line.occurred_end
            OR owner.organization_id IS NULL OR owner.application_id IS NULL OR owner.environment_id IS NULL OR owner.workload_client_id IS NULL
            OR (event.organization_id,event.application_id,event.environment_id,event.workload_client_id)
                IS DISTINCT FROM (owner.organization_id,owner.application_id,owner.environment_id,owner.workload_client_id) THEN
            RAISE EXCEPTION 'Billing operator event association differs from persisted facts';
        END IF;
        IF NEW.comparison_state NOT IN ('reviewed_match','reviewed_discrepancy','unmatched') THEN
            RAISE EXCEPTION 'Invalid request comparison state';
        END IF;
        IF event.usage_state <> 'measured' OR event.total_tokens IS NULL
            OR event.pricing_state <> 'priced' OR event.metered_amount IS NULL THEN
            IF NEW.comparison_state <> 'unmatched' THEN RAISE EXCEPTION 'Unknown usage or price cannot be compared'; END IF;
        ELSE
            IF NEW.quantity_difference IS DISTINCT FROM (line.quantity - event.total_tokens)
                OR NEW.amount_difference IS DISTINCT FROM (line.amount - event.metered_amount)
                OR (NEW.comparison_state = 'reviewed_match') IS DISTINCT FROM
                    (line.quantity = event.total_tokens AND line.amount = event.metered_amount) THEN
                RAISE EXCEPTION 'Billing review differences must equal immutable facts';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS sand_ai_billing_review_guard ON sand_ai_billing_review;
CREATE TRIGGER sand_ai_billing_review_guard BEFORE INSERT ON sand_ai_billing_review
    FOR EACH ROW EXECUTE FUNCTION sand_ai_billing_review_guard();

-- Records only objects inserted by this declaration. Compatible pre-existing menus are borrowed.
CREATE TABLE sand_ai_billing_navigation_ownership (
    object_kind VARCHAR(16) NOT NULL CHECK (object_kind IN ('menu','role_menu')),
    object_id BIGINT NOT NULL CHECK (object_id > 0),
    definition JSONB NOT NULL CHECK (jsonb_typeof(definition) = 'object'),
    PRIMARY KEY (object_kind,object_id)
);
COMMENT ON TABLE sand_ai_billing_navigation_ownership IS 'Exact ownership of new billing navigation nodes and navigation-only role links; no automatic API grants';
CREATE TRIGGER sand_ai_billing_navigation_ownership_immutable BEFORE UPDATE OR DELETE ON sand_ai_billing_navigation_ownership
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();

DO $billing_navigation$
DECLARE
    root_id bigint;
    page_id bigint;
    node_id bigint;
    link_id bigint;
    count_nodes integer;
    specification record;
    granted_role record;
    navigation_id bigint;
BEGIN
    -- Host tables have no code/slug or role-menu tuple uniqueness. Serialize declaration with edits.
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,root_id FROM sand_system_menu WHERE code = 'SandAI';
    IF count_nodes > 1 OR EXISTS(SELECT 1 FROM sand_system_menu WHERE parent_id = 0 AND path = '/sand-ai' AND code IS DISTINCT FROM 'SandAI') THEN
        RAISE EXCEPTION 'SandAI root navigation conflicts with host menu';
    END IF;
    IF count_nodes = 0 THEN
        INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
        VALUES(0,'SandAI','SandAI','',1,'/sand-ai','','',80,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id INTO root_id;
        INSERT INTO sand_ai_billing_navigation_ownership
        SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
        FROM sand_system_menu menu WHERE id=root_id;
    ELSIF NOT EXISTS(SELECT 1 FROM sand_system_menu WHERE id=root_id AND parent_id=0 AND type=1 AND path='/sand-ai' AND COALESCE(component,'')='' AND COALESCE(slug,'')='' AND status=1 AND delete_time IS NULL) THEN
        RAISE EXCEPTION 'Existing SandAI root navigation has incompatible declaration';
    END IF;
    SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu WHERE code='SandAIUsage';
    IF count_nodes > 1 OR EXISTS(SELECT 1 FROM sand_system_menu WHERE parent_id=root_id AND path='usage' AND code IS DISTINCT FROM 'SandAIUsage') THEN
        RAISE EXCEPTION 'SandAI usage navigation conflicts with host menu';
    END IF;
    IF count_nodes = 0 THEN
        INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
        VALUES(root_id,'费用与账单','SandAIUsage','',2,'usage','/plugin/sand-ai/usage/index','',40,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id INTO page_id;
        INSERT INTO sand_ai_billing_navigation_ownership
        SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
        FROM sand_system_menu menu WHERE id=page_id;
    ELSIF NOT EXISTS(SELECT 1 FROM sand_system_menu WHERE id=page_id AND parent_id=root_id AND type=2 AND path='usage' AND component='/plugin/sand-ai/usage/index' AND COALESCE(slug,'')='' AND status=1 AND delete_time IS NULL) THEN
        RAISE EXCEPTION 'Existing SandAI usage navigation has incompatible declaration';
    END IF;
    FOR specification IN SELECT * FROM (VALUES
        ('用量记录','sand_ai:usage:index',1),
        ('查看账单','sand_ai:billing:index',2),
        ('导入账单','sand_ai:billing:import',3),
        ('人工账单审核','sand_ai:billing:review',4)
    ) AS permissions(name,code,sort) LOOP
        SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu WHERE code=specification.code OR slug=specification.code;
        IF count_nodes > 1 THEN RAISE EXCEPTION 'SandAI billing permission is duplicated: %',specification.code; END IF;
        IF count_nodes=0 THEN
            INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
            VALUES(page_id,specification.name,specification.code,specification.code,3,'','','',specification.sort,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id INTO node_id;
            INSERT INTO sand_ai_billing_navigation_ownership
            SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
            FROM sand_system_menu menu WHERE id=node_id;
        ELSIF NOT EXISTS(SELECT 1 FROM sand_system_menu WHERE id=node_id AND code=specification.code AND slug=specification.code AND parent_id=page_id AND type=3 AND status=1 AND delete_time IS NULL) THEN
            RAISE EXCEPTION 'Existing SandAI billing permission has incompatible declaration: %',specification.code;
        END IF;
    END LOOP;
    -- Only roles already holding an existing read permission get the missing navigation links.
    FOR granted_role IN
        SELECT DISTINCT role_menu.role_id FROM sand_system_role_menu role_menu
        JOIN sand_system_menu permission ON permission.id=role_menu.menu_id
        JOIN sand_system_role role ON role.id=role_menu.role_id
        WHERE permission.type=3 AND permission.status=1 AND permission.delete_time IS NULL
          AND role.status=1 AND role.delete_time IS NULL
          AND permission.slug IN ('sand_ai:usage:index','sand_ai:billing:index')
    LOOP
        FOREACH navigation_id IN ARRAY ARRAY[root_id,page_id] LOOP
            IF NOT EXISTS(SELECT 1 FROM sand_system_role_menu WHERE role_id=granted_role.role_id AND menu_id=navigation_id) THEN
                INSERT INTO sand_system_role_menu(role_id,menu_id) VALUES(granted_role.role_id,navigation_id) RETURNING id INTO link_id;
                INSERT INTO sand_ai_billing_navigation_ownership
                SELECT 'role_menu',id,jsonb_build_object('role_id',role_id,'menu_id',menu_id)
                FROM sand_system_role_menu WHERE id=link_id;
            END IF;
        END LOOP;
    END LOOP;
END
$billing_navigation$;

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202609280096_billing_operator_review','016f4fbc3737b2db5d8db7e5a6722023d78ea9c891c0decac4915891acebb27f',CURRENT_TIMESTAMP(0));

-- Migration 202610010097_material_file_payload_digest (sha256 b000c12b2571ba7e825ec3d19ff06b6efb47943f8553c122abbc0c6452fe634a)
-- Source-only migration. Apply only after explicit database authorization.
-- NULL identifies legacy evidence. Do not rehash or backfill immutable rows.
ALTER TABLE sand_ai_workflow_file_result
    ADD COLUMN payload_digest_version smallint NULL,
    ADD CONSTRAINT ck_sand_ai_workflow_file_result_digest_version
        CHECK (payload_digest_version IS NULL OR payload_digest_version = 2);

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610010097_material_file_payload_digest','b000c12b2571ba7e825ec3d19ff06b6efb47943f8553c122abbc0c6452fe634a',CURRENT_TIMESTAMP(0));

-- Migration 202610010098_audio_transcript_seal (sha256 e13c2d807c8e390d1e18ce0c6c525cd7eef01892ce3cbb2b2ce2aefafae2a42f)
-- Source only. No historical transcript is promoted to sealed evidence.
ALTER TABLE sand_ai_task_run ADD COLUMN audio_seal_eligible boolean NOT NULL DEFAULT false;

CREATE TABLE sand_ai_audio_transcript_seal (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    audio_task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    review_revision integer NOT NULL CHECK (review_revision BETWEEN 0 AND 10000),
    review_id bigint NULL REFERENCES sand_ai_audio_segment_review(id) ON DELETE RESTRICT,
    previous_seal_id bigint NULL REFERENCES sand_ai_audio_transcript_seal(id) ON DELETE RESTRICT,
    base_seal_id bigint NULL REFERENCES sand_ai_audio_transcript_seal(id) ON DELETE RESTRICT,
    normalization_version varchar(48) NOT NULL DEFAULT 'audio_transcript_seal@1',
    transcript_sha256 char(64) NOT NULL,
    base_sha256 char(64) NOT NULL,
    previous_sha256 char(64) NULL,
    environment_id bigint NOT NULL,
    task_version integer NOT NULL,
    file_id bigint NOT NULL REFERENCES sand_ai_file(id) ON DELETE RESTRICT,
    file_ref varchar(96) NOT NULL,
    file_sha256 char(64) NOT NULL,
    owner_scope char(64) NOT NULL,
    data_egress_policy varchar(24) NOT NULL,
    total_segments integer NOT NULL CHECK (total_segments BETWEEN 1 AND 10000),
    eligible_segments integer NOT NULL CHECK (eligible_segments BETWEEN 0 AND total_segments),
    -- A BEFORE INSERT trigger validates and consumes this transient parameter.
    -- Neither original nor reviewed transcript bodies are retained in this ledger.
    projection_text text NULL CHECK (projection_text IS NULL),
    content_purged_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_audio_transcript_seal_revision UNIQUE (audio_task_id, review_revision),
    CONSTRAINT ck_sand_ai_audio_transcript_seal_digest CHECK (
        transcript_sha256 ~ '^[a-f0-9]{64}$' AND base_sha256 ~ '^[a-f0-9]{64}$'
        AND file_sha256 ~ '^[a-f0-9]{64}$' AND owner_scope ~ '^[a-f0-9]{64}$'
        AND normalization_version = 'audio_transcript_seal@1'
        AND data_egress_policy IN ('local_only', 'approved_cloud')
        AND ((review_revision = 0 AND review_id IS NULL AND previous_seal_id IS NULL
            AND base_seal_id IS NULL AND previous_sha256 IS NULL AND base_sha256 = transcript_sha256)
         OR (review_revision > 0 AND review_id IS NOT NULL AND previous_seal_id IS NOT NULL
            AND base_seal_id IS NOT NULL AND previous_sha256 ~ '^[a-f0-9]{64}$'))
    )
);
-- Bounded lookups for the latest text/speaker at each segment while sealing.
CREATE INDEX ix_sand_ai_audio_review_segment_revision
    ON sand_ai_audio_segment_review(audio_task_id, segment_index, revision DESC);
CREATE INDEX ix_sand_ai_audio_review_speaker_revision
    ON sand_ai_audio_segment_review(audio_task_id, segment_index, revision DESC)
    WHERE speaker_changed;

CREATE FUNCTION sand_ai_audio_transcript_seal_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    task sand_ai_task_run%ROWTYPE;
    base sand_ai_audio_transcript_seal%ROWTYPE;
    previous sand_ai_audio_transcript_seal%ROWTYPE;
    review sand_ai_audio_segment_review%ROWTYPE;
    projected jsonb;
    candidate jsonb;
    projected_segments jsonb;
BEGIN
    SELECT * INTO task FROM sand_ai_task_run WHERE id = NEW.audio_task_id FOR UPDATE;
    IF NOT FOUND OR NOT task.audio_seal_eligible OR task.state <> 'succeeded'
       OR task.task_type <> 'audio_transcription' OR task.resource_type <> 'audio_transcription'
       OR task.resource_id <> task.id OR task.content_purged_time IS NOT NULL
       OR task.status <> 1 OR task.delete_time IS NOT NULL OR task.result IS NULL
       OR NEW.projection_text IS NULL OR NEW.content_purged_time IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_INVALID';
    END IF;
    candidate := NEW.projection_text::jsonb;
    IF task.result #>> '{schema,code}' IS DISTINCT FROM 'sand_ai.audio_transcription'
       OR task.result #>> '{schema,version}' IS DISTINCT FROM '1.0.0'
       OR jsonb_typeof(task.result->'segments') IS DISTINCT FROM 'array'
       OR task.result #>> '{technical,driver_code}' IS DISTINCT FROM task.input #>> '{execution_driver,code}'
       OR task.result #>> '{technical,driver_version}' IS DISTINCT FROM task.input #>> '{execution_driver,version}'
       OR EXISTS (
           SELECT 1 FROM jsonb_array_elements(task.result->'segments') segment
           WHERE segment #>> '{source,file_ref}' IS DISTINCT FROM task.input #>> '{request,file_ref}'
              OR segment #>> '{source,content_sha256}' IS DISTINCT FROM task.input->>'file_sha256'
       ) THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_INVALID';
    END IF;
    IF NEW.review_revision = 0 THEN
        IF EXISTS (SELECT 1 FROM sand_ai_audio_segment_review WHERE audio_task_id = task.id) THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_BASE_LATE';
        END IF;
        projected := task.result;
        NEW.review_id := NULL;
        NEW.previous_seal_id := NULL;
        NEW.previous_sha256 := NULL;
        NEW.base_seal_id := NULL;
    ELSE
        SELECT * INTO base FROM sand_ai_audio_transcript_seal
            WHERE audio_task_id = task.id AND review_revision = 0 AND content_purged_time IS NULL;
        IF NOT FOUND THEN RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_BASE_MISSING'; END IF;
        SELECT * INTO previous FROM sand_ai_audio_transcript_seal
            WHERE audio_task_id = task.id AND review_revision = NEW.review_revision - 1
              AND content_purged_time IS NULL;
        IF NOT FOUND THEN RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_CHAIN_INVALID'; END IF;
        SELECT * INTO review FROM sand_ai_audio_segment_review
            WHERE audio_task_id = task.id AND revision = NEW.review_revision;
        IF NOT FOUND OR review.expected_revision <> previous.review_revision
           OR review.base_sha256 <> base.transcript_sha256 OR review.owner_scope <> base.owner_scope
           OR review.segment_index >= base.total_segments OR review.content_purged_time IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_REVIEW_INVALID';
        END IF;
        -- Latest text and latest explicit speaker edit reproduce the PHP overlay.
        -- Rebuilding each segment once avoids a full JSON copy per prior review.
        SELECT jsonb_agg(
            CASE WHEN correction.id IS NULL THEN original.segment ELSE
                original.segment || jsonb_build_object(
                    'text', btrim(correction.review_text, E' \t\n\r\013'),
                    'speaker', CASE WHEN speaker.id IS NULL THEN original.segment->'speaker'
                        ELSE to_jsonb(speaker.review_speaker) END,
                    'confidence', '0.0000',
                    'warnings', (
                        SELECT COALESCE(jsonb_agg(warning.value ORDER BY warning.position), '[]'::jsonb)
                        FROM jsonb_array_elements(original.segment->'warnings')
                            WITH ORDINALITY warning(value,position)
                        WHERE warning.value NOT IN ('"no_recognized_speech"'::jsonb, '"human_reviewed"'::jsonb)
                    ) || CASE WHEN btrim(correction.review_text, E' \t\n\r\013') = ''
                        THEN '["no_recognized_speech","human_reviewed"]'::jsonb
                        ELSE '["human_reviewed"]'::jsonb END,
                    'review_revision', correction.revision
                )
            END ORDER BY original.position
        ) INTO projected_segments
        FROM jsonb_array_elements(task.result->'segments') WITH ORDINALITY original(segment,position)
        LEFT JOIN LATERAL (
            SELECT id,review_text,revision FROM sand_ai_audio_segment_review
            WHERE audio_task_id = task.id AND segment_index = original.position - 1
              AND revision <= NEW.review_revision
            ORDER BY revision DESC LIMIT 1
        ) correction ON true
        LEFT JOIN LATERAL (
            SELECT id,review_speaker FROM sand_ai_audio_segment_review
            WHERE audio_task_id = task.id AND segment_index = original.position - 1
              AND revision <= NEW.review_revision AND speaker_changed
            ORDER BY revision DESC LIMIT 1
        ) speaker ON true;
        projected := task.result || jsonb_build_object(
            'segments', projected_segments, 'review_revision', NEW.review_revision);
        NEW.review_id := review.id;
        NEW.previous_seal_id := previous.id;
        NEW.previous_sha256 := previous.transcript_sha256;
        NEW.base_seal_id := base.id;
    END IF;
    IF candidate IS DISTINCT FROM projected THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_PROJECTION_INVALID';
    END IF;
    -- Hash the exact presenter/overlay serialization submitted by the writer.
    -- JSONB text/key order is never used as the byte digest.
    NEW.transcript_sha256 := encode(sha256(convert_to(NEW.projection_text, 'UTF8')), 'hex');
    NEW.base_sha256 := CASE WHEN NEW.review_revision = 0 THEN NEW.transcript_sha256
        ELSE base.transcript_sha256 END;
    NEW.normalization_version := 'audio_transcript_seal@1';
    NEW.environment_id := task.environment_id;
    NEW.task_version := task.version;
    NEW.file_id := (task.input->>'file_id')::bigint;
    NEW.file_ref := task.input #>> '{request,file_ref}';
    NEW.file_sha256 := task.input->>'file_sha256';
    NEW.owner_scope := task.input->>'_trusted_identity_scope';
    NEW.data_egress_policy := task.input #>> '{request,data_egress_policy}';
    NEW.total_segments := jsonb_array_length(candidate->'segments');
    SELECT count(*) INTO NEW.eligible_segments FROM jsonb_array_elements(candidate->'segments') segment
        WHERE btrim(segment->>'text', E' \t\n\r\013') <> '';
    NEW.projection_text := NULL;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_audio_transcript_seal_insert
    BEFORE INSERT ON sand_ai_audio_transcript_seal
    FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_transcript_seal_insert();

CREATE FUNCTION sand_ai_audio_transcript_seal_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL
       AND to_jsonb(NEW) - 'content_purged_time' = to_jsonb(OLD) - 'content_purged_time'
       AND EXISTS (SELECT 1 FROM sand_ai_task_run WHERE id = OLD.audio_task_id
           AND content_purged_time IS NOT NULL) THEN RETURN NEW; END IF;
    RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_IMMUTABLE';
END $$;
CREATE TRIGGER sand_ai_audio_transcript_seal_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_audio_transcript_seal
    FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_transcript_seal_immutable();

CREATE FUNCTION sand_ai_audio_task_seal_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.audio_seal_eligible THEN RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_IMMUTABLE'; END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'INSERT' THEN
        NEW.audio_seal_eligible := NEW.task_type = 'audio_transcription';
        RETURN NEW;
    END IF;
    IF NEW.audio_seal_eligible IS DISTINCT FROM OLD.audio_seal_eligible THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_MARKER_IMMUTABLE';
    END IF;
    IF NOT OLD.audio_seal_eligible OR OLD.state <> 'succeeded' THEN RETURN NEW; END IF;
    IF NEW.id <> OLD.id OR NEW.environment_id <> OLD.environment_id
       OR NEW.task_type <> OLD.task_type OR NEW.resource_type <> OLD.resource_type
       OR NEW.resource_id <> OLD.resource_id OR NEW.request_id <> OLD.request_id
       OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
       OR NEW.application_id IS DISTINCT FROM OLD.application_id
       OR NEW.workload_client_id IS DISTINCT FROM OLD.workload_client_id
       OR NEW.version <> OLD.version OR NEW.state <> OLD.state THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_TASK_IMMUTABLE';
    END IF;
    IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL THEN
        IF NEW.result IS NOT NULL OR NEW.input IS DISTINCT FROM
           jsonb_set(OLD.input,'{request,glossary}','[]'::jsonb,false) THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_PURGE_INVALID';
        END IF;
    ELSIF NEW.result IS DISTINCT FROM OLD.result OR NEW.input IS DISTINCT FROM OLD.input
       OR NEW.content_purged_time IS DISTINCT FROM OLD.content_purged_time THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_BODY_IMMUTABLE';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_audio_task_seal_guard
    BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_task_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_task_seal_guard();

CREATE FUNCTION sand_ai_audio_task_seal_purge() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL
       AND NEW.task_type = 'audio_transcription' THEN
        UPDATE sand_ai_audio_transcript_seal SET content_purged_time = NEW.content_purged_time
            WHERE audio_task_id = NEW.id AND content_purged_time IS NULL;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_audio_task_seal_purge
    AFTER UPDATE OF content_purged_time ON sand_ai_task_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_task_seal_purge();

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610010098_audio_transcript_seal','e13c2d807c8e390d1e18ce0c6c525cd7eef01892ce3cbb2b2ce2aefafae2a42f',CURRENT_TIMESTAMP(0));

-- 202610010099_configuration_navigation
-- SandAI administrator configuration navigation. API permissions are declared,
-- never automatically granted; only existing read grants receive navigation links.
CREATE TABLE sand_ai_configuration_navigation_ownership (
    object_kind VARCHAR(16) NOT NULL CHECK (object_kind IN ('menu','role_menu')),
    object_id BIGINT NOT NULL CHECK (object_id > 0),
    definition JSONB NOT NULL CHECK (jsonb_typeof(definition) = 'object'),
    PRIMARY KEY (object_kind,object_id)
);
COMMENT ON TABLE sand_ai_configuration_navigation_ownership IS 'Exact ownership of configuration menu nodes and navigation-only role links; no automatic API grants';
CREATE TRIGGER sand_ai_configuration_navigation_ownership_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_configuration_navigation_ownership
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();

DO $configuration_navigation$
DECLARE
    root_id bigint;
    page_id bigint;
    node_id bigint;
    link_id bigint;
    count_nodes integer;
    page record;
    specification record;
    granted_role record;
    navigation_id bigint;
BEGIN
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,root_id FROM sand_system_menu WHERE code='SandAI';
    IF count_nodes <> 1 OR NOT EXISTS (
        SELECT 1 FROM sand_system_menu WHERE id=root_id AND parent_id=0 AND type=1
          AND path='/sand-ai' AND COALESCE(component,'')='' AND COALESCE(slug,'')=''
          AND status=1 AND delete_time IS NULL
    ) THEN
        RAISE EXCEPTION 'SandAI root navigation is missing or incompatible; review the installed billing navigation declaration';
    END IF;
    FOR page IN SELECT * FROM (VALUES
        ('首次开通','SandAIOnboarding','onboarding','/plugin/sand-ai/onboarding/index',90,'sand_ai:onboarding:read'),
        ('能力配置','SandAICapability','capability','/plugin/sand-ai/capability/index',80,'sand_ai:setup:capability:read'),
        ('材料配置','SandAIMaterialConfig','material-config','/plugin/sand-ai/capability/index',70,'sand_ai:setup:material-profile:read'),
        ('服务商','SandAIProvider','provider','/plugin/sand-ai/provider/index',60,'sand_ai:provider:index')
    ) AS pages(name,code,path,component,sort,read_permission) LOOP
        SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu WHERE code=page.code;
        IF count_nodes > 1 OR EXISTS (
            SELECT 1 FROM sand_system_menu WHERE parent_id=root_id AND path=page.path AND code IS DISTINCT FROM page.code
        ) THEN RAISE EXCEPTION 'SandAI configuration navigation conflicts: %',page.code; END IF;
        IF count_nodes=0 THEN
            INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
            VALUES(root_id,page.name,page.code,'',2,page.path,page.component,'',page.sort,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            RETURNING id INTO page_id;
            INSERT INTO sand_ai_configuration_navigation_ownership
            SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
            FROM sand_system_menu menu WHERE id=page_id;
        ELSIF NOT EXISTS (
            SELECT 1 FROM sand_system_menu WHERE id=page_id AND parent_id=root_id AND type=2
              AND path=page.path AND component=page.component AND COALESCE(slug,'')=''
              AND status=1 AND delete_time IS NULL
        ) THEN RAISE EXCEPTION 'Existing SandAI configuration navigation is incompatible: %',page.code;
        END IF;
        FOR specification IN SELECT * FROM (VALUES
            ('SandAIOnboarding','查看首次开通','sand_ai:onboarding:read',1),
            ('SandAIOnboarding','发现服务商模型','sand_ai:onboarding:discover',2),
            ('SandAIOnboarding','验证服务商模型','sand_ai:onboarding:verify',3),
            ('SandAIOnboarding','发布已验证模型','sand_ai:onboarding:publish',4),
            ('SandAIOnboarding','查看服务目录状态','sand_ai:setup:service-catalog:read',5),
            ('SandAIOnboarding','登记服务动作','sand_ai:setup:service-catalog:register',6),
            ('SandAICapability','查看能力配置元数据','sand_ai:setup:capability:metadata',1),
            ('SandAICapability','查看能力配置','sand_ai:setup:capability:read',2),
            ('SandAICapability','保存能力配置草稿','sand_ai:setup:capability:save',3),
            ('SandAICapability','发布能力配置','sand_ai:setup:capability:publish',4),
            ('SandAICapability','查看模型部署','sand_ai:setup:deployment:read',5),
            ('SandAICapability','保存模型部署草稿','sand_ai:setup:deployment:save',6),
            ('SandAICapability','验证模型部署','sand_ai:setup:deployment:verify',7),
            ('SandAIMaterialConfig','查看材料配置','sand_ai:setup:material-profile:read',1),
            ('SandAIMaterialConfig','保存材料配置草稿','sand_ai:setup:material-profile:save',2),
            ('SandAIMaterialConfig','发布材料配置','sand_ai:setup:material-profile:publish',3),
            ('SandAIMaterialConfig','撤销材料配置','sand_ai:setup:material-profile:revoke',4),
            ('SandAIProvider','查看服务商列表','sand_ai:provider:index',1),
            ('SandAIProvider','读取服务商','sand_ai:provider:read',2),
            ('SandAIProvider','新增服务商','sand_ai:provider:save',3),
            ('SandAIProvider','修改服务商','sand_ai:provider:update',4),
            ('SandAIProvider','删除服务商','sand_ai:provider:destroy',5)
        ) AS permissions(page_code,name,code,sort) WHERE page_code=page.code LOOP
            SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu
            WHERE code=specification.code OR slug=specification.code;
            IF count_nodes > 1 THEN RAISE EXCEPTION 'SandAI configuration permission is duplicated: %',specification.code; END IF;
            IF count_nodes=0 THEN
                INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
                VALUES(page_id,specification.name,specification.code,specification.code,3,'','','',specification.sort,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                RETURNING id INTO node_id;
                INSERT INTO sand_ai_configuration_navigation_ownership
                SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
                FROM sand_system_menu menu WHERE id=node_id;
            ELSIF NOT EXISTS (
                SELECT 1 FROM sand_system_menu WHERE id=node_id AND code=specification.code AND slug=specification.code
                  AND type=3 AND status=1 AND delete_time IS NULL
            ) THEN RAISE EXCEPTION 'Existing SandAI configuration permission is incompatible: %',specification.code;
            END IF;
        END LOOP;
        -- The super-administrator already reads all menus through SandAdmin.
        -- Other roles receive only navigation corresponding to a prior read grant.
        FOR granted_role IN
            SELECT DISTINCT role_menu.role_id FROM sand_system_role_menu role_menu
            JOIN sand_system_menu permission ON permission.id=role_menu.menu_id
            JOIN sand_system_role role ON role.id=role_menu.role_id
            WHERE permission.type=3 AND permission.status=1 AND permission.delete_time IS NULL
              AND role.status=1 AND role.delete_time IS NULL AND permission.slug=page.read_permission
        LOOP
            FOREACH navigation_id IN ARRAY ARRAY[root_id,page_id] LOOP
                IF NOT EXISTS(SELECT 1 FROM sand_system_role_menu WHERE role_id=granted_role.role_id AND menu_id=navigation_id) THEN
                    INSERT INTO sand_system_role_menu(role_id,menu_id) VALUES(granted_role.role_id,navigation_id) RETURNING id INTO link_id;
                    INSERT INTO sand_ai_configuration_navigation_ownership
                    SELECT 'role_menu',id,jsonb_build_object('role_id',role_id,'menu_id',menu_id)
                    FROM sand_system_role_menu WHERE id=link_id;
                END IF;
            END LOOP;
        END LOOP;
    END LOOP;
END
$configuration_navigation$;

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610010099_configuration_navigation','fb3c0bf69f810f909bac7eb872c24897811ac3d8605d00af1879fd45ab81e4f7',CURRENT_TIMESTAMP(0));

-- Freeze the prompt format per accepted Skill run. Existing runs retain v1 so
-- their provider request fingerprint and paid receipt can be recovered exactly.
ALTER TABLE sand_ai_skill_run
    ADD COLUMN prompt_contract_version smallint NOT NULL DEFAULT 1,
    ADD CONSTRAINT sand_ai_skill_run_prompt_contract_v100_ck
        CHECK (prompt_contract_version IN (1,2));

CREATE FUNCTION sand_ai_skill_run_prompt_contract_immutable_v100() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.prompt_contract_version IS DISTINCT FROM OLD.prompt_contract_version
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_PROMPT_CONTRACT_IMMUTABLE'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_run_prompt_contract_immutable_v100
    BEFORE UPDATE ON sand_ai_skill_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_run_prompt_contract_immutable_v100();

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610010100_skill_prompt_contract','e8118615921f0a1aeb75f57103f63ab43ba5a5cc2fcedbfbdca834774c410092',CURRENT_TIMESTAMP(0));

-- Migration 202610020101_material_output_failure_evidence (sha256 87052b0c056f1842f5d1f0eb589d35d88f380bfc703960d3f03f8099e72da8ed)
-- Source-only candidate. Executing this migration requires separate approval.
CREATE TABLE sand_ai_workflow_file_output_failure (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    workflow_run_id bigint NOT NULL REFERENCES sand_ai_workflow_run(id) ON DELETE RESTRICT,
    workflow_run_file_id bigint NOT NULL REFERENCES sand_ai_workflow_run_file(id) ON DELETE RESTRICT,
    task_run_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    attempt_no integer NOT NULL CHECK (attempt_no > 0),
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT,
    dispatch_id bigint NOT NULL REFERENCES sand_ai_invocation_dispatch(id) ON DELETE RESTRICT,
    receipt_id bigint NOT NULL REFERENCES sand_ai_invocation_outcome_receipt(id) ON DELETE RESTRICT,
    usage_event_id bigint NOT NULL REFERENCES sand_ai_usage_event(id) ON DELETE RESTRICT,
    execution_snapshot_sha256 char(64) NOT NULL
        CHECK (execution_snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    error_code varchar(96) NOT NULL
        CHECK (error_code IN ('SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID')),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_workflow_file_output_failure_attempt_uq
        UNIQUE (workflow_run_file_id, attempt_no),
    CONSTRAINT sand_ai_workflow_file_output_failure_invocation_uq UNIQUE (invocation_id),
    CONSTRAINT sand_ai_workflow_file_output_failure_dispatch_uq UNIQUE (dispatch_id),
    CONSTRAINT sand_ai_workflow_file_output_failure_receipt_uq UNIQUE (receipt_id),
    CONSTRAINT sand_ai_workflow_file_output_failure_usage_uq UNIQUE (usage_event_id)
);

CREATE FUNCTION sand_ai_workflow_file_output_failure_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'SandAI material output failure evidence is immutable';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER sand_ai_workflow_file_output_failure_no_mutation
    BEFORE UPDATE OR DELETE ON sand_ai_workflow_file_output_failure
    FOR EACH ROW EXECUTE FUNCTION sand_ai_workflow_file_output_failure_immutable();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES ('202610020101_material_output_failure_evidence','87052b0c056f1842f5d1f0eb589d35d88f380bfc703960d3f03f8099e72da8ed',CURRENT_TIMESTAMP(0));

-- Migration 202610020102_meeting_token_count_ledger (sha256 a938f402a8c421bc2f769182a65482064d225da62c44ee6625f4fab2bc187529)
-- Source-only candidate; execution requires separate migration authorization.
CREATE TABLE sand_ai_meeting_token_count_request (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    audio_task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    environment_id bigint NOT NULL,
    organization_id bigint NOT NULL,
    application_id bigint NOT NULL,
    workload_client_id bigint NOT NULL,
    owner_scope_sha256 char(64) NOT NULL CHECK (owner_scope_sha256 ~ '^[a-f0-9]{64}$'),
    capacity_sha256 char(64) NOT NULL CHECK (capacity_sha256 ~ '^[a-f0-9]{64}$'),
    route_sha256 char(64) NOT NULL CHECK (route_sha256 ~ '^[a-f0-9]{64}$'),
    provider_id bigint NOT NULL,
    model_id bigint NOT NULL,
    deployment_id bigint NOT NULL,
    deployment_revision integer NOT NULL CHECK (deployment_revision > 0),
    remote_model varchar(96) NOT NULL,
    input_sha256 char(64) NOT NULL CHECK (input_sha256 ~ '^[a-f0-9]{64}$'),
    request_id varchar(160) NOT NULL UNIQUE,
    request_identity_sha256 char(64) NOT NULL CHECK (request_identity_sha256 ~ '^[a-f0-9]{64}$'),
    transcript_sha256 char(64) NOT NULL CHECK (transcript_sha256 ~ '^[a-f0-9]{64}$'),
    review_revision integer NOT NULL CHECK (review_revision >= 0),
    planner_version varchar(64) NOT NULL,
    lease_fence bigint NOT NULL CHECK (lease_fence > 0),
    state varchar(32) NOT NULL DEFAULT 'send_intent' CHECK (state = 'send_intent'),
    metering_unit varchar(32) NOT NULL DEFAULT 'external_request' CHECK (metering_unit = 'external_request'),
    metering_quantity integer NOT NULL DEFAULT 1 CHECK (metering_quantity = 1),
    pricing_state varchar(16) NOT NULL DEFAULT 'unpriced' CHECK (pricing_state = 'unpriced'),
    metered_amount numeric(24,8) NULL CHECK (metered_amount IS NULL),
    currency varchar(8) NULL CHECK (currency IS NULL),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_meeting_token_count_request_key_uq UNIQUE (task_id, input_sha256, capacity_sha256)
);
CREATE TABLE sand_ai_meeting_token_count_outcome (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    request_event_id bigint NOT NULL UNIQUE REFERENCES sand_ai_meeting_token_count_request(id) ON DELETE RESTRICT,
    state varchar(16) NOT NULL CHECK (state IN ('succeeded','rejected','ambiguous')),
    http_status integer NULL CHECK (http_status BETWEEN 100 AND 599),
    prompt_tokens integer NULL CHECK (prompt_tokens BETWEEN 1 AND 1000000),
    capacity_receipt jsonb NULL,
    outcome_sha256 char(64) NOT NULL CHECK (outcome_sha256 ~ '^[a-f0-9]{64}$'),
    pricing_state varchar(16) NOT NULL DEFAULT 'unpriced' CHECK (pricing_state = 'unpriced'),
    metered_amount numeric(24,8) NULL CHECK (metered_amount IS NULL),
    currency varchar(8) NULL CHECK (currency IS NULL),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_meeting_token_count_outcome_shape_ck CHECK (
        (state = 'succeeded' AND http_status IS NOT NULL AND http_status = 200 AND prompt_tokens IS NOT NULL AND capacity_receipt IS NOT NULL)
        OR (state <> 'succeeded' AND prompt_tokens IS NULL AND capacity_receipt IS NULL)
    )
);
CREATE FUNCTION sand_ai_meeting_token_count_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'SandAI tokenizer external-call evidence is immutable';
END $$;
CREATE TRIGGER sand_ai_meeting_token_count_request_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_meeting_token_count_request
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_token_count_immutable();
CREATE TRIGGER sand_ai_meeting_token_count_outcome_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_meeting_token_count_outcome
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_token_count_immutable();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES ('202610020102_meeting_token_count_ledger','a938f402a8c421bc2f769182a65482064d225da62c44ee6625f4fab2bc187529',CURRENT_TIMESTAMP(0));

-- Migration 202610020103_ocr_dispatch_usage (sha256 09898115079e87b155ff04ccc52749698946088a54d6919f7caee032fc80342f)
-- 4.4 source-only: requires explicit migration execution authorization.
-- No historical row is backfilled. OCR request attempts never imply free usage or measured pages.
CREATE TABLE sand_ai_ocr_dispatch (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    task_run_id bigint NOT NULL,
    file_parse_id bigint NOT NULL REFERENCES sand_ai_file_parse(id) ON DELETE RESTRICT,
    file_id bigint NOT NULL REFERENCES sand_ai_file(id) ON DELETE RESTRICT,
    attempt_no integer NOT NULL CHECK (attempt_no > 0),
    lease_fence bigint NOT NULL CHECK (lease_fence > 0),
    parser_code varchar(64) NOT NULL,
    parser_version varchar(64) NOT NULL,
    attribution jsonb NOT NULL,
    create_time timestamp(0) without time zone NOT NULL,
    CONSTRAINT uq_sand_ai_ocr_dispatch_task_fence_v103 UNIQUE(task_run_id,lease_fence),
    CONSTRAINT uq_sand_ai_ocr_dispatch_environment_v103 UNIQUE(id,environment_id),
    CONSTRAINT fk_sand_ai_ocr_dispatch_task_environment_v103 FOREIGN KEY(task_run_id,environment_id)
        REFERENCES sand_ai_task_run(id,environment_id) ON DELETE RESTRICT,
    CONSTRAINT ck_sand_ai_ocr_dispatch_shape_v103 CHECK ((
        parser_code = 'local_paddle_ocr' AND btrim(parser_version) <> ''
        AND jsonb_typeof(attribution) = 'object' AND attribution->>'format_version' = '1'
        AND attribution->>'capability_code' = 'image_ocr'
        AND jsonb_typeof(attribution->'identity_snapshot') = 'object'
    ) IS TRUE)
);
CREATE INDEX idx_sand_ai_ocr_dispatch_parse_v103 ON sand_ai_ocr_dispatch(file_parse_id,id);
CREATE TABLE sand_ai_ocr_dispatch_outcome (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    ocr_dispatch_id bigint NOT NULL REFERENCES sand_ai_ocr_dispatch(id) ON DELETE RESTRICT,
    state varchar(32) NOT NULL,
    http_status smallint NULL,
    create_time timestamp(0) without time zone NOT NULL,
    CONSTRAINT uq_sand_ai_ocr_dispatch_outcome_v103 UNIQUE(ocr_dispatch_id),
    CONSTRAINT ck_sand_ai_ocr_dispatch_outcome_v103 CHECK ((
        (state='response_known' AND http_status BETWEEN 100 AND 599)
        OR (state='uncertain' AND http_status IS NULL)
    ) IS TRUE)
);
CREATE FUNCTION sand_ai_reject_ocr_mutation_v103() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'SAND_AI_OCR_LEDGER_IMMUTABLE';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_ocr_dispatch_immutable_v103 BEFORE UPDATE OR DELETE ON sand_ai_ocr_dispatch
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_ocr_mutation_v103();
CREATE TRIGGER trg_sand_ai_ocr_outcome_immutable_v103 BEFORE UPDATE OR DELETE ON sand_ai_ocr_dispatch_outcome
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_ocr_mutation_v103();

CREATE FUNCTION sand_ai_ocr_dispatch_binding_v103() RETURNS trigger AS $$
DECLARE task sand_ai_task_run%ROWTYPE; parse sand_ai_file_parse%ROWTYPE;
BEGIN
    SELECT * INTO task FROM sand_ai_task_run WHERE id=NEW.task_run_id FOR UPDATE;
    SELECT * INTO parse FROM sand_ai_file_parse WHERE id=NEW.file_parse_id FOR UPDATE;
    IF task.id IS NULL OR parse.id IS NULL OR task.task_type IS DISTINCT FROM 'file_parse'
       OR task.state IS DISTINCT FROM 'running' OR parse.state IS DISTINCT FROM 'running'
       OR task.environment_id IS DISTINCT FROM NEW.environment_id
       OR task.resource_id IS DISTINCT FROM NEW.file_id OR parse.file_id IS DISTINCT FROM NEW.file_id
       OR task.file_parse_id IS DISTINCT FROM NEW.file_parse_id
       OR task.attempt_count IS DISTINCT FROM NEW.attempt_no OR task.lease_fence IS DISTINCT FROM NEW.lease_fence
       OR parse.parser_code IS DISTINCT FROM NEW.parser_code OR parse.parser_version IS DISTINCT FROM NEW.parser_version
       OR NEW.attribution IS DISTINCT FROM jsonb_set(task.input->'_usage_attribution','{capability_code}','"image_ocr"'::jsonb)
       OR EXISTS (SELECT 1 FROM sand_ai_ocr_dispatch prior WHERE prior.task_run_id=NEW.task_run_id
           AND NOT EXISTS (SELECT 1 FROM sand_ai_ocr_dispatch_outcome outcome
               WHERE outcome.ocr_dispatch_id=prior.id AND outcome.state='response_known')) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_DISPATCH_BINDING_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_ocr_dispatch_binding_v103 BEFORE INSERT ON sand_ai_ocr_dispatch
    FOR EACH ROW EXECUTE FUNCTION sand_ai_ocr_dispatch_binding_v103();

ALTER TABLE sand_ai_usage_event ADD COLUMN ocr_dispatch_id bigint NULL;
ALTER TABLE sand_ai_usage_event ADD CONSTRAINT fk_sand_ai_ocr_usage_environment_v103
    FOREIGN KEY(ocr_dispatch_id,environment_id) REFERENCES sand_ai_ocr_dispatch(id,environment_id) ON DELETE RESTRICT;
CREATE UNIQUE INDEX uq_sand_ai_ocr_usage_dispatch_v103 ON sand_ai_usage_event(ocr_dispatch_id) WHERE ocr_dispatch_id IS NOT NULL;
-- Preserve all historical 061 branches verbatim inside the new OCR-null branch.
ALTER TABLE sand_ai_usage_event DROP CONSTRAINT ck_sand_ai_usage_event_tool_metering;
ALTER TABLE sand_ai_usage_event ADD CONSTRAINT ck_sand_ai_usage_event_tool_metering CHECK ((
    (ocr_dispatch_id IS NULL AND (

        (speech_run_id IS NULL AND audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND metering_unit IS NULL AND metering_quantity IS NULL)
        OR (speech_run_id IS NULL AND audio_task_run_id IS NULL AND tool_run_id IS NOT NULL AND mcp_snapshot_id IS NULL AND tool_id IS NOT NULL AND tool_version_id IS NOT NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND btrim(deployment_snapshot->>'adapter') <> ''
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'runtime'
            AND ((deployment_snapshot->>'adapter' = 'standard_readonly_http' AND metering_quantity = 1)
                OR (deployment_snapshot->>'adapter' = 'mcp_streamable_http' AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity)))
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (speech_run_id IS NULL AND audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NOT NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity) AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND deployment_snapshot->>'adapter' = 'mcp_streamable_http'
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'discovery'
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (speech_run_id IS NULL AND audio_task_run_id IS NOT NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND invocation_id IS NULL
            AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
            AND service_action = 'sand_ai.audio.transcription' AND source_kind = 'capability' AND capability_code = 'audio_transcription'
            AND metering_unit = 'audio_seconds' AND metering_quantity > 0
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0 AND usage_state = 'measured'
            AND reconciliation_state = 'unreconciled' AND reconciled_amount IS NULL
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL AND metered_amount IS NOT NULL AND currency IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))
        )
        OR (speech_run_id IS NOT NULL AND audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL
            AND tool_id IS NULL AND tool_version_id IS NULL AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL
            AND invocation_id IS NULL AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
            AND service_action = 'sand_ai.audio.speech' AND source_kind = 'capability' AND capability_code = 'audio_speech'
            AND metering_unit = 'input_characters' AND metering_quantity BETWEEN 1 AND 4096
            AND metering_quantity = trunc(metering_quantity)
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0 AND usage_state = 'measured'
            AND reconciliation_state = 'unreconciled' AND reconciled_amount IS NULL
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL AND metered_amount IS NOT NULL AND currency IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))
        )
    ))
    OR (ocr_dispatch_id IS NOT NULL AND speech_run_id IS NULL AND audio_task_run_id IS NULL
        AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
        AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL
        AND invocation_id IS NULL AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL
        AND service_action='sand_ai.document_parse' AND source_kind='capability' AND capability_code='image_ocr'
        AND metering_unit='external_request' AND metering_quantity=1
        AND input_tokens=0 AND output_tokens=0 AND total_tokens=0 AND usage_state='measured'
        AND pricing_state='unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL
        AND reconciled_amount IS NULL AND currency IS NULL AND reconciliation_state='unreconciled')
) IS TRUE) NOT VALID;

CREATE FUNCTION sand_ai_ocr_usage_binding_v103() RETURNS trigger AS $$
DECLARE dispatch sand_ai_ocr_dispatch%ROWTYPE;
BEGIN
    IF NEW.ocr_dispatch_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO dispatch FROM sand_ai_ocr_dispatch WHERE id=NEW.ocr_dispatch_id;
    IF dispatch.id IS NULL OR NEW.environment_id IS DISTINCT FROM dispatch.environment_id
       OR NEW.organization_id IS DISTINCT FROM (dispatch.attribution->>'organization_id')::bigint
       OR NEW.application_id IS DISTINCT FROM (dispatch.attribution->>'application_id')::bigint
       OR NEW.workload_client_id IS DISTINCT FROM (dispatch.attribution->>'workload_client_id')::bigint
       OR NEW.identity_snapshot IS DISTINCT FROM dispatch.attribution->'identity_snapshot'
       OR NEW.provider_snapshot IS DISTINCT FROM jsonb_build_object('kind','private_ocr_driver','code',dispatch.parser_code)
       OR NEW.model_snapshot IS DISTINCT FROM '{}'::jsonb
       OR NEW.deployment_snapshot IS DISTINCT FROM jsonb_build_object(
           'task_run_id',dispatch.task_run_id,'file_parse_id',dispatch.file_parse_id,'file_id',dispatch.file_id,
           'attempt_no',dispatch.attempt_no,'lease_fence',dispatch.lease_fence,
           'parser_code',dispatch.parser_code,'parser_version',dispatch.parser_version)
       OR NEW.price_snapshot IS DISTINCT FROM jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)
       OR NEW.create_time IS DISTINCT FROM dispatch.create_time
       OR NEW.idempotency_key IS DISTINCT FROM 'ocr-dispatch:' || dispatch.id::text THEN
        RAISE EXCEPTION 'SAND_AI_OCR_USAGE_BINDING_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_ocr_usage_binding_v103 BEFORE INSERT ON sand_ai_usage_event
    FOR EACH ROW EXECUTE FUNCTION sand_ai_ocr_usage_binding_v103();

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610020103_ocr_dispatch_usage', '09898115079e87b155ff04ccc52749698946088a54d6919f7caee032fc80342f', CURRENT_TIMESTAMP(0));

-- Migration 202610020104_ai_source_task_content_purge (sha256 ebd8f3dd707279d3044d3d3d34dc5d44ba23ea9764560344d7ce05fa77b53f38)
-- 6.3 source-only: execute only with explicit authorization in an existing database.
-- Ordinary AI completions retain typed source-block references for lifecycle discovery.
-- Keep the established audio, meeting-summary and agent purge conditions unchanged.
ALTER TABLE sand_ai_task_run DROP CONSTRAINT ck_sand_ai_task_content_purged_v086;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_content_purged_v104
    CHECK (
        content_purged_time IS NULL
        OR (
            state IN ('succeeded', 'failed', 'canceled')
            AND result IS NULL
            AND (
                (task_type = 'audio_transcription'
                    AND COALESCE(input #> '{request,glossary}' = '[]'::jsonb, false))
                OR (task_type = 'ai_completion' AND input ? '_meeting_summary')
                OR task_type = 'agent_run'
                OR (task_type = 'ai_completion'
                    AND NOT (input ? '_meeting_summary')
                    AND CASE WHEN jsonb_typeof(input->'source_block_ids') = 'array'
                        THEN jsonb_array_length(input->'source_block_ids') > 0 ELSE false END)
            )
        )
    );

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610020104_ai_source_task_content_purge', 'ebd8f3dd707279d3044d3d3d34dc5d44ba23ea9764560344d7ce05fa77b53f38', CURRENT_TIMESTAMP(0));

-- Migration 202610020105_registered_audio_routes (sha256 c4a2d899389007abb790918512bbf9f9dafa5f9ba39def1df573606f375d5818)
-- Source only: execute through the authorized lifecycle in an existing database.
-- Preserve historical checksums and existing rows. Enforce registered ASR drivers
-- and an explicit deployment on every new or updated audio-transcription route.
ALTER TABLE sand_ai_capability_route
    DROP CONSTRAINT ck_sand_ai_capability_route_audio_v030;
ALTER TABLE sand_ai_capability_route
    ADD CONSTRAINT ck_sand_ai_capability_route_audio_v105
    CHECK (
        capability_code <> 'audio_transcription'
        OR (
            driver_code IS NOT NULL
            AND driver_code IN ('local_whisper_cpp', 'openai_compatible_audio', 'glm_asr_audio')
            AND deployment_id IS NOT NULL
        )
    ) NOT VALID;

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610020105_registered_audio_routes', 'c4a2d899389007abb790918512bbf9f9dafa5f9ba39def1df573606f375d5818', CURRENT_TIMESTAMP(0));

-- Migration 202610020106_audio_chunk_manifest (sha256 4b3f3cb96c021d7c829413f1618c42229421f269ebfaa3d3d5c111373e963533)
-- Registered source candidate; independent SQL/install acceptance pending. Runtime remains opt-in.
-- New migration source only; not installed or executed.
-- Minimal preparation slice. The separate 107 dispatch proposal must ship with adapted consumers.
CREATE TABLE sand_ai_audio_chunk_manifest (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    task_run_id bigint NOT NULL,
    file_ref varchar(124) NOT NULL,
    file_sha256 char(64) NOT NULL,
    media_type varchar(127) NOT NULL,
    normalized_sha256 char(64) NOT NULL,
    recipe varchar(64) NOT NULL,
    sample_count bigint NOT NULL,
    chunk_count integer NOT NULL,
    manifest_sha256 char(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sand_ai_audio_manifest_task_v106 FOREIGN KEY (task_run_id, environment_id) REFERENCES sand_ai_task_run(id,environment_id),
    CONSTRAINT uq_sand_ai_audio_manifest_task_v106 UNIQUE (environment_id,task_run_id),
    CONSTRAINT uq_sand_ai_audio_manifest_scope_v106 UNIQUE (id,environment_id,task_run_id),
    CONSTRAINT ck_sand_ai_audio_manifest_hash_v106 CHECK (file_sha256 ~ '^[a-f0-9]{64}$' AND normalized_sha256 ~ '^[a-f0-9]{64}$' AND manifest_sha256 ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_audio_manifest_format_v106 CHECK (file_ref ~ '^saf_[A-Za-z0-9_-]{16,120}$' AND recipe='pcm_s16le_mono_16000_fixed30/v1' AND sample_count BETWEEN 1 AND 230400000 AND chunk_count=(sample_count+479999)/480000)
);
CREATE TABLE sand_ai_audio_chunk (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    audio_chunk_manifest_id bigint NOT NULL,
    environment_id bigint NOT NULL,
    task_run_id bigint NOT NULL,
    sequence_no integer NOT NULL,
    start_sample bigint NOT NULL,
    end_sample bigint NOT NULL,
    byte_size integer NOT NULL,
    chunk_ref varchar(37) NOT NULL,
    content_sha256 char(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sand_ai_audio_chunk_manifest_v106 FOREIGN KEY (audio_chunk_manifest_id,environment_id,task_run_id) REFERENCES sand_ai_audio_chunk_manifest(id,environment_id,task_run_id),
    CONSTRAINT uq_sand_ai_audio_chunk_sequence_v106 UNIQUE (audio_chunk_manifest_id,sequence_no),
    CONSTRAINT uq_sand_ai_audio_chunk_ref_v106 UNIQUE (chunk_ref),
    CONSTRAINT uq_sand_ai_audio_chunk_scope_v106 UNIQUE (id,environment_id,task_run_id),
    CONSTRAINT ck_sand_ai_audio_chunk_input_v106 CHECK (sequence_no BETWEEN 1 AND 480 AND start_sample=(sequence_no-1)::bigint*480000 AND end_sample>start_sample AND end_sample-start_sample<=480000 AND byte_size=44+(end_sample-start_sample)*2 AND chunk_ref ~ '^saac_[a-f0-9]{32}$' AND content_sha256 ~ '^[a-f0-9]{64}$')
);
-- Deferred coverage guard forbids partial, missing, duplicated, shortened or extended manifests.
CREATE FUNCTION sand_ai_audio_manifest_coverage_v106() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE manifest_id bigint; m sand_ai_audio_chunk_manifest; actual_count integer;
BEGIN
    IF TG_TABLE_NAME='sand_ai_audio_chunk_manifest' THEN manifest_id=NEW.id;
    ELSE manifest_id=NEW.audio_chunk_manifest_id; END IF;
    SELECT * INTO STRICT m FROM sand_ai_audio_chunk_manifest WHERE id=manifest_id;
    SELECT count(*) INTO actual_count FROM sand_ai_audio_chunk WHERE audio_chunk_manifest_id=m.id;
    IF actual_count<>m.chunk_count OR EXISTS (
        SELECT 1 FROM sand_ai_audio_chunk c WHERE c.audio_chunk_manifest_id=m.id
        AND (c.sequence_no>m.chunk_count OR c.end_sample<>LEAST(c.sequence_no::bigint*480000,m.sample_count))
    ) THEN RAISE EXCEPTION 'SAND_AI_AUDIO_MANIFEST_COVERAGE_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER sand_ai_audio_manifest_coverage_v106 AFTER INSERT ON sand_ai_audio_chunk_manifest DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_manifest_coverage_v106();
CREATE CONSTRAINT TRIGGER sand_ai_audio_chunk_coverage_v106 AFTER INSERT ON sand_ai_audio_chunk DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_manifest_coverage_v106();
CREATE FUNCTION sand_ai_audio_manifest_immutable_v106() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'SAND_AI_AUDIO_MANIFEST_IMMUTABLE'; END $$;
CREATE TRIGGER sand_ai_audio_manifest_immutable_v106 BEFORE UPDATE OR DELETE ON sand_ai_audio_chunk_manifest FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_manifest_immutable_v106();
CREATE TRIGGER sand_ai_audio_chunk_immutable_v106 BEFORE UPDATE OR DELETE ON sand_ai_audio_chunk FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_manifest_immutable_v106();
CREATE FUNCTION sand_ai_audio_manifest_source_guard_v106() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original_input jsonb;
BEGIN
    SELECT input INTO original_input FROM sand_ai_task_run
    WHERE id=NEW.task_run_id AND environment_id=NEW.environment_id AND task_type='audio_transcription'
        AND delete_time IS NULL AND content_purged_time IS NULL FOR UPDATE;
    IF original_input IS NULL
        OR original_input->'request'->>'file_ref' IS DISTINCT FROM NEW.file_ref
        OR original_input->>'file_sha256' IS DISTINCT FROM NEW.file_sha256
        OR original_input->>'file_media_type' IS DISTINCT FROM NEW.media_type
        OR EXISTS (SELECT 1 FROM sand_ai_external_audio_dispatch WHERE task_run_id=NEW.task_run_id AND environment_id=NEW.environment_id) THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_MANIFEST_SOURCE_CONFLICT';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_audio_manifest_source_guard_v106 BEFORE INSERT ON sand_ai_audio_chunk_manifest FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_manifest_source_guard_v106();

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610020106_audio_chunk_manifest', '4b3f3cb96c021d7c829413f1618c42229421f269ebfaa3d3d5c111373e963533', CURRENT_TIMESTAMP(0));

-- Migration 202610020107_audio_chunk_dispatch (sha256 2562179cc2815c271afd3840ca09d718dacf58d4575d53e8554450163089cb70)
-- Registered source candidate; independent SQL/install acceptance pending. Runtime remains opt-in.
-- Registered source candidate. Requires 093/106 and completed 108 before runtime activation.
-- Requires reviewed chunk-aware Worker scheduling/aggregate/read/purge consumers before installation.
-- SQL093 already dropped uq_sand_ai_external_audio_dispatch_task_v031. Do not drop it twice.
-- Keep existing logical execution, receipt, budget, and per-dispatch Usage uniqueness unchanged.
ALTER TABLE sand_ai_external_audio_dispatch ADD COLUMN audio_chunk_id bigint NULL;
ALTER TABLE sand_ai_external_audio_dispatch ADD CONSTRAINT fk_sand_ai_external_audio_chunk_v107
    FOREIGN KEY (audio_chunk_id,environment_id,task_run_id) REFERENCES sand_ai_audio_chunk(id,environment_id,task_run_id);
CREATE UNIQUE INDEX uq_sand_ai_external_audio_chunk_v107 ON sand_ai_external_audio_dispatch(audio_chunk_id) WHERE audio_chunk_id IS NOT NULL;
CREATE INDEX ix_sand_ai_external_audio_chunk_task_v107 ON sand_ai_external_audio_dispatch(environment_id,task_run_id,audio_chunk_id,state) WHERE audio_chunk_id IS NOT NULL;
-- TaskRun running timestamps use the trusted host timezone, not UTC receipt timestamps.
-- Chunk ledger.guard sets this validated value transaction-locally on the same connection.
CREATE FUNCTION sand_ai_audio_chunk_lease_live_v107(lease_expire_time timestamp without time zone) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE zone text;
BEGIN
    zone=current_setting('sand_ai.task_timezone',true);
    IF zone IS NULL OR NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name=zone) THEN RETURN false; END IF;
    RETURN COALESCE(lease_expire_time>(clock_timestamp() AT TIME ZONE zone),false);
END $$;
CREATE FUNCTION sand_ai_external_audio_chunk_guard_v107() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_audio_chunk; m sand_ai_audio_chunk_manifest; t sand_ai_task_run; expected_execution text; expected_seconds numeric(20,6);
BEGIN
    IF TG_OP='UPDATE' THEN
        IF NEW.audio_chunk_id IS DISTINCT FROM OLD.audio_chunk_id THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_BINDING_IMMUTABLE';
        END IF;
        IF NEW.audio_chunk_id IS NULL THEN RETURN NEW; END IF;
        -- Existing v031/v072/v093 enforce the content-purge shape and terminal immutability.
        IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL THEN RETURN NEW; END IF;
        SELECT * INTO STRICT t FROM sand_ai_task_run WHERE id=NEW.task_run_id AND environment_id=NEW.environment_id FOR UPDATE;
        IF t.state<>'running' OR t.delete_time IS NOT NULL OR t.content_purged_time IS NOT NULL
            OR NOT sand_ai_audio_chunk_lease_live_v107(t.lease_expire_time) THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_LEASE_LOST';
        END IF;
        -- A newer fenced owner may seal a stale in-flight request unknown, without resending it.
        IF OLD.state='dispatching' AND NEW.state='ambiguous' AND t.lease_fence>OLD.lease_fence
            AND NEW.lease_owner IS NOT DISTINCT FROM OLD.lease_owner
            AND NEW.lease_token IS NOT DISTINCT FROM OLD.lease_token
            AND NEW.lease_fence IS NOT DISTINCT FROM OLD.lease_fence THEN RETURN NEW; END IF;
        IF NEW.lease_owner IS DISTINCT FROM t.lease_owner OR NEW.lease_token IS DISTINCT FROM t.lease_token
            OR NEW.lease_fence IS DISTINCT FROM t.lease_fence THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_LEASE_LOST';
        END IF;
        RETURN NEW;
    END IF;
    -- Serialize legacy/chunk mode selection on the existing task lock.
    SELECT * INTO STRICT t FROM sand_ai_task_run WHERE id=NEW.task_run_id AND environment_id=NEW.environment_id FOR UPDATE;
    IF NEW.audio_chunk_id IS NULL THEN
        IF EXISTS (SELECT 1 FROM sand_ai_audio_chunk_manifest WHERE task_run_id=NEW.task_run_id AND environment_id=NEW.environment_id) THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_DISPATCH_MODE_CONFLICT';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT c FROM sand_ai_audio_chunk WHERE id=NEW.audio_chunk_id;
    SELECT * INTO STRICT m FROM sand_ai_audio_chunk_manifest WHERE id=c.audio_chunk_manifest_id;
    IF t.task_type<>'audio_transcription' OR t.state<>'running' OR t.status<>1
        OR t.delete_time IS NOT NULL OR t.content_purged_time IS NOT NULL
        OR NOT sand_ai_audio_chunk_lease_live_v107(t.lease_expire_time)
        OR NEW.state<>'prepared' OR NEW.lease_owner IS NOT NULL OR NEW.lease_token IS NOT NULL OR NEW.lease_fence IS NOT NULL
        OR EXISTS (SELECT 1 FROM sand_ai_external_audio_dispatch WHERE task_run_id=NEW.task_run_id AND environment_id=NEW.environment_id AND audio_chunk_id IS NULL)
        OR NEW.environment_id<>c.environment_id OR NEW.task_run_id<>c.task_run_id
        OR t.input->'request'->>'file_ref' IS DISTINCT FROM m.file_ref
        OR t.input->>'file_sha256' IS DISTINCT FROM m.file_sha256
        OR t.input->>'file_media_type' IS DISTINCT FROM m.media_type
        OR NEW.frozen_request->>'file_ref' IS DISTINCT FROM m.file_ref
        OR NEW.frozen_request->>'file_sha256' IS DISTINCT FROM c.content_sha256
        OR NEW.frozen_request->>'media_type' IS DISTINCT FROM 'audio/wav'
        OR NEW.frozen_request->>'trusted_scope' IS DISTINCT FROM t.input->>'_trusted_identity_scope'
        OR NEW.frozen_request->>'driver_code' IS DISTINCT FROM t.input->'execution_driver'->>'code'
        OR NEW.frozen_request->>'driver_version' IS DISTINCT FROM t.input->'execution_driver'->>'version'
        OR NEW.frozen_request->>'egress_policy' IS DISTINCT FROM t.input->'request'->>'data_egress_policy'
        OR NEW.frozen_request->'usage_attribution' IS DISTINCT FROM t.input->'usage_attribution'
        OR NEW.frozen_request->'execution_target' IS DISTINCT FROM t.input->'execution_target'
        OR NEW.frozen_request->'price_snapshot' IS DISTINCT FROM t.input->'pricing_snapshot'
        OR NEW.frozen_request->'parameters' IS DISTINCT FROM jsonb_build_object(
            'language',t.input->'request'->'language','speaker_diarization',t.input->'request'->'speaker_diarization','glossary',t.input->'request'->'glossary')
        OR t.input->'request'->'speaker_diarization' IS DISTINCT FROM 'false'::jsonb
        OR NOT EXISTS (SELECT 1 FROM sand_ai_audio_chunk_preparation p JOIN sand_ai_audio_chunk_object o ON o.audio_chunk_preparation_id=p.id
            WHERE p.environment_id=NEW.environment_id AND p.task_run_id=NEW.task_run_id AND p.state='sealed'
            AND o.sequence_no=c.sequence_no AND o.state='ready' AND o.chunk_ref=c.chunk_ref
            AND o.content_sha256=c.content_sha256 AND o.byte_size=c.byte_size)
        OR EXISTS (SELECT 1 FROM sand_ai_audio_chunk previous LEFT JOIN sand_ai_external_audio_dispatch d ON d.audio_chunk_id=previous.id
            WHERE previous.audio_chunk_manifest_id=m.id AND previous.sequence_no<c.sequence_no AND d.state IS DISTINCT FROM 'succeeded') THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_BINDING_INVALID';
    END IF;
    expected_execution='audiochunk_' || encode(sha256(convert_to(NEW.environment_id::text || '|' || NEW.task_run_id::text || '|' || m.manifest_sha256 || '|' || c.sequence_no::text,'UTF8')),'hex');
    IF NEW.logical_execution_id<>expected_execution OR NEW.frozen_request->>'logical_execution_id' IS DISTINCT FROM expected_execution THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_EXECUTION_INVALID';
    END IF;
    expected_seconds=ceil((c.end_sample-c.start_sample)::numeric/16)/1000;
    IF NEW.budget_audio_seconds IS DISTINCT FROM expected_seconds THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_DURATION_INVALID';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_external_audio_chunk_guard_v107 BEFORE INSERT OR UPDATE ON sand_ai_external_audio_dispatch FOR EACH ROW EXECUTE FUNCTION sand_ai_external_audio_chunk_guard_v107();

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610020107_audio_chunk_dispatch', '2562179cc2815c271afd3840ca09d718dacf58d4575d53e8554450163089cb70', CURRENT_TIMESTAMP(0));

-- Migration 202610020108_audio_chunk_object_ownership (sha256 8b0c59782486b87cb7a45b4fa27bdb35ad31d91e6d4d3eb4e26eb1edcd7f8db3)
-- Registered source candidate; independent SQL/install acceptance pending. Runtime remains opt-in.
-- New migration source only; requires 106. Runtime is disabled by default.
CREATE TABLE sand_ai_audio_chunk_store (
    code varchar(64) PRIMARY KEY,
    root_sha256 char(64) NOT NULL UNIQUE,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sand_ai_chunk_store_binding_v108 UNIQUE(code,root_sha256),
    CONSTRAINT ck_sand_ai_chunk_store_shape_v108 CHECK (code ~ '^[a-z][a-z0-9_-]{1,63}$' AND root_sha256 ~ '^[a-f0-9]{64}$')
);
CREATE FUNCTION sand_ai_audio_chunk_store_immutable_v108() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_STORE_IMMUTABLE'; END $$;
CREATE TRIGGER sand_ai_audio_chunk_store_immutable_v108 BEFORE UPDATE OR DELETE ON sand_ai_audio_chunk_store FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_chunk_store_immutable_v108();
CREATE TABLE sand_ai_audio_chunk_preparation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    task_run_id bigint NOT NULL,
    store_code varchar(64) NOT NULL,
    store_root_sha256 char(64) NOT NULL,
    file_ref varchar(124) NOT NULL,
    file_sha256 char(64) NOT NULL,
    normalized_sha256 char(64) NOT NULL,
    sample_count bigint NOT NULL,
    reserved_bytes bigint NOT NULL,
    state varchar(16) NOT NULL DEFAULT 'preparing',
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sand_ai_chunk_preparation_store_v108 FOREIGN KEY (store_code,store_root_sha256) REFERENCES sand_ai_audio_chunk_store(code,root_sha256),
    CONSTRAINT fk_sand_ai_chunk_preparation_task_v108 FOREIGN KEY (task_run_id,environment_id) REFERENCES sand_ai_task_run(id,environment_id),
    CONSTRAINT uq_sand_ai_chunk_preparation_task_v108 UNIQUE(task_run_id),
    CONSTRAINT ck_sand_ai_chunk_preparation_shape_v108 CHECK (store_code ~ '^[a-z][a-z0-9_-]{1,63}$' AND file_ref ~ '^saf_[A-Za-z0-9_-]{16,120}$' AND file_sha256 ~ '^[a-f0-9]{64}$' AND normalized_sha256 ~ '^[a-f0-9]{64}$' AND sample_count BETWEEN 1 AND 230400000 AND reserved_bytes=44+sample_count*4+((sample_count+479999)/480000)*44 AND state IN ('preparing','sealed','cleaning','cleaned'))
);
CREATE INDEX ix_sand_ai_chunk_preparation_cleanup_v108 ON sand_ai_audio_chunk_preparation(store_code,state,id);
CREATE TABLE sand_ai_audio_chunk_object (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    audio_chunk_preparation_id bigint NOT NULL REFERENCES sand_ai_audio_chunk_preparation(id),
    sequence_no integer NOT NULL,
    chunk_ref varchar(37) NOT NULL,
    byte_size bigint NOT NULL,
    content_sha256 char(64),
    state varchar(16) NOT NULL DEFAULT 'allocated',
    cleanup_attempt_count integer NOT NULL DEFAULT 0,
    cleanup_last_attempt_time timestamp(0) without time zone,
    cleanup_next_attempt_time timestamp(0) without time zone NOT NULL DEFAULT (clock_timestamp() AT TIME ZONE 'UTC'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_ai_chunk_cleanup_schedule_v108 CHECK (cleanup_attempt_count BETWEEN 0 AND 1000000 AND ((cleanup_attempt_count=0 AND cleanup_last_attempt_time IS NULL) OR (cleanup_attempt_count>0 AND cleanup_last_attempt_time IS NOT NULL AND cleanup_next_attempt_time>cleanup_last_attempt_time))),
    CONSTRAINT uq_sand_ai_chunk_object_sequence_v108 UNIQUE(audio_chunk_preparation_id,sequence_no),
    CONSTRAINT uq_sand_ai_chunk_object_ref_v108 UNIQUE(chunk_ref),
    CONSTRAINT ck_sand_ai_chunk_object_shape_v108 CHECK (sequence_no BETWEEN 0 AND 480 AND chunk_ref ~ '^saac_[a-f0-9]{32}$' AND byte_size BETWEEN 46 AND 460800044 AND (content_sha256 IS NULL OR content_sha256 ~ '^[a-f0-9]{64}$') AND state IN ('allocated','ready','deleted') AND (state<>'ready' OR content_sha256 IS NOT NULL))
);
CREATE INDEX ix_sand_ai_chunk_object_cleanup_v108 ON sand_ai_audio_chunk_object(cleanup_last_attempt_time,id) WHERE state<>'deleted';
CREATE FUNCTION sand_ai_audio_chunk_object_guard_v108() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p sand_ai_audio_chunk_preparation; expected_bytes bigint;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_CHUNK_OBJECT_OWNERSHIP_IMMUTABLE'; END IF;
    SELECT * INTO STRICT p FROM sand_ai_audio_chunk_preparation WHERE id=NEW.audio_chunk_preparation_id;
    expected_bytes=CASE WHEN NEW.sequence_no=0 THEN 44+p.sample_count*2 ELSE 44+(LEAST(NEW.sequence_no::bigint*480000,p.sample_count)-(NEW.sequence_no-1)::bigint*480000)*2 END;
    IF NEW.sequence_no>(p.sample_count+479999)/480000 OR NEW.byte_size<>expected_bytes THEN RAISE EXCEPTION 'SAND_AI_CHUNK_OBJECT_RANGE_INVALID'; END IF;
    IF TG_OP='INSERT' THEN
        IF p.state<>'preparing' OR NEW.state<>'allocated' OR (NEW.sequence_no=0 AND NEW.content_sha256 IS DISTINCT FROM p.normalized_sha256) THEN RAISE EXCEPTION 'SAND_AI_CHUNK_OBJECT_STATE_INVALID'; END IF;
        RETURN NEW;
    END IF;
    -- Metadata-only cleanup claims leave ownership/content/state intact and advance persistent fair order.
    IF p.state='cleaning' AND OLD.state<>'deleted'
       AND (NEW.id,NEW.audio_chunk_preparation_id,NEW.sequence_no,NEW.chunk_ref,NEW.byte_size,NEW.content_sha256,NEW.state,NEW.create_time)
           IS NOT DISTINCT FROM (OLD.id,OLD.audio_chunk_preparation_id,OLD.sequence_no,OLD.chunk_ref,OLD.byte_size,OLD.content_sha256,OLD.state,OLD.create_time)
       AND NEW.cleanup_attempt_count=LEAST(OLD.cleanup_attempt_count+1,1000000)
       AND NEW.cleanup_last_attempt_time IS NOT NULL
       AND (OLD.cleanup_last_attempt_time IS NULL OR NEW.cleanup_last_attempt_time>=OLD.cleanup_last_attempt_time)
       AND NEW.cleanup_next_attempt_time>NEW.cleanup_last_attempt_time
       AND NEW.cleanup_next_attempt_time<=NEW.cleanup_last_attempt_time+interval '301 seconds' THEN RETURN NEW; END IF;
    IF (NEW.cleanup_attempt_count,NEW.cleanup_last_attempt_time,NEW.cleanup_next_attempt_time)
       IS DISTINCT FROM (OLD.cleanup_attempt_count,OLD.cleanup_last_attempt_time,OLD.cleanup_next_attempt_time) THEN
        RAISE EXCEPTION 'SAND_AI_CHUNK_CLEANUP_SCHEDULE_INVALID';
    END IF;
    IF (NEW.id,NEW.audio_chunk_preparation_id,NEW.sequence_no,NEW.chunk_ref,NEW.byte_size,NEW.create_time) IS DISTINCT FROM (OLD.id,OLD.audio_chunk_preparation_id,OLD.sequence_no,OLD.chunk_ref,OLD.byte_size,OLD.create_time)
        OR (OLD.content_sha256 IS NOT NULL AND NEW.content_sha256 IS DISTINCT FROM OLD.content_sha256)
        OR NOT ((OLD.state='allocated' AND NEW.state IN ('allocated','ready')) OR (OLD.state IN ('allocated','ready') AND NEW.state='deleted'))
        OR (NEW.state='deleted' AND p.state<>'cleaning') OR (NEW.state<>'deleted' AND p.state<>'preparing') THEN RAISE EXCEPTION 'SAND_AI_CHUNK_OBJECT_STATE_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_audio_chunk_object_guard_v108 BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_audio_chunk_object FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_chunk_object_guard_v108();
CREATE FUNCTION sand_ai_audio_chunk_preparation_guard_v108() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original_input jsonb; original_task sand_ai_task_run; total integer; ready integer; deleted integer;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_CHUNK_PREPARATION_IMMUTABLE'; END IF;
    SELECT * INTO STRICT original_task FROM sand_ai_task_run WHERE id=NEW.task_run_id AND environment_id=NEW.environment_id FOR UPDATE;
    original_input=original_task.input;
    IF TG_OP='INSERT' THEN
        IF original_input->'request'->>'file_ref' IS DISTINCT FROM NEW.file_ref OR original_input->>'file_sha256' IS DISTINCT FROM NEW.file_sha256 OR NEW.state<>'preparing' THEN RAISE EXCEPTION 'SAND_AI_CHUNK_PREPARATION_SOURCE_INVALID'; END IF;
        RETURN NEW;
    END IF;
    IF (NEW.id,NEW.environment_id,NEW.task_run_id,NEW.store_code,NEW.store_root_sha256,NEW.file_ref,NEW.file_sha256,NEW.normalized_sha256,NEW.sample_count,NEW.reserved_bytes,NEW.create_time) IS DISTINCT FROM (OLD.id,OLD.environment_id,OLD.task_run_id,OLD.store_code,OLD.store_root_sha256,OLD.file_ref,OLD.file_sha256,OLD.normalized_sha256,OLD.sample_count,OLD.reserved_bytes,OLD.create_time)
        OR NOT ((OLD.state='preparing' AND NEW.state IN ('sealed','cleaning')) OR (OLD.state='sealed' AND NEW.state='cleaning') OR (OLD.state='cleaning' AND NEW.state='cleaned')) THEN RAISE EXCEPTION 'SAND_AI_CHUNK_PREPARATION_STATE_INVALID'; END IF;
    -- A failed task remains eligible for same-task retry. Only irreversible lifecycle closure permits deletion.
    IF NEW.state='cleaning' AND original_task.state<>'canceled'
       AND original_task.content_purged_time IS NULL AND original_task.delete_time IS NULL THEN
        RAISE EXCEPTION 'SAND_AI_CHUNK_SOURCE_STILL_RETRYABLE';
    END IF;
    SELECT count(*),count(*) FILTER(WHERE state='ready'),count(*) FILTER(WHERE state='deleted') INTO total,ready,deleted FROM sand_ai_audio_chunk_object WHERE audio_chunk_preparation_id=NEW.id;
    IF total<>(NEW.sample_count+479999)/480000+1 OR (NEW.state='sealed' AND (ready<>total OR NOT EXISTS(SELECT 1 FROM sand_ai_audio_chunk_manifest WHERE task_run_id=NEW.task_run_id AND environment_id=NEW.environment_id))) OR (NEW.state='cleaned' AND deleted<>total) THEN RAISE EXCEPTION 'SAND_AI_CHUNK_PREPARATION_COVERAGE_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_audio_chunk_preparation_guard_v108 BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_audio_chunk_preparation FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_chunk_preparation_guard_v108();
-- Once object ownership is installed, no caller can bypass durable-object proof using the older freezer.
CREATE FUNCTION sand_ai_audio_chunk_durable_guard_v108() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m sand_ai_audio_chunk_manifest;
BEGIN
    SELECT * INTO STRICT m FROM sand_ai_audio_chunk_manifest WHERE id=NEW.audio_chunk_manifest_id;
    IF NOT EXISTS (
        SELECT 1 FROM sand_ai_audio_chunk_object o JOIN sand_ai_audio_chunk_preparation p ON p.id=o.audio_chunk_preparation_id
        WHERE p.environment_id=NEW.environment_id AND p.task_run_id=NEW.task_run_id AND p.state='preparing'
          AND p.file_ref=m.file_ref AND p.file_sha256=m.file_sha256 AND p.normalized_sha256=m.normalized_sha256 AND p.sample_count=m.sample_count
          AND o.sequence_no=NEW.sequence_no AND o.chunk_ref=NEW.chunk_ref AND o.content_sha256=NEW.content_sha256
          AND o.byte_size=NEW.byte_size AND o.state='ready'
    ) THEN RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_DURABLE_INPUT_REQUIRED'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_audio_chunk_durable_guard_v108 BEFORE INSERT ON sand_ai_audio_chunk FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_chunk_durable_guard_v108();

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610020108_audio_chunk_object_ownership', '8b0c59782486b87cb7a45b4fa27bdb35ad31d91e6d4d3eb4e26eb1edcd7f8db3', CURRENT_TIMESTAMP(0));

-- Migration 202610030109_meeting_worker_foundation (sha256 b545ff383ab90b96e45bc9dc16c12cdc7a1811ccf78bdbdf2766b17c7a42c66c)
-- Registered isolated source candidate; SQL execution/installation remains unverified.
-- Foundation only: pages/events are storage facts, NEVER verified coverage.
-- Existing 088 nodes/tasks remain unchanged unless a NEW task enrolls explicitly.
CREATE TABLE sand_ai_meeting_worker_checkpoint (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL UNIQUE REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    source_kind varchar(16) NOT NULL CHECK (source_kind IN ('seal','aggregation')),
    source_seal_id bigint NULL REFERENCES sand_ai_audio_transcript_seal(id) ON DELETE RESTRICT,
    anchor_snapshot jsonb NOT NULL CHECK (jsonb_typeof(anchor_snapshot) = 'object'),
    anchor_sha256 char(64) NOT NULL CHECK (anchor_sha256 ~ '^[a-f0-9]{64}$'),
    anchor_text text NULL CHECK (anchor_text IS NULL), -- consumed by trigger
    state varchar(16) NOT NULL DEFAULT 'staging' CHECK (state IN ('staging','purged')),
    event_sequence bigint NOT NULL DEFAULT 0 CHECK (event_sequence >= 0),
    last_event_sha256 char(64) NULL CHECK (last_event_sha256 ~ '^[a-f0-9]{64}$'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CHECK ((source_kind = 'seal' AND source_seal_id IS NOT NULL)
        OR (source_kind = 'aggregation' AND source_seal_id IS NULL)),
    CHECK ((event_sequence = 0 AND last_event_sha256 IS NULL)
        OR (event_sequence > 0 AND last_event_sha256 IS NOT NULL))
);
CREATE TABLE sand_ai_meeting_worker_page (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES sand_ai_meeting_worker_checkpoint(task_id) ON DELETE RESTRICT,
    page_kind varchar(16) NOT NULL CHECK (page_kind IN ('source','leaf','frontier')),
    round_no integer NOT NULL CHECK (round_no BETWEEN 0 AND 32),
    sequence_no integer NOT NULL CHECK (sequence_no BETWEEN 1 AND 200000),
    entry_count integer NOT NULL CHECK (entry_count BETWEEN 1 AND 32),
    payload_sha256 char(64) NOT NULL CHECK (payload_sha256 ~ '^[a-f0-9]{64}$'),
    private_payload jsonb NULL,
    payload_text text NULL CHECK (payload_text IS NULL), -- exact bytes consumed; not JSONB::text
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    UNIQUE (task_id,page_kind,round_no,sequence_no), UNIQUE (id,task_id),
    CHECK (private_payload IS NULL OR (jsonb_typeof(private_payload) = 'array'
        AND jsonb_array_length(private_payload) = entry_count
        AND octet_length(private_payload::text) <= 524288))
);
CREATE TABLE sand_ai_meeting_worker_event (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES sand_ai_meeting_worker_checkpoint(task_id) ON DELETE RESTRICT,
    sequence_no bigint NOT NULL CHECK (sequence_no > 0),
    previous_sha256 char(64) NULL CHECK (previous_sha256 ~ '^[a-f0-9]{64}$'),
    event_sha256 char(64) NOT NULL CHECK (event_sha256 ~ '^[a-f0-9]{64}$'),
    page_id bigint NOT NULL,
    anchor_sha256 char(64) NOT NULL CHECK (anchor_sha256 ~ '^[a-f0-9]{64}$'),
    lease_fence bigint NOT NULL CHECK (lease_fence > 0),
    event_text text NULL CHECK (event_text IS NULL),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    UNIQUE (task_id,sequence_no), UNIQUE (page_id),
    FOREIGN KEY (page_id,task_id) REFERENCES sand_ai_meeting_worker_page(id,task_id) ON DELETE RESTRICT
);
-- No checkpoint cursor can claim validated nodes. The only writable projection
-- is the head of an append-only chain of actual immutable page identities.
CREATE FUNCTION sand_ai_meeting_worker_task(task_key bigint) RETURNS sand_ai_task_run LANGUAGE plpgsql AS $$
DECLARE t sand_ai_task_run%ROWTYPE; zone text;
BEGIN
    SELECT * INTO t FROM sand_ai_task_run WHERE id=task_key FOR UPDATE;
    zone := current_setting('sand_ai.running_timezone', true);
    IF NOT FOUND OR t.task_type <> 'ai_completion' OR NOT (t.input ? '_meeting_summary')
       OR t.state <> 'running' OR t.status <> 1 OR t.delete_time IS NOT NULL
       OR t.content_purged_time IS NOT NULL OR zone IS NULL
       OR NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name=zone)
       OR t.lease_owner IS DISTINCT FROM current_setting('sand_ai.worker_owner',true)
       OR t.lease_token IS DISTINCT FROM current_setting('sand_ai.worker_token',true)
       OR t.lease_fence::text IS DISTINCT FROM current_setting('sand_ai.worker_fence',true)
       OR t.lease_expire_time IS NULL
       OR t.lease_expire_time <= (clock_timestamp() AT TIME ZONE zone) THEN
        RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
    END IF;
    RETURN t;
END $$;
CREATE FUNCTION sand_ai_meeting_worker_checkpoint_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t sand_ai_task_run%ROWTYPE; s sand_ai_audio_transcript_seal%ROWTYPE;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_IMMUTABLE'; END IF;
    IF TG_OP='INSERT' THEN
        t := sand_ai_meeting_worker_task(NEW.task_id);
        IF NEW.state <> 'staging' OR NEW.event_sequence <> 0 OR NEW.last_event_sha256 IS NOT NULL
           OR NEW.anchor_text IS NULL OR octet_length(NEW.anchor_text) > 8388608
           OR NEW.anchor_text::jsonb IS DISTINCT FROM NEW.anchor_snapshot
           OR NEW.anchor_snapshot IS DISTINCT FROM jsonb_build_object(
               'source',(t.input->'_meeting_summary')-'audio_authorization','plan',t.input->'_meeting_plan',
               'capacity',t.input->'_meeting_capacity','route',t.input->'_meeting_route')
           OR t.input->'_meeting_plan' IS NULL
           OR EXISTS (SELECT 1 FROM sand_ai_meeting_summary_node WHERE task_id=NEW.task_id) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_ENROLLMENT_INVALID';
        END IF;
        -- Aggregation retains a schema slot for the audited long-recording binding.
        -- Its concrete manifest/receipt check must be wired before accepting it.
        IF NEW.source_kind <> 'seal' THEN RAISE EXCEPTION 'SAND_AI_MEETING_AGGREGATION_BINDING_UNAVAILABLE'; END IF;
        SELECT * INTO s FROM sand_ai_audio_transcript_seal WHERE id=NEW.source_seal_id FOR SHARE;
        IF NOT FOUND OR s.content_purged_time IS NOT NULL OR s.environment_id <> t.environment_id
           OR s.audio_task_id::text IS DISTINCT FROM t.input #>> '{_meeting_summary,audio_task_id}'
           OR s.file_id::text IS DISTINCT FROM t.input #>> '{_meeting_summary,file_id}'
           OR s.transcript_sha256 IS DISTINCT FROM t.input #>> '{_meeting_summary,sha256}'
           OR s.review_revision::text IS DISTINCT FROM t.input #>> '{_meeting_summary,review_revision}'
           OR s.id::text IS DISTINCT FROM t.input #>> '{_meeting_summary,transcript_seal,id}' THEN
            RAISE EXCEPTION 'SAND_AI_SCOPE_SNAPSHOT_INVALID';
        END IF;
        NEW.anchor_sha256 := encode(sha256(convert_to(NEW.anchor_text,'UTF8')),'hex');
        NEW.anchor_text := NULL;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    ELSE
        IF ROW(NEW.id,NEW.task_id,NEW.source_kind,NEW.source_seal_id,NEW.anchor_snapshot,
            NEW.anchor_sha256,NEW.anchor_text,NEW.create_time) IS DISTINCT FROM
           ROW(OLD.id,OLD.task_id,OLD.source_kind,OLD.source_seal_id,OLD.anchor_snapshot,
            OLD.anchor_sha256,OLD.anchor_text,OLD.create_time) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_IMMUTABLE';
        END IF;
        SELECT * INTO t FROM sand_ai_task_run WHERE id=NEW.task_id FOR UPDATE;
        IF t.content_purged_time IS NOT NULL AND NEW.state='purged'
           AND NEW.event_sequence=OLD.event_sequence
           AND NEW.last_event_sha256 IS NOT DISTINCT FROM OLD.last_event_sha256 THEN RETURN NEW; END IF;
        IF pg_trigger_depth() < 2 OR NEW.state <> OLD.state OR OLD.state <> 'staging'
           OR NEW.event_sequence <> OLD.event_sequence+1
           OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_event e
               WHERE e.task_id=NEW.task_id AND e.sequence_no=NEW.event_sequence
               AND e.event_sha256=NEW.last_event_sha256
               AND e.previous_sha256 IS NOT DISTINCT FROM OLD.last_event_sha256) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_CHAIN_INVALID';
        END IF;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_checkpoint_guard BEFORE INSERT OR UPDATE OR DELETE
    ON sand_ai_meeting_worker_checkpoint FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_checkpoint_guard();
CREATE FUNCTION sand_ai_meeting_worker_page_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t sand_ai_task_run%ROWTYPE;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_IMMUTABLE'; END IF;
    IF TG_OP='UPDATE' THEN
        SELECT * INTO t FROM sand_ai_task_run WHERE id=OLD.task_id FOR UPDATE;
        IF t.content_purged_time IS NULL OR NEW.private_payload IS NOT NULL
           OR (to_jsonb(NEW)-'private_payload') IS DISTINCT FROM (to_jsonb(OLD)-'private_payload') THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_IMMUTABLE';
        END IF;
        RETURN NEW;
    END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    IF NEW.payload_text IS NULL OR octet_length(NEW.payload_text) > 262144
       OR NEW.payload_text::jsonb IS DISTINCT FROM NEW.private_payload
       OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint c
           WHERE c.task_id=NEW.task_id AND c.state='staging')
       OR NEW.sequence_no <> COALESCE((SELECT sequence_no+1 FROM sand_ai_meeting_worker_page
           WHERE task_id=NEW.task_id AND page_kind=NEW.page_kind AND round_no=NEW.round_no
           ORDER BY sequence_no DESC LIMIT 1),1) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_PAGE_INVALID';
    END IF;
    NEW.payload_sha256 := encode(sha256(convert_to(NEW.payload_text,'UTF8')),'hex');
    NEW.payload_text := NULL;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_page_guard BEFORE INSERT OR UPDATE OR DELETE
    ON sand_ai_meeting_worker_page FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_page_guard();
CREATE FUNCTION sand_ai_meeting_worker_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_worker_checkpoint%ROWTYPE; p sand_ai_meeting_worker_page%ROWTYPE; t sand_ai_task_run%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_IMMUTABLE'; END IF;
    t := sand_ai_meeting_worker_task(NEW.task_id);
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id FOR UPDATE;
    SELECT * INTO p FROM sand_ai_meeting_worker_page WHERE id=NEW.page_id AND task_id=NEW.task_id FOR SHARE;
    IF c.id IS NULL OR p.id IS NULL OR c.state <> 'staging' OR p.private_payload IS NULL
       OR NEW.sequence_no <> c.event_sequence+1
       OR NEW.previous_sha256 IS DISTINCT FROM c.last_event_sha256
       OR NEW.anchor_sha256 <> c.anchor_sha256 OR NEW.lease_fence <> t.lease_fence
       OR NEW.event_text IS NULL OR octet_length(NEW.event_text) > 4096
       OR NEW.event_text::jsonb IS DISTINCT FROM jsonb_build_object(
           'task_id',NEW.task_id,'sequence_no',NEW.sequence_no,'previous_sha256',NEW.previous_sha256,
           'anchor_sha256',c.anchor_sha256,'page_id',p.id,'payload_sha256',p.payload_sha256,
           'page_kind',p.page_kind,'round_no',p.round_no,'page_sequence_no',p.sequence_no,
           'entry_count',p.entry_count,'lease_fence',NEW.lease_fence) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_CHAIN_INVALID';
    END IF;
    NEW.event_sha256 := encode(sha256(convert_to(NEW.event_text,'UTF8')),'hex');
    NEW.event_text := NULL;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_event_guard BEFORE INSERT OR UPDATE OR DELETE
    ON sand_ai_meeting_worker_event FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_event_guard();
CREATE FUNCTION sand_ai_meeting_worker_event_head() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    UPDATE sand_ai_meeting_worker_checkpoint SET event_sequence=NEW.sequence_no,last_event_sha256=NEW.event_sha256
        WHERE task_id=NEW.task_id;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_event_head AFTER INSERT ON sand_ai_meeting_worker_event
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_event_head();
-- Deferred: page + chain event must commit atomically. A page alone is not progress.
CREATE FUNCTION sand_ai_meeting_worker_page_commit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_event WHERE page_id=NEW.id AND task_id=NEW.task_id) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_PAGE_UNCOMMITTED';
    END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER sand_ai_meeting_worker_page_commit AFTER INSERT ON sand_ai_meeting_worker_page
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_page_commit();
-- Freeze the complete source/plan/capacity/route, while preserving legal queued
-- authorization refresh. Identity change and clearing source anchors are forbidden.
CREATE FUNCTION sand_ai_meeting_worker_task_anchor() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_worker_checkpoint%ROWTYPE;
BEGIN
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=OLD.id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF ROW(NEW.environment_id,NEW.task_type,NEW.resource_type,NEW.resource_id,NEW.request_id,
        (NEW.input->'_meeting_summary')-'audio_authorization',NEW.input->'_meeting_plan',NEW.input->'_meeting_capacity',NEW.input->'_meeting_route')
       IS DISTINCT FROM ROW(OLD.environment_id,OLD.task_type,OLD.resource_type,OLD.resource_id,OLD.request_id,
        (OLD.input->'_meeting_summary')-'audio_authorization',OLD.input->'_meeting_plan',OLD.input->'_meeting_capacity',OLD.input->'_meeting_route')
       OR (OLD.content_purged_time IS NOT NULL AND NEW.content_purged_time IS DISTINCT FROM OLD.content_purged_time) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_ANCHOR_IMMUTABLE';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_task_anchor BEFORE UPDATE ON sand_ai_task_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_task_anchor();
CREATE FUNCTION sand_ai_meeting_worker_purge() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL THEN
        UPDATE sand_ai_meeting_worker_checkpoint SET state='purged' WHERE task_id=NEW.id;
        UPDATE sand_ai_meeting_worker_page SET private_payload=NULL WHERE task_id=NEW.id AND private_payload IS NOT NULL;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_purge AFTER UPDATE OF content_purged_time ON sand_ai_task_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_purge();
-- Node enforcement applies only to explicitly enrolled new tasks. Never backfill
-- old result hashes or silently promote old rows to verified v2 evidence.
ALTER TABLE sand_ai_meeting_summary_node ADD COLUMN worker_input_text text NULL,
    ADD COLUMN worker_result_text text NULL,
    ADD CONSTRAINT sand_ai_meeting_node_transient_ck CHECK (worker_input_text IS NULL AND worker_result_text IS NULL);
CREATE FUNCTION sand_ai_meeting_worker_node_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t sand_ai_task_run%ROWTYPE; c sand_ai_meeting_worker_checkpoint%ROWTYPE; parent sand_ai_meeting_summary_node%ROWTYPE;
BEGIN
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=COALESCE(NEW.task_id,OLD.task_id);
    IF NOT FOUND THEN RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END; END IF;
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_IMMUTABLE'; END IF;
    SELECT * INTO t FROM sand_ai_task_run WHERE id=NEW.task_id FOR UPDATE;
    IF TG_OP='UPDATE' THEN
        IF ROW(NEW.id,NEW.task_id,NEW.task_step_id,NEW.node_code,NEW.node_kind,NEW.level_no,NEW.sequence_no,
            NEW.segment_start_index,NEW.segment_end_index,NEW.transcript_sha256,NEW.review_revision,
            NEW.planner_version,NEW.input_sha256,NEW.dispatch_scope,NEW.create_time)
           IS DISTINCT FROM ROW(OLD.id,OLD.task_id,OLD.task_step_id,OLD.node_code,OLD.node_kind,OLD.level_no,OLD.sequence_no,
            OLD.segment_start_index,OLD.segment_end_index,OLD.transcript_sha256,OLD.review_revision,
            OLD.planner_version,OLD.input_sha256,OLD.dispatch_scope,OLD.create_time) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_IMMUTABLE';
        END IF;
        IF t.content_purged_time IS NOT NULL AND NEW.input_snapshot IS NULL AND NEW.result_snapshot IS NULL
           AND (to_jsonb(NEW)-ARRAY['input_snapshot','result_snapshot','update_time'])
               IS NOT DISTINCT FROM (to_jsonb(OLD)-ARRAY['input_snapshot','result_snapshot','update_time']) THEN RETURN NEW; END IF;
        IF NEW.input_snapshot IS DISTINCT FROM OLD.input_snapshot OR NEW.worker_input_text IS NOT NULL
           OR (OLD.result_sha256 IS NOT NULL AND ROW(NEW.result_snapshot,NEW.result_sha256,NEW.last_invocation_id)
               IS DISTINCT FROM ROW(OLD.result_snapshot,OLD.result_sha256,OLD.last_invocation_id))
           OR (OLD.parent_node_code IS NOT NULL AND NEW.parent_node_code IS DISTINCT FROM OLD.parent_node_code) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_IMMUTABLE';
        END IF;
    ELSE
        IF NEW.worker_input_text IS NULL OR octet_length(NEW.worker_input_text)>262144
           OR NEW.worker_input_text::jsonb IS DISTINCT FROM NEW.input_snapshot
           OR NEW.result_snapshot IS NOT NULL OR NEW.result_sha256 IS NOT NULL OR NEW.last_invocation_id IS NOT NULL
           OR NEW.parent_node_code IS NOT NULL
           -- The existing sequence=1 parent exemption can never own a node.
           OR NOT EXISTS (SELECT 1 FROM sand_ai_task_step step
               WHERE step.id=NEW.task_step_id AND step.task_id=NEW.task_id
                 AND step.sequence_no>1 AND step.step_code=NEW.node_code)
           OR NEW.transcript_sha256 IS DISTINCT FROM c.anchor_snapshot #>> '{source,sha256}'
           OR NEW.review_revision::text IS DISTINCT FROM c.anchor_snapshot #>> '{source,review_revision}'
           OR NEW.planner_version IS DISTINCT FROM c.anchor_snapshot #>> '{source,planner_version}' THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_INPUT_INVALID';
        END IF;
        NEW.input_sha256:=encode(sha256(convert_to(NEW.worker_input_text,'UTF8')),'hex');
        NEW.worker_input_text:=NULL;
    END IF;
    IF NEW.parent_node_code IS DISTINCT FROM OLD.parent_node_code AND NEW.parent_node_code IS NOT NULL THEN
        SELECT * INTO parent FROM sand_ai_meeting_summary_node
            WHERE task_id=NEW.task_id AND node_code=NEW.parent_node_code FOR SHARE;
        -- Separate shape from array operations: missing/null/scalar must fail
        -- before jsonb_array_length is evaluated, never rely on SQL NULL in IF.
        IF NOT FOUND OR parent.node_kind IS DISTINCT FROM 'merge'
           OR jsonb_typeof(parent.input_snapshot->'children') IS DISTINCT FROM 'array' THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PARENT_INVALID';
        END IF;
        IF jsonb_array_length(parent.input_snapshot->'children') IS DISTINCT FROM 2
           OR jsonb_typeof(parent.input_snapshot #> '{children,0}') IS DISTINCT FROM 'string'
           OR jsonb_typeof(parent.input_snapshot #> '{children,1}') IS DISTINCT FROM 'string'
           OR ((parent.input_snapshot #>> '{children,0}') ~ '^[a-z0-9][a-z0-9._-]{0,95}$') IS DISTINCT FROM true
           OR ((parent.input_snapshot #>> '{children,1}') ~ '^[a-z0-9][a-z0-9._-]{0,95}$') IS DISTINCT FROM true
           OR (parent.input_snapshot #>> '{children,0}') IS NOT DISTINCT FROM (parent.input_snapshot #>> '{children,1}')
           OR (parent.input_snapshot->'children' @> jsonb_build_array(NEW.node_code)) IS DISTINCT FROM true THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PARENT_INVALID';
        END IF;
    END IF;
    IF TG_OP='UPDATE' AND OLD.result_sha256 IS NULL AND NEW.result_sha256 IS NOT NULL THEN
        IF NEW.worker_result_text IS NULL OR octet_length(NEW.worker_result_text)>262144
           OR NEW.worker_result_text::jsonb IS DISTINCT FROM NEW.result_snapshot
           OR NEW.last_invocation_id IS NULL
           OR NEW.last_invocation_id::text IS DISTINCT FROM NEW.result_snapshot->>'invocation_id' THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_INVALID';
        END IF;
        NEW.result_sha256:=encode(sha256(convert_to(NEW.worker_result_text,'UTF8')),'hex');
        NEW.worker_result_text:=NULL;
    ELSIF NEW.worker_result_text IS NOT NULL
       OR (NEW.result_sha256 IS NULL AND (NEW.result_snapshot IS NOT NULL OR NEW.last_invocation_id IS NOT NULL)) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_INVALID';
    END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_node_guard BEFORE INSERT OR UPDATE OR DELETE
    ON sand_ai_meeting_summary_node FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_node_guard();
-- Shared deferred invariant: both owning tables must check the same exact
-- node/step/dispatch/request/usage relationship at commit, in either write order.
CREATE FUNCTION sand_ai_meeting_worker_assert_node_sealed(node_key bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE n sand_ai_meeting_summary_node%ROWTYPE; s sand_ai_task_step%ROWTYPE;
BEGIN
    SELECT * INTO n FROM sand_ai_meeting_summary_node WHERE id=node_key FOR SHARE;
    IF NOT FOUND OR n.result_sha256 IS NULL OR n.result_snapshot IS NULL OR n.last_invocation_id IS NULL THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_UNOWNED';
    END IF;
    SELECT * INTO s FROM sand_ai_task_step WHERE id=n.task_step_id AND task_id=n.task_id FOR SHARE;
    IF s.id IS NULL OR s.sequence_no<=1 OR s.state <> 'succeeded' OR s.step_code <> n.node_code OR s.attempt_count < 1
       OR s.status <> 1 OR s.delete_time IS NOT NULL
       OR n.result_snapshot->>'request_id' IS DISTINCT FROM format('task_%s_%s_attempt_%s',n.task_id,n.node_code,s.attempt_count)
       OR NOT EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch d
           JOIN sand_ai_invocation i ON i.id=d.invocation_id
           JOIN sand_ai_usage_event u ON u.invocation_id=i.id
           WHERE d.invocation_id=n.last_invocation_id AND d.parent_task_run_id=n.task_id
             AND d.parent_task_step_id=n.task_step_id AND d.workflow_node_code=n.node_code
             AND d.execution_scope='ai_task' AND d.state='succeeded'
             AND i.request_id=n.result_snapshot->>'request_id' AND i.state='succeeded'
             AND i.environment_id=(SELECT environment_id FROM sand_ai_task_run WHERE id=n.task_id)
             AND u.environment_id=i.environment_id) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_UNOWNED';
    END IF;
    -- Check only after every joined ownership row has been read/locked.
    PERFORM sand_ai_meeting_worker_task(n.task_id);
END $$;
CREATE FUNCTION sand_ai_meeting_worker_node_seal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE n sand_ai_meeting_summary_node%ROWTYPE; owning_step sand_ai_task_step%ROWTYPE;
BEGIN
    SELECT * INTO n FROM sand_ai_meeting_summary_node WHERE id=NEW.id;
    IF NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=n.task_id) THEN RETURN NULL; END IF;
    -- Original task tombstone is the sole legal private-body purge path.
    IF EXISTS (SELECT 1 FROM sand_ai_task_run WHERE id=n.task_id AND content_purged_time IS NOT NULL) THEN RETURN NULL; END IF;
    IF n.result_sha256 IS NULL THEN
        -- A step may predate enrollment and never fire the new step trigger.
        -- The node-side check must reject its already committed succeeded state.
        SELECT * INTO owning_step FROM sand_ai_task_step
            WHERE id=n.task_step_id AND task_id=n.task_id FOR SHARE;
        IF NOT FOUND OR owning_step.state='succeeded' THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_UNOWNED';
        END IF;
        PERFORM sand_ai_meeting_worker_task(n.task_id);
        RETURN NULL;
    END IF;
    PERFORM sand_ai_meeting_worker_assert_node_sealed(n.id);
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER sand_ai_meeting_worker_node_seal AFTER INSERT OR UPDATE ON sand_ai_meeting_summary_node
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_node_seal();
CREATE FUNCTION sand_ai_meeting_worker_step_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id) THEN RETURN NEW; END IF;
        -- sequence=1/invoke_model remains the existing parent lifecycle. All new
        -- node steps require the current task lease, before their node is inserted.
        IF NEW.sequence_no=1 AND NEW.step_code='invoke_model' THEN RETURN NEW; END IF;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
        RETURN NEW;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=OLD.task_id)
       AND (TG_OP='DELETE' OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id)) THEN
        RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
    END IF;
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE'; END IF;
    IF ROW(NEW.id,NEW.task_id,NEW.step_code,NEW.sequence_no,NEW.create_time)
       IS DISTINCT FROM ROW(OLD.id,OLD.task_id,OLD.step_code,OLD.sequence_no,OLD.create_time) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE';
    END IF;
    -- Exact no-op is safe even after terminal state, lease expiry or legal purge.
    IF to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD) THEN RETURN NEW; END IF;
    IF OLD.sequence_no=1 AND OLD.step_code='invoke_model' THEN RETURN NEW; END IF;
    IF OLD.state='succeeded' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE'; END IF;
    -- No purge exemption for pending-step writes: cleanup does not alter steps.
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_step_guard BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_task_step
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_step_guard();
CREATE FUNCTION sand_ai_meeting_worker_step_seal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s sand_ai_task_step%ROWTYPE; n sand_ai_meeting_summary_node%ROWTYPE;
BEGIN
    IF TG_OP='UPDATE' AND to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD) THEN RETURN NULL; END IF;
    SELECT * INTO s FROM sand_ai_task_step WHERE id=NEW.id FOR SHARE;
    IF NOT FOUND OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=s.task_id) THEN RETURN NULL; END IF;
    IF s.sequence_no=1 AND s.step_code='invoke_model' THEN RETURN NULL; END IF;
    -- Lease/cleanup boundary is repeated at commit after any node locks, even
    -- when the changed step is still pending. No late status projection survives.
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    IF s.state='succeeded' THEN
        SELECT * INTO n FROM sand_ai_meeting_summary_node WHERE task_id=s.task_id AND task_step_id=s.id FOR SHARE;
        IF NOT FOUND OR n.node_code IS DISTINCT FROM s.step_code THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_UNOWNED';
        END IF;
        PERFORM sand_ai_meeting_worker_assert_node_sealed(n.id);
    END IF;
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER sand_ai_meeting_worker_step_seal AFTER INSERT OR UPDATE ON sand_ai_task_step
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_step_seal();

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610030109_meeting_worker_foundation', 'b545ff383ab90b96e45bc9dc16c12cdc7a1811ccf78bdbdf2766b17c7a42c66c', CURRENT_TIMESTAMP(0));

-- Migration 202610030111_meeting_durable_plan (sha256 2da2f09d3f7c5f5068a70f6527d92c18b433b8b39d135783b3edd00dafe51cfd)
-- Registered isolated candidate; SQL execution/installation remains unverified.
-- Requires 109's sand_ai_meeting_worker_task fenced parent-task guard. No execution here.
CREATE TABLE sand_ai_meeting_plan_checkpoint (
    task_id bigint PRIMARY KEY REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    anchor jsonb NOT NULL CHECK(jsonb_typeof(anchor)='object'),
    anchor_sha256 char(64) NOT NULL CHECK(anchor_sha256 ~ '^[a-f0-9]{64}$'),
    anchor_text text NULL CHECK(anchor_text IS NULL),
    phase varchar(16) NOT NULL DEFAULT 'preparing' CHECK(phase IN ('preparing','complete','purged')),
    state_json jsonb NOT NULL CHECK(jsonb_typeof(state_json)='object'),
    state_sha256 char(64) NOT NULL CHECK(state_sha256 ~ '^[a-f0-9]{64}$'),
    state_text text NULL CHECK(state_text IS NULL),
    event_sequence bigint NOT NULL DEFAULT 0 CHECK(event_sequence>=0),
    last_event_sha256 char(64) NULL CHECK(last_event_sha256 ~ '^[a-f0-9]{64}$'),
    manifest_sha256 char(64) NULL CHECK(manifest_sha256 ~ '^[a-f0-9]{64}$'),
    content_purged_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CHECK((phase='complete')=(manifest_sha256 IS NOT NULL) OR phase='purged'),
    CHECK((event_sequence=0)=(last_event_sha256 IS NULL))
);
CREATE TABLE sand_ai_meeting_plan_page (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES sand_ai_meeting_plan_checkpoint(task_id) ON DELETE RESTRICT,
    page_kind varchar(16) NOT NULL CHECK(page_kind IN ('source','leaf')),
    sequence_no integer NOT NULL CHECK(sequence_no BETWEEN 1 AND 100000),
    entry_count integer NOT NULL CHECK(entry_count BETWEEN 1 AND 32),
    payload_sha256 char(64) NOT NULL CHECK(payload_sha256 ~ '^[a-f0-9]{64}$'),
    private_payload jsonb NULL,
    payload_text text NULL CHECK(payload_text IS NULL),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    UNIQUE(task_id,page_kind,sequence_no), UNIQUE(id,task_id),
    CHECK(private_payload IS NULL OR (jsonb_typeof(private_payload)='array'
        AND jsonb_array_length(private_payload)=entry_count AND octet_length(private_payload::text)<=4194304))
);
CREATE TABLE sand_ai_meeting_plan_event (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES sand_ai_meeting_plan_checkpoint(task_id) ON DELETE RESTRICT,
    sequence_no bigint NOT NULL CHECK(sequence_no>0),
    previous_sha256 char(64) NULL,
    event_sha256 char(64) NOT NULL CHECK(event_sha256 ~ '^[a-f0-9]{64}$'),
    anchor_sha256 char(64) NOT NULL CHECK(anchor_sha256 ~ '^[a-f0-9]{64}$'),
    page_id bigint NULL,
    lease_fence bigint NOT NULL CHECK(lease_fence>0),
    event_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    event_text text NULL CHECK(event_text IS NULL),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    UNIQUE(task_id,sequence_no),UNIQUE(page_id),
    FOREIGN KEY(page_id,task_id) REFERENCES sand_ai_meeting_plan_page(id,task_id) ON DELETE RESTRICT
);
CREATE FUNCTION sand_ai_meeting_plan_checkpoint_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t sand_ai_task_run%ROWTYPE;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_IMMUTABLE'; END IF;
    IF TG_OP='INSERT' THEN
        t:=sand_ai_meeting_worker_task(NEW.task_id);
        IF NEW.phase<>'preparing' OR NEW.event_sequence<>0 OR NEW.manifest_sha256 IS NOT NULL
            OR NEW.anchor_text IS NULL OR octet_length(NEW.anchor_text)>65536 OR NEW.anchor_text::jsonb IS DISTINCT FROM NEW.anchor
            OR NEW.state_text IS NULL OR octet_length(NEW.state_text)>262144 OR NEW.state_text::jsonb IS DISTINCT FROM NEW.state_json
            OR NEW.anchor->'source' IS DISTINCT FROM (t.input->'_meeting_summary')-'audio_authorization'
            OR NEW.anchor->'capacity' IS DISTINCT FROM t.input->'_meeting_capacity'
            OR NEW.anchor->'route' IS DISTINCT FROM t.input->'_meeting_route'
            OR (NEW.anchor->>'total_segments')::integer NOT BETWEEN 1 AND 10000
            OR NEW.anchor->>'total_segments' IS DISTINCT FROM t.input#>>'{_meeting_summary,total_segments}'
            OR (NEW.anchor->>'eligible_segments' IS NOT NULL AND (NEW.anchor->>'eligible_segments')::integer NOT BETWEEN 1 AND (NEW.anchor->>'total_segments')::integer)
            OR NEW.state_json IS DISTINCT FROM jsonb_build_object('source_cursor',0,'eligible_segments',0,'leaf_count',0,'source_page_count',0,
                'batch','[]'::jsonb,'batch_chars',0,'pending','[]'::jsonb,'tokenizer_call_count',0,
                'source_chain_sha256',repeat('0',64),'leaf_chain_sha256',repeat('0',64))
            OR EXISTS(SELECT 1 FROM sand_ai_meeting_summary_node WHERE task_id=NEW.task_id)
            OR t.input ? '_meeting_plan' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_ENROLLMENT_INVALID'; END IF;
        NEW.anchor_sha256:=encode(sha256(convert_to(NEW.anchor_text,'UTF8')),'hex'); NEW.anchor_text:=NULL;
        NEW.state_sha256:=encode(sha256(convert_to(NEW.state_text,'UTF8')),'hex'); NEW.state_text:=NULL;
        RETURN NEW;
    END IF;
    IF ROW(NEW.task_id,NEW.anchor,NEW.anchor_sha256,NEW.create_time) IS DISTINCT FROM ROW(OLD.task_id,OLD.anchor,OLD.anchor_sha256,OLD.create_time)
        OR NEW.anchor_text IS NOT NULL OR NEW.state_text IS NOT NULL THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_IMMUTABLE'; END IF;
    -- Only event-head projection or task-purge triggers may mutate this row.
    IF pg_trigger_depth()<2 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_DIRECT_WRITE'; END IF;
    IF NEW.phase='purged' AND NEW.content_purged_time IS NOT NULL AND EXISTS(SELECT 1 FROM sand_ai_task_run WHERE id=NEW.task_id AND content_purged_time IS NOT NULL) THEN RETURN NEW; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    IF OLD.phase<>'preparing' OR NEW.content_purged_time IS NOT NULL OR NEW.event_sequence<>OLD.event_sequence+1
        OR NOT EXISTS(SELECT 1 FROM sand_ai_meeting_plan_event e WHERE e.task_id=NEW.task_id AND e.sequence_no=NEW.event_sequence
            AND e.event_sha256=NEW.last_event_sha256 AND e.previous_sha256 IS NOT DISTINCT FROM OLD.last_event_sha256
            AND e.event_payload->'state' IS NOT DISTINCT FROM NEW.state_json
            AND e.event_payload->>'phase'=NEW.phase AND e.event_payload->>'previous_state_sha256'=OLD.state_sha256
            AND e.event_payload->>'manifest_sha256' IS NOT DISTINCT FROM NEW.manifest_sha256)
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_HEAD_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_plan_checkpoint_guard BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_meeting_plan_checkpoint FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_checkpoint_guard();
CREATE FUNCTION sand_ai_meeting_plan_page_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_IMMUTABLE'; END IF;
    IF TG_OP='UPDATE' THEN
        IF pg_trigger_depth()>1 AND OLD.private_payload IS NOT NULL AND NEW.private_payload IS NULL
            AND ROW(NEW.id,NEW.task_id,NEW.page_kind,NEW.sequence_no,NEW.entry_count,NEW.payload_sha256,NEW.create_time) IS NOT DISTINCT FROM ROW(OLD.id,OLD.task_id,OLD.page_kind,OLD.sequence_no,OLD.entry_count,OLD.payload_sha256,OLD.create_time)
            AND EXISTS(SELECT 1 FROM sand_ai_task_run WHERE id=NEW.task_id AND content_purged_time IS NOT NULL) THEN RETURN NEW; END IF;
        RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_IMMUTABLE';
    END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=NEW.task_id FOR UPDATE;
    IF NOT FOUND OR c.phase<>'preparing' OR c.content_purged_time IS NOT NULL OR NEW.private_payload IS NULL
        OR NEW.payload_text IS NULL OR octet_length(NEW.payload_text)>3145728 OR NEW.payload_text::jsonb IS DISTINCT FROM NEW.private_payload
        OR (NEW.page_kind='source' AND NEW.sequence_no<>(c.state_json->>'source_page_count')::integer+1)
        OR (NEW.page_kind='leaf' AND (NEW.entry_count<>1 OR NEW.sequence_no<>(c.state_json->>'leaf_count')::integer+1))
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_PAGE_INVALID'; END IF;
    NEW.payload_sha256:=encode(sha256(convert_to(NEW.payload_text,'UTF8')),'hex');NEW.payload_text:=NULL;RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_plan_page_guard BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_meeting_plan_page FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_page_guard();
CREATE FUNCTION sand_ai_meeting_plan_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE; p sand_ai_meeting_plan_page%ROWTYPE; t sand_ai_task_run%ROWTYPE; s jsonb; item jsonb; pos integer; expected_parts jsonb; actual_parts jsonb; left_parts jsonb; right_parts jsonb; first_parts jsonb; middle integer; codepoints integer; offset_no integer;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_IMMUTABLE'; END IF;
    t:=sand_ai_meeting_worker_task(NEW.task_id);
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=NEW.task_id FOR UPDATE;
    IF NOT FOUND OR c.phase<>'preparing' OR NEW.event_text IS NULL OR octet_length(NEW.event_text)>1048576 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_EVENT_INVALID'; END IF;
    NEW.event_payload:=NEW.event_text::jsonb;s:=NEW.event_payload->'state';
    IF NOT NEW.event_payload ?& ARRAY['task_id','sequence_no','previous_sha256','anchor_sha256','previous_state_sha256','state','state_text','state_sha256','phase','page_id','payload_sha256','manifest_sha256','lease_fence']
        OR NOT s ?& ARRAY['source_cursor','eligible_segments','leaf_count','source_page_count','batch','batch_chars','pending','tokenizer_call_count','source_chain_sha256','leaf_chain_sha256']
        OR NEW.sequence_no<>c.event_sequence+1 OR NEW.previous_sha256 IS DISTINCT FROM c.last_event_sha256 OR NEW.anchor_sha256<>c.anchor_sha256
        OR NEW.lease_fence<>t.lease_fence OR NEW.event_payload->>'previous_state_sha256'<>c.state_sha256
        OR NEW.event_payload->>'anchor_sha256'<>c.anchor_sha256 OR (NEW.event_payload->>'sequence_no')::bigint<>NEW.sequence_no
        OR (NEW.event_payload->>'task_id')::bigint<>NEW.task_id OR (NEW.event_payload->>'lease_fence')::bigint<>NEW.lease_fence
        OR NEW.event_payload->>'previous_sha256' IS DISTINCT FROM c.last_event_sha256
        OR (NEW.event_payload->>'page_id')::bigint IS DISTINCT FROM NEW.page_id
        OR octet_length(NEW.event_payload->>'state_text')>262144
        OR (NEW.event_payload->>'state_text')::jsonb IS DISTINCT FROM s
        OR NEW.event_payload->>'state_sha256' IS DISTINCT FROM encode(sha256(convert_to(NEW.event_payload->>'state_text','UTF8')),'hex')
        OR jsonb_typeof(s)<>'object' OR jsonb_typeof(s->'pending')<>'array' OR jsonb_typeof(s->'batch')<>'array'
        OR jsonb_array_length(s->'pending')>128 OR jsonb_array_length(s->'batch')>512 OR octet_length(s::text)>524288
        OR (s->>'source_cursor')::integer<(c.state_json->>'source_cursor')::integer OR (s->>'source_cursor')::integer>(c.anchor->>'total_segments')::integer
        OR (s->>'leaf_count')::integer<(c.state_json->>'leaf_count')::integer OR (s->>'leaf_count')::integer>100000
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_EVENT_INVALID'; END IF;
    IF NEW.page_id IS NOT NULL THEN
        SELECT * INTO p FROM sand_ai_meeting_plan_page WHERE id=NEW.page_id AND task_id=NEW.task_id;
        IF NOT FOUND OR p.private_payload IS NULL OR NEW.event_payload->>'payload_sha256'<>p.payload_sha256
            OR NEW.event_payload->>'phase'<>'preparing' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_PAGE_EVENT_INVALID'; END IF;
        IF p.page_kind='source' THEN
            IF p.entry_count<>least(32,(c.anchor->>'total_segments')::integer-(c.state_json->>'source_cursor')::integer)
                OR (s->>'source_cursor')::integer<>(c.state_json->>'source_cursor')::integer+p.entry_count
                OR (s->>'source_page_count')::integer<>p.sequence_no
                OR (s->>'leaf_count')::integer<>(c.state_json->>'leaf_count')::integer
                OR s->'tokenizer_call_count' IS DISTINCT FROM c.state_json->'tokenizer_call_count'
                OR s->>'leaf_chain_sha256'<>c.state_json->>'leaf_chain_sha256'
                OR s->>'source_chain_sha256'<>encode(sha256(convert_to((c.state_json->>'source_chain_sha256')||':'||p.payload_sha256,'UTF8')),'hex')
                OR (s->>'eligible_segments')::integer<>(c.state_json->>'eligible_segments')::integer+(SELECT count(*) FROM jsonb_array_elements(p.private_payload) entry WHERE entry#>>'{proof,exclusion}' IS NULL) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_COVERAGE_INVALID'; END IF;
            IF c.state_json->'pending'<>'[]'::jsonb THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_WHILE_PENDING'; END IF;
            expected_parts:=c.state_json->'batch';
            pos:=(c.state_json->>'source_cursor')::integer;
            FOR item IN SELECT value FROM jsonb_array_elements(p.private_payload) LOOP
                IF (item->>'segment_index')::integer<>pos OR item#>>'{proof,segment_id}'<>'S'||(pos+1)::text
                    OR item#>>'{proof,text_sha256}'<>encode(sha256(convert_to(item#>>'{private_segment,text}','UTF8')),'hex')
                    OR (item#>>'{proof,unicode_characters}')::integer<>char_length(item#>>'{private_segment,text}')
                    OR (item#>>'{proof,unicode_characters}')::integer>10000
                    OR ((item#>>'{proof,exclusion}' IS NULL)=(btrim(item#>>'{private_segment,text}',E' \t\n\r\013')=''))
                    OR (item#>>'{proof,exclusion}' IS NOT NULL AND item#>>'{proof,exclusion}'<>'no_usable_text') THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_COVERAGE_INVALID'; END IF;
                IF item#>>'{proof,exclusion}' IS NULL THEN
                    codepoints:=(item#>>'{proof,unicode_characters}')::integer;offset_no:=0;
                    WHILE offset_no<codepoints LOOP
                        expected_parts:=expected_parts||jsonb_build_array(jsonb_build_object('segment_index',pos,'start_offset',offset_no,'end_offset',least(codepoints,offset_no+8000)));
                        offset_no:=offset_no+8000;
                    END LOOP;
                END IF;
                pos:=pos+1;
            END LOOP;
            actual_parts:='[]'::jsonb;
            FOR item IN SELECT value FROM jsonb_array_elements(s->'pending') LOOP actual_parts:=actual_parts||item; END LOOP;
            actual_parts:=actual_parts||s->'batch';
            IF actual_parts IS DISTINCT FROM expected_parts THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_PARTITION_INVALID'; END IF;
        ELSE
            IF (s->>'source_cursor')::integer<>(c.state_json->>'source_cursor')::integer
                OR (s->>'source_page_count')::integer<>(c.state_json->>'source_page_count')::integer
                OR (s->>'leaf_count')::integer<>p.sequence_no
                OR s->>'source_chain_sha256'<>c.state_json->>'source_chain_sha256'
                OR (s->>'eligible_segments')::integer<>(c.state_json->>'eligible_segments')::integer
                OR s->'tokenizer_call_count' IS DISTINCT FROM c.state_json->'tokenizer_call_count'
                OR s->>'leaf_chain_sha256'<>encode(sha256(convert_to((c.state_json->>'leaf_chain_sha256')||':'||p.payload_sha256,'UTF8')),'hex')
                OR p.private_payload->0->'parts' IS DISTINCT FROM c.state_json#>'{pending,0}'
                OR p.private_payload#>>'{0,node_code}'<> 'chunk_'||lpad(p.sequence_no::text,greatest(5,length(p.sequence_no::text)),'0')
                OR (p.private_payload#>>'{0,sequence_no}')::integer<>p.sequence_no
                OR (p.private_payload#>>'{0,token_count_receipt,prompt_tokens}')::integer>(c.anchor#>>'{capacity,input_token_limit}')::integer
                THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_INVALID'; END IF;
            IF s->'pending' IS DISTINCT FROM ((c.state_json->'pending')-0) OR s->'batch' IS DISTINCT FROM c.state_json->'batch'
                OR s->'batch_chars' IS DISTINCT FROM c.state_json->'batch_chars' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_PARTITION_INVALID'; END IF;
            IF NOT EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request r JOIN sand_ai_meeting_token_count_outcome o ON o.request_event_id=r.id
                WHERE r.task_id=NEW.task_id AND r.input_sha256=p.private_payload#>>'{0,messages_sha256}'
                    AND r.capacity_sha256=c.anchor#>>'{capacity,snapshot_sha256}' AND o.state='succeeded'
                    AND o.capacity_receipt=p.private_payload#>'{0,token_count_receipt}') THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TOKEN_RECEIPT_INVALID'; END IF;
        END IF;
    ELSE
        IF NEW.event_payload->>'phase'='complete' THEN
            IF (s->>'source_cursor')::integer<>(c.anchor->>'total_segments')::integer OR s->'pending'<>'[]'::jsonb OR s->'batch'<>'[]'::jsonb
                OR (s->>'eligible_segments')::integer<1 OR (s->>'leaf_count')::integer<1
                OR NEW.event_payload->>'manifest_sha256' IS NULL
                OR s IS DISTINCT FROM jsonb_set(c.state_json,'{tokenizer_call_count}',to_jsonb((SELECT request_count FROM sand_ai_meeting_plan_tokenizer_counter WHERE task_id=NEW.task_id))) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COMPLETION_INVALID'; END IF;
        ELSE
            first_parts:=c.state_json#>'{pending,0}';
            IF NEW.event_payload->>'phase'<>'preparing' OR first_parts IS NULL OR jsonb_array_length(first_parts)<1 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SPLIT_INVALID'; END IF;
            IF jsonb_array_length(first_parts)>1 THEN
                middle:=jsonb_array_length(first_parts)/2;
                SELECT jsonb_agg(value ORDER BY ordinality) INTO left_parts FROM jsonb_array_elements(first_parts) WITH ORDINALITY WHERE ordinality<=middle;
                SELECT jsonb_agg(value ORDER BY ordinality) INTO right_parts FROM jsonb_array_elements(first_parts) WITH ORDINALITY WHERE ordinality>middle;
            ELSE
                item:=first_parts->0;codepoints:=(item->>'end_offset')::integer-(item->>'start_offset')::integer;
                IF codepoints<2 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_UNSPLITTABLE'; END IF;
                middle:=(item->>'start_offset')::integer+codepoints/2;
                left_parts:=jsonb_build_array(jsonb_set(item,'{end_offset}',to_jsonb(middle)));
                right_parts:=jsonb_build_array(jsonb_set(item,'{start_offset}',to_jsonb(middle)));
            END IF;
            IF s IS DISTINCT FROM jsonb_set(c.state_json,'{pending}',jsonb_build_array(left_parts,right_parts)||((c.state_json->'pending')-0)) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SPLIT_PARTITION_INVALID'; END IF;
        END IF;
    END IF;
    NEW.event_sha256:=encode(sha256(convert_to(NEW.event_text,'UTF8')),'hex');NEW.event_text:=NULL;RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_plan_event_guard BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_meeting_plan_event FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_event_guard();
CREATE FUNCTION sand_ai_meeting_plan_event_head() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    -- state_sha256 uses the canonical bytes embedded within immutable event text.
    -- The producer also rereads/fingerprints state after every commit projection.
    UPDATE sand_ai_meeting_plan_checkpoint SET state_json=NEW.event_payload->'state',
        state_sha256=NEW.event_payload->>'state_sha256',phase=NEW.event_payload->>'phase',event_sequence=NEW.sequence_no,
        last_event_sha256=NEW.event_sha256,manifest_sha256=NEW.event_payload->>'manifest_sha256' WHERE task_id=NEW.task_id;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_plan_event_head AFTER INSERT ON sand_ai_meeting_plan_event FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_event_head();
CREATE FUNCTION sand_ai_meeting_plan_page_commit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.private_payload IS NOT NULL AND NOT EXISTS(SELECT 1 FROM sand_ai_meeting_plan_event WHERE page_id=NEW.id AND task_id=NEW.task_id)
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_ORPHAN_PAGE'; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER sand_ai_meeting_plan_page_commit AFTER INSERT ON sand_ai_meeting_plan_page DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_page_commit();
CREATE FUNCTION sand_ai_meeting_plan_task_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE; expected_plan jsonb;
BEGIN
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=OLD.id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF ((NEW.input-ARRAY['_meeting_plan','iam_authorization'])#-ARRAY['_meeting_summary','audio_authorization'])
        IS DISTINCT FROM ((OLD.input-ARRAY['_meeting_plan','iam_authorization'])#-ARRAY['_meeting_summary','audio_authorization'])
        AND NEW.content_purged_time IS NULL
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TASK_ANCHOR_CHANGED'; END IF;
    IF NEW.input->'_meeting_plan' IS DISTINCT FROM OLD.input->'_meeting_plan' AND NEW.content_purged_time IS NULL THEN
        PERFORM sand_ai_meeting_worker_task(OLD.id);
        expected_plan:=jsonb_build_object(
            'version','meeting-summary-v3','normalization','none_unicode_codepoint@1',
            'transcript_sha256',c.anchor#>>'{source,sha256}','capacity_sha256',c.anchor#>>'{capacity,snapshot_sha256}',
            'total_segments',(c.anchor->>'total_segments')::integer,'eligible_segments',(c.state_json->>'eligible_segments')::integer,
            'leaf_count',(c.state_json->>'leaf_count')::integer,'source_page_count',(c.state_json->>'source_page_count')::integer,
            'source_chain_sha256',c.state_json->>'source_chain_sha256','leaf_chain_sha256',c.state_json->>'leaf_chain_sha256',
            'tokenizer_call_count',(c.state_json->>'tokenizer_call_count')::integer,'tokenizer_pricing_state','unpriced',
            'manifest_sha256',c.manifest_sha256,'prepared_plan_ref',jsonb_build_object(
                'version','meeting_prepared_plan@1','task_id',OLD.id,'anchor_sha256',c.anchor_sha256,'manifest_sha256',c.manifest_sha256));
        IF c.phase<>'complete' OR NEW.input->'_meeting_plan' IS DISTINCT FROM expected_plan THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TASK_PROOF_INVALID';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_plan_task_guard BEFORE UPDATE ON sand_ai_task_run FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_task_guard();
CREATE FUNCTION sand_ai_meeting_plan_commit_lease() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER sand_ai_meeting_plan_event_commit AFTER INSERT ON sand_ai_meeting_plan_event
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_commit_lease();
CREATE CONSTRAINT TRIGGER sand_ai_meeting_plan_checkpoint_commit AFTER INSERT ON sand_ai_meeting_plan_checkpoint
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_commit_lease();
CREATE FUNCTION sand_ai_meeting_plan_purge() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL THEN
        UPDATE sand_ai_meeting_plan_page SET private_payload=NULL WHERE task_id=NEW.id AND private_payload IS NOT NULL;
        UPDATE sand_ai_meeting_plan_checkpoint SET phase='purged',content_purged_time=NEW.content_purged_time WHERE task_id=NEW.id;
    END IF;RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_plan_purge AFTER UPDATE OF content_purged_time ON sand_ai_task_run FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_purge();
-- O(1) per-task tokenizer intent count. Existing legacy tasks retain their old ledger path.
CREATE TABLE sand_ai_meeting_plan_tokenizer_counter (
    task_id bigint PRIMARY KEY REFERENCES sand_ai_meeting_plan_checkpoint(task_id) ON DELETE RESTRICT,
    request_count integer NOT NULL DEFAULT 0 CHECK(request_count BETWEEN 0 AND 100000),
    last_request_event_id bigint NULL REFERENCES sand_ai_meeting_token_count_request(id) ON DELETE RESTRICT,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);
CREATE FUNCTION sand_ai_meeting_plan_tokenizer_counter_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' OR pg_trigger_depth()<2 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COUNTER_DIRECT_WRITE'; END IF;
    IF TG_OP='INSERT' THEN
        IF NEW.request_count<>0 OR NEW.last_request_event_id IS NOT NULL THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COUNTER_INVALID'; END IF;
    ELSIF NEW.task_id<>OLD.task_id OR NEW.create_time<>OLD.create_time OR NEW.request_count<>OLD.request_count+1
        OR NEW.last_request_event_id IS NULL OR NOT EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request WHERE id=NEW.last_request_event_id AND task_id=NEW.task_id)
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COUNTER_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_plan_tokenizer_counter_guard BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_meeting_plan_tokenizer_counter FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_tokenizer_counter_guard();
CREATE FUNCTION sand_ai_meeting_plan_tokenizer_counter_init() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request WHERE task_id=NEW.task_id) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_PRIOR_TOKENIZER_INTENT'; END IF;
    INSERT INTO sand_ai_meeting_plan_tokenizer_counter(task_id) VALUES(NEW.task_id);RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_plan_tokenizer_counter_init AFTER INSERT ON sand_ai_meeting_plan_checkpoint FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_tokenizer_counter_init();
CREATE FUNCTION sand_ai_meeting_plan_tokenizer_counter_add() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE;
BEGIN
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=NEW.task_id FOR UPDATE;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF c.phase NOT IN ('preparing','complete') OR c.content_purged_time IS NOT NULL OR NEW.capacity_sha256<>c.anchor#>>'{capacity,snapshot_sha256}'
        OR NEW.transcript_sha256<>c.anchor#>>'{source,sha256}' OR NEW.route_sha256<>c.anchor#>>'{route,snapshot_sha256}'
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COUNTER_SCOPE_INVALID'; END IF;
    UPDATE sand_ai_meeting_plan_tokenizer_counter SET request_count=request_count+1,last_request_event_id=NEW.id WHERE task_id=NEW.task_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COUNTER_MISSING'; END IF;RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_plan_tokenizer_counter_add AFTER INSERT ON sand_ai_meeting_token_count_request FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_plan_tokenizer_counter_add();

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610030111_meeting_durable_plan', '2da2f09d3f7c5f5068a70f6527d92c18b433b8b39d135783b3edd00dafe51cfd', CURRENT_TIMESTAMP(0));

-- Migration 202610030112_meeting_leaf_materialization (sha256 7e6a6bfb52ab88863939a947a143903a04b187dea8b79e6921b787593fa1b202)
-- 112 is after 111: concrete prepared-plan and aggregation enrollment replace the 109 seal-only guard.
CREATE OR REPLACE FUNCTION sand_ai_meeting_worker_checkpoint_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t sand_ai_task_run%ROWTYPE; s sand_ai_audio_transcript_seal%ROWTYPE; prepared record;
    binding jsonb; source_audio record; source_manifest record; review_row record;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_IMMUTABLE'; END IF;
    IF TG_OP='INSERT' THEN
        t := sand_ai_meeting_worker_task(NEW.task_id);
        IF NEW.state <> 'staging' OR NEW.event_sequence <> 0 OR NEW.last_event_sha256 IS NOT NULL
           OR NEW.anchor_text IS NULL OR octet_length(NEW.anchor_text) > 8388608
           OR NEW.anchor_text::jsonb IS DISTINCT FROM NEW.anchor_snapshot
           OR NEW.anchor_snapshot IS DISTINCT FROM jsonb_build_object(
               'source',(t.input->'_meeting_summary')-'audio_authorization','plan',t.input->'_meeting_plan',
               'capacity',t.input->'_meeting_capacity','route',t.input->'_meeting_route')
           OR t.input->'_meeting_plan' IS NULL
           OR EXISTS (SELECT 1 FROM sand_ai_meeting_summary_node WHERE task_id=NEW.task_id) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_ENROLLMENT_INVALID';
        END IF;
        IF NEW.anchor_snapshot #> '{plan,prepared_plan_ref}' IS NOT NULL THEN
            SELECT * INTO prepared FROM sand_ai_meeting_plan_checkpoint WHERE task_id=NEW.task_id FOR SHARE;
            IF NOT FOUND OR prepared.phase<>'complete' OR prepared.content_purged_time IS NOT NULL
                OR NEW.anchor_snapshot #>> '{plan,prepared_plan_ref,version}' IS DISTINCT FROM 'meeting_prepared_plan@1'
                OR NEW.anchor_snapshot #>> '{plan,prepared_plan_ref,task_id}' IS DISTINCT FROM NEW.task_id::text
                OR NEW.anchor_snapshot #>> '{plan,prepared_plan_ref,anchor_sha256}' IS DISTINCT FROM prepared.anchor_sha256
                OR NEW.anchor_snapshot #>> '{plan,prepared_plan_ref,manifest_sha256}' IS DISTINCT FROM prepared.manifest_sha256
                OR NEW.anchor_snapshot #>> '{plan,manifest_sha256}' IS DISTINCT FROM prepared.manifest_sha256
                OR NEW.anchor_snapshot->'source' IS DISTINCT FROM prepared.anchor->'source'
                OR NEW.anchor_snapshot->'capacity' IS DISTINCT FROM prepared.anchor->'capacity'
                OR NEW.anchor_snapshot->'route' IS DISTINCT FROM prepared.anchor->'route'
                OR NEW.anchor_snapshot #>> '{plan,total_segments}' IS DISTINCT FROM prepared.anchor->>'total_segments'
                OR NEW.anchor_snapshot #>> '{plan,eligible_segments}' IS DISTINCT FROM prepared.state_json->>'eligible_segments'
                OR NEW.anchor_snapshot #>> '{plan,leaf_count}' IS DISTINCT FROM prepared.state_json->>'leaf_count' THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_PREPARED_PLAN_INVALID';
            END IF;
        END IF;
        IF NEW.source_kind='seal' THEN
          SELECT * INTO s FROM sand_ai_audio_transcript_seal WHERE id=NEW.source_seal_id FOR SHARE;
          IF NOT FOUND OR s.content_purged_time IS NOT NULL OR s.environment_id <> t.environment_id
           OR s.audio_task_id::text IS DISTINCT FROM t.input #>> '{_meeting_summary,audio_task_id}'
           OR s.file_id::text IS DISTINCT FROM t.input #>> '{_meeting_summary,file_id}'
           OR s.transcript_sha256 IS DISTINCT FROM t.input #>> '{_meeting_summary,sha256}'
           OR s.review_revision::text IS DISTINCT FROM t.input #>> '{_meeting_summary,review_revision}'
           OR s.id::text IS DISTINCT FROM t.input #>> '{_meeting_summary,transcript_seal,id}' THEN
              RAISE EXCEPTION 'SAND_AI_SCOPE_SNAPSHOT_INVALID';
          END IF;
        ELSE
            binding:=t.input #> '{_meeting_summary,aggregation_source}';
            IF NEW.source_seal_id IS NOT NULL OR jsonb_typeof(binding) IS DISTINCT FROM 'object'
                OR binding->>'version' IS DISTINCT FROM 'meeting_aggregation_source@1'
                OR binding->>'sha_algorithm' IS DISTINCT FROM 'sha256/aggregation-review-anchor@1'
                OR NEW.anchor_snapshot #> '{plan,prepared_plan_ref}' IS NULL THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_AGGREGATION_BINDING_INVALID';
            END IF;
            SELECT id,environment_id,version,xmin::text AS row_xmin,state,status,delete_time,content_purged_time,
                result->'_audio_chunk_aggregation' AS marker INTO source_audio
                FROM sand_ai_task_run WHERE id=(binding->>'audio_task_id')::bigint FOR SHARE;
            IF NOT FOUND OR source_audio.environment_id<>t.environment_id OR source_audio.state<>'succeeded'
                OR source_audio.status<>1 OR source_audio.delete_time IS NOT NULL OR source_audio.content_purged_time IS NOT NULL
                OR source_audio.version::text IS DISTINCT FROM binding->>'task_version'
                OR source_audio.row_xmin IS DISTINCT FROM binding->>'task_xmin'
                OR binding->>'audio_task_id' IS DISTINCT FROM t.input #>> '{_meeting_summary,audio_task_id}'
                OR binding->>'review_revision' IS DISTINCT FROM t.input #>> '{_meeting_summary,review_revision}'
                OR binding->>'total_segments' IS DISTINCT FROM t.input #>> '{_meeting_summary,total_segments}'
                OR source_audio.marker->>'manifest_sha256' IS DISTINCT FROM binding->>'manifest_sha256'
                OR source_audio.marker->>'transcript_sha256' IS DISTINCT FROM binding->>'completion_sha256'
                OR source_audio.marker->>'chunk_count' IS DISTINCT FROM binding->>'chunk_count' THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_AGGREGATION_BINDING_INVALID';
            END IF;
            SELECT id,task_run_id,environment_id,manifest_sha256,chunk_count INTO source_manifest
                FROM sand_ai_audio_chunk_manifest WHERE id=(binding->>'manifest_id')::bigint FOR SHARE;
            IF NOT FOUND OR source_manifest.task_run_id<>source_audio.id OR source_manifest.environment_id<>t.environment_id
                OR source_manifest.manifest_sha256 IS DISTINCT FROM binding->>'manifest_sha256'
                OR source_manifest.chunk_count::text IS DISTINCT FROM binding->>'chunk_count' THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_AGGREGATION_BINDING_INVALID';
            END IF;
            IF (binding->>'review_revision')::integer>0 THEN
                SELECT id,xmin::text AS row_xmin,base_sha256,owner_scope,content_purged_time INTO review_row
                    FROM sand_ai_audio_segment_review WHERE audio_task_id=source_audio.id
                        AND revision=(binding->>'review_revision')::integer FOR SHARE;
                IF NOT FOUND OR review_row.id::text IS DISTINCT FROM binding->>'review_last_id'
                    OR review_row.row_xmin IS DISTINCT FROM binding->>'review_last_xmin'
                    OR review_row.base_sha256 IS DISTINCT FROM binding->>'review_base_sha256'
                    OR review_row.owner_scope IS DISTINCT FROM binding->>'owner_scope'
                    OR review_row.content_purged_time IS NOT NULL THEN
                    RAISE EXCEPTION 'SAND_AI_MEETING_AGGREGATION_BINDING_INVALID';
                END IF;
            END IF;
        END IF;
        NEW.anchor_sha256 := encode(sha256(convert_to(NEW.anchor_text,'UTF8')),'hex');
        NEW.anchor_text := NULL;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    ELSE
        IF ROW(NEW.id,NEW.task_id,NEW.source_kind,NEW.source_seal_id,NEW.anchor_snapshot,
            NEW.anchor_sha256,NEW.anchor_text,NEW.create_time) IS DISTINCT FROM
           ROW(OLD.id,OLD.task_id,OLD.source_kind,OLD.source_seal_id,OLD.anchor_snapshot,
            OLD.anchor_sha256,OLD.anchor_text,OLD.create_time) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_IMMUTABLE';
        END IF;
        SELECT * INTO t FROM sand_ai_task_run WHERE id=NEW.task_id FOR UPDATE;
        IF t.content_purged_time IS NOT NULL AND NEW.state='purged'
           AND NEW.event_sequence=OLD.event_sequence
           AND NEW.last_event_sha256 IS NOT DISTINCT FROM OLD.last_event_sha256 THEN RETURN NEW; END IF;
        IF pg_trigger_depth() < 2 OR NEW.state <> OLD.state OR OLD.state <> 'staging'
           OR NEW.event_sequence <> OLD.event_sequence+1
           OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_event e
               WHERE e.task_id=NEW.task_id AND e.sequence_no=NEW.event_sequence
               AND e.event_sha256=NEW.last_event_sha256
               AND e.previous_sha256 IS NOT DISTINCT FROM OLD.last_event_sha256) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_CHAIN_INVALID';
        END IF;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    END IF;
    RETURN NEW;
END $$;

-- Registered isolated candidate; requires 109 and 111. No SQL execution evidence.
CREATE TABLE sand_ai_meeting_leaf_part_page (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES sand_ai_meeting_worker_checkpoint(task_id) ON DELETE RESTRICT,
    leaf_sequence_no integer NOT NULL CHECK (leaf_sequence_no BETWEEN 1 AND 100000),
    part_page_no integer NOT NULL CHECK (part_page_no BETWEEN 1 AND 16),
    page_id bigint NOT NULL,
    page_sequence_no integer NOT NULL CHECK (page_sequence_no BETWEEN 1 AND 200000),
    part_start integer NOT NULL CHECK (part_start BETWEEN 0 AND 511),
    part_count integer NOT NULL CHECK (part_count BETWEEN 1 AND 32),
    anchor_sha256 char(64) NOT NULL CHECK (anchor_sha256 ~ '^[a-f0-9]{64}$'),
    UNIQUE(task_id,leaf_sequence_no,part_page_no), UNIQUE(page_id),
    FOREIGN KEY(page_id,task_id) REFERENCES sand_ai_meeting_worker_page(id,task_id) ON DELETE RESTRICT
);
CREATE FUNCTION sand_ai_meeting_leaf_part_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_worker_checkpoint%ROWTYPE; p sand_ai_meeting_worker_page%ROWTYPE;
    leaf jsonb; entry jsonb; part jsonb; proof jsonb; i integer; source_index integer; part_total integer;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_IMMUTABLE'; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id AND state='staging';
    IF NOT FOUND OR NEW.anchor_sha256 IS DISTINCT FROM c.anchor_sha256
        OR NEW.part_start<>(NEW.part_page_no-1)*32 THEN RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID'; END IF;
    SELECT private_payload->0 INTO leaf FROM sand_ai_meeting_plan_page
        WHERE task_id=NEW.task_id AND page_kind='leaf' AND sequence_no=NEW.leaf_sequence_no;
    IF leaf IS NULL OR jsonb_typeof(leaf->'parts') IS DISTINCT FROM 'array'
        OR leaf->>'node_code' IS DISTINCT FROM format('chunk_%s',lpad(NEW.leaf_sequence_no::text,greatest(5,length(NEW.leaf_sequence_no::text)),'0'))
        OR leaf->>'sequence_no' IS DISTINCT FROM NEW.leaf_sequence_no::text THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID';
    END IF;
    part_total := jsonb_array_length(leaf->'parts');
    IF part_total NOT BETWEEN 1 AND 512 OR NEW.part_start>=part_total
        OR NEW.part_count<>least(32,part_total-NEW.part_start)
        OR (NEW.part_page_no>1 AND NOT EXISTS(SELECT 1 FROM sand_ai_meeting_leaf_part_page
            WHERE task_id=NEW.task_id AND leaf_sequence_no=NEW.leaf_sequence_no AND part_page_no=NEW.part_page_no-1)) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID';
    END IF;
    SELECT * INTO p FROM sand_ai_meeting_worker_page WHERE id=NEW.page_id AND task_id=NEW.task_id;
    IF NOT FOUND OR p.page_kind<>'leaf' OR p.round_no<>0 OR p.sequence_no<>NEW.page_sequence_no
        OR p.private_payload IS NULL OR p.entry_count<>NEW.part_count THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID';
    END IF;
    FOR i IN 0..NEW.part_count-1 LOOP
        entry := p.private_payload->i;
        part := leaf->'parts'->(NEW.part_start+i);
        IF entry->>'leaf_sequence_no' IS DISTINCT FROM NEW.leaf_sequence_no::text
            OR entry->>'part_index' IS DISTINCT FROM (NEW.part_start+i)::text
            OR entry->'part' IS DISTINCT FROM part OR jsonb_typeof(entry->'line') IS DISTINCT FROM 'string'
            OR jsonb_typeof(part->'segment_index') IS DISTINCT FROM 'number'
            OR (part->>'segment_index') !~ '^[0-9]+$' THEN RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID'; END IF;
        source_index := (part->>'segment_index')::integer;
        IF source_index NOT BETWEEN 0 AND 9999 THEN RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID'; END IF;
        SELECT private_payload->(source_index%32)->'proof' INTO proof FROM sand_ai_meeting_plan_page
            WHERE task_id=NEW.task_id AND page_kind='source' AND sequence_no=source_index/32+1;
        IF proof IS NULL OR entry->'source_proof' IS DISTINCT FROM proof
            OR NOT EXISTS(SELECT 1 FROM sand_ai_meeting_worker_page sp WHERE sp.task_id=NEW.task_id
                AND sp.page_kind='source' AND sp.round_no=0 AND sp.sequence_no=source_index/32+1
                AND sp.private_payload->(source_index%32) IS NOT DISTINCT FROM jsonb_build_object('segment_index',source_index,'proof',proof)) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID';
        END IF;
    END LOOP;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_leaf_part_guard BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_meeting_leaf_part_page
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_leaf_part_guard();
CREATE FUNCTION sand_ai_meeting_leaf_part_commit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.page_kind='leaf' THEN
        IF NOT EXISTS(SELECT 1 FROM sand_ai_meeting_leaf_part_page WHERE page_id=NEW.id AND task_id=NEW.task_id) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_MAPPING_MISSING';
        END IF;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    END IF;
    RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER sand_ai_meeting_leaf_part_commit AFTER INSERT ON sand_ai_meeting_worker_page
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_leaf_part_commit();

-- New immutable nodes also bind exact message bytes to the official planned
-- leaf receipt. Old 088 nodes retain NULL and are never rewritten/backfilled.
ALTER TABLE sand_ai_meeting_summary_node ADD COLUMN worker_messages_sha256 char(64) NULL
    CHECK(worker_messages_sha256 ~ '^[a-f0-9]{64}$'),
    ADD COLUMN worker_messages_text text NULL CHECK(worker_messages_text IS NULL);
CREATE FUNCTION sand_ai_meeting_node_message_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_worker_checkpoint%ROWTYPE; leaf jsonb; expected_ids jsonb;
BEGIN
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF TG_OP='UPDATE' THEN
        IF NEW.worker_messages_sha256 IS DISTINCT FROM OLD.worker_messages_sha256 OR NEW.worker_messages_text IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_MESSAGES_IMMUTABLE';
        END IF;
        RETURN NEW;
    END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    IF NEW.worker_messages_text IS NULL OR octet_length(NEW.worker_messages_text)>262144
        OR NEW.worker_messages_text::jsonb IS DISTINCT FROM NEW.input_snapshot->'messages' THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_NODE_MESSAGES_INVALID';
    END IF;
    NEW.worker_messages_sha256 := encode(sha256(convert_to(NEW.worker_messages_text,'UTF8')),'hex');
    NEW.worker_messages_text := NULL;
    IF NEW.node_kind='chunk' THEN
        SELECT private_payload->0 INTO leaf FROM sand_ai_meeting_plan_page
            WHERE task_id=NEW.task_id AND page_kind='leaf' AND sequence_no=NEW.sequence_no;
        IF leaf IS NULL OR leaf->>'node_code' IS DISTINCT FROM NEW.node_code
            OR leaf->>'sequence_no' IS DISTINCT FROM NEW.sequence_no::text
            OR jsonb_typeof(leaf->'parts') IS DISTINCT FROM 'array'
            OR jsonb_array_length(leaf->'parts') NOT BETWEEN 1 AND 512
            OR leaf->>'messages_sha256' IS DISTINCT FROM NEW.worker_messages_sha256
            OR leaf #>> '{token_count_receipt,input_sha256}' IS DISTINCT FROM NEW.worker_messages_sha256
            OR NEW.segment_start_index::text IS DISTINCT FROM leaf #>> '{parts,0,segment_index}'
            OR NEW.segment_end_index::text IS DISTINCT FROM
                leaf->'parts'->(jsonb_array_length(leaf->'parts')-1)->>'segment_index'
            OR NEW.level_no<>0 THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PLAN_INVALID'; END IF;
        SELECT jsonb_agg('S'||(segment_position+1)::text ORDER BY first_position) INTO expected_ids
            FROM(SELECT (part->>'segment_index')::integer AS segment_position,min(position) AS first_position
                FROM jsonb_array_elements(leaf->'parts') WITH ORDINALITY selected(part,position)
                GROUP BY (part->>'segment_index')::integer) sources;
        IF expected_ids IS DISTINCT FROM NEW.input_snapshot->'segment_ids' THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PLAN_INVALID';
        END IF;
    ELSIF NEW.node_kind<>'merge' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PLAN_INVALID'; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_node_message_guard BEFORE INSERT OR UPDATE ON sand_ai_meeting_summary_node
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_node_message_guard();
CREATE INDEX sand_ai_meeting_unfinished_step_idx ON sand_ai_task_step(task_id,sequence_no,id)
    WHERE sequence_no>1 AND state<>'succeeded';
CREATE INDEX sand_ai_meeting_root_idx ON sand_ai_meeting_summary_node(task_id,id) WHERE parent_node_code IS NULL;

-- A verification page is the actual next 32 immutable nodes. Every cumulative
-- count is computed here from those rows and the previous guarded page.
-- Supplying a PHP last-id/covered-count cannot skip a single node.
CREATE FUNCTION sand_ai_meeting_verification_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior jsonb; expected jsonb:='[]'::jsonb; n sand_ai_meeting_summary_node%ROWTYPE;
    total integer:=0; leaves integer:=0; roots integer:=0; last_id bigint:=0; child jsonb; child_count integer;
BEGIN
    IF NEW.page_kind<>'frontier' OR NEW.round_no<>32 THEN RETURN NEW; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    SELECT private_payload->(entry_count-1) INTO prior FROM sand_ai_meeting_worker_page
        WHERE task_id=NEW.task_id AND page_kind='frontier' AND round_no=32 ORDER BY sequence_no DESC LIMIT 1;
    IF FOUND THEN
        last_id:=(prior->>'node_id')::bigint; total:=(prior->>'total_nodes')::integer;
        leaves:=(prior->>'leaf_nodes')::integer; roots:=(prior->>'root_nodes')::integer;
    END IF;
    FOR n IN SELECT * FROM sand_ai_meeting_summary_node WHERE task_id=NEW.task_id AND id>last_id ORDER BY id LIMIT 32 LOOP
        PERFORM sand_ai_meeting_worker_assert_node_sealed(n.id);
        IF n.worker_messages_sha256 IS NULL THEN RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INVALID'; END IF;
        total:=total+1;
        IF n.node_kind='chunk' THEN leaves:=leaves+1; END IF;
        IF n.parent_node_code IS NULL THEN roots:=roots+1; END IF;
        IF n.node_kind='merge' THEN
            IF jsonb_typeof(n.input_snapshot->'children') IS DISTINCT FROM 'array'
                OR jsonb_array_length(n.input_snapshot->'children')<>2 THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INVALID';
            END IF;
            SELECT count(*) INTO child_count FROM sand_ai_meeting_summary_node cn
                WHERE cn.task_id=n.task_id AND cn.node_code IN (n.input_snapshot #>> '{children,0}',n.input_snapshot #>> '{children,1}')
                    AND cn.parent_node_code=n.node_code AND cn.level_no<n.level_no;
            IF child_count<>2 THEN RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INVALID'; END IF;
        END IF;
        expected:=expected||jsonb_build_array(jsonb_build_object(
            'node_id',n.id,'task_step_id',n.task_step_id,'node_code',n.node_code,'input_sha256',n.input_sha256,
            'messages_sha256',n.worker_messages_sha256,'result_sha256',n.result_sha256,'last_invocation_id',n.last_invocation_id,
            'total_nodes',total,'leaf_nodes',leaves,'root_nodes',roots));
    END LOOP;
    IF jsonb_array_length(expected)=0 OR NEW.private_payload IS DISTINCT FROM expected THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INVALID';
    END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_verification_guard BEFORE INSERT ON sand_ai_meeting_worker_page
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_verification_guard();

-- Freeze topology when the first verification page begins. Legal task purge
-- still clears private fields through 109 and leaves proof identities intact.
CREATE FUNCTION sand_ai_meeting_verified_graph_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS(SELECT 1 FROM sand_ai_meeting_worker_page WHERE task_id=NEW.task_id
        AND page_kind='frontier' AND round_no=32) THEN
        IF TG_OP='INSERT' OR NEW.parent_node_code IS DISTINCT FROM OLD.parent_node_code THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_VERIFIED_GRAPH_IMMUTABLE';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_verified_graph_guard BEFORE INSERT OR UPDATE ON sand_ai_meeting_summary_node
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_verified_graph_guard();

CREATE FUNCTION sand_ai_meeting_worker_completion_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_worker_checkpoint%ROWTYPE; head jsonb; n sand_ai_meeting_summary_node%ROWTYPE;
    leaf_total integer; root_total integer; expected_summary jsonb;
BEGIN
    IF NEW.state<>'succeeded' OR OLD.state='succeeded' THEN RETURN NEW; END IF;
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.id);
    leaf_total:=(c.anchor_snapshot #>> '{plan,leaf_count}')::integer;
    SELECT private_payload->(entry_count-1) INTO head FROM sand_ai_meeting_worker_page
        WHERE task_id=NEW.id AND page_kind='frontier' AND round_no=32 ORDER BY sequence_no DESC LIMIT 1;
    IF head IS NULL OR leaf_total NOT BETWEEN 1 AND 100000
        OR (head->>'total_nodes')::integer<>2*leaf_total-1 OR (head->>'leaf_nodes')::integer<>leaf_total
        OR (head->>'root_nodes')::integer<>1
        OR EXISTS(SELECT 1 FROM sand_ai_meeting_summary_node WHERE task_id=NEW.id AND id>(head->>'node_id')::bigint LIMIT 1) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INCOMPLETE';
    END IF;
    SELECT count(*) INTO root_total FROM(SELECT id FROM sand_ai_meeting_summary_node WHERE task_id=NEW.id
        AND parent_node_code IS NULL LIMIT 2) roots;
    IF root_total<>1 THEN RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INCOMPLETE'; END IF;
    SELECT * INTO n FROM sand_ai_meeting_summary_node WHERE task_id=NEW.id AND parent_node_code IS NULL LIMIT 1;
    expected_summary:=jsonb_set(n.result_snapshot->'summary','{schema,version}','"1.1.0"'::jsonb);
    expected_summary:=expected_summary||jsonb_build_object('coverage',jsonb_build_object(
        'total_segments',(c.anchor_snapshot #>> '{plan,total_segments}')::integer,
        'eligible_segments',(c.anchor_snapshot #>> '{plan,eligible_segments}')::integer,
        'covered_segments',(c.anchor_snapshot #>> '{plan,eligible_segments}')::integer,
        'uncovered_segment_ids','[]'::jsonb,'complete',true));
    IF NEW.result->'meeting_summary' IS DISTINCT FROM expected_summary
        OR NEW.result #>> '{output,content}' IS DISTINCT FROM n.result_snapshot->>'content'
        OR NEW.result #>> '{invocation,request_id}' IS DISTINCT FROM n.result_snapshot->>'request_id'
        OR NEW.result->>'audio_review_revision' IS DISTINCT FROM c.anchor_snapshot #>> '{source,review_revision}' THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_RESULT_CHANGED';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_completion_guard BEFORE UPDATE OF state,result ON sand_ai_task_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_completion_guard();

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610030112_meeting_leaf_materialization', '7e6a6bfb52ab88863939a947a143903a04b187dea8b79e6921b787593fa1b202', CURRENT_TIMESTAMP(0));

-- Migration 202610030114_meeting_plan_source_partition (sha256 182badfc7ac8bef073ccde33759d2849c37dd79822a9d65a22d9c982fad6063f)
-- Preserve migration 111 and its checksum; correct JSONB operator grouping.
CREATE OR REPLACE FUNCTION sand_ai_meeting_plan_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE; p sand_ai_meeting_plan_page%ROWTYPE; t sand_ai_task_run%ROWTYPE; s jsonb; item jsonb; pos integer; expected_parts jsonb; actual_parts jsonb; left_parts jsonb; right_parts jsonb; first_parts jsonb; middle integer; codepoints integer; offset_no integer;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_IMMUTABLE'; END IF;
    t:=sand_ai_meeting_worker_task(NEW.task_id);
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=NEW.task_id FOR UPDATE;
    IF NOT FOUND OR c.phase<>'preparing' OR NEW.event_text IS NULL OR octet_length(NEW.event_text)>1048576 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_EVENT_INVALID'; END IF;
    NEW.event_payload:=NEW.event_text::jsonb;s:=NEW.event_payload->'state';
    IF NOT NEW.event_payload ?& ARRAY['task_id','sequence_no','previous_sha256','anchor_sha256','previous_state_sha256','state','state_text','state_sha256','phase','page_id','payload_sha256','manifest_sha256','lease_fence']
        OR NOT s ?& ARRAY['source_cursor','eligible_segments','leaf_count','source_page_count','batch','batch_chars','pending','tokenizer_call_count','source_chain_sha256','leaf_chain_sha256']
        OR NEW.sequence_no<>c.event_sequence+1 OR NEW.previous_sha256 IS DISTINCT FROM c.last_event_sha256 OR NEW.anchor_sha256<>c.anchor_sha256
        OR NEW.lease_fence<>t.lease_fence OR NEW.event_payload->>'previous_state_sha256'<>c.state_sha256
        OR NEW.event_payload->>'anchor_sha256'<>c.anchor_sha256 OR (NEW.event_payload->>'sequence_no')::bigint<>NEW.sequence_no
        OR (NEW.event_payload->>'task_id')::bigint<>NEW.task_id OR (NEW.event_payload->>'lease_fence')::bigint<>NEW.lease_fence
        OR NEW.event_payload->>'previous_sha256' IS DISTINCT FROM c.last_event_sha256
        OR (NEW.event_payload->>'page_id')::bigint IS DISTINCT FROM NEW.page_id
        OR octet_length(NEW.event_payload->>'state_text')>262144
        OR (NEW.event_payload->>'state_text')::jsonb IS DISTINCT FROM s
        OR NEW.event_payload->>'state_sha256' IS DISTINCT FROM encode(sha256(convert_to(NEW.event_payload->>'state_text','UTF8')),'hex')
        OR jsonb_typeof(s)<>'object' OR jsonb_typeof(s->'pending')<>'array' OR jsonb_typeof(s->'batch')<>'array'
        OR jsonb_array_length(s->'pending')>128 OR jsonb_array_length(s->'batch')>512 OR octet_length(s::text)>524288
        OR (s->>'source_cursor')::integer<(c.state_json->>'source_cursor')::integer OR (s->>'source_cursor')::integer>(c.anchor->>'total_segments')::integer
        OR (s->>'leaf_count')::integer<(c.state_json->>'leaf_count')::integer OR (s->>'leaf_count')::integer>100000
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_EVENT_INVALID'; END IF;
    IF NEW.page_id IS NOT NULL THEN
        SELECT * INTO p FROM sand_ai_meeting_plan_page WHERE id=NEW.page_id AND task_id=NEW.task_id;
        IF NOT FOUND OR p.private_payload IS NULL OR NEW.event_payload->>'payload_sha256'<>p.payload_sha256
            OR NEW.event_payload->>'phase'<>'preparing' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_PAGE_EVENT_INVALID'; END IF;
        IF p.page_kind='source' THEN
            IF p.entry_count<>least(32,(c.anchor->>'total_segments')::integer-(c.state_json->>'source_cursor')::integer)
                OR (s->>'source_cursor')::integer<>(c.state_json->>'source_cursor')::integer+p.entry_count
                OR (s->>'source_page_count')::integer<>p.sequence_no
                OR (s->>'leaf_count')::integer<>(c.state_json->>'leaf_count')::integer
                OR s->'tokenizer_call_count' IS DISTINCT FROM c.state_json->'tokenizer_call_count'
                OR s->>'leaf_chain_sha256'<>c.state_json->>'leaf_chain_sha256'
                OR s->>'source_chain_sha256'<>encode(sha256(convert_to((c.state_json->>'source_chain_sha256')||':'||p.payload_sha256,'UTF8')),'hex')
                OR (s->>'eligible_segments')::integer<>(c.state_json->>'eligible_segments')::integer+(SELECT count(*) FROM jsonb_array_elements(p.private_payload) entry WHERE entry#>>'{proof,exclusion}' IS NULL) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_COVERAGE_INVALID'; END IF;
            IF c.state_json->'pending'<>'[]'::jsonb THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_WHILE_PENDING'; END IF;
            expected_parts:=c.state_json->'batch';
            pos:=(c.state_json->>'source_cursor')::integer;
            FOR item IN SELECT value FROM jsonb_array_elements(p.private_payload) LOOP
                IF (item->>'segment_index')::integer<>pos OR item#>>'{proof,segment_id}'<>'S'||(pos+1)::text
                    OR item#>>'{proof,text_sha256}'<>encode(sha256(convert_to(item#>>'{private_segment,text}','UTF8')),'hex')
                    OR (item#>>'{proof,unicode_characters}')::integer<>char_length(item#>>'{private_segment,text}')
                    OR (item#>>'{proof,unicode_characters}')::integer>10000
                    OR ((item#>>'{proof,exclusion}' IS NULL)=(btrim(item#>>'{private_segment,text}',E' \t\n\r\013')=''))
                    OR (item#>>'{proof,exclusion}' IS NOT NULL AND item#>>'{proof,exclusion}'<>'no_usable_text') THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_COVERAGE_INVALID'; END IF;
                IF item#>>'{proof,exclusion}' IS NULL THEN
                    codepoints:=(item#>>'{proof,unicode_characters}')::integer;offset_no:=0;
                    WHILE offset_no<codepoints LOOP
                        expected_parts:=expected_parts||jsonb_build_array(jsonb_build_object('segment_index',pos,'start_offset',offset_no,'end_offset',least(codepoints,offset_no+8000)));
                        offset_no:=offset_no+8000;
                    END LOOP;
                END IF;
                pos:=pos+1;
            END LOOP;
            actual_parts:='[]'::jsonb;
            FOR item IN SELECT value FROM jsonb_array_elements(s->'pending') LOOP actual_parts:=actual_parts||item; END LOOP;
            actual_parts:=actual_parts||(s->'batch');
            IF actual_parts IS DISTINCT FROM expected_parts THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_PARTITION_INVALID'; END IF;
        ELSE
            IF (s->>'source_cursor')::integer<>(c.state_json->>'source_cursor')::integer
                OR (s->>'source_page_count')::integer<>(c.state_json->>'source_page_count')::integer
                OR (s->>'leaf_count')::integer<>p.sequence_no
                OR s->>'source_chain_sha256'<>c.state_json->>'source_chain_sha256'
                OR (s->>'eligible_segments')::integer<>(c.state_json->>'eligible_segments')::integer
                OR s->'tokenizer_call_count' IS DISTINCT FROM c.state_json->'tokenizer_call_count'
                OR s->>'leaf_chain_sha256'<>encode(sha256(convert_to((c.state_json->>'leaf_chain_sha256')||':'||p.payload_sha256,'UTF8')),'hex')
                OR p.private_payload->0->'parts' IS DISTINCT FROM c.state_json#>'{pending,0}'
                OR p.private_payload#>>'{0,node_code}'<> 'chunk_'||lpad(p.sequence_no::text,greatest(5,length(p.sequence_no::text)),'0')
                OR (p.private_payload#>>'{0,sequence_no}')::integer<>p.sequence_no
                OR (p.private_payload#>>'{0,token_count_receipt,prompt_tokens}')::integer>(c.anchor#>>'{capacity,input_token_limit}')::integer
                THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_INVALID'; END IF;
            IF s->'pending' IS DISTINCT FROM ((c.state_json->'pending')-0) OR s->'batch' IS DISTINCT FROM c.state_json->'batch'
                OR s->'batch_chars' IS DISTINCT FROM c.state_json->'batch_chars' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_PARTITION_INVALID'; END IF;
            IF NOT EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request r JOIN sand_ai_meeting_token_count_outcome o ON o.request_event_id=r.id
                WHERE r.task_id=NEW.task_id AND r.input_sha256=p.private_payload#>>'{0,messages_sha256}'
                    AND r.capacity_sha256=c.anchor#>>'{capacity,snapshot_sha256}' AND o.state='succeeded'
                    AND o.capacity_receipt=p.private_payload#>'{0,token_count_receipt}') THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TOKEN_RECEIPT_INVALID'; END IF;
        END IF;
    ELSE
        IF NEW.event_payload->>'phase'='complete' THEN
            IF (s->>'source_cursor')::integer<>(c.anchor->>'total_segments')::integer OR s->'pending'<>'[]'::jsonb OR s->'batch'<>'[]'::jsonb
                OR (s->>'eligible_segments')::integer<1 OR (s->>'leaf_count')::integer<1
                OR NEW.event_payload->>'manifest_sha256' IS NULL
                OR s IS DISTINCT FROM jsonb_set(c.state_json,'{tokenizer_call_count}',to_jsonb((SELECT request_count FROM sand_ai_meeting_plan_tokenizer_counter WHERE task_id=NEW.task_id))) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COMPLETION_INVALID'; END IF;
        ELSE
            first_parts:=c.state_json#>'{pending,0}';
            IF NEW.event_payload->>'phase'<>'preparing' OR first_parts IS NULL OR jsonb_array_length(first_parts)<1 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SPLIT_INVALID'; END IF;
            IF jsonb_array_length(first_parts)>1 THEN
                middle:=jsonb_array_length(first_parts)/2;
                SELECT jsonb_agg(value ORDER BY ordinality) INTO left_parts FROM jsonb_array_elements(first_parts) WITH ORDINALITY WHERE ordinality<=middle;
                SELECT jsonb_agg(value ORDER BY ordinality) INTO right_parts FROM jsonb_array_elements(first_parts) WITH ORDINALITY WHERE ordinality>middle;
            ELSE
                item:=first_parts->0;codepoints:=(item->>'end_offset')::integer-(item->>'start_offset')::integer;
                IF codepoints<2 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_UNSPLITTABLE'; END IF;
                middle:=(item->>'start_offset')::integer+codepoints/2;
                left_parts:=jsonb_build_array(jsonb_set(item,'{end_offset}',to_jsonb(middle)));
                right_parts:=jsonb_build_array(jsonb_set(item,'{start_offset}',to_jsonb(middle)));
            END IF;
            IF s IS DISTINCT FROM jsonb_set(c.state_json,'{pending}',jsonb_build_array(left_parts,right_parts)||((c.state_json->'pending')-0)) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SPLIT_PARTITION_INVALID'; END IF;
        END IF;
    END IF;
    NEW.event_sha256:=encode(sha256(convert_to(NEW.event_text,'UTF8')),'hex');NEW.event_text:=NULL;RETURN NEW;
END $$;

INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('202610030114_meeting_plan_source_partition', '182badfc7ac8bef073ccde33759d2849c37dd79822a9d65a22d9c982fad6063f', CURRENT_TIMESTAMP(0));

-- Migration 202610030115_configuration_trial_navigation (sha256 f710a667dd565ce96068d8d670f4acd1da81610e19b91d4b17e635ee0d7f9a39)
-- Configuration navigation never grants workload service actions.
-- SandAdmin UserMenuCache grants all navigation to its existing superadmin;
-- task trial has no role links and still requires a real IAM workload identity.
DO $configuration_trial_navigation$
DECLARE
    root_id bigint;
    page_id bigint;
    node_id bigint;
    count_nodes integer;
    page record;
    granted_role record;
    navigation_id bigint;
    link_id bigint;
BEGIN
    IF to_regclass('sand_ai_configuration_navigation_ownership') IS NULL THEN
        RAISE EXCEPTION 'SandAI configuration navigation ownership is missing';
    END IF;
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,root_id FROM sand_system_menu WHERE code='SandAI';
    IF count_nodes<>1 OR NOT EXISTS(
        SELECT 1 FROM sand_system_menu WHERE id=root_id AND parent_id=0 AND type=1
          AND path='/sand-ai' AND COALESCE(component,'')='' AND COALESCE(slug,'')=''
          AND status=1 AND delete_time IS NULL
    ) THEN RAISE EXCEPTION 'SandAI root navigation is missing or incompatible'; END IF;

    FOR page IN SELECT * FROM (VALUES
        ('任务与会议试用','SandAITaskTrial','task','/plugin/sand-ai/task/index',50,NULL::text,''),
        ('智能体配置','SandAIAgentConfiguration','agent','/plugin/sand-ai/agent/index',40,'sand_ai:agent:definition:read','查看智能体配置'),
        ('知识库配置','SandAIKnowledgeConfiguration','knowledge','/plugin/sand-ai/knowledge/index',30,'sand_ai:knowledge:index','查看知识库配置')
    ) AS pages(name,code,path,component,sort,read_permission,permission_name) LOOP
        SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu WHERE code=page.code;
        IF count_nodes>1 OR EXISTS(
            SELECT 1 FROM sand_system_menu WHERE parent_id=root_id AND path=page.path AND code IS DISTINCT FROM page.code
        ) THEN RAISE EXCEPTION 'SandAI navigation conflicts: %',page.code; END IF;
        IF count_nodes=0 THEN
            INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
            VALUES(root_id,page.name,page.code,'',2,page.path,page.component,'',page.sort,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            RETURNING id INTO page_id;
            INSERT INTO sand_ai_configuration_navigation_ownership
            SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
            FROM sand_system_menu menu WHERE id=page_id;
        ELSIF NOT EXISTS(
            SELECT 1 FROM sand_system_menu WHERE id=page_id AND parent_id=root_id AND type=2
              AND name=page.name AND path=page.path AND component=page.component
              AND COALESCE(slug,'')='' AND is_hidden=0 AND status=1 AND delete_time IS NULL
        ) THEN RAISE EXCEPTION 'Existing SandAI navigation is incompatible: %',page.code; END IF;

        IF page.read_permission IS NULL THEN
            -- No administrator-to-workload conversion and no role eligibility guess.
            IF EXISTS(SELECT 1 FROM sand_system_role_menu WHERE menu_id=page_id) THEN
                RAISE EXCEPTION 'SandAI task trial navigation has unexpected role links';
            END IF;
            CONTINUE;
        END IF;

        -- Declare only the exact read permission already present on the public
        -- backend controller; never grant it to a role during navigation setup.
        SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu
        WHERE code=page.read_permission OR slug=page.read_permission;
        IF count_nodes>1 THEN RAISE EXCEPTION 'SandAI read permission is duplicated: %',page.read_permission; END IF;
        IF count_nodes=0 THEN
            INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
            VALUES(page_id,page.permission_name,page.read_permission,page.read_permission,3,'','','',1,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            RETURNING id INTO node_id;
            INSERT INTO sand_ai_configuration_navigation_ownership
            SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
            FROM sand_system_menu menu WHERE id=node_id;
        ELSIF NOT EXISTS(
            SELECT 1 FROM sand_system_menu WHERE id=node_id AND code=page.read_permission
              AND slug=page.read_permission AND type=3 AND status=1 AND delete_time IS NULL
        ) THEN RAISE EXCEPTION 'Existing SandAI read permission is incompatible: %',page.read_permission; END IF;

        FOR granted_role IN
            SELECT DISTINCT role_menu.role_id FROM sand_system_role_menu role_menu
            JOIN sand_system_menu permission ON permission.id=role_menu.menu_id
            JOIN sand_system_role role ON role.id=role_menu.role_id
            WHERE permission.id=node_id AND permission.type=3 AND permission.status=1
              AND permission.delete_time IS NULL AND role.status=1 AND role.delete_time IS NULL
              AND permission.slug=page.read_permission
        LOOP
            FOREACH navigation_id IN ARRAY ARRAY[root_id,page_id] LOOP
                IF NOT EXISTS(SELECT 1 FROM sand_system_role_menu WHERE role_id=granted_role.role_id AND menu_id=navigation_id) THEN
                    INSERT INTO sand_system_role_menu(role_id,menu_id) VALUES(granted_role.role_id,navigation_id) RETURNING id INTO link_id;
                    INSERT INTO sand_ai_configuration_navigation_ownership
                    SELECT 'role_menu',id,jsonb_build_object('role_id',role_id,'menu_id',menu_id)
                    FROM sand_system_role_menu WHERE id=link_id;
                END IF;
            END LOOP;
        END LOOP;
    END LOOP;
END
$configuration_trial_navigation$;

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610030115_configuration_trial_navigation','f710a667dd565ce96068d8d670f4acd1da81610e19b91d4b17e635ee0d7f9a39',CURRENT_TIMESTAMP(0));

-- Migration 202610030120_meeting_terminal_transition (sha256 760ce10f04018ef58b9fc86bc239600338c8f497ecb3443bd35d9a59f58f2cf9)
-- New registered migration. Original 109 ledger and checksums remain unchanged.
-- A proof is captured BEFORE the parent clears its exact live lease, and is
-- usable only by failed/canceled step projection in that same transaction.
CREATE TABLE sand_ai_meeting_terminal_transition (
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    transaction_id bigint NOT NULL,
    original_state varchar(32) NOT NULL,
    transition_mode varchar(32) NOT NULL CHECK (transition_mode IN ('live','expired_reclaim','inactive_cancel')),
    terminal_state varchar(32) NOT NULL CHECK (terminal_state IN ('failed','canceled')),
    original_fence bigint NOT NULL,
    terminal_fence bigint NOT NULL,
    worker_owner text NULL,
    token_sha256 char(64) NULL,
    lease_expire_time timestamp without time zone NULL,
    running_timezone text NOT NULL,
    PRIMARY KEY(task_id,transaction_id)
);
CREATE FUNCTION sand_ai_meeting_terminal_proof_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t sand_ai_task_run%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' OR pg_trigger_depth() <> 2 THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROOF_IMMUTABLE';
    END IF;
    SELECT * INTO t FROM sand_ai_task_run WHERE id=NEW.task_id FOR UPDATE;
    IF NOT FOUND OR NEW.transaction_id <> txid_current()
       OR NEW.original_state IS DISTINCT FROM t.state
       OR NEW.original_fence <> t.lease_fence
       OR NEW.worker_owner IS DISTINCT FROM t.lease_owner
       OR NEW.token_sha256 IS DISTINCT FROM (CASE WHEN t.lease_token IS NULL THEN NULL ELSE encode(sha256(convert_to(t.lease_token,'UTF8')),'hex') END)
       OR NEW.lease_expire_time IS DISTINCT FROM t.lease_expire_time THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROOF_INVALID';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_terminal_proof_guard BEFORE INSERT OR UPDATE OR DELETE
    ON sand_ai_meeting_terminal_transition FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_terminal_proof_guard();
CREATE FUNCTION sand_ai_meeting_terminal_capture() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE zone text; mode text;
BEGIN
    IF OLD.state=NEW.state OR NEW.state NOT IN ('failed','canceled')
       OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=OLD.id) THEN RETURN NEW; END IF;
    zone:=current_setting('sand_ai.running_timezone',true);
    IF zone IS NULL OR NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name=zone)
       OR OLD.status<>1 OR OLD.delete_time IS NOT NULL OR OLD.content_purged_time IS NOT NULL
       OR NEW.lease_owner IS NOT NULL OR NEW.lease_token IS NOT NULL OR NEW.lease_expire_time IS NOT NULL
       OR NEW.completed_at IS NULL
       OR ROW(NEW.input,NEW.environment_id,NEW.organization_id,NEW.application_id,NEW.workload_client_id,NEW.task_type)
            IS DISTINCT FROM ROW(OLD.input,OLD.environment_id,OLD.organization_id,OLD.application_id,OLD.workload_client_id,OLD.task_type) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROOF_INVALID';
    END IF;
    mode:='inactive_cancel';
    IF OLD.state='running' THEN
        mode:='live';
        IF NEW.state='failed' AND current_setting('sand_ai.meeting_terminal_mode',true)='expired_reclaim' THEN
            mode:='expired_reclaim';
            IF OLD.lease_expire_time IS NULL OR OLD.lease_expire_time > (clock_timestamp() AT TIME ZONE zone)
               OR OLD.lease_owner IS DISTINCT FROM current_setting('sand_ai.worker_owner',true)
               OR OLD.lease_token IS DISTINCT FROM current_setting('sand_ai.worker_token',true)
               OR OLD.lease_fence::text IS DISTINCT FROM current_setting('sand_ai.worker_fence',true)
               OR OLD.attempt_count < OLD.max_attempts
               OR EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch WHERE parent_task_run_id=OLD.id) THEN
                RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
            END IF;
        ELSE
            PERFORM sand_ai_meeting_worker_task(OLD.id);
        END IF;
    ELSIF NEW.state<>'canceled' OR OLD.state NOT IN ('authorization_pending','queued','retrying','recovery_pending')
        OR OLD.lease_owner IS NOT NULL OR OLD.lease_token IS NOT NULL OR OLD.lease_expire_time IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
    END IF;
    IF NEW.lease_fence <> OLD.lease_fence + (CASE WHEN NEW.state='canceled' OR mode='expired_reclaim' THEN 1 ELSE 0 END) THEN
        RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
    END IF;
    INSERT INTO sand_ai_meeting_terminal_transition VALUES(OLD.id,txid_current(),OLD.state,mode,NEW.state,
        OLD.lease_fence,NEW.lease_fence,OLD.lease_owner,
        CASE WHEN OLD.lease_token IS NULL THEN NULL ELSE encode(sha256(convert_to(OLD.lease_token,'UTF8')),'hex') END,
        OLD.lease_expire_time,zone);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_terminal_capture BEFORE UPDATE ON sand_ai_task_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_terminal_capture();
CREATE FUNCTION sand_ai_meeting_terminal_step(task_key bigint, step_state text) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE p sand_ai_meeting_terminal_transition%ROWTYPE; t sand_ai_task_run%ROWTYPE;
BEGIN
    SELECT * INTO t FROM sand_ai_task_run WHERE id=task_key FOR SHARE;
    IF NOT FOUND OR t.state NOT IN ('failed','canceled') OR step_state IS DISTINCT FROM t.state THEN RETURN false; END IF;
    SELECT * INTO p FROM sand_ai_meeting_terminal_transition WHERE task_id=task_key AND transaction_id=txid_current();
    IF NOT FOUND OR p.terminal_state IS DISTINCT FROM t.state OR p.terminal_fence <> t.lease_fence
       OR t.status<>1 OR t.delete_time IS NOT NULL OR t.content_purged_time IS NOT NULL
       OR t.lease_owner IS NOT NULL OR t.lease_token IS NOT NULL OR t.lease_expire_time IS NOT NULL
       OR t.completed_at IS NULL THEN RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST'; END IF;
    IF p.original_state='running' AND (
        p.worker_owner IS DISTINCT FROM current_setting('sand_ai.worker_owner',true)
        OR p.token_sha256 IS DISTINCT FROM encode(sha256(convert_to(coalesce(current_setting('sand_ai.worker_token',true),''),'UTF8')),'hex')
        OR p.original_fence::text IS DISTINCT FROM current_setting('sand_ai.worker_fence',true)
        OR p.running_timezone IS DISTINCT FROM current_setting('sand_ai.running_timezone',true)
        OR p.lease_expire_time IS NULL
        OR (p.transition_mode='live' AND p.lease_expire_time <= (clock_timestamp() AT TIME ZONE p.running_timezone))
        OR (p.transition_mode='expired_reclaim' AND (p.lease_expire_time > (clock_timestamp() AT TIME ZONE p.running_timezone)
            OR current_setting('sand_ai.meeting_terminal_mode',true) IS DISTINCT FROM 'expired_reclaim'))) THEN
        RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
    END IF;
    RETURN true;
END $$;
CREATE OR REPLACE FUNCTION sand_ai_meeting_worker_step_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id) THEN RETURN NEW; END IF;
        -- sequence=1/invoke_model remains the existing parent lifecycle. All new
        -- node steps require the current task lease, before their node is inserted.
        IF NEW.sequence_no=1 AND NEW.step_code='invoke_model' THEN RETURN NEW; END IF;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
        RETURN NEW;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=OLD.task_id)
       AND (TG_OP='DELETE' OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id)) THEN
        RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
    END IF;
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE'; END IF;
    IF ROW(NEW.id,NEW.task_id,NEW.step_code,NEW.sequence_no,NEW.create_time)
       IS DISTINCT FROM ROW(OLD.id,OLD.task_id,OLD.step_code,OLD.sequence_no,OLD.create_time) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE';
    END IF;
    -- Exact no-op is safe even after terminal state, lease expiry or legal purge.
    IF to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD) THEN RETURN NEW; END IF;
    IF OLD.sequence_no=1 AND OLD.step_code='invoke_model' THEN RETURN NEW; END IF;
    IF OLD.state='succeeded' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE'; END IF;
    IF NEW.state IN ('failed','canceled') AND sand_ai_meeting_terminal_step(NEW.task_id,NEW.state) THEN
        IF (to_jsonb(NEW)-ARRAY['state','error_code','error_summary','completed_at','update_time'])
            IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','error_code','error_summary','completed_at','update_time'])
           OR NEW.completed_at IS NULL THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE'; END IF;
        RETURN NEW;
    END IF;
    -- No purge exemption for pending-step writes: cleanup does not alter steps.
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION sand_ai_meeting_worker_step_seal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s sand_ai_task_step%ROWTYPE; n sand_ai_meeting_summary_node%ROWTYPE;
BEGIN
    IF TG_OP='UPDATE' AND to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD) THEN RETURN NULL; END IF;
    SELECT * INTO s FROM sand_ai_task_step WHERE id=NEW.id FOR SHARE;
    IF NOT FOUND OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=s.task_id) THEN RETURN NULL; END IF;
    IF s.sequence_no=1 AND s.step_code='invoke_model' THEN RETURN NULL; END IF;
    IF s.state IN ('failed','canceled') AND sand_ai_meeting_terminal_step(s.task_id,s.state) THEN
        IF EXISTS (SELECT 1 FROM sand_ai_task_step WHERE task_id=s.task_id AND state NOT IN ('succeeded',s.state))
           OR EXISTS (SELECT 1 FROM sand_ai_meeting_summary_node n JOIN sand_ai_task_step child ON child.id=n.task_step_id
               WHERE n.task_id=s.task_id AND n.result_sha256 IS NOT NULL AND child.state<>'succeeded') THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROJECTION_INVALID';
        END IF;
        RETURN NULL;
    END IF;
    -- Lease/cleanup boundary is repeated at commit after any node locks, even
    -- when the changed step is still pending. No late status projection survives.
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    IF s.state='succeeded' THEN
        SELECT * INTO n FROM sand_ai_meeting_summary_node WHERE task_id=s.task_id AND task_step_id=s.id FOR SHARE;
        IF NOT FOUND OR n.node_code IS DISTINCT FROM s.step_code THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_UNOWNED';
        END IF;
        PERFORM sand_ai_meeting_worker_assert_node_sealed(n.id);
    END IF;
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    RETURN NULL;
END $$;

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610030120_meeting_terminal_transition','760ce10f04018ef58b9fc86bc239600338c8f497ecb3443bd35d9a59f58f2cf9',CURRENT_TIMESTAMP(0));

-- Migration 202610030121_meeting_terminal_step_alias (sha256 bd3efc3dc983919db07b6cfb8312421aac8dee397d0db5d292d0ade3f0250687)
-- Forward repair for applied 120: avoid collision between the local row variable
-- n and a relation alias. Terminal proof, exact lease and projection checks remain.
CREATE OR REPLACE FUNCTION sand_ai_meeting_worker_step_seal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s sand_ai_task_step%ROWTYPE; n sand_ai_meeting_summary_node%ROWTYPE;
BEGIN
    IF TG_OP='UPDATE' AND to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD) THEN RETURN NULL; END IF;
    SELECT * INTO s FROM sand_ai_task_step WHERE id=NEW.id FOR SHARE;
    IF NOT FOUND OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=s.task_id) THEN RETURN NULL; END IF;
    IF s.sequence_no=1 AND s.step_code='invoke_model' THEN RETURN NULL; END IF;
    IF s.state IN ('failed','canceled') AND sand_ai_meeting_terminal_step(s.task_id,s.state) THEN
        IF EXISTS (SELECT 1 FROM sand_ai_task_step WHERE task_id=s.task_id AND state NOT IN ('succeeded',s.state))
           OR EXISTS (SELECT 1 FROM sand_ai_meeting_summary_node node_row JOIN sand_ai_task_step child ON child.id=node_row.task_step_id
               WHERE node_row.task_id=s.task_id AND node_row.result_sha256 IS NOT NULL AND child.state<>'succeeded') THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROJECTION_INVALID';
        END IF;
        RETURN NULL;
    END IF;
    -- Lease/cleanup boundary is repeated at commit after any node locks, even
    -- when the changed step is still pending. No late status projection survives.
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    IF s.state='succeeded' THEN
        SELECT * INTO n FROM sand_ai_meeting_summary_node WHERE task_id=s.task_id AND task_step_id=s.id FOR SHARE;
        IF NOT FOUND OR n.node_code IS DISTINCT FROM s.step_code THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_UNOWNED';
        END IF;
        PERFORM sand_ai_meeting_worker_assert_node_sealed(n.id);
    END IF;
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    RETURN NULL;
END $$;

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610030121_meeting_terminal_step_alias','bd3efc3dc983919db07b6cfb8312421aac8dee397d0db5d292d0ade3f0250687',CURRENT_TIMESTAMP(0));

-- Migration 202610030122_agent_stream_event (sha256 72a128052af06479a558919eeee9c6fdd871b1ac993b3ec223bbbbb680cd362c)
-- Private, bounded native provider text deltas. No identity or fee ledger.
CREATE TABLE sand_ai_agent_stream_event (
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    event_no integer NOT NULL CHECK (event_no BETWEEN 1 AND 4096),
    round_no integer NOT NULL CHECK (round_no BETWEEN 1 AND 3),
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT,
    dispatch_id bigint NOT NULL REFERENCES sand_ai_invocation_dispatch(id) ON DELETE RESTRICT,
    ciphertext_envelope jsonb NOT NULL CHECK (jsonb_typeof(ciphertext_envelope)='object'),
    plaintext_bytes integer NOT NULL CHECK (plaintext_bytes BETWEEN 1 AND 262144),
    text_bytes integer NOT NULL CHECK (text_bytes BETWEEN 1 AND 8192),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_agent_stream_event_pk PRIMARY KEY (task_id,event_no)
);
CREATE INDEX sand_ai_agent_stream_event_invocation_idx ON sand_ai_agent_stream_event(invocation_id,event_no);

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610030122_agent_stream_event','72a128052af06479a558919eeee9c6fdd871b1ac993b3ec223bbbbb680cd362c',CURRENT_TIMESTAMP(0));

-- Migration 202610030123_skill_draft_revision (sha256 36a4acf2ea425af4d2357f985e8591058705fc3af1a0ccbfdc5f77d290da19f7)
-- Preserve fixed Skill evidence while allowing a new revision of an unpublished draft.
DO $$
DECLARE constraint_name text;
BEGIN
    FOR constraint_name IN
        SELECT conname FROM pg_constraint
        WHERE conrelid='sand_ai_skill_version'::regclass AND contype='c'
            AND pg_get_constraintdef(oid) LIKE '%state%'
            AND pg_get_constraintdef(oid) LIKE '%draft%'
            AND pg_get_constraintdef(oid) LIKE '%published%'
            AND pg_get_constraintdef(oid) LIKE '%revoked%'
    LOOP
        EXECUTE format('ALTER TABLE sand_ai_skill_version DROP CONSTRAINT %I', constraint_name);
    END LOOP;
END $$;
ALTER TABLE sand_ai_skill_version ADD CONSTRAINT ck_sand_ai_skill_version_state_v123
    CHECK (state IN ('draft','published','revoked','abandoned'));

CREATE OR REPLACE FUNCTION sand_ai_skill_version_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    -- Retain the existing narrow source-content redaction contract from migration 095.
    IF TG_OP='UPDATE' AND sand_ai_skill_source_redaction_allowed(OLD,NEW) THEN RETURN NEW; END IF;
    IF TG_OP='DELETE' OR OLD.state IN ('published','revoked','abandoned') THEN
        RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_IMMUTABLE';
    END IF;
    IF OLD.state<>'draft' OR NEW.state NOT IN ('draft','published','revoked','abandoned') THEN
        RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_STATE_INVALID';
    END IF;
    IF NEW.state='published' THEN
        IF to_jsonb(NEW)-ARRAY['state','published_time','published_consumer_context','update_time']
            IS DISTINCT FROM to_jsonb(OLD)-ARRAY['state','published_time','published_consumer_context','update_time']
        THEN RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_IMMUTABLE'; END IF;
    ELSE
        IF to_jsonb(NEW)-ARRAY['state','update_time']
            IS DISTINCT FROM to_jsonb(OLD)-ARRAY['state','update_time']
        THEN RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_IMMUTABLE'; END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_skill_knowledge_ref_immutable_v019() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP IN ('UPDATE','DELETE') THEN
        RAISE EXCEPTION 'Skill knowledge references are immutable; save a new draft version';
    END IF;
    IF EXISTS (SELECT 1 FROM sand_ai_skill_version WHERE id=NEW.skill_version_id AND state<>'draft') THEN
        RAISE EXCEPTION 'Only a new draft version accepts its fixed knowledge reference';
    END IF;
    RETURN NEW;
END $$;

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610030123_skill_draft_revision','36a4acf2ea425af4d2357f985e8591058705fc3af1a0ccbfdc5f77d290da19f7',CURRENT_TIMESTAMP(0));

-- Migration 202610040124_meeting_execution_registration (sha256 c02b8f998bf9d7faa6ef30aba6bc1452663e7c1b19697952a9cbe05d88742591)
-- SandAI runtime capability registration; owner references are verified through SandIAM.
CREATE TABLE sand_ai_meeting_execution_registration (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    organization_id bigint NOT NULL CHECK (organization_id > 0),
    application_id bigint NOT NULL CHECK (application_id > 0),
    environment_id bigint NOT NULL CHECK (environment_id > 0),
    model_code varchar(128) NOT NULL CHECK (length(model_code) > 0),
    revision integer NOT NULL CHECK (revision > 0),
    state varchar(16) NOT NULL CHECK (state IN ('disabled','enabled','revoked')),
    route_snapshot jsonb NOT NULL CHECK (jsonb_typeof(route_snapshot)='object'),
    route_sha256 char(64) NOT NULL CHECK (route_sha256 ~ '^[a-f0-9]{64}$'),
    capacity jsonb NOT NULL CHECK (jsonb_typeof(capacity)='object'),
    created_by bigint NOT NULL CHECK (created_by > 0),
    updated_by bigint NOT NULL CHECK (updated_by > 0),
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (0,1)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    delete_time timestamp(0) without time zone NULL,
    UNIQUE (environment_id,model_code)
);
COMMENT ON TABLE sand_ai_meeting_execution_registration IS
    'Administrator-confirmed meeting capacity; no credentials, IAM grants or remote request.';

INSERT INTO sand_ai_schema_migration (version,checksum,applied_time) VALUES ('202610040124_meeting_execution_registration','c02b8f998bf9d7faa6ef30aba6bc1452663e7c1b19697952a9cbe05d88742591',CURRENT_TIMESTAMP(0));

-- Migration 202610040125_usage_refund_adjustment (sha256 66b1bc89fe3d692152d28661f057764038a3c348da444abee39ad866f86cfec1)
CREATE TABLE sand_ai_usage_adjustment (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    usage_event_id bigint NOT NULL REFERENCES sand_ai_usage_event(id) ON DELETE RESTRICT,
    reconciliation_id bigint NOT NULL REFERENCES sand_ai_usage_reconciliation(id) ON DELETE RESTRICT,
    organization_id bigint NOT NULL CHECK (organization_id > 0),
    application_id bigint NOT NULL CHECK (application_id > 0),
    environment_id bigint NOT NULL CHECK (environment_id > 0),
    workload_client_id bigint NOT NULL CHECK (workload_client_id > 0),
    provider_id bigint NOT NULL REFERENCES sand_ai_provider(id) ON DELETE RESTRICT,
    adjustment_kind varchar(16) NOT NULL CHECK (adjustment_kind = 'refund'),
    amount_basis varchar(32) NOT NULL CHECK (amount_basis = 'reconciled_bill'),
    original_billed_amount numeric(20,6) NOT NULL CHECK (original_billed_amount > 0),
    refund_amount numeric(20,6) NOT NULL CHECK (refund_amount > 0 AND refund_amount <= original_billed_amount),
    currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    evidence_id bigint NOT NULL REFERENCES sand_ai_billing_evidence(id) ON DELETE RESTRICT,
    evidence_sha256 varchar(64) NOT NULL CHECK (evidence_sha256 ~ '^[a-f0-9]{64}$'),
    evidence_locator varchar(256) NOT NULL CHECK (length(btrim(evidence_locator)) > 0),
    provider_refund_reference varchar(128) NOT NULL CHECK (length(btrim(provider_refund_reference)) > 0),
    trust_basis varchar(32) NOT NULL CHECK (trust_basis = 'operator_reviewed'),
    provider_refund_confirmed boolean NOT NULL CHECK (provider_refund_confirmed),
    admin_id bigint NOT NULL CHECK (admin_id > 0),
    idempotency_key varchar(128) NOT NULL CHECK (length(btrim(idempotency_key)) > 0),
    fingerprint varchar(64) NOT NULL CHECK (fingerprint ~ '^[a-f0-9]{64}$'),
    note varchar(2000) NOT NULL CHECK (length(btrim(note)) > 0),
    create_time timestamp(0) without time zone NOT NULL DEFAULT (CURRENT_TIMESTAMP AT TIME ZONE 'UTC'),
    CONSTRAINT uq_sand_ai_usage_adjustment_scope_key UNIQUE (environment_id,usage_event_id,idempotency_key),
    CONSTRAINT uq_sand_ai_usage_adjustment_provider_reference UNIQUE (provider_id,provider_refund_reference)
);
CREATE INDEX idx_sand_ai_usage_adjustment_event ON sand_ai_usage_adjustment(usage_event_id,id DESC);
CREATE INDEX idx_sand_ai_usage_adjustment_environment ON sand_ai_usage_adjustment(environment_id,id DESC);
COMMENT ON TABLE sand_ai_usage_adjustment IS 'Append-only operator reviewed refund; gross original usage and billing remain immutable';
COMMENT ON COLUMN sand_ai_usage_adjustment.evidence_sha256 IS 'Frozen private attachment digest; file consistency is not provider authentication';
COMMENT ON COLUMN sand_ai_usage_adjustment.provider_refund_confirmed IS 'Operator declaration after reviewing actual refund attachment, not provider-verified status';

CREATE OR REPLACE FUNCTION sand_ai_usage_adjustment_guard_v125() RETURNS trigger AS $$
DECLARE
    original_event sand_ai_usage_event%ROWTYPE;
    bill sand_ai_usage_reconciliation%ROWTYPE;
    line sand_ai_billing_line%ROWTYPE;
    evidence sand_ai_billing_evidence%ROWTYPE;
    billing_provider bigint;
    recorded_refunds numeric;
BEGIN
    -- The next SUM must observe refunds committed while waiting for the event
    -- lock. A repeatable-read snapshot would retain an outdated total.
    IF current_setting('transaction_isolation') <> 'read committed' THEN
        RAISE EXCEPTION 'Refund cumulative guard requires READ COMMITTED isolation';
    END IF;
    -- All refunds of one original event serialize, irrespective of key/admin.
    SELECT * INTO STRICT original_event FROM sand_ai_usage_event
        WHERE id=NEW.usage_event_id FOR UPDATE;
    SELECT * INTO STRICT bill FROM sand_ai_usage_reconciliation WHERE id=NEW.reconciliation_id;
    SELECT * INTO STRICT line FROM sand_ai_billing_line WHERE id=bill.billing_line_id;
    SELECT provider_id INTO STRICT billing_provider FROM sand_ai_billing_import WHERE id=line.billing_import_id;
    SELECT * INTO STRICT evidence FROM sand_ai_billing_evidence WHERE id=NEW.evidence_id FOR UPDATE;
    IF (NEW.organization_id,NEW.application_id,NEW.environment_id,NEW.workload_client_id,NEW.provider_id)
            IS DISTINCT FROM
       (original_event.organization_id,original_event.application_id,original_event.environment_id,original_event.workload_client_id,original_event.provider_id)
        OR original_event.pricing_state <> 'priced'
        OR original_event.currency IS NULL
        OR NEW.currency IS DISTINCT FROM original_event.currency
        OR original_event.identity_snapshot->>'organization_ref' IS DISTINCT FROM NEW.organization_id::text
        OR original_event.identity_snapshot->>'application_ref' IS DISTINCT FROM NEW.application_id::text
        OR original_event.identity_snapshot->>'workload_client_ref' IS DISTINCT FROM NEW.workload_client_id::text THEN
        RAISE EXCEPTION 'Refund ownership or original currency is unverified';
    END IF;
    IF bill.state <> 'reconciled'
        OR bill.usage_event_id IS DISTINCT FROM original_event.id
        OR bill.quantity_difference IS DISTINCT FROM 0::numeric
        OR bill.amount_difference IS DISTINCT FROM 0::numeric
        OR bill.currency IS DISTINCT FROM NEW.currency
        OR line.currency IS DISTINCT FROM bill.currency
        OR line.amount IS DISTINCT FROM bill.billed_amount
        OR billing_provider IS DISTINCT FROM NEW.provider_id
        OR NEW.original_billed_amount IS DISTINCT FROM bill.billed_amount
        OR bill.billed_amount <= 0 THEN
        RAISE EXCEPTION 'Refund requires the exact original reconciled bill';
    END IF;
    IF evidence.provider_id IS DISTINCT FROM NEW.provider_id
        OR evidence.state <> 'available'
        OR evidence.sha256 IS DISTINCT FROM NEW.evidence_sha256 THEN
        RAISE EXCEPTION 'Refund requires a matching available immutable evidence digest';
    END IF;
    SELECT COALESCE(SUM(refund_amount),0) INTO recorded_refunds
        FROM sand_ai_usage_adjustment WHERE usage_event_id=original_event.id;
    IF recorded_refunds + NEW.refund_amount > bill.billed_amount THEN
        RAISE EXCEPTION 'Cumulative refunds exceed the original reconciled bill';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_usage_adjustment_guard_v125 BEFORE INSERT ON sand_ai_usage_adjustment
    FOR EACH ROW EXECUTE FUNCTION sand_ai_usage_adjustment_guard_v125();
CREATE TRIGGER trg_sand_ai_usage_adjustment_immutable_v125 BEFORE UPDATE OR DELETE ON sand_ai_usage_adjustment
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610040125_usage_refund_adjustment','66b1bc89fe3d692152d28661f057764038a3c348da444abee39ad866f86cfec1',CURRENT_TIMESTAMP(0));

-- Migration 202610040126_usage_adjustment_permissions (sha256 fc8774c619ccde0ce6b83383068fa92d89ef41bbc7688b6af6c2c73cd778f6a8)
DO $usage_adjustment_permissions$
DECLARE root_id bigint; node_id bigint; matches integer; specification record;
BEGIN
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO matches,root_id FROM sand_system_menu
        WHERE code='SandAI' AND parent_id=0 AND type=1 AND path='/sand-ai'
          AND status=1 AND delete_time IS NULL;
    IF matches<>1 THEN RAISE EXCEPTION 'Exact SandAI root menu is required'; END IF;
    FOR specification IN SELECT * FROM (VALUES
        ('SandAI refund adjustment read','sand_ai:usage:adjustment:index',1),
        ('SandAI record reviewed refund','sand_ai:usage:adjustment:refund',2)
    ) AS definitions(name,code,sort) LOOP
        SELECT count(*),min(id) INTO matches,node_id FROM sand_system_menu
            WHERE code=specification.code OR slug=specification.code;
        IF matches>0 THEN RAISE EXCEPTION 'Refund permission already exists; explicit adoption required: %',specification.code; END IF;
        INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
        VALUES(root_id,specification.name,specification.code,specification.code,3,'','','',specification.sort,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        RETURNING id INTO node_id;
        INSERT INTO sand_ai_configuration_navigation_ownership
        SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
        FROM sand_system_menu menu WHERE id=node_id;
    END LOOP;
END
$usage_adjustment_permissions$;

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610040126_usage_adjustment_permissions','fc8774c619ccde0ce6b83383068fa92d89ef41bbc7688b6af6c2c73cd778f6a8',CURRENT_TIMESTAMP(0));

-- Migration 202610040127_meeting_local_planning (sha256 ece6e307bf4975f8706624452ffd59adbfcbac744963891fb9343435ff6e3ba1)
-- Explicit local SDK planning variant; legacy remote guards and immutable migration checksums remain.
CREATE OR REPLACE FUNCTION sand_ai_meeting_plan_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE; p sand_ai_meeting_plan_page%ROWTYPE; t sand_ai_task_run%ROWTYPE; s jsonb; item jsonb; pos integer; expected_parts jsonb; actual_parts jsonb; left_parts jsonb; right_parts jsonb; first_parts jsonb; middle integer; codepoints integer; offset_no integer; local_mode boolean;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_IMMUTABLE'; END IF;
    t:=sand_ai_meeting_worker_task(NEW.task_id);
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=NEW.task_id FOR UPDATE;
    IF NOT FOUND OR c.phase<>'preparing' OR NEW.event_text IS NULL OR octet_length(NEW.event_text)>1048576 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_EVENT_INVALID'; END IF;
    NEW.event_payload:=NEW.event_text::jsonb;s:=NEW.event_payload->'state';
    local_mode:=coalesce(c.anchor#>>'{capacity,planning_driver}'='neuron_delimiter_splitter@1',false);
    IF local_mode AND (c.anchor#>'{capacity,counter}' IS DISTINCT FROM 'null'::jsonb
        OR c.anchor#>'{capacity,endpoint}' IS DISTINCT FROM 'null'::jsonb
        OR c.anchor#>'{capacity,max_tokenizer_requests}' IS DISTINCT FROM '0'::jsonb
        OR s->'tokenizer_call_count' IS DISTINCT FROM '0'::jsonb
        OR EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request WHERE task_id=NEW.task_id))
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_LOCAL_COUNTER_INVALID'; END IF;
    IF NOT NEW.event_payload ?& ARRAY['task_id','sequence_no','previous_sha256','anchor_sha256','previous_state_sha256','state','state_text','state_sha256','phase','page_id','payload_sha256','manifest_sha256','lease_fence']
        OR NOT s ?& ARRAY['source_cursor','eligible_segments','leaf_count','source_page_count','batch','batch_chars','pending','tokenizer_call_count','source_chain_sha256','leaf_chain_sha256']
        OR NEW.sequence_no<>c.event_sequence+1 OR NEW.previous_sha256 IS DISTINCT FROM c.last_event_sha256 OR NEW.anchor_sha256<>c.anchor_sha256
        OR NEW.lease_fence<>t.lease_fence OR NEW.event_payload->>'previous_state_sha256'<>c.state_sha256
        OR NEW.event_payload->>'anchor_sha256'<>c.anchor_sha256 OR (NEW.event_payload->>'sequence_no')::bigint<>NEW.sequence_no
        OR (NEW.event_payload->>'task_id')::bigint<>NEW.task_id OR (NEW.event_payload->>'lease_fence')::bigint<>NEW.lease_fence
        OR NEW.event_payload->>'previous_sha256' IS DISTINCT FROM c.last_event_sha256
        OR (NEW.event_payload->>'page_id')::bigint IS DISTINCT FROM NEW.page_id
        OR octet_length(NEW.event_payload->>'state_text')>262144
        OR (NEW.event_payload->>'state_text')::jsonb IS DISTINCT FROM s
        OR NEW.event_payload->>'state_sha256' IS DISTINCT FROM encode(sha256(convert_to(NEW.event_payload->>'state_text','UTF8')),'hex')
        OR jsonb_typeof(s)<>'object' OR jsonb_typeof(s->'pending')<>'array' OR jsonb_typeof(s->'batch')<>'array'
        OR jsonb_array_length(s->'pending')>128 OR jsonb_array_length(s->'batch')>512 OR octet_length(s::text)>524288
        OR (s->>'source_cursor')::integer<(c.state_json->>'source_cursor')::integer OR (s->>'source_cursor')::integer>(c.anchor->>'total_segments')::integer
        OR (s->>'leaf_count')::integer<(c.state_json->>'leaf_count')::integer OR (s->>'leaf_count')::integer>100000
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_EVENT_INVALID'; END IF;
    IF NEW.page_id IS NOT NULL THEN
        SELECT * INTO p FROM sand_ai_meeting_plan_page WHERE id=NEW.page_id AND task_id=NEW.task_id;
        IF NOT FOUND OR p.private_payload IS NULL OR NEW.event_payload->>'payload_sha256'<>p.payload_sha256
            OR NEW.event_payload->>'phase'<>'preparing' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_PAGE_EVENT_INVALID'; END IF;
        IF p.page_kind='source' THEN
            IF p.entry_count<>least(32,(c.anchor->>'total_segments')::integer-(c.state_json->>'source_cursor')::integer)
                OR (s->>'source_cursor')::integer<>(c.state_json->>'source_cursor')::integer+p.entry_count
                OR (s->>'source_page_count')::integer<>p.sequence_no
                OR (s->>'leaf_count')::integer<>(c.state_json->>'leaf_count')::integer
                OR s->'tokenizer_call_count' IS DISTINCT FROM c.state_json->'tokenizer_call_count'
                OR s->>'leaf_chain_sha256'<>c.state_json->>'leaf_chain_sha256'
                OR s->>'source_chain_sha256'<>encode(sha256(convert_to((c.state_json->>'source_chain_sha256')||':'||p.payload_sha256,'UTF8')),'hex')
                OR (s->>'eligible_segments')::integer<>(c.state_json->>'eligible_segments')::integer+(SELECT count(*) FROM jsonb_array_elements(p.private_payload) entry WHERE entry#>>'{proof,exclusion}' IS NULL) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_COVERAGE_INVALID'; END IF;
            IF c.state_json->'pending'<>'[]'::jsonb THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_WHILE_PENDING'; END IF;
            expected_parts:=c.state_json->'batch';
            pos:=(c.state_json->>'source_cursor')::integer;
            FOR item IN SELECT value FROM jsonb_array_elements(p.private_payload) LOOP
                IF (item->>'segment_index')::integer<>pos OR item#>>'{proof,segment_id}'<>'S'||(pos+1)::text
                    OR item#>>'{proof,text_sha256}'<>encode(sha256(convert_to(item#>>'{private_segment,text}','UTF8')),'hex')
                    OR (item#>>'{proof,unicode_characters}')::integer<>char_length(item#>>'{private_segment,text}')
                    OR (item#>>'{proof,unicode_characters}')::integer>10000
                    OR ((item#>>'{proof,exclusion}' IS NULL)=(btrim(item#>>'{private_segment,text}',E' \t\n\r\013')=''))
                    OR (item#>>'{proof,exclusion}' IS NOT NULL AND item#>>'{proof,exclusion}'<>'no_usable_text') THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_COVERAGE_INVALID'; END IF;
                IF item#>>'{proof,exclusion}' IS NULL THEN
                    codepoints:=(item#>>'{proof,unicode_characters}')::integer;offset_no:=0;
                    WHILE offset_no<codepoints LOOP
                        expected_parts:=expected_parts||jsonb_build_array(jsonb_build_object('segment_index',pos,'start_offset',offset_no,'end_offset',least(codepoints,offset_no+8000)));
                        offset_no:=offset_no+8000;
                    END LOOP;
                END IF;
                pos:=pos+1;
            END LOOP;
            actual_parts:='[]'::jsonb;
            FOR item IN SELECT value FROM jsonb_array_elements(s->'pending') LOOP actual_parts:=actual_parts||item; END LOOP;
            actual_parts:=actual_parts||(s->'batch');
            IF actual_parts IS DISTINCT FROM expected_parts THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_PARTITION_INVALID'; END IF;
        ELSE
            IF (s->>'source_cursor')::integer<>(c.state_json->>'source_cursor')::integer
                OR (s->>'source_page_count')::integer<>(c.state_json->>'source_page_count')::integer
                OR (s->>'leaf_count')::integer<>p.sequence_no
                OR s->>'source_chain_sha256'<>c.state_json->>'source_chain_sha256'
                OR (s->>'eligible_segments')::integer<>(c.state_json->>'eligible_segments')::integer
                OR s->'tokenizer_call_count' IS DISTINCT FROM c.state_json->'tokenizer_call_count'
                OR s->>'leaf_chain_sha256'<>encode(sha256(convert_to((c.state_json->>'leaf_chain_sha256')||':'||p.payload_sha256,'UTF8')),'hex')
                OR p.private_payload->0->'parts' IS DISTINCT FROM c.state_json#>'{pending,0}'
                OR p.private_payload#>>'{0,node_code}'<> 'chunk_'||lpad(p.sequence_no::text,greatest(5,length(p.sequence_no::text)),'0')
                OR (p.private_payload#>>'{0,sequence_no}')::integer<>p.sequence_no
                OR (NOT local_mode AND (p.private_payload#>>'{0,token_count_receipt,prompt_tokens}')::integer>(c.anchor#>>'{capacity,input_token_limit}')::integer)
                THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_INVALID'; END IF;
            IF s->'pending' IS DISTINCT FROM ((c.state_json->'pending')-0) OR s->'batch' IS DISTINCT FROM c.state_json->'batch'
                OR s->'batch_chars' IS DISTINCT FROM c.state_json->'batch_chars' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_PARTITION_INVALID'; END IF;
            IF local_mode THEN
                IF (p.private_payload->0) ? 'token_count_receipt'
                    OR p.private_payload#>'{0,planning_evidence}' IS DISTINCT FROM (jsonb_build_object('driver','neuron_delimiter_splitter@1','sdk_package','neuron-core/neuron-ai','sdk_version','4.0.2','max_length',12000,'word_overlap',0,'min_length',0,'token_count',NULL,'token_count_state','not_measured','counter_request_count',0) || jsonb_build_object('messages_sha256',p.private_payload#>>'{0,messages_sha256}'))
                    OR (p.private_payload#>>'{0,messages_sha256}') !~ '^[a-f0-9]{64}$'
                    THEN RAISE EXCEPTION 'SAND_AI_MEETING_LOCAL_EVIDENCE_INVALID'; END IF;
            ELSE
            IF NOT EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request r JOIN sand_ai_meeting_token_count_outcome o ON o.request_event_id=r.id
                WHERE r.task_id=NEW.task_id AND r.input_sha256=p.private_payload#>>'{0,messages_sha256}'
                    AND r.capacity_sha256=c.anchor#>>'{capacity,snapshot_sha256}' AND o.state='succeeded'
                    AND o.capacity_receipt=p.private_payload#>'{0,token_count_receipt}') THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TOKEN_RECEIPT_INVALID'; END IF;
            END IF;
        END IF;
    ELSE
        IF NEW.event_payload->>'phase'='complete' THEN
            IF (s->>'source_cursor')::integer<>(c.anchor->>'total_segments')::integer OR s->'pending'<>'[]'::jsonb OR s->'batch'<>'[]'::jsonb
                OR (s->>'eligible_segments')::integer<1 OR (s->>'leaf_count')::integer<1
                OR NEW.event_payload->>'manifest_sha256' IS NULL
                OR s IS DISTINCT FROM jsonb_set(c.state_json,'{tokenizer_call_count}',to_jsonb((SELECT request_count FROM sand_ai_meeting_plan_tokenizer_counter WHERE task_id=NEW.task_id))) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COMPLETION_INVALID'; END IF;
        ELSE
            IF local_mode THEN RAISE EXCEPTION 'SAND_AI_MEETING_LOCAL_UNEXPECTED_FIT_SPLIT'; END IF;
            first_parts:=c.state_json#>'{pending,0}';
            IF NEW.event_payload->>'phase'<>'preparing' OR first_parts IS NULL OR jsonb_array_length(first_parts)<1 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SPLIT_INVALID'; END IF;
            IF jsonb_array_length(first_parts)>1 THEN
                middle:=jsonb_array_length(first_parts)/2;
                SELECT jsonb_agg(value ORDER BY ordinality) INTO left_parts FROM jsonb_array_elements(first_parts) WITH ORDINALITY WHERE ordinality<=middle;
                SELECT jsonb_agg(value ORDER BY ordinality) INTO right_parts FROM jsonb_array_elements(first_parts) WITH ORDINALITY WHERE ordinality>middle;
            ELSE
                item:=first_parts->0;codepoints:=(item->>'end_offset')::integer-(item->>'start_offset')::integer;
                IF codepoints<2 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_UNSPLITTABLE'; END IF;
                middle:=(item->>'start_offset')::integer+codepoints/2;
                left_parts:=jsonb_build_array(jsonb_set(item,'{end_offset}',to_jsonb(middle)));
                right_parts:=jsonb_build_array(jsonb_set(item,'{start_offset}',to_jsonb(middle)));
            END IF;
            IF s IS DISTINCT FROM jsonb_set(c.state_json,'{pending}',jsonb_build_array(left_parts,right_parts)||((c.state_json->'pending')-0)) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SPLIT_PARTITION_INVALID'; END IF;
        END IF;
    END IF;
    NEW.event_sha256:=encode(sha256(convert_to(NEW.event_text,'UTF8')),'hex');NEW.event_text:=NULL;RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_meeting_plan_task_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE; expected_plan jsonb;
BEGIN
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=OLD.id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF ((NEW.input-ARRAY['_meeting_plan','iam_authorization'])#-ARRAY['_meeting_summary','audio_authorization'])
        IS DISTINCT FROM ((OLD.input-ARRAY['_meeting_plan','iam_authorization'])#-ARRAY['_meeting_summary','audio_authorization'])
        AND NEW.content_purged_time IS NULL
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TASK_ANCHOR_CHANGED'; END IF;
    IF NEW.input->'_meeting_plan' IS DISTINCT FROM OLD.input->'_meeting_plan' AND NEW.content_purged_time IS NULL THEN
        PERFORM sand_ai_meeting_worker_task(OLD.id);
        expected_plan:=jsonb_build_object(
            'version','meeting-summary-v3','normalization','none_unicode_codepoint@1',
            'transcript_sha256',c.anchor#>>'{source,sha256}','capacity_sha256',c.anchor#>>'{capacity,snapshot_sha256}',
            'total_segments',(c.anchor->>'total_segments')::integer,'eligible_segments',(c.state_json->>'eligible_segments')::integer,
            'leaf_count',(c.state_json->>'leaf_count')::integer,'source_page_count',(c.state_json->>'source_page_count')::integer,
            'source_chain_sha256',c.state_json->>'source_chain_sha256','leaf_chain_sha256',c.state_json->>'leaf_chain_sha256',
            'tokenizer_call_count',(c.state_json->>'tokenizer_call_count')::integer,'tokenizer_pricing_state','unpriced',
            'manifest_sha256',c.manifest_sha256,'prepared_plan_ref',jsonb_build_object(
                'version','meeting_prepared_plan@1','task_id',OLD.id,'anchor_sha256',c.anchor_sha256,'manifest_sha256',c.manifest_sha256));
        IF c.anchor#>>'{capacity,planning_driver}'='neuron_delimiter_splitter@1' THEN
            IF (c.state_json->>'tokenizer_call_count')::integer<>0
                OR EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request WHERE task_id=OLD.id)
                THEN RAISE EXCEPTION 'SAND_AI_MEETING_LOCAL_COUNTER_INVALID'; END IF;
            expected_plan:=jsonb_set(expected_plan,'{tokenizer_pricing_state}','"not_applicable"'::jsonb)
                || jsonb_build_object('planning',jsonb_build_object('driver','neuron_delimiter_splitter@1','sdk_package','neuron-core/neuron-ai','sdk_version','4.0.2','max_length',12000,'word_overlap',0,'min_length',0,'token_count',NULL,'token_count_state','not_measured','counter_request_count',0));
        END IF;
        IF c.phase<>'complete' OR NEW.input->'_meeting_plan' IS DISTINCT FROM expected_plan THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TASK_PROOF_INVALID';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_meeting_node_message_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_worker_checkpoint%ROWTYPE; leaf jsonb; expected_ids jsonb;
BEGIN
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF TG_OP='UPDATE' THEN
        IF NEW.worker_messages_sha256 IS DISTINCT FROM OLD.worker_messages_sha256 OR NEW.worker_messages_text IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_MESSAGES_IMMUTABLE';
        END IF;
        RETURN NEW;
    END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    IF NEW.worker_messages_text IS NULL OR octet_length(NEW.worker_messages_text)>262144
        OR NEW.worker_messages_text::jsonb IS DISTINCT FROM NEW.input_snapshot->'messages' THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_NODE_MESSAGES_INVALID';
    END IF;
    NEW.worker_messages_sha256 := encode(sha256(convert_to(NEW.worker_messages_text,'UTF8')),'hex');
    NEW.worker_messages_text := NULL;
    IF NEW.node_kind='chunk' THEN
        SELECT private_payload->0 INTO leaf FROM sand_ai_meeting_plan_page
            WHERE task_id=NEW.task_id AND page_kind='leaf' AND sequence_no=NEW.sequence_no;
        IF leaf IS NULL OR leaf->>'node_code' IS DISTINCT FROM NEW.node_code
            OR leaf->>'sequence_no' IS DISTINCT FROM NEW.sequence_no::text
            OR jsonb_typeof(leaf->'parts') IS DISTINCT FROM 'array'
            OR jsonb_array_length(leaf->'parts') NOT BETWEEN 1 AND 512
            OR leaf->>'messages_sha256' IS DISTINCT FROM NEW.worker_messages_sha256
            OR (CASE WHEN c.anchor_snapshot#>>'{capacity,planning_driver}'='neuron_delimiter_splitter@1'
                THEN (leaf ? 'token_count_receipt' OR leaf#>'{planning_evidence}' IS DISTINCT FROM (jsonb_build_object('driver','neuron_delimiter_splitter@1','sdk_package','neuron-core/neuron-ai','sdk_version','4.0.2','max_length',12000,'word_overlap',0,'min_length',0,'token_count',NULL,'token_count_state','not_measured','counter_request_count',0) || jsonb_build_object('messages_sha256',NEW.worker_messages_sha256)))
                ELSE leaf #>> '{token_count_receipt,input_sha256}' IS DISTINCT FROM NEW.worker_messages_sha256 END)
            OR NEW.segment_start_index::text IS DISTINCT FROM leaf #>> '{parts,0,segment_index}'
            OR NEW.segment_end_index::text IS DISTINCT FROM
                leaf->'parts'->(jsonb_array_length(leaf->'parts')-1)->>'segment_index'
            OR NEW.level_no<>0 THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PLAN_INVALID'; END IF;
        SELECT jsonb_agg('S'||(segment_position+1)::text ORDER BY first_position) INTO expected_ids
            FROM(SELECT (part->>'segment_index')::integer AS segment_position,min(position) AS first_position
                FROM jsonb_array_elements(leaf->'parts') WITH ORDINALITY selected(part,position)
                GROUP BY (part->>'segment_index')::integer) sources;
        IF expected_ids IS DISTINCT FROM NEW.input_snapshot->'segment_ids' THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PLAN_INVALID';
        END IF;
    ELSIF NEW.node_kind<>'merge' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PLAN_INVALID'; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610040127_meeting_local_planning','ece6e307bf4975f8706624452ffd59adbfcbac744963891fb9343435ff6e3ba1',CURRENT_TIMESTAMP(0));

-- Migration 202610040128_mcp_stable_protocol_profiles (sha256 292303bb7f8fdca184c6376b724442c1ce638842bd69588d2aff32f9711c327b)
-- Preserve the installed November profile and admit one reviewed stable negotiated profile.
ALTER TABLE sand_ai_tool_mcp_snapshot
    DROP CONSTRAINT ck_sand_ai_tool_mcp_snapshot_protocol;
ALTER TABLE sand_ai_tool_mcp_snapshot
    ADD CONSTRAINT ck_sand_ai_tool_mcp_snapshot_protocol
    CHECK (protocol_version IN ('2025-11-25', '2025-06-18'));

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610040128_mcp_stable_protocol_profiles','292303bb7f8fdca184c6376b724442c1ce638842bd69588d2aff32f9711c327b',CURRENT_TIMESTAMP(0));
-- Migration 202610040129_agent_readonly_tool_parent (sha256 a2da9d0161e257554b30feb623bf7ebd3be1a6673c1422aa19693e1b1b8f808b)
-- New versions and task-bound tool runs only; preserve historical snapshots and receipts.
ALTER TABLE sand_ai_agent_version
    ADD COLUMN readonly_tools jsonb NOT NULL DEFAULT '[]'::jsonb,
    ADD CONSTRAINT ck_sand_ai_agent_version_readonly_tools
        CHECK (jsonb_typeof(readonly_tools) = 'array' AND jsonb_array_length(readonly_tools) <= 2);

ALTER TABLE sand_ai_task_step
    ADD CONSTRAINT uk_sand_ai_task_step_id_task UNIQUE (id, task_id);

ALTER TABLE sand_ai_tool_run
    ADD COLUMN parent_task_run_id bigint NULL REFERENCES sand_ai_task_run(id),
    ADD COLUMN parent_task_step_id bigint NULL,
    ADD COLUMN queued_authorization jsonb NULL,
    ADD CONSTRAINT fk_sand_ai_tool_run_parent_step
        FOREIGN KEY (parent_task_step_id, parent_task_run_id)
        REFERENCES sand_ai_task_step(id, task_id),
    ADD CONSTRAINT ck_sand_ai_tool_run_parent
        CHECK ((parent_task_run_id IS NULL AND parent_task_step_id IS NULL AND queued_authorization IS NULL)
            OR (parent_task_run_id IS NOT NULL AND parent_task_step_id IS NOT NULL
                AND parent_task_run_id > 0 AND parent_task_step_id > 0
                AND (queued_authorization IS NULL OR jsonb_typeof(queued_authorization) = 'object')));

CREATE UNIQUE INDEX uk_sand_ai_tool_run_parent_step
    ON sand_ai_tool_run(parent_task_step_id) WHERE parent_task_step_id IS NOT NULL;
CREATE INDEX ix_sand_ai_tool_run_parent_task
    ON sand_ai_tool_run(parent_task_run_id, id) WHERE parent_task_run_id IS NOT NULL;

ALTER TABLE sand_ai_agent_operation
    DROP CONSTRAINT sand_ai_agent_operation_operation_code_check,
    ADD CONSTRAINT sand_ai_agent_operation_operation_code_check
        CHECK (operation_code IN ('draft', 'publish', 'disable', 'rollback'));

ALTER TABLE sand_ai_agent_stream_event
    DROP CONSTRAINT sand_ai_agent_stream_event_round_no_check,
    ADD CONSTRAINT sand_ai_agent_stream_event_round_no_check CHECK (round_no BETWEEN 1 AND 4);

-- Existing original request identity guards remain installed. Freeze the new parent and authorization evidence too.
CREATE FUNCTION sand_ai_agent_tool_parent_guard_v129() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ROW(OLD.parent_task_run_id,OLD.parent_task_step_id) IS DISTINCT FROM ROW(NEW.parent_task_run_id,NEW.parent_task_step_id) THEN
        RAISE EXCEPTION 'agent tool parent evidence is immutable' USING ERRCODE='55000';
    END IF;
    IF OLD.queued_authorization IS NOT NULL AND NEW.queued_authorization IS DISTINCT FROM OLD.queued_authorization THEN
        RAISE EXCEPTION 'agent tool original authorization is immutable' USING ERRCODE='55000';
    END IF;
    IF OLD.queued_authorization IS NULL AND NEW.queued_authorization IS NOT NULL AND
        (OLD.state <> 'running' OR NOT EXISTS(SELECT 1 FROM sand_ai_tool_attempt a WHERE a.tool_run_id=OLD.id AND a.attempt_no=1 AND a.state='prepared')) THEN
        RAISE EXCEPTION 'agent tool original authorization must precede dispatch' USING ERRCODE='55000';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_sand_ai_agent_tool_parent_guard_v129 BEFORE UPDATE ON sand_ai_tool_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_agent_tool_parent_guard_v129();

CREATE FUNCTION sand_ai_agent_version_published_guard_v129() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.state='published' AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'published agent configuration is immutable' USING ERRCODE='55000';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_sand_ai_agent_version_published_guard_v129 BEFORE UPDATE ON sand_ai_agent_version
    FOR EACH ROW EXECUTE FUNCTION sand_ai_agent_version_published_guard_v129();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610040129_agent_readonly_tool_parent','a2da9d0161e257554b30feb623bf7ebd3be1a6673c1422aa19693e1b1b8f808b',CURRENT_TIMESTAMP(0));

-- Future authenticated administrator inference only. No backfill of legacy evidence.
CREATE TABLE sand_ai_admin_validation_attempt (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    target_kind varchar(32) NOT NULL CHECK(target_kind IN ('deployment','temporary_connection')),
    config_revision_id bigint REFERENCES sand_ai_config_revision(id) ON DELETE RESTRICT,
    deployment_id bigint REFERENCES sand_ai_model_deployment(id) ON DELETE RESTRICT,
    deployment_revision bigint,
    provider_id bigint REFERENCES sand_ai_provider(id) ON DELETE RESTRICT,
    model_id bigint REFERENCES sand_ai_model(id) ON DELETE RESTRICT,
    attempt_id char(32) NOT NULL CHECK(attempt_id ~ '^[a-f0-9]{32}$'),
    admin_actor_id bigint NOT NULL CHECK(admin_actor_id>0),
    capability_code varchar(64) NOT NULL CHECK(capability_code IN ('chat','embedding','audio_transcription','audio_speech')),
    service_action varchar(96) NOT NULL,
    request_sha256 char(64) NOT NULL CHECK(request_sha256 ~ '^[a-f0-9]{64}$'),
    idempotency_hmac char(64) NOT NULL CHECK(idempotency_hmac ~ '^[a-f0-9]{64}$'),
    execution_snapshot_sha256 char(64) NOT NULL CHECK(execution_snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    execution_snapshot jsonb NOT NULL CHECK(jsonb_typeof(execution_snapshot)='object'),
    execution_snapshot_canonical text NOT NULL CHECK(execution_snapshot_canonical::jsonb=execution_snapshot),
    target_hmac char(64), body_sha256 char(64),
    settings_snapshot jsonb NOT NULL CHECK(jsonb_typeof(settings_snapshot) IN ('object','array')),
    call_started_at timestamp(0) without time zone NOT NULL,
    call_start_pricing_state varchar(16) NOT NULL CHECK(call_start_pricing_state IN ('priced','unpriced')),
    call_start_price_revision_id bigint REFERENCES sand_ai_price_revision(id) ON DELETE RESTRICT,
    call_start_price_snapshot jsonb NOT NULL CHECK(jsonb_typeof(call_start_price_snapshot)='object'),
    create_time timestamp(0) without time zone NOT NULL,
    CHECK(((target_kind='deployment' AND config_revision_id IS NOT NULL AND deployment_id IS NOT NULL
        AND deployment_revision>0 AND provider_id IS NOT NULL AND model_id IS NOT NULL
        AND target_hmac IS NULL AND (body_sha256 IS NULL OR body_sha256 ~ '^[a-f0-9]{64}$')
        AND (capability_code<>'chat' OR body_sha256 IS NOT NULL) AND service_action='sand_ai.setup.deployment.verify')
      OR(target_kind='temporary_connection' AND config_revision_id IS NULL AND deployment_id IS NULL
        AND deployment_revision IS NULL AND provider_id IS NULL AND model_id IS NULL
        AND capability_code='chat' AND service_action='sand_ai.onboarding.verify'
        AND target_hmac ~ '^[a-f0-9]{64}$' AND body_sha256 ~ '^[a-f0-9]{64}$'
        AND call_start_pricing_state='unpriced' AND call_start_price_revision_id IS NULL
        AND call_start_price_snapshot=jsonb_build_object('snapshot_version',1,'reason','temporary_target_unregistered'))) IS TRUE),
    CHECK(((call_start_pricing_state='priced' AND call_start_price_revision_id IS NOT NULL)
      OR(call_start_pricing_state='unpriced' AND call_start_price_revision_id IS NULL
        AND call_start_price_snapshot ? 'reason' AND length(call_start_price_snapshot->>'reason')>0)) IS TRUE)
);
CREATE UNIQUE INDEX uq_sand_ai_admin_validation_claim_v130 ON sand_ai_admin_validation_attempt(config_revision_id,attempt_id) WHERE config_revision_id IS NOT NULL;
CREATE UNIQUE INDEX uq_sand_ai_admin_validation_key_v130 ON sand_ai_admin_validation_attempt(config_revision_id,idempotency_hmac) WHERE config_revision_id IS NOT NULL;
CREATE UNIQUE INDEX uq_sand_ai_temporary_validation_key_v130 ON sand_ai_admin_validation_attempt(admin_actor_id,idempotency_hmac) WHERE target_kind='temporary_connection';
CREATE INDEX idx_sand_ai_admin_validation_time_v130 ON sand_ai_admin_validation_attempt(call_started_at DESC,id DESC);

CREATE TABLE sand_ai_admin_validation_receipt (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    attempt_id bigint NOT NULL UNIQUE REFERENCES sand_ai_admin_validation_attempt(id) ON DELETE RESTRICT,
    outcome_state varchar(24) NOT NULL CHECK(outcome_state IN ('response_known','not_dispatched','ambiguous')),
    usage_measured boolean NOT NULL,
    input_tokens integer, output_tokens integer, total_tokens integer,
    error_code varchar(128) CHECK(error_code ~ '^SAND_AI_[A-Z0-9_]{1,100}$'),
    evidence_sha256 char(64) NOT NULL CHECK(evidence_sha256 ~ '^[a-f0-9]{64}$'),
    create_time timestamp(0) without time zone NOT NULL,
    CHECK(((usage_measured AND outcome_state='response_known' AND input_tokens>=0 AND output_tokens>=0
        AND input_tokens::bigint+output_tokens::bigint=total_tokens::bigint)
      OR(NOT usage_measured AND input_tokens IS NULL AND output_tokens IS NULL AND total_tokens IS NULL)) IS TRUE)
);
CREATE TABLE sand_ai_admin_validation_draft_binding (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    attempt_id bigint NOT NULL UNIQUE REFERENCES sand_ai_admin_validation_attempt(id) ON DELETE RESTRICT,
    config_revision_id bigint NOT NULL UNIQUE REFERENCES sand_ai_config_revision(id) ON DELETE RESTRICT,
    draft_result jsonb NOT NULL CHECK(jsonb_typeof(draft_result)='object'),
    create_time timestamp(0) without time zone NOT NULL
);
CREATE FUNCTION sand_ai_admin_validation_immutable_v130() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_IMMUTABLE'; END $$;
CREATE TRIGGER trg_sand_ai_admin_validation_attempt_immutable_v130 BEFORE UPDATE OR DELETE ON sand_ai_admin_validation_attempt FOR EACH ROW EXECUTE FUNCTION sand_ai_admin_validation_immutable_v130();
CREATE TRIGGER trg_sand_ai_admin_validation_receipt_immutable_v130 BEFORE UPDATE OR DELETE ON sand_ai_admin_validation_receipt FOR EACH ROW EXECUTE FUNCTION sand_ai_admin_validation_immutable_v130();
CREATE TRIGGER trg_sand_ai_admin_validation_binding_immutable_v130 BEFORE UPDATE OR DELETE ON sand_ai_admin_validation_draft_binding FOR EACH ROW EXECUTE FUNCTION sand_ai_admin_validation_immutable_v130();

CREATE FUNCTION sand_ai_admin_validation_json_v130(value jsonb) RETURNS text LANGUAGE plpgsql IMMUTABLE STRICT AS $$
DECLARE encoded text;
BEGIN
    IF jsonb_typeof(value)='object' THEN
        SELECT '{'||coalesce(string_agg(to_jsonb(key)::text||':'||sand_ai_admin_validation_json_v130(item),',' ORDER BY key COLLATE "C"),'')||'}' INTO encoded FROM jsonb_each(value) AS fields(key,item);
    ELSIF jsonb_typeof(value)='array' THEN
        SELECT '['||coalesce(string_agg(sand_ai_admin_validation_json_v130(item),',' ORDER BY position),'')||']' INTO encoded FROM jsonb_array_elements(value) WITH ORDINALITY AS items(item,position);
    ELSE encoded=value::text;
    END IF;
    RETURN encoded;
END $$;

CREATE FUNCTION sand_ai_admin_validation_attempt_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE config sand_ai_config_revision%ROWTYPE; deployment sand_ai_model_deployment%ROWTYPE;
    price sand_ai_price_revision%ROWTYPE; frozen jsonb; actual_rate sand_ai_price_rate%ROWTYPE; meter text;
BEGIN
    IF NEW.target_kind='deployment' THEN
        SELECT * INTO STRICT config FROM sand_ai_config_revision WHERE id=NEW.config_revision_id FOR UPDATE;
        SELECT * INTO STRICT deployment FROM sand_ai_model_deployment WHERE id=NEW.deployment_id;
        IF config.resource_type<>'model_deployment' OR config.resource_id IS DISTINCT FROM NEW.deployment_id
          OR config.revision IS DISTINCT FROM NEW.deployment_revision OR config.state<>'verifying'
          OR config.delete_time IS NOT NULL OR deployment.delete_time IS NOT NULL
          OR deployment.revision IS DISTINCT FROM NEW.deployment_revision
          OR deployment.provider_id IS DISTINCT FROM NEW.provider_id OR deployment.model_id IS DISTINCT FROM NEW.model_id
          OR config.payload->'deployment_verification_claim'->>'attempt_id' IS DISTINCT FROM NEW.attempt_id::text
          OR config.payload->'deployment_verification_claim'->>'admin_actor_id' IS DISTINCT FROM NEW.admin_actor_id::text
          OR config.payload->'deployment_verification_claim'->>'request_sha256' IS DISTINCT FROM NEW.request_sha256::text
          OR config.payload->'deployment_verification_claim'->>'idempotency_hmac' IS DISTINCT FROM NEW.idempotency_hmac::text
          OR config.payload->'deployment_verification_claim'->>'execution_snapshot_digest' IS DISTINCT FROM NEW.execution_snapshot_sha256::text
          OR (NEW.execution_snapshot->'provider'->>'id')::bigint IS DISTINCT FROM NEW.provider_id
          OR (NEW.execution_snapshot->'model'->>'id')::bigint IS DISTINCT FROM NEW.model_id
          OR NEW.execution_snapshot->'model'->>'type' IS DISTINCT FROM NEW.capability_code
          OR (NEW.execution_snapshot->'deployment'->>'id')::bigint IS DISTINCT FROM NEW.deployment_id
          OR (NEW.execution_snapshot->'deployment'->>'revision')::bigint IS DISTINCT FROM NEW.deployment_revision
          OR encode(sha256(convert_to(NEW.execution_snapshot_canonical,'UTF8')),'hex') IS DISTINCT FROM NEW.execution_snapshot_sha256::text THEN
            RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_CLAIM_INVALID';
        END IF;
    ELSE
        IF NEW.execution_snapshot IS DISTINCT FROM jsonb_build_object('version','temporary_validation_execution@1','target_hmac',NEW.target_hmac::text,'body_sha256',NEW.body_sha256::text,'settings',NEW.settings_snapshot)
          OR NEW.request_sha256 IS DISTINCT FROM NEW.execution_snapshot_sha256
          OR encode(sha256(convert_to(sand_ai_admin_validation_json_v130(jsonb_build_object('target',NEW.target_hmac::text,'body',NEW.body_sha256::text,'settings',NEW.settings_snapshot)),'UTF8')),'hex') IS DISTINCT FROM NEW.request_sha256::text THEN
            RAISE EXCEPTION 'SAND_AI_TEMPORARY_VALIDATION_FACTS_INVALID';
        END IF;
    END IF;
    frozen=NEW.call_start_price_snapshot;
    IF NEW.call_start_pricing_state='priced' THEN
        SELECT * INTO STRICT price FROM sand_ai_price_revision WHERE id=NEW.call_start_price_revision_id;
        IF price.state<>'published' OR price.delete_time IS NOT NULL OR price.provider_id IS DISTINCT FROM NEW.provider_id
          OR price.model_id IS DISTINCT FROM NEW.model_id OR price.capability_code IS DISTINCT FROM NEW.capability_code
          OR NEW.call_started_at<price.effective_from OR (price.effective_until IS NOT NULL AND NEW.call_started_at>=price.effective_until)
          OR (frozen->>'snapshot_version')::int IS DISTINCT FROM 1
          OR (frozen->>'price_revision_id')::bigint IS DISTINCT FROM price.id
          OR frozen->>'currency' IS DISTINCT FROM price.currency
          OR (frozen->>'effective_from')::timestamp IS DISTINCT FROM price.effective_from
          OR (frozen->>'effective_until')::timestamp IS DISTINCT FROM price.effective_until THEN
            RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_PRICE_INVALID';
        END IF;
        FOREACH meter IN ARRAY ARRAY['input_tokens','output_tokens'] LOOP
            SELECT * INTO STRICT actual_rate FROM sand_ai_price_rate WHERE price_revision_id=price.id AND meter_code=meter;
            IF (frozen->'rates'->meter->>'unit_price')::numeric IS DISTINCT FROM actual_rate.unit_price
              OR (frozen->'rates'->meter->>'per_quantity')::bigint IS DISTINCT FROM actual_rate.per_quantity
              OR actual_rate.per_quantity<=0 OR actual_rate.unit_price<0 THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_RATE_INVALID'; END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_admin_validation_attempt_guard_v130 BEFORE INSERT ON sand_ai_admin_validation_attempt FOR EACH ROW EXECUTE FUNCTION sand_ai_admin_validation_attempt_guard_v130();

CREATE FUNCTION sand_ai_admin_validation_receipt_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE attempt sand_ai_admin_validation_attempt%ROWTYPE; facts jsonb;
BEGIN
    SELECT * INTO STRICT attempt FROM sand_ai_admin_validation_attempt WHERE id=NEW.attempt_id FOR UPDATE;
    facts=jsonb_build_object('attempt_id',NEW.attempt_id,'outcome_state',NEW.outcome_state,'usage_measured',NEW.usage_measured,'input_tokens',NEW.input_tokens,'output_tokens',NEW.output_tokens,'total_tokens',NEW.total_tokens,'error_code',NEW.error_code);
    IF encode(sha256(convert_to(sand_ai_admin_validation_json_v130(facts),'UTF8')),'hex') IS DISTINCT FROM NEW.evidence_sha256::text
      OR NEW.create_time<attempt.call_started_at THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_RECEIPT_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_admin_validation_receipt_guard_v130 BEFORE INSERT ON sand_ai_admin_validation_receipt FOR EACH ROW EXECUTE FUNCTION sand_ai_admin_validation_receipt_guard_v130();

ALTER TABLE sand_ai_usage_event ADD COLUMN admin_validation_attempt_id bigint REFERENCES sand_ai_admin_validation_attempt(id) ON DELETE RESTRICT;
ALTER TABLE sand_ai_usage_event ADD COLUMN admin_actor_id bigint;
CREATE UNIQUE INDEX uq_sand_ai_admin_validation_event_v130 ON sand_ai_usage_event(admin_validation_attempt_id) WHERE admin_validation_attempt_id IS NOT NULL;
ALTER TABLE sand_ai_usage_event ALTER COLUMN environment_id DROP NOT NULL;
-- Keep each actual prior source/anchor expression exactly; only a strict admin arm is added.
DO $$
DECLARE previous record; expression text;
BEGIN
    FOR previous IN SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated FROM pg_constraint
      WHERE conrelid='sand_ai_usage_event'::regclass AND conname IN ('sand_ai_usage_event_source_kind_ck_v073','ck_sand_ai_usage_event_tool_metering') LOOP
        expression=previous.expression;
        EXECUTE format('ALTER TABLE sand_ai_usage_event DROP CONSTRAINT %I',previous.conname);
        EXECUTE format('ALTER TABLE sand_ai_usage_event ADD CONSTRAINT %I CHECK ((source_kind=''admin_verify'' OR (source_kind<>''admin_verify'' AND environment_id IS NOT NULL AND admin_validation_attempt_id IS NULL AND admin_actor_id IS NULL AND (%s) IS TRUE)) IS TRUE)%s',previous.conname,expression,CASE WHEN previous.convalidated THEN '' ELSE ' NOT VALID' END);
    END LOOP;
    IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='sand_ai_usage_event'::regclass AND conname='sand_ai_usage_event_source_kind_ck_v073') OR NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='sand_ai_usage_event'::regclass AND conname='ck_sand_ai_usage_event_tool_metering') THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_PREVIOUS_CONSTRAINT_MISSING'; END IF;
END $$;
ALTER TABLE sand_ai_usage_event ADD CONSTRAINT ck_sand_ai_platform_scope_v130 CHECK ((
    (source_kind='admin_verify' AND admin_validation_attempt_id IS NOT NULL AND admin_actor_id>0
      AND organization_id IS NULL AND application_id IS NULL AND environment_id IS NULL AND workload_client_id IS NULL
      AND invocation_id IS NULL AND skill_id IS NULL AND skill_version_id IS NULL AND skill_run_id IS NULL
      AND audio_task_run_id IS NULL AND speech_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL
      AND tool_id IS NULL AND tool_version_id IS NULL AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL
      AND ocr_dispatch_id IS NULL AND audio_execution_id IS NULL AND external_audio_dispatch_id IS NULL
      AND metering_unit IS NULL AND metering_quantity IS NULL)
    OR(source_kind<>'admin_verify' AND environment_id IS NOT NULL AND admin_validation_attempt_id IS NULL AND admin_actor_id IS NULL)
) IS TRUE);
CREATE FUNCTION sand_ai_admin_validation_event_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE attempt sand_ai_admin_validation_attempt%ROWTYPE; receipt sand_ai_admin_validation_receipt%ROWTYPE;
    amount_input numeric; amount_output numeric; snapshot jsonb;
BEGIN
    IF NEW.source_kind<>'admin_verify' THEN RETURN NEW; END IF;
    SELECT * INTO STRICT attempt FROM sand_ai_admin_validation_attempt WHERE id=NEW.admin_validation_attempt_id FOR UPDATE;
    SELECT * INTO STRICT receipt FROM sand_ai_admin_validation_receipt WHERE attempt_id=attempt.id;
    IF receipt.outcome_state<>'response_known' OR NEW.admin_actor_id IS DISTINCT FROM attempt.admin_actor_id
      OR NEW.provider_id IS DISTINCT FROM attempt.provider_id OR NEW.model_id IS DISTINCT FROM attempt.model_id OR NEW.deployment_id IS DISTINCT FROM attempt.deployment_id
      OR NEW.service_action IS DISTINCT FROM attempt.service_action OR NEW.capability_code IS DISTINCT FROM attempt.capability_code
      OR NEW.identity_snapshot IS DISTINCT FROM jsonb_build_object('scope_kind','platform_admin','admin_actor_id',attempt.admin_actor_id)
      OR NEW.provider_snapshot IS DISTINCT FROM coalesce(attempt.execution_snapshot->'provider','[]'::jsonb)
      OR NEW.model_snapshot IS DISTINCT FROM coalesce(attempt.execution_snapshot->'model','[]'::jsonb)
      OR NEW.deployment_snapshot IS DISTINCT FROM coalesce(attempt.execution_snapshot->'deployment','[]'::jsonb)
      OR NEW.idempotency_key IS DISTINCT FROM 'admin_validation:'||attempt.id::text
      OR NEW.input_tokens IS DISTINCT FROM coalesce(receipt.input_tokens,0) OR NEW.output_tokens IS DISTINCT FROM coalesce(receipt.output_tokens,0)
      OR NEW.total_tokens IS DISTINCT FROM coalesce(receipt.total_tokens,0)
      OR NEW.usage_state IS DISTINCT FROM (CASE WHEN receipt.usage_measured THEN 'measured' ELSE 'unmeasured' END)
      OR NEW.reconciliation_state<>'unreconciled' OR NEW.reconciled_amount IS NOT NULL THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_INVALID'; END IF;
    snapshot=attempt.call_start_price_snapshot;
    IF receipt.usage_measured AND attempt.call_start_pricing_state='priced' THEN
        amount_input=round(receipt.input_tokens::numeric*(snapshot->'rates'->'input_tokens'->>'unit_price')::numeric/(snapshot->'rates'->'input_tokens'->>'per_quantity')::numeric,6);
        amount_output=round(receipt.output_tokens::numeric*(snapshot->'rates'->'output_tokens'->>'unit_price')::numeric/(snapshot->'rates'->'output_tokens'->>'per_quantity')::numeric,6);
        IF NEW.pricing_state<>'priced' OR NEW.price_revision_id IS DISTINCT FROM attempt.call_start_price_revision_id
          OR NEW.currency IS DISTINCT FROM snapshot->>'currency' OR NEW.metered_amount IS DISTINCT FROM amount_input+amount_output
          OR NEW.price_snapshot-'amounts' IS DISTINCT FROM snapshot-'snapshot_version'
          OR (NEW.price_snapshot->'amounts'->>'input_amount')::numeric IS DISTINCT FROM amount_input
          OR (NEW.price_snapshot->'amounts'->>'output_amount')::numeric IS DISTINCT FROM amount_output
          OR (NEW.price_snapshot->'amounts'->>'amount')::numeric IS DISTINCT FROM amount_input+amount_output THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_PRICE_INVALID'; END IF;
    ELSE
        IF NEW.pricing_state<>'unpriced' OR NEW.price_revision_id IS NOT NULL OR NEW.currency IS NOT NULL OR NEW.metered_amount IS NOT NULL
          OR NEW.price_snapshot IS DISTINCT FROM jsonb_build_object('reason',CASE WHEN receipt.usage_measured THEN snapshot->>'reason' ELSE 'provider_token_usage_unavailable' END) THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_UNKNOWN_INVALID'; END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_admin_validation_event_guard_v130 BEFORE INSERT ON sand_ai_usage_event FOR EACH ROW EXECUTE FUNCTION sand_ai_admin_validation_event_guard_v130();
CREATE FUNCTION sand_ai_admin_validation_binding_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE attempt sand_ai_admin_validation_attempt%ROWTYPE; config sand_ai_config_revision%ROWTYPE;
BEGIN
    SELECT * INTO STRICT attempt FROM sand_ai_admin_validation_attempt WHERE id=NEW.attempt_id FOR UPDATE;
    SELECT * INTO STRICT config FROM sand_ai_config_revision WHERE id=NEW.config_revision_id;
    IF attempt.target_kind<>'temporary_connection' OR config.resource_type<>'model_deployment' OR config.state<>'tested'
      OR NOT EXISTS(SELECT 1 FROM sand_ai_admin_validation_receipt WHERE attempt_id=attempt.id AND outcome_state='response_known' AND error_code IS NULL)
      OR NOT EXISTS(SELECT 1 FROM sand_ai_usage_event WHERE admin_validation_attempt_id=attempt.id AND source_kind='admin_verify' AND pricing_state='unpriced')
      OR (NEW.draft_result->'deployment'->>'id')::bigint IS DISTINCT FROM config.resource_id
      OR (NEW.draft_result->'deployment'->>'revision')::bigint IS DISTINCT FROM config.revision
      OR NEW.draft_result->'deployment'->>'state' IS DISTINCT FROM 'tested' THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_BINDING_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_admin_validation_binding_guard_v130 BEFORE INSERT ON sand_ai_admin_validation_draft_binding FOR EACH ROW EXECUTE FUNCTION sand_ai_admin_validation_binding_guard_v130();
-- The original canonical event UPDATE/DELETE immutability trigger is unchanged.
CREATE FUNCTION sand_ai_admin_validation_event_coverage_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE event_count bigint;
BEGIN
    SELECT count(*) INTO event_count FROM sand_ai_usage_event WHERE admin_validation_attempt_id=NEW.attempt_id;
    IF (NEW.outcome_state='response_known' AND event_count<>1)
      OR(NEW.outcome_state<>'response_known' AND event_count<>0) THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_COVERAGE_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER trg_sand_ai_admin_validation_event_coverage_v130 AFTER INSERT ON sand_ai_admin_validation_receipt DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_admin_validation_event_coverage_v130();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610040130_admin_validation_usage','fed113b08a61b9330d4c9d29f345a6b09f6cdc64227130a779246dbd41e7935a',CURRENT_TIMESTAMP(0));

-- Private encrypted Neuron 4 control/checkpoint/memo records. Migration source only.
CREATE TABLE IF NOT EXISTS sand_ai_agent_checkpoint (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL,
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id),
    partition_sha256 varchar(64) NOT NULL,
    record_key_sha256 varchar(64) NOT NULL,
    encrypted_config text NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sand_ai_agent_checkpoint_record_unique UNIQUE (environment_id,task_id,partition_sha256,record_key_sha256),
    CONSTRAINT sand_ai_agent_checkpoint_partition_hash CHECK (partition_sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT sand_ai_agent_checkpoint_record_hash CHECK (record_key_sha256 ~ '^[0-9a-f]{64}$')
);
CREATE INDEX IF NOT EXISTS sand_ai_agent_checkpoint_task_idx ON sand_ai_agent_checkpoint (environment_id,task_id);

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610050131_agent_continuation_checkpoint','61b2f8da66e42d1b4cb4cf98fd1ce77abf635fe8e2bd33e7a4e02d08499ffb88',CURRENT_TIMESTAMP(0));

-- Source-only: explicit database authorization is required before execution.
-- Receipts identify one administrator's original draft save, never a budget reservation or IAM grant.
CREATE TABLE sand_ai_budget_policy_operation (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    created_by varchar(64) NOT NULL,
    idempotency_key varchar(128) NOT NULL,
    scope_kind varchar(32) NOT NULL,
    scope_ref varchar(191) NOT NULL,
    budget_policy_id bigint NULL REFERENCES sand_ai_budget_policy(id),
    reference_environment_id bigint NULL,
    state varchar(16) NOT NULL,
    request_fingerprint char(64) NOT NULL,
    response_snapshot jsonb NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sand_ai_budget_policy_operation_request UNIQUE (created_by, idempotency_key),
    CONSTRAINT ck_sand_ai_budget_policy_operation_actor CHECK (created_by ~ '^[1-9][0-9]{0,18}$'),
    CONSTRAINT ck_sand_ai_budget_policy_operation_key CHECK (
        length(idempotency_key) BETWEEN 1 AND 128 AND idempotency_key !~ '[^!-~]'
    ),
    CONSTRAINT ck_sand_ai_budget_policy_operation_scope CHECK (
        scope_kind IN ('organization', 'application', 'environment', 'service', 'capability', 'model')
        AND length(scope_ref) > 0 AND btrim(scope_ref) = scope_ref
    ),
    CONSTRAINT ck_sand_ai_budget_policy_operation_reference CHECK (
        reference_environment_id IS NULL OR reference_environment_id > 0
    ),
    CONSTRAINT ck_sand_ai_budget_policy_operation_fingerprint CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_budget_policy_operation_state CHECK (
        (state = 'saved' AND budget_policy_id IS NOT NULL AND budget_policy_id > 0)
        OR (state = 'not_saved' AND budget_policy_id IS NULL)
    ),
    CONSTRAINT ck_sand_ai_budget_policy_operation_response CHECK ((
        jsonb_typeof(response_snapshot) = 'object'
        AND ((state = 'saved' AND response_snapshot->>'state' = 'draft'
              AND response_snapshot->>'id' = budget_policy_id::text
              AND response_snapshot->>'scope_kind' = scope_kind
              AND response_snapshot->>'scope_ref' = scope_ref)
             OR (state = 'not_saved' AND response_snapshot->>'state' = 'not_saved'
                 AND response_snapshot->>'error_code' IN (
                     'SAND_AI_BUDGET_VALIDATION_ERROR', 'SAND_AI_BUDGET_IMMUTABLE', 'SAND_AI_RESOURCE_NOT_FOUND'
                 )
                 AND jsonb_typeof(response_snapshot->'message') = 'string'))
    ) IS TRUE)
);

CREATE INDEX idx_sand_ai_budget_policy_operation_policy
    ON sand_ai_budget_policy_operation (budget_policy_id, id)
    WHERE budget_policy_id IS NOT NULL;

CREATE TRIGGER trg_sand_ai_budget_policy_operation_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_budget_policy_operation
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_budget_evidence_mutation();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610050132_budget_policy_operation','fb6e819a7e20f7ee58fa2d9c9f8c6066ba584dd53edd7351bd22479503b0f729',CURRENT_TIMESTAMP(0));

-- SOURCE only; apply through the authorized normal plugin migration lifecycle.
CREATE TABLE sand_ai_knowledge_query_intent (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    organization_id bigint NOT NULL CHECK (organization_id > 0),
    application_id bigint NOT NULL CHECK (application_id > 0),
    environment_id bigint NOT NULL CHECK (environment_id > 0),
    workload_client_id bigint NOT NULL CHECK (workload_client_id > 0),
    request_id varchar(128) NOT NULL CHECK (request_id ~ '^knowledge-query:[a-f0-9]{64}$'),
    publication_id bigint NOT NULL REFERENCES sand_ai_knowledge_publication(id),
    knowledge_base_version_id bigint NOT NULL REFERENCES sand_ai_knowledge_base_version(id),
    source_scope_sha256 char(64) NOT NULL CHECK (source_scope_sha256 ~ '^[a-f0-9]{64}$'),
    data_class varchar(32) NULL CHECK (data_class IS NULL OR data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$'),
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    retrieval_snapshot_sha256 char(64) NULL CHECK (retrieval_snapshot_sha256 IS NULL OR retrieval_snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_knowledge_query_intent_scope_request_uq
        UNIQUE (organization_id,application_id,environment_id,workload_client_id,request_id)
);

CREATE OR REPLACE FUNCTION sand_ai_knowledge_query_intent_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.retrieval_snapshot_sha256 IS NULL
       AND NEW.retrieval_snapshot_sha256 IS NOT NULL
       AND (to_jsonb(NEW) - 'retrieval_snapshot_sha256') = (to_jsonb(OLD) - 'retrieval_snapshot_sha256') THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'knowledge query intent is immutable';
END;
$$;
CREATE TRIGGER sand_ai_knowledge_query_intent_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_knowledge_query_intent
    FOR EACH ROW EXECUTE FUNCTION sand_ai_knowledge_query_intent_immutable();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610060133_knowledge_query_intent','4860e90eb160290f3c1333ddeec125b2b6587c0b0db2d3f88bd3f83d8f167c26',CURRENT_TIMESTAMP(0));

-- SOURCE only; apply through the authorized normal plugin migration lifecycle.
ALTER TABLE sand_ai_feishu_delivery DROP CONSTRAINT sand_ai_feishu_delivery_state_check;
ALTER TABLE sand_ai_feishu_delivery ADD CONSTRAINT sand_ai_feishu_delivery_state_check
    CHECK (state IN ('pending','sending','sent','failed','uncertain'));

-- Legacy attempts have no durable distinction between token failure and an ambiguous text POST.
-- Preserve successful deliveries and untouched pending rows; never replay an unproven old send.
UPDATE sand_ai_feishu_delivery
SET state='uncertain',error_code='SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',
    lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0)
WHERE state='sending' OR (state IN ('pending','failed') AND attempt_count>0);

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610060134_feishu_delivery_uncertain','05fd64073ff7668fe9d98a90a171e4adfe8872321a9f6cf56abb74f8b2d9453f',CURRENT_TIMESTAMP(0));

-- SOURCE ONLY: apply to an existing authorized database through the normal upgrade path.
-- This adds one independent civil drafting profile; existing published modes keep their semantics.
ALTER TABLE sand_ai_legal_analysis_profile
    DROP CONSTRAINT sand_ai_legal_analysis_profile_code_ck;
ALTER TABLE sand_ai_legal_analysis_profile
    ADD CONSTRAINT sand_ai_legal_analysis_profile_code_ck
    CHECK (code IN ('legal_research', 'litigation_preparation', 'judgment_analysis', 'complaint_drafting'));

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610060135_complaint_drafting_profile','8543f5891552ee7dda288252b387ec26e7f8b836a2beef7167d28ad738f03a36',CURRENT_TIMESTAMP(0));

-- SOURCE ONLY: apply to an existing authorized database through the normal upgrade path.
-- This adds an independent research planning profile; existing published modes keep their semantics.
ALTER TABLE sand_ai_legal_analysis_profile
    DROP CONSTRAINT sand_ai_legal_analysis_profile_code_ck;
ALTER TABLE sand_ai_legal_analysis_profile
    ADD CONSTRAINT sand_ai_legal_analysis_profile_code_ck
    CHECK (code IN ('legal_research', 'litigation_preparation', 'judgment_analysis', 'complaint_drafting', 'legal_research_planning'));

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610060136_legal_research_planning_profile','2dea553b39e6432949d56a37b7ac3c272cc86a9170f66fef9515d16ec9dbd701',CURRENT_TIMESTAMP(0));

-- SOURCE only. No provider, database migration or administrator verification is executed here.
-- Actual professional service quantities are distinct from chat token evidence.
ALTER TABLE sand_ai_price_rate DROP CONSTRAINT ck_sand_ai_price_rate_meter;
ALTER TABLE sand_ai_price_rate ADD CONSTRAINT ck_sand_ai_price_rate_meter CHECK (
    meter_code IN ('input_tokens','output_tokens','audio_seconds','input_characters','rerank_search_units','rerank_tokens'));

-- Preserve the installed previous constraints and add only a strict professional arm.
DO $$
DECLARE prior record; professional text;
BEGIN
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_invocation_dispatch'::regclass AND conname='ck_sand_ai_invocation_dispatch_scope_v066';
    professional=$arm$execution_scope='rerank' AND parent_task_run_id IS NULL AND parent_task_step_id IS NULL
        AND workflow_node_code IS NULL AND source_kind='knowledge_query' AND service_action='sand_ai.knowledge.query'
        AND capability_code='rerank' AND environment_id>0 AND application_id>0$arm$;
    EXECUTE format('ALTER TABLE sand_ai_invocation_dispatch DROP CONSTRAINT %I',prior.conname);
    EXECUTE format('ALTER TABLE sand_ai_invocation_dispatch ADD CONSTRAINT %I CHECK (((%s) OR (%s)) IS TRUE)%s',
        prior.conname,prior.expression,professional,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_usage_event'::regclass AND conname='ck_sand_ai_usage_event_tool_metering';
    professional=$arm$capability_code='rerank' AND source_kind='knowledge_query' AND service_action='sand_ai.knowledge.query'
      AND invocation_id IS NOT NULL AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
      AND organization_id>0 AND application_id>0 AND environment_id>0 AND workload_client_id>0
      AND admin_validation_attempt_id IS NULL AND admin_actor_id IS NULL
      AND tool_run_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL AND tool_connection_id IS NULL
      AND tool_connection_revision_id IS NULL AND mcp_snapshot_id IS NULL AND speech_run_id IS NULL AND audio_task_run_id IS NULL
      AND external_audio_dispatch_id IS NULL AND audio_execution_id IS NULL AND ocr_dispatch_id IS NULL
      AND skill_id IS NULL AND skill_version_id IS NULL AND skill_run_id IS NULL
      AND metering_unit IN ('rerank_search_units','rerank_tokens')
      AND input_tokens=0 AND output_tokens=0 AND total_tokens=0
      AND ((usage_state='measured' AND metering_quantity>=0 AND metering_quantity=trunc(metering_quantity))
        OR(usage_state='unmeasured' AND metering_quantity IS NULL))$arm$;
    EXECUTE format('ALTER TABLE sand_ai_usage_event DROP CONSTRAINT %I',prior.conname);
    EXECUTE format('ALTER TABLE sand_ai_usage_event ADD CONSTRAINT %I CHECK (((%s) OR (%s)) IS TRUE)%s',
        prior.conname,prior.expression,professional,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_usage_event'::regclass AND conname='ck_sand_ai_platform_scope_v130';
    professional=$arm$source_kind='admin_verify' AND capability_code='rerank'
      AND admin_validation_attempt_id IS NOT NULL AND admin_actor_id>0 AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
      AND organization_id IS NULL AND application_id IS NULL AND environment_id IS NULL AND workload_client_id IS NULL
      AND invocation_id IS NULL AND skill_id IS NULL AND skill_version_id IS NULL AND skill_run_id IS NULL
      AND audio_task_run_id IS NULL AND speech_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL
      AND tool_id IS NULL AND tool_version_id IS NULL AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL
      AND ocr_dispatch_id IS NULL AND audio_execution_id IS NULL AND external_audio_dispatch_id IS NULL
      AND metering_unit IN ('rerank_search_units','rerank_tokens') AND input_tokens=0 AND output_tokens=0 AND total_tokens=0
      AND ((usage_state='measured' AND metering_quantity>=0 AND metering_quantity=trunc(metering_quantity))
        OR(usage_state='unmeasured' AND metering_quantity IS NULL))$arm$;
    EXECUTE format('ALTER TABLE sand_ai_usage_event DROP CONSTRAINT %I',prior.conname);
    EXECUTE format('ALTER TABLE sand_ai_usage_event ADD CONSTRAINT %I CHECK (((%s) OR (%s)) IS TRUE)%s',
        prior.conname,prior.expression,professional,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
END $$;

ALTER TABLE sand_ai_admin_validation_attempt DROP CONSTRAINT sand_ai_admin_validation_attempt_capability_code_check;
ALTER TABLE sand_ai_admin_validation_attempt ADD CONSTRAINT sand_ai_admin_validation_attempt_capability_code_check
  CHECK(capability_code IN ('chat','embedding','audio_transcription','audio_speech','rerank'));
ALTER TABLE sand_ai_admin_validation_receipt ADD COLUMN rerank_meter_code varchar(32), ADD COLUMN rerank_quantity bigint;
DO $$
DECLARE prior record;
BEGIN
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_admin_validation_receipt'::regclass AND conname='sand_ai_admin_validation_receipt_check';
    EXECUTE format('ALTER TABLE sand_ai_admin_validation_receipt DROP CONSTRAINT %I',prior.conname);
    EXECUTE format($ddl$ALTER TABLE sand_ai_admin_validation_receipt ADD CONSTRAINT %I CHECK ((
      (rerank_meter_code IS NULL AND rerank_quantity IS NULL AND (%s))
      OR(rerank_meter_code IN ('rerank_search_units','rerank_tokens') AND input_tokens IS NULL AND output_tokens IS NULL AND total_tokens IS NULL
        AND ((usage_measured AND outcome_state='response_known' AND rerank_quantity>=0)
          OR(NOT usage_measured AND rerank_quantity IS NULL)))) IS TRUE)%s$ddl$,
      prior.conname,prior.expression,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
END $$;


CREATE OR REPLACE FUNCTION sand_ai_admin_validation_attempt_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE config sand_ai_config_revision%ROWTYPE; deployment sand_ai_model_deployment%ROWTYPE;
    price sand_ai_price_revision%ROWTYPE; frozen jsonb; actual_rate sand_ai_price_rate%ROWTYPE; meter text;
BEGIN
    IF NEW.target_kind='deployment' THEN
        SELECT * INTO STRICT config FROM sand_ai_config_revision WHERE id=NEW.config_revision_id FOR UPDATE;
        SELECT * INTO STRICT deployment FROM sand_ai_model_deployment WHERE id=NEW.deployment_id;
        IF config.resource_type<>'model_deployment' OR config.resource_id IS DISTINCT FROM NEW.deployment_id
          OR config.revision IS DISTINCT FROM NEW.deployment_revision OR config.state<>'verifying'
          OR config.delete_time IS NOT NULL OR deployment.delete_time IS NOT NULL
          OR deployment.revision IS DISTINCT FROM NEW.deployment_revision
          OR deployment.provider_id IS DISTINCT FROM NEW.provider_id OR deployment.model_id IS DISTINCT FROM NEW.model_id
          OR config.payload->'deployment_verification_claim'->>'attempt_id' IS DISTINCT FROM NEW.attempt_id::text
          OR config.payload->'deployment_verification_claim'->>'admin_actor_id' IS DISTINCT FROM NEW.admin_actor_id::text
          OR config.payload->'deployment_verification_claim'->>'request_sha256' IS DISTINCT FROM NEW.request_sha256::text
          OR config.payload->'deployment_verification_claim'->>'idempotency_hmac' IS DISTINCT FROM NEW.idempotency_hmac::text
          OR config.payload->'deployment_verification_claim'->>'execution_snapshot_digest' IS DISTINCT FROM NEW.execution_snapshot_sha256::text
          OR (NEW.execution_snapshot->'provider'->>'id')::bigint IS DISTINCT FROM NEW.provider_id
          OR (NEW.execution_snapshot->'model'->>'id')::bigint IS DISTINCT FROM NEW.model_id
          OR NEW.execution_snapshot->'model'->>'type' IS DISTINCT FROM NEW.capability_code
          OR (NEW.execution_snapshot->'deployment'->>'id')::bigint IS DISTINCT FROM NEW.deployment_id
          OR (NEW.execution_snapshot->'deployment'->>'revision')::bigint IS DISTINCT FROM NEW.deployment_revision
          OR encode(sha256(convert_to(NEW.execution_snapshot_canonical,'UTF8')),'hex') IS DISTINCT FROM NEW.execution_snapshot_sha256::text THEN
            RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_CLAIM_INVALID';
        END IF;
    ELSE
        IF NEW.execution_snapshot IS DISTINCT FROM jsonb_build_object('version','temporary_validation_execution@1','target_hmac',NEW.target_hmac::text,'body_sha256',NEW.body_sha256::text,'settings',NEW.settings_snapshot)
          OR NEW.request_sha256 IS DISTINCT FROM NEW.execution_snapshot_sha256
          OR encode(sha256(convert_to(sand_ai_admin_validation_json_v130(jsonb_build_object('target',NEW.target_hmac::text,'body',NEW.body_sha256::text,'settings',NEW.settings_snapshot)),'UTF8')),'hex') IS DISTINCT FROM NEW.request_sha256::text THEN
            RAISE EXCEPTION 'SAND_AI_TEMPORARY_VALIDATION_FACTS_INVALID';
        END IF;
    END IF;
    frozen=NEW.call_start_price_snapshot;
    IF NEW.call_start_pricing_state='priced' THEN
        SELECT * INTO STRICT price FROM sand_ai_price_revision WHERE id=NEW.call_start_price_revision_id;
        IF price.state<>'published' OR price.delete_time IS NOT NULL OR price.provider_id IS DISTINCT FROM NEW.provider_id
          OR price.model_id IS DISTINCT FROM NEW.model_id OR price.capability_code IS DISTINCT FROM NEW.capability_code
          OR NEW.call_started_at<price.effective_from OR (price.effective_until IS NOT NULL AND NEW.call_started_at>=price.effective_until)
          OR (frozen->>'snapshot_version')::int IS DISTINCT FROM 1
          OR (frozen->>'price_revision_id')::bigint IS DISTINCT FROM price.id
          OR frozen->>'currency' IS DISTINCT FROM price.currency
          OR (frozen->>'effective_from')::timestamp IS DISTINCT FROM price.effective_from
          OR (frozen->>'effective_until')::timestamp IS DISTINCT FROM price.effective_until THEN
            RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_PRICE_INVALID';
        END IF;
        IF NEW.capability_code='rerank' THEN
            meter=CASE NEW.execution_snapshot->'provider'->>'adapter' WHEN 'cohere_rerank' THEN 'rerank_search_units' WHEN 'jina_rerank' THEN 'rerank_tokens' ELSE NULL END;
            IF meter IS NULL OR frozen->>'metering_unit' IS DISTINCT FROM meter
              OR (SELECT count(*) FROM jsonb_object_keys(frozen->'rates'))<>1
              OR (SELECT count(*) FROM sand_ai_price_rate WHERE price_revision_id=price.id)<>1 THEN
                RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_PRICE_INVALID'; END IF;
        END IF;
        FOREACH meter IN ARRAY (CASE WHEN NEW.capability_code='rerank' THEN ARRAY[meter] ELSE ARRAY['input_tokens','output_tokens'] END) LOOP
            SELECT * INTO STRICT actual_rate FROM sand_ai_price_rate WHERE price_revision_id=price.id AND meter_code=meter;
            IF (frozen->'rates'->meter->>'unit_price')::numeric IS DISTINCT FROM actual_rate.unit_price
              OR (frozen->'rates'->meter->>'per_quantity')::bigint IS DISTINCT FROM actual_rate.per_quantity
              OR actual_rate.per_quantity<=0 OR actual_rate.unit_price<0 THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_RATE_INVALID'; END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_admin_validation_receipt_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE attempt sand_ai_admin_validation_attempt%ROWTYPE; facts jsonb;
BEGIN
    SELECT * INTO STRICT attempt FROM sand_ai_admin_validation_attempt WHERE id=NEW.attempt_id FOR UPDATE;
    facts=jsonb_build_object('attempt_id',NEW.attempt_id,'outcome_state',NEW.outcome_state,'usage_measured',NEW.usage_measured,'input_tokens',NEW.input_tokens,'output_tokens',NEW.output_tokens,'total_tokens',NEW.total_tokens,'error_code',NEW.error_code);
    IF attempt.capability_code='rerank' THEN
        IF NEW.rerank_meter_code IS DISTINCT FROM attempt.call_start_price_snapshot->>'metering_unit'
          OR NEW.input_tokens IS NOT NULL OR NEW.output_tokens IS NOT NULL OR NEW.total_tokens IS NOT NULL
          OR NEW.usage_measured IS DISTINCT FROM (NEW.rerank_quantity IS NOT NULL) THEN
            RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_RECEIPT_INVALID'; END IF;
        facts=facts||jsonb_build_object('rerank_meter_code',NEW.rerank_meter_code,'rerank_quantity',NEW.rerank_quantity);
    ELSIF NEW.rerank_meter_code IS NOT NULL OR NEW.rerank_quantity IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_RECEIPT_INVALID';
    END IF;
    IF encode(sha256(convert_to(sand_ai_admin_validation_json_v130(facts),'UTF8')),'hex') IS DISTINCT FROM NEW.evidence_sha256::text
      OR NEW.create_time<attempt.call_started_at THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_RECEIPT_INVALID'; END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_admin_validation_event_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE attempt sand_ai_admin_validation_attempt%ROWTYPE; receipt sand_ai_admin_validation_receipt%ROWTYPE;
    amount_input numeric; amount_output numeric; snapshot jsonb;
BEGIN
    IF NEW.source_kind<>'admin_verify' THEN RETURN NEW; END IF;
    SELECT * INTO STRICT attempt FROM sand_ai_admin_validation_attempt WHERE id=NEW.admin_validation_attempt_id FOR UPDATE;
    SELECT * INTO STRICT receipt FROM sand_ai_admin_validation_receipt WHERE attempt_id=attempt.id;
    IF receipt.outcome_state<>'response_known' OR NEW.admin_actor_id IS DISTINCT FROM attempt.admin_actor_id
      OR NEW.provider_id IS DISTINCT FROM attempt.provider_id OR NEW.model_id IS DISTINCT FROM attempt.model_id OR NEW.deployment_id IS DISTINCT FROM attempt.deployment_id
      OR NEW.service_action IS DISTINCT FROM attempt.service_action OR NEW.capability_code IS DISTINCT FROM attempt.capability_code
      OR NEW.identity_snapshot IS DISTINCT FROM jsonb_build_object('scope_kind','platform_admin','admin_actor_id',attempt.admin_actor_id)
      OR NEW.provider_snapshot IS DISTINCT FROM coalesce(attempt.execution_snapshot->'provider','[]'::jsonb)
      OR NEW.model_snapshot IS DISTINCT FROM coalesce(attempt.execution_snapshot->'model','[]'::jsonb)
      OR NEW.deployment_snapshot IS DISTINCT FROM coalesce(attempt.execution_snapshot->'deployment','[]'::jsonb)
      OR NEW.idempotency_key IS DISTINCT FROM 'admin_validation:'||attempt.id::text
      OR NEW.input_tokens IS DISTINCT FROM coalesce(receipt.input_tokens,0) OR NEW.output_tokens IS DISTINCT FROM coalesce(receipt.output_tokens,0)
      OR NEW.total_tokens IS DISTINCT FROM coalesce(receipt.total_tokens,0)
      OR NEW.usage_state IS DISTINCT FROM (CASE WHEN receipt.usage_measured THEN 'measured' ELSE 'unmeasured' END)
      OR NEW.reconciliation_state<>'unreconciled' OR NEW.reconciled_amount IS NOT NULL THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_INVALID'; END IF;
    snapshot=attempt.call_start_price_snapshot;
    IF attempt.capability_code='rerank' THEN
        IF NEW.metering_unit IS DISTINCT FROM receipt.rerank_meter_code
          OR NEW.metering_quantity IS DISTINCT FROM receipt.rerank_quantity::numeric
          OR NEW.price_snapshot IS DISTINCT FROM snapshot||jsonb_build_object('called_at',to_char(attempt.call_started_at,'YYYY-MM-DD HH24:MI:SS'),
            'quantity',receipt.rerank_quantity,'metered_amount',CASE WHEN receipt.usage_measured AND attempt.call_start_pricing_state='priced'
              THEN to_char(round(receipt.rerank_quantity::numeric*(snapshot->'rates'->receipt.rerank_meter_code->>'unit_price')::numeric
                /(snapshot->'rates'->receipt.rerank_meter_code->>'per_quantity')::numeric,6),'FM99999999999999999990.000000') ELSE NULL END) THEN
            RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_EVENT_INVALID'; END IF;
        IF receipt.usage_measured AND attempt.call_start_pricing_state='priced' THEN
            amount_input=round(receipt.rerank_quantity::numeric*(snapshot->'rates'->receipt.rerank_meter_code->>'unit_price')::numeric
              /(snapshot->'rates'->receipt.rerank_meter_code->>'per_quantity')::numeric,6);
            IF NEW.pricing_state<>'priced' OR NEW.price_revision_id IS DISTINCT FROM attempt.call_start_price_revision_id
              OR NEW.currency IS DISTINCT FROM snapshot->>'currency' OR NEW.metered_amount IS DISTINCT FROM amount_input THEN
                RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_EVENT_PRICE_INVALID'; END IF;
        ELSE
            IF NEW.pricing_state<>'unpriced' OR NEW.price_revision_id IS NOT NULL OR NEW.currency IS NOT NULL OR NEW.metered_amount IS NOT NULL THEN
                RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_EVENT_UNKNOWN_INVALID'; END IF;
        END IF;
        RETURN NEW;
    END IF;
    IF receipt.usage_measured AND attempt.call_start_pricing_state='priced' THEN
        amount_input=round(receipt.input_tokens::numeric*(snapshot->'rates'->'input_tokens'->>'unit_price')::numeric/(snapshot->'rates'->'input_tokens'->>'per_quantity')::numeric,6);
        amount_output=round(receipt.output_tokens::numeric*(snapshot->'rates'->'output_tokens'->>'unit_price')::numeric/(snapshot->'rates'->'output_tokens'->>'per_quantity')::numeric,6);
        IF NEW.pricing_state<>'priced' OR NEW.price_revision_id IS DISTINCT FROM attempt.call_start_price_revision_id
          OR NEW.currency IS DISTINCT FROM snapshot->>'currency' OR NEW.metered_amount IS DISTINCT FROM amount_input+amount_output
          OR NEW.price_snapshot-'amounts' IS DISTINCT FROM snapshot-'snapshot_version'
          OR (NEW.price_snapshot->'amounts'->>'input_amount')::numeric IS DISTINCT FROM amount_input
          OR (NEW.price_snapshot->'amounts'->>'output_amount')::numeric IS DISTINCT FROM amount_output
          OR (NEW.price_snapshot->'amounts'->>'amount')::numeric IS DISTINCT FROM amount_input+amount_output THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_PRICE_INVALID'; END IF;
    ELSE
        IF NEW.pricing_state<>'unpriced' OR NEW.price_revision_id IS NOT NULL OR NEW.currency IS NOT NULL OR NEW.metered_amount IS NOT NULL
          OR NEW.price_snapshot IS DISTINCT FROM jsonb_build_object('reason',CASE WHEN receipt.usage_measured THEN snapshot->>'reason' ELSE 'provider_token_usage_unavailable' END) THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_UNKNOWN_INVALID'; END IF;
    END IF;
    RETURN NEW;
END $$;


CREATE FUNCTION sand_ai_professional_rerank_usage_guard_v137() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE invocation sand_ai_invocation%ROWTYPE; dispatch sand_ai_invocation_dispatch%ROWTYPE; expected_amount numeric; frozen jsonb;
BEGIN
    IF NEW.capability_code<>'rerank' OR NEW.invocation_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO STRICT invocation FROM sand_ai_invocation WHERE id=NEW.invocation_id;
    SELECT * INTO STRICT dispatch FROM sand_ai_invocation_dispatch WHERE invocation_id=invocation.id;
    frozen=invocation.call_start_price_snapshot;
    IF dispatch.execution_scope<>'rerank' OR dispatch.capability_code<>'rerank' OR dispatch.source_kind<>'knowledge_query'
      OR dispatch.service_action<>'sand_ai.knowledge.query' OR NEW.source_kind<>'knowledge_query' OR NEW.service_action<>'sand_ai.knowledge.query'
      OR dispatch.state<>'dispatching' OR invocation.state<>'running'
      OR NEW.organization_id IS DISTINCT FROM invocation.organization_id OR NEW.application_id IS DISTINCT FROM invocation.application_id
      OR NEW.environment_id IS DISTINCT FROM invocation.environment_id OR NEW.workload_client_id IS DISTINCT FROM invocation.workload_client_id
      OR NEW.provider_id IS DISTINCT FROM dispatch.provider_id OR NEW.model_id IS DISTINCT FROM invocation.model_id OR NEW.deployment_id IS DISTINCT FROM invocation.deployment_id
      OR NEW.metering_unit IS DISTINCT FROM frozen->>'metering_unit'
      OR NEW.provider_snapshot IS DISTINCT FROM jsonb_build_object('id',dispatch.provider_id)
      OR NEW.model_snapshot IS DISTINCT FROM jsonb_build_object('id',dispatch.model_id,'code',dispatch.route_snapshot->>'model_code')
      OR NEW.deployment_snapshot IS DISTINCT FROM jsonb_build_object('id',dispatch.deployment_id,'remote_model',dispatch.route_snapshot->>'deployment_remote_model','route',dispatch.route_snapshot)
      OR NEW.idempotency_key IS DISTINCT FROM 'invocation:'||invocation.id::text
      OR NEW.input_tokens<>0 OR NEW.output_tokens<>0 OR NEW.total_tokens<>0 THEN RAISE EXCEPTION 'SAND_AI_RERANK_USAGE_BINDING_INVALID'; END IF;
    IF NEW.usage_state='measured' AND invocation.call_start_pricing_state='priced' THEN
        expected_amount=round(NEW.metering_quantity*(frozen->'rates'->NEW.metering_unit->>'unit_price')::numeric
          /(frozen->'rates'->NEW.metering_unit->>'per_quantity')::numeric,6);
        IF NEW.pricing_state<>'priced' OR NEW.price_revision_id IS DISTINCT FROM invocation.call_start_price_revision_id
          OR NEW.currency IS DISTINCT FROM frozen->>'currency' OR NEW.metered_amount IS DISTINCT FROM expected_amount THEN
            RAISE EXCEPTION 'SAND_AI_RERANK_USAGE_PRICE_INVALID'; END IF;
    ELSE
        IF NEW.pricing_state<>'unpriced' OR NEW.price_revision_id IS NOT NULL OR NEW.currency IS NOT NULL OR NEW.metered_amount IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_RERANK_USAGE_UNKNOWN_INVALID'; END IF;
    END IF;
    IF NEW.price_snapshot IS DISTINCT FROM frozen||jsonb_build_object('called_at',to_char(invocation.call_started_at,'YYYY-MM-DD HH24:MI:SS'),
      'quantity',NEW.metering_quantity,'metered_amount',CASE WHEN expected_amount IS NOT NULL
        THEN to_char(expected_amount,'FM99999999999999999990.000000') ELSE NULL END) THEN
        RAISE EXCEPTION 'SAND_AI_RERANK_USAGE_SNAPSHOT_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_professional_rerank_usage_guard_v137 BEFORE INSERT ON sand_ai_usage_event
  FOR EACH ROW EXECUTE FUNCTION sand_ai_professional_rerank_usage_guard_v137();


INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610060137_knowledge_professional_rerank','333cf404485a8b3cf13d1e473375fa29963a841a7eafb7c38b16cd3e1ba44053',CURRENT_TIMESTAMP(0));

-- SOURCE only. Soft unknown cost is recorded without a fabricated monetary reservation.
CREATE TABLE sand_ai_rerank_soft_budget (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    policy_id bigint NOT NULL REFERENCES sand_ai_budget_policy(id),
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id),
    request_fingerprint char(64) NOT NULL CHECK(request_fingerprint ~ '^[a-f0-9]{64}$'),
    policy_revision integer NOT NULL CHECK(policy_revision>0),
    currency char(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
    price_revision_id bigint NULL REFERENCES sand_ai_price_revision(id),
    metering_unit varchar(32) NOT NULL CHECK(metering_unit IN ('rerank_search_units','rerank_tokens')),
    state varchar(16) NOT NULL CHECK(state IN ('prepared','held','settled','released')),
    metered_amount numeric(20,6) NULL,
    expires_at timestamp(0) without time zone NULL,
    snapshot jsonb NOT NULL CHECK(jsonb_typeof(snapshot)='object'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT uk_sand_ai_rerank_soft_budget_member UNIQUE(invocation_id,policy_id),
    CONSTRAINT ck_sand_ai_rerank_soft_budget_cost CHECK((
      (state='prepared' AND metered_amount IS NULL AND expires_at IS NOT NULL)
      OR(state='held' AND metered_amount IS NULL AND expires_at IS NULL)
      OR(state='released' AND metered_amount IS NULL AND expires_at IS NULL)
      OR(state='settled' AND metered_amount>=0 AND price_revision_id IS NOT NULL AND expires_at IS NULL)) IS TRUE)
);
CREATE INDEX idx_sand_ai_rerank_soft_budget_pending ON sand_ai_rerank_soft_budget(policy_id,state)
    WHERE state IN ('prepared','held');

CREATE FUNCTION sand_ai_rerank_soft_budget_guard_v138() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner sand_ai_invocation%ROWTYPE; ledger sand_ai_invocation_dispatch%ROWTYPE;
    policy sand_ai_budget_policy%ROWTYPE; usage sand_ai_usage_event%ROWTYPE;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Original soft cost obligations cannot be deleted'; END IF;
    IF TG_OP='UPDATE' THEN
        IF (NEW.id,NEW.policy_id,NEW.invocation_id,NEW.request_fingerprint,NEW.policy_revision,NEW.currency,
            NEW.price_revision_id,NEW.metering_unit,NEW.snapshot,NEW.create_time)
          IS DISTINCT FROM (OLD.id,OLD.policy_id,OLD.invocation_id,OLD.request_fingerprint,OLD.policy_revision,OLD.currency,
            OLD.price_revision_id,OLD.metering_unit,OLD.snapshot,OLD.create_time)
          OR OLD.state IN ('settled','released')
          OR NEW.state NOT IN ('held','settled','released')
          OR (OLD.state='held' AND NEW.state='released') THEN
            RAISE EXCEPTION 'Soft cost identity or terminal state changed';
        END IF;
    END IF;
    SELECT * INTO STRICT owner FROM sand_ai_invocation WHERE id=NEW.invocation_id;
    SELECT * INTO STRICT ledger FROM sand_ai_invocation_dispatch WHERE invocation_id=NEW.invocation_id;
    IF owner.request_fingerprint IS DISTINCT FROM NEW.request_fingerprint
      OR ledger.execution_scope<>'rerank' OR ledger.capability_code<>'rerank'
      OR ledger.source_kind<>'knowledge_query' OR ledger.service_action<>'sand_ai.knowledge.query'
      OR NEW.snapshot->>'body_sha256' IS DISTINCT FROM ledger.request_body_digest
      OR NEW.snapshot->>'route_sha256' IS DISTINCT FROM ledger.route_snapshot->>'snapshot_sha256'
      OR NEW.snapshot->'price' IS DISTINCT FROM ledger.price_snapshot
      OR NEW.price_revision_id IS DISTINCT FROM owner.call_start_price_revision_id
      OR NEW.metering_unit IS DISTINCT FROM owner.call_start_price_snapshot->>'metering_unit'
      OR NEW.snapshot->>'enforcement' IS DISTINCT FROM 'soft'
      OR NEW.snapshot->>'policy_revision' IS DISTINCT FROM NEW.policy_revision::text
      OR (NEW.snapshot->>'unpriced_policy' IN ('block','allow')) IS NOT TRUE
      OR (NEW.snapshot->>'unmeasured_policy' IN ('hold','allow')) IS NOT TRUE THEN
        RAISE EXCEPTION 'Soft cost obligation differs from the original professional dispatch';
    END IF;
    IF TG_OP='INSERT' THEN
        SELECT * INTO STRICT policy FROM sand_ai_budget_policy WHERE id=NEW.policy_id;
        IF NEW.state<>'prepared' OR owner.state<>'running' OR ledger.state<>'prepared'
          OR ledger.dispatch_start_time IS NOT NULL OR policy.enforcement<>'soft'
          OR policy.state<>'published' OR policy.delete_time IS NOT NULL
          OR policy.revision<>NEW.policy_revision OR policy.currency<>NEW.currency
          OR NEW.snapshot->>'unpriced_policy' IS DISTINCT FROM policy.unpriced_policy
          OR NEW.snapshot->>'unmeasured_policy' IS DISTINCT FROM policy.unmeasured_policy
          OR (NEW.price_revision_id IS NULL AND policy.unpriced_policy='block') THEN
            RAISE EXCEPTION 'Soft cost admission is not current and unsent';
        END IF;
    ELSIF NEW.state='settled' THEN
        SELECT * INTO STRICT usage FROM sand_ai_usage_event WHERE invocation_id=NEW.invocation_id;
        IF ledger.dispatch_start_time IS NULL OR usage.capability_code<>'rerank'
          OR usage.source_kind<>'knowledge_query' OR usage.usage_state<>'measured'
          OR usage.metering_unit<>NEW.metering_unit OR usage.price_revision_id IS DISTINCT FROM NEW.price_revision_id
          OR usage.currency IS DISTINCT FROM NEW.currency OR usage.metered_amount IS DISTINCT FROM NEW.metered_amount THEN
            RAISE EXCEPTION 'Soft settlement must match the immutable actual usage and original price';
        END IF;
    ELSIF NEW.state='held' AND ledger.dispatch_start_time IS NULL THEN
        RAISE EXCEPTION 'Unsent soft obligations must release instead of holding unknown usage';
    ELSIF NEW.state='released' AND EXISTS(SELECT 1 FROM sand_ai_usage_event WHERE invocation_id=NEW.invocation_id) THEN
        RAISE EXCEPTION 'Recorded provider usage cannot release a soft cost obligation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_rerank_soft_budget_guard_v138 BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_rerank_soft_budget
    FOR EACH ROW EXECUTE FUNCTION sand_ai_rerank_soft_budget_guard_v138();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610060138_knowledge_professional_rerank_soft_budget','f8092a02855fa55546b93321f37b35901b7e919a24728f1a3bb3a4ab2f22cdb3',CURRENT_TIMESTAMP(0));


-- SOURCE ONLY: existing authorized database upgrade; does not publish an environment profile.
-- Independent planning, complete analysis, and private-template drafting retain the existing Neuron workflow.
ALTER TABLE sand_ai_legal_analysis_profile
    DROP CONSTRAINT sand_ai_legal_analysis_profile_code_ck;
ALTER TABLE sand_ai_legal_analysis_profile
    ADD CONSTRAINT sand_ai_legal_analysis_profile_code_ck
    CHECK (code IN ('legal_research', 'litigation_preparation', 'judgment_analysis', 'complaint_drafting', 'legal_research_planning', 'judgment_remedy_planning', 'judgment_remedy_analysis', 'judgment_remedy_drafting'));

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610060139_judgment_remedies_profiles','9e89d2bd1c23757059fb8e862eca28ab1b0606a7776944839d4b6596d676dd60',CURRENT_TIMESTAMP(0));



-- SOURCE ONLY. Normal lifecycle applies this migration; no runtime schema creation.
ALTER TABLE sand_ai_tool_run ADD COLUMN content_purged_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_tool_outcome_receipt ADD COLUMN content_purged_time timestamp(0) without time zone NULL;

CREATE TABLE sand_ai_tool_fulltext_file (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    tool_run_id bigint NOT NULL REFERENCES sand_ai_tool_run(id),
    file_id bigint NOT NULL REFERENCES sand_ai_file(id),
    environment_id bigint NOT NULL,
    content_sha256 char(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_tool_fulltext_file_run UNIQUE(tool_run_id),
    CONSTRAINT ck_sand_ai_tool_fulltext_file_identity CHECK(environment_id>0 AND content_sha256 ~ '^[0-9a-f]{64}$')
);
CREATE INDEX idx_sand_ai_tool_fulltext_file_file ON sand_ai_tool_fulltext_file(environment_id,file_id,tool_run_id);

ALTER TABLE sand_ai_tool_run DROP CONSTRAINT ck_sand_ai_tool_run_terminal;
ALTER TABLE sand_ai_tool_run ADD CONSTRAINT ck_sand_ai_tool_run_terminal CHECK (
    (state='running' AND completed_time IS NULL AND error_code IS NULL)
    OR (state='succeeded' AND completed_time IS NOT NULL AND citations IS NOT NULL AND metering IS NOT NULL AND error_code IS NULL
        AND ((result IS NOT NULL AND content_purged_time IS NULL)
          OR (result IS NULL AND content_purged_time IS NOT NULL AND execution_snapshot->>'result_visibility'='private_fulltext')))
    OR (state IN ('failed','ambiguous') AND completed_time IS NOT NULL AND result IS NULL AND error_code IS NOT NULL AND error_detail IS NOT NULL)
);
ALTER TABLE sand_ai_tool_run ADD CONSTRAINT ck_sand_ai_tool_fulltext_purged_v140 CHECK (
    content_purged_time IS NULL OR
    (state='succeeded' AND result IS NULL AND execution_snapshot->>'result_visibility'='private_fulltext')
);

ALTER TABLE sand_ai_tool_outcome_receipt
    ALTER COLUMN key_id DROP NOT NULL, ALTER COLUMN nonce DROP NOT NULL, ALTER COLUMN tag DROP NOT NULL,
    ALTER COLUMN ciphertext DROP NOT NULL, ALTER COLUMN plaintext_bytes DROP NOT NULL,
    ALTER COLUMN ciphertext_bytes DROP NOT NULL, ALTER COLUMN content_hmac DROP NOT NULL;
ALTER TABLE sand_ai_tool_outcome_receipt DROP CONSTRAINT ck_sand_ai_tool_outcome_receipt_crypto;
ALTER TABLE sand_ai_tool_outcome_receipt DROP CONSTRAINT ck_sand_ai_tool_outcome_receipt_state;
ALTER TABLE sand_ai_tool_outcome_receipt ADD CONSTRAINT ck_sand_ai_tool_outcome_receipt_state CHECK(state IN ('available','destroyed'));
ALTER TABLE sand_ai_tool_outcome_receipt ADD CONSTRAINT ck_sand_ai_tool_outcome_receipt_crypto CHECK (
    receipt_version='v1' AND cipher_version='v1' AND (
      (state='available' AND content_purged_time IS NULL AND key_id IS NOT NULL AND nonce IS NOT NULL AND tag IS NOT NULL
       AND ciphertext IS NOT NULL AND plaintext_bytes IS NOT NULL AND ciphertext_bytes IS NOT NULL AND content_hmac IS NOT NULL
       AND octet_length(nonce)=12 AND octet_length(tag)=16 AND octet_length(content_hmac)=32
       AND plaintext_bytes>0 AND ciphertext_bytes=octet_length(ciphertext))
      OR (state='destroyed' AND content_purged_time IS NOT NULL AND key_id IS NULL AND nonce IS NULL AND tag IS NULL
       AND ciphertext IS NULL AND plaintext_bytes IS NULL AND ciphertext_bytes IS NULL AND content_hmac IS NULL)
    )
);

-- Immutable protocol quantity and terminal success. Unpriced amounts remain recorded unchanged.
CREATE FUNCTION sand_ai_tool_fulltext_settled_v140(run_id bigint, destroyed_receipt boolean) RETURNS boolean
LANGUAGE sql STABLE AS $$
SELECT EXISTS (
    SELECT 1 FROM sand_ai_tool_run r
    JOIN sand_ai_tool_attempt a ON a.tool_run_id=r.id AND a.attempt_no=1
    JOIN sand_ai_tool_outcome_receipt receipt ON receipt.tool_run_id=r.id
    JOIN sand_ai_tool_source source ON source.tool_run_id=r.id AND source.sequence_no=1
    JOIN sand_ai_usage_event usage ON usage.tool_run_id=r.id
    JOIN sand_ai_tool_usage_seal seal ON seal.owner_kind='runtime' AND seal.owner_id=r.id
    WHERE r.id=run_id AND r.state='succeeded' AND r.content_purged_time IS NULL
      AND r.execution_snapshot->>'result_visibility'='private_fulltext'
      AND r.execution_snapshot->>'adapter'='mcp_streamable_http'
      AND jsonb_typeof(r.result->'content')='string' AND jsonb_typeof(r.result->'title')='string'
      AND r.completed_time IS NOT NULL AND r.lease_expires_at IS NULL AND r.audit_log_id IS NOT NULL
      AND a.state='succeeded' AND a.completed_time IS NOT NULL AND a.dispatched_time IS NOT NULL
      AND a.http_status BETWEEN 200 AND 299
      AND receipt.environment_id=r.environment_id AND receipt.tool_id=r.tool_id AND receipt.tool_version_id=r.tool_version_id
      AND receipt.request_fingerprint=r.request_fingerprint
      AND ((NOT destroyed_receipt AND receipt.state='available' AND receipt.content_purged_time IS NULL)
        OR (destroyed_receipt AND receipt.state='destroyed' AND receipt.content_purged_time IS NOT NULL AND receipt.ciphertext IS NULL))
      AND usage.environment_id=r.environment_id AND usage.tool_id=r.tool_id AND usage.tool_version_id=r.tool_version_id
      AND usage.tool_connection_id=r.tool_connection_id AND usage.tool_connection_revision_id=r.tool_connection_revision_id
      AND usage.organization_id IS NOT DISTINCT FROM r.organization_id
      AND usage.application_id IS NOT DISTINCT FROM r.application_id
      AND usage.workload_client_id IS NOT DISTINCT FROM r.workload_client_id
      AND usage.identity_snapshot=r.identity_snapshot AND usage.service_action='sand_ai.tool.run'
      AND usage.source_kind='tool' AND usage.capability_code='external_readonly' AND usage.usage_state='measured'
      AND usage.metering_unit='external_request' AND usage.metering_quantity=seal.usage_quantity
      AND seal.dispatch_count=3 AND seal.usage_quantity=seal.dispatch_count
      AND r.metering->>'unit'='external_request' AND (r.metering->>'quantity')::numeric=usage.metering_quantity
      AND jsonb_array_length(r.citations)=1 AND r.citations->0->>'source_ref' ~ '^legal_fulltext:[0-9a-f]{64}$'
      AND r.citations->0->'locator'->>'type'='opaque'
      AND r.citations->0->'locator'->>'value'=r.citations->0->>'source_ref'
      AND r.citations->0->>'content_hash'=encode(sha256(convert_to(r.result->>'content','UTF8')),'hex')
      AND (SELECT count(*) FROM sand_ai_tool_source s WHERE s.tool_run_id=r.id)=1
      AND r.citations->0=jsonb_build_object('source_ref',source.source_ref,'locator',source.locator,
          'source_version',source.source_version,'content_hash',source.content_hash)
      AND (SELECT count(*) FROM sand_ai_tool_protocol_request p WHERE p.tool_run_id=r.id)=6
      AND NOT EXISTS (
          SELECT 1 FROM sand_ai_tool_protocol_request p WHERE p.tool_run_id=r.id
          AND (p.sequence_no NOT BETWEEN 1 AND 3
            OR p.request_kind<>CASE p.sequence_no WHEN 1 THEN 'initialize' WHEN 2 THEN 'initialized' WHEN 3 THEN 'tools_call' END
            OR (p.event_kind='response_known' AND (p.http_status NOT BETWEEN 200 AND 299
              OR (p.sequence_no=2 AND p.http_status<>202)
              OR (p.sequence_no=3 AND p.http_status<>a.http_status)))
            OR NOT EXISTS(SELECT 1 FROM sand_ai_tool_protocol_request q WHERE q.tool_run_id=p.tool_run_id
                AND q.sequence_no=p.sequence_no AND q.event_kind=CASE p.event_kind
                    WHEN 'dispatch_started' THEN 'response_known' ELSE 'dispatch_started' END))
      )
);
$$;

CREATE FUNCTION sand_ai_tool_fulltext_file_insert_v140() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    -- File-first locking matches application bind/delete and prevents deleting/binding races.
    PERFORM id FROM sand_ai_file WHERE id=NEW.file_id FOR UPDATE;
    PERFORM id FROM sand_ai_tool_run WHERE id=NEW.tool_run_id FOR UPDATE;
    IF NOT sand_ai_tool_fulltext_settled_v140(NEW.tool_run_id,false) OR NOT EXISTS (
        SELECT 1 FROM sand_ai_tool_run r JOIN sand_ai_file f ON f.id=NEW.file_id
        WHERE r.id=NEW.tool_run_id AND r.environment_id=NEW.environment_id AND f.environment_id=NEW.environment_id
          AND f.status=1 AND f.delete_time IS NULL AND f.state IN ('uploaded','parsing','parsed','parse_failed','parse_canceled')
          AND f.owner_attribution->'identity_snapshot'=r.identity_snapshot
          AND NEW.content_sha256=f.sha256
          AND NEW.content_sha256=encode(sha256(convert_to(r.result->>'content','UTF8')),'hex')
    ) THEN RAISE EXCEPTION 'Fulltext file binding needs original terminal receipt, usage and uploaded bytes'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_tool_fulltext_file_insert_v140 BEFORE INSERT ON sand_ai_tool_fulltext_file
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_fulltext_file_insert_v140();
CREATE TRIGGER trg_sand_ai_tool_fulltext_file_immutable_v140 BEFORE UPDATE OR DELETE ON sand_ai_tool_fulltext_file
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_immutable_guard();

CREATE FUNCTION sand_ai_tool_fulltext_deleted_file_v140(run_id bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
SELECT EXISTS (
    SELECT 1 FROM sand_ai_tool_fulltext_file link
    JOIN sand_ai_tool_run r ON r.id=link.tool_run_id
    JOIN sand_ai_file f ON f.id=link.file_id
    WHERE link.tool_run_id=run_id AND f.environment_id=link.environment_id AND r.environment_id=link.environment_id
      AND f.state='deleted' AND f.status=2 AND f.delete_time IS NOT NULL
      AND f.owner_attribution->'identity_snapshot'=r.identity_snapshot
      AND link.content_sha256=f.sha256
      AND link.content_sha256=encode(sha256(convert_to(r.result->>'content','UTF8')),'hex')
);
$$;

-- Exact terminal mutation exception: result bytes and tombstone only, all original evidence stays.
CREATE OR REPLACE FUNCTION sand_ai_tool_run_terminal_immutable_v023() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'tool run records are append-only' USING ERRCODE='55000'; END IF;
    IF OLD.state IN ('succeeded','failed','ambiguous') AND NEW IS DISTINCT FROM OLD THEN
        IF OLD.state='succeeded' AND OLD.content_purged_time IS NULL AND OLD.result IS NOT NULL
          AND NEW.result IS NULL AND NEW.content_purged_time=CURRENT_TIMESTAMP(0) AND NEW.update_time=CURRENT_TIMESTAMP(0)
          AND (to_jsonb(NEW)-ARRAY['result','content_purged_time','update_time'])
            IS NOT DISTINCT FROM (to_jsonb(OLD)-ARRAY['result','content_purged_time','update_time'])
          AND sand_ai_tool_fulltext_settled_v140(OLD.id,true)
          AND sand_ai_tool_fulltext_deleted_file_v140(OLD.id) THEN RETURN NEW; END IF;
        RAISE EXCEPTION 'terminal tool run evidence is immutable' USING ERRCODE='55000';
    END IF;
    RETURN NEW;
END $$;

CREATE FUNCTION sand_ai_tool_fulltext_receipt_purge_v140() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' AND OLD.state='available' AND OLD.content_purged_time IS NULL
      AND NEW.state='destroyed' AND NEW.content_purged_time=CURRENT_TIMESTAMP(0)
      AND NEW.key_id IS NULL AND NEW.nonce IS NULL AND NEW.tag IS NULL AND NEW.ciphertext IS NULL
      AND NEW.plaintext_bytes IS NULL AND NEW.ciphertext_bytes IS NULL AND NEW.content_hmac IS NULL
      AND (to_jsonb(NEW)-ARRAY['state','content_purged_time','key_id','nonce','tag','ciphertext','plaintext_bytes','ciphertext_bytes','content_hmac'])
        IS NOT DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','content_purged_time','key_id','nonce','tag','ciphertext','plaintext_bytes','ciphertext_bytes','content_hmac'])
      AND sand_ai_tool_fulltext_settled_v140(OLD.tool_run_id,false)
      AND sand_ai_tool_fulltext_deleted_file_v140(OLD.tool_run_id) THEN RETURN NEW; END IF;
    RAISE EXCEPTION 'SAND_AI_TOOL_EVIDENCE_IMMUTABLE';
END $$;
DROP TRIGGER trg_sand_ai_tool_receipt_immutable ON sand_ai_tool_outcome_receipt;
CREATE TRIGGER trg_sand_ai_tool_receipt_immutable BEFORE UPDATE OR DELETE ON sand_ai_tool_outcome_receipt
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_fulltext_receipt_purge_v140();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610060140_tool_fulltext_file_lifecycle','3654997477ca5682d29807de9a7b474b5d8a3353eef9298ca8d528cfb841f3fb',CURRENT_TIMESTAMP(0));

-- 202610070141_composition_workflow
-- SOURCE only; no migration is executed by this delivery.
CREATE TABLE sand_ai_composition_definition (
 id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
 environment_id bigint NOT NULL, code varchar(64) NOT NULL, name varchar(100) NOT NULL,
 state varchar(16) NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','published','disabled')),
 status smallint NOT NULL DEFAULT 1 CHECK(status IN (1,2)), active_version_id bigint NULL,
 created_by varchar(64) NOT NULL, updated_by varchar(64) NOT NULL,
 create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
 update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0), delete_time timestamp(0) without time zone NULL,
 CONSTRAINT sand_ai_composition_definition_environment_code_uq UNIQUE(environment_id,code)
);
CREATE TABLE sand_ai_composition_version (
 id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
 definition_id bigint NOT NULL REFERENCES sand_ai_composition_definition(id), version_no integer NOT NULL CHECK(version_no>0),
 state varchar(16) NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','published')), revision integer NOT NULL DEFAULT 1 CHECK(revision>0),
 graph jsonb NOT NULL CHECK(jsonb_typeof(graph)='object'), snapshot jsonb NULL, snapshot_sha256 char(64) NOT NULL,
 published_time timestamp(0) without time zone NULL, created_by varchar(64) NOT NULL,
 create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0), update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
 CONSTRAINT sand_ai_composition_version_definition_no_uq UNIQUE(definition_id,version_no),
 CONSTRAINT sand_ai_composition_version_definition_id_uq UNIQUE(definition_id,id),
 CONSTRAINT sand_ai_composition_version_published_ck CHECK((state='draft' AND snapshot IS NULL AND published_time IS NULL) OR (state='published' AND snapshot IS NOT NULL AND published_time IS NOT NULL))
);
CREATE UNIQUE INDEX sand_ai_composition_version_draft_uq ON sand_ai_composition_version(definition_id) WHERE state='draft';
ALTER TABLE sand_ai_composition_definition ADD CONSTRAINT sand_ai_composition_definition_active_fk FOREIGN KEY(id,active_version_id) REFERENCES sand_ai_composition_version(definition_id,id);
CREATE TABLE sand_ai_composition_operation (
 id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, environment_id bigint NOT NULL,
 operation varchar(16) NOT NULL CHECK(operation IN ('draft','publish','disable')),
 request_key varchar(128) NOT NULL, actor_ref varchar(64) NOT NULL, fingerprint char(64) NOT NULL,
 definition_id bigint NOT NULL REFERENCES sand_ai_composition_definition(id), response jsonb NOT NULL,
 create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
 CONSTRAINT sand_ai_composition_operation_key_uq UNIQUE(environment_id,operation,request_key)
);
CREATE TABLE sand_ai_composition_run (
 id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, environment_id bigint NOT NULL,
 organization_id varchar(64) NOT NULL, application_id varchar(64) NOT NULL, workload_client_id varchar(64) NOT NULL,
 version_id bigint NOT NULL REFERENCES sand_ai_composition_version(id), request_key varchar(128) NOT NULL,
 fingerprint char(64) NOT NULL, snapshot_sha256 char(64) NOT NULL,
 state varchar(20) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','running','waiting','partial_failed','succeeded','cancelled')),
 encrypted_input jsonb NULL, input_sha256 char(64) NOT NULL, retain_until timestamp(0) without time zone NOT NULL,
 content_purged_time timestamp(0) without time zone NULL,
 lease_token varchar(64) NULL, lease_until timestamp(0) without time zone NULL,
 create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0), update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
 CONSTRAINT sand_ai_composition_run_scope_key_uq UNIQUE(environment_id,organization_id,application_id,workload_client_id,request_key)
);
CREATE TABLE sand_ai_composition_node (
 id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, run_id bigint NOT NULL REFERENCES sand_ai_composition_run(id),
 node_code varchar(32) NOT NULL, request_key varchar(128) NOT NULL,
 state varchar(20) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','submitting','waiting','succeeded','failed','unknown')),
 child jsonb NULL, input_sha256 char(64) NULL, output_sha256 char(64) NULL, error_code varchar(128) NULL,
 create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0), update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
 CONSTRAINT sand_ai_composition_node_run_code_uq UNIQUE(run_id,node_code), CONSTRAINT sand_ai_composition_node_key_uq UNIQUE(request_key)
);
CREATE TABLE sand_ai_composition_run_operation (
 id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, run_id bigint NOT NULL REFERENCES sand_ai_composition_run(id),
 operation varchar(40) NOT NULL, request_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL,
 state varchar(16) NOT NULL CHECK(state IN ('started','finished')), response jsonb NULL,
 create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
 CONSTRAINT sand_ai_composition_run_operation_key_uq UNIQUE(run_id,request_key)
);
CREATE INDEX sand_ai_composition_run_retention_idx ON sand_ai_composition_run(retain_until) WHERE encrypted_input IS NOT NULL;
CREATE FUNCTION sand_ai_composition_immutable_published() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.state='published' THEN RAISE EXCEPTION 'Published AI composition versions are immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_composition_version_immutable BEFORE UPDATE OR DELETE ON sand_ai_composition_version FOR EACH ROW EXECUTE FUNCTION sand_ai_composition_immutable_published();

-- Register this feature in the existing exact-owned SandAdmin navigation inventory.
DO $composition_navigation$
DECLARE root_id bigint; page_id bigint; node_id bigint; matches integer; specification record;
BEGIN
 LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
 SELECT count(*),min(id) INTO matches,root_id FROM sand_system_menu WHERE code='SandAI' AND parent_id=0 AND type=1 AND path='/sand-ai' AND status=1 AND delete_time IS NULL;
 IF matches<>1 THEN RAISE EXCEPTION 'Exact SandAI root menu is required'; END IF;
 IF EXISTS(SELECT 1 FROM sand_system_menu WHERE code='SandAIComposition' OR path='ai-workflows') THEN RAISE EXCEPTION 'AI composition menu exists; explicit adoption required'; END IF;
 INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
 VALUES(root_id,'AI 工作流','SandAIComposition','',2,'ai-workflows','/plugin/sand-ai/composition/index','',45,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id INTO page_id;
 INSERT INTO sand_ai_configuration_navigation_ownership SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by'] FROM sand_system_menu menu WHERE id=page_id;
 FOR specification IN SELECT * FROM (VALUES ('查看 AI 工作流','sand_ai:agent:composition:read',1),('编辑和发布 AI 工作流','sand_ai:agent:composition:manage',2)) AS permissions(name,code,sort) LOOP
  IF EXISTS(SELECT 1 FROM sand_system_menu WHERE code=specification.code OR slug=specification.code) THEN RAISE EXCEPTION 'AI composition permission exists; explicit adoption required'; END IF;
  INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
  VALUES(page_id,specification.name,specification.code,specification.code,3,'','','',specification.sort,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id INTO node_id;
  INSERT INTO sand_ai_configuration_navigation_ownership SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by'] FROM sand_system_menu menu WHERE id=node_id;
 END LOOP;
END $composition_navigation$;

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610070141_composition_workflow','ed3e884f59d6bcb357bc5969fe1c99f1bb5c4eccb35966553b91540fbedfda0a',CURRENT_TIMESTAMP(0));

CREATE TABLE sand_ai_billing_operation_receipt (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    admin_id bigint NOT NULL CHECK (admin_id > 0),
    operation varchar(16) NOT NULL CHECK (operation IN ('import','sync','review')),
    target_id bigint NOT NULL CHECK (target_id > 0),
    request_key varchar(128) NOT NULL CHECK (length(btrim(request_key)) > 0),
    input_sha256 varchar(64) NOT NULL CHECK (input_sha256 ~ '^[a-f0-9]{64}$'),
    state varchar(16) NOT NULL CHECK (state IN ('prepared','started','rejected')),
    claim_token varchar(64) NULL CHECK (claim_token IS NULL OR claim_token ~ '^[a-f0-9]{64}$'),
    error_code varchar(96) NULL,
    create_time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (admin_id,operation,target_id,request_key),
    CHECK ((state='rejected' AND error_code IS NOT NULL) OR (operation='sync' AND state IN ('prepared','started') AND claim_token IS NOT NULL AND error_code IS NULL))
);
CREATE INDEX idx_sand_ai_billing_operation_original ON sand_ai_billing_operation_receipt (target_id,request_key,operation);
COMMENT ON TABLE sand_ai_billing_operation_receipt IS 'Nonfinancial original operation claim/rejection; completed facts stay exclusively in original billing ledger';
CREATE FUNCTION sand_ai_billing_operation_receipt_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Billing original operation proof cannot be deleted'; END IF;
    IF OLD.state<>'prepared' OR OLD.operation<>'sync' OR NEW.state NOT IN ('started','rejected')
        OR (to_jsonb(NEW)-ARRAY['state','error_code','update_time']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','error_code','update_time']) THEN
        RAISE EXCEPTION 'Billing original operation facts are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_billing_operation_receipt_guard BEFORE UPDATE OR DELETE ON sand_ai_billing_operation_receipt
FOR EACH ROW EXECUTE FUNCTION sand_ai_billing_operation_receipt_guard();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610070142_billing_operation_receipt','901eb42d0fd771367feaf664d27718a67f4c5c9c0d2c1a8ead35bc36d4591163',CURRENT_TIMESTAMP(0));

-- Forward repair for applied 120. Keep its checksum, terminal proof and
-- terminal-step projection unchanged. A historical dispatch is safe to retain
-- only when its exact node success, request and usage were durably sealed.
CREATE OR REPLACE FUNCTION sand_ai_meeting_expired_reclaim_dispatch_sealed(dispatch_key bigint)
RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS (
        SELECT 1 FROM sand_ai_invocation_dispatch d
        JOIN sand_ai_task_run t ON t.id=d.parent_task_run_id
        JOIN sand_ai_task_step s ON s.id=d.parent_task_step_id AND s.task_id=t.id
        JOIN sand_ai_meeting_summary_node n ON n.task_id=t.id AND n.task_step_id=s.id
            AND n.node_code=d.workflow_node_code AND n.last_invocation_id=d.invocation_id
        JOIN sand_ai_invocation i ON i.id=d.invocation_id
        WHERE d.id=dispatch_key AND d.state='succeeded' AND d.execution_scope='ai_task'
          AND d.environment_id=t.environment_id AND n.dispatch_scope=d.execution_scope
          AND s.sequence_no>1 AND s.state='succeeded' AND s.step_code=n.node_code
          AND s.attempt_count>=1 AND s.status=1 AND s.delete_time IS NULL
          AND n.result_sha256 IS NOT NULL AND n.result_snapshot IS NOT NULL
          AND n.result_snapshot->>'invocation_id'=n.last_invocation_id::text
          AND n.result_snapshot->>'request_id'=format('task_%s_%s_attempt_%s',n.task_id,n.node_code,s.attempt_count)
          AND i.state='succeeded' AND i.environment_id=t.environment_id
          AND i.request_id=n.result_snapshot->>'request_id'
          AND EXISTS (SELECT 1 FROM sand_ai_usage_event u
              WHERE u.invocation_id=i.id AND u.environment_id=i.environment_id)
    );
$$;

-- Prepared alone proves neither terminal invocation nor released budget. Only
-- the durable zero-send closure may stop protecting its original reservations.
-- Failed provider outcomes and unknown dispatches never satisfy this proof.
CREATE OR REPLACE FUNCTION sand_ai_meeting_expired_reclaim_dispatch_unsent_closed(dispatch_key bigint)
RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS (
        SELECT 1 FROM sand_ai_invocation_dispatch d
        JOIN sand_ai_task_run t ON t.id=d.parent_task_run_id
        JOIN sand_ai_task_step s ON s.id=d.parent_task_step_id AND s.task_id=t.id
        JOIN sand_ai_meeting_summary_node n ON n.task_id=t.id AND n.task_step_id=s.id
            AND n.node_code=d.workflow_node_code
        JOIN sand_ai_invocation i ON i.id=d.invocation_id
        WHERE d.id=dispatch_key AND d.state='not_dispatched' AND d.execution_scope='ai_task'
          AND d.dispatch_start_time IS NULL AND d.dispatch_complete_time IS NOT NULL
          AND d.environment_id=t.environment_id AND n.dispatch_scope=d.execution_scope
          AND s.sequence_no>1 AND s.step_code=n.node_code AND s.status=1 AND s.delete_time IS NULL
          AND n.result_sha256 IS NULL AND n.result_snapshot IS NULL AND n.last_invocation_id IS NULL
          AND i.state='failed' AND i.completed_at IS NOT NULL AND i.environment_id=t.environment_id
          AND i.organization_id=t.organization_id AND i.application_id=t.application_id
          AND i.workload_client_id=t.workload_client_id AND d.application_id=t.application_id
          AND i.model_id=d.model_id AND i.deployment_id=d.deployment_id
          AND i.request_fingerprint ~ '^[a-f0-9]{64}$'
          AND d.logical_request_id=encode(sha256(convert_to(i.request_id,'UTF8')),'hex')
          AND NOT EXISTS (SELECT 1 FROM sand_ai_usage_event u WHERE u.invocation_id=i.id)
          AND NOT EXISTS (SELECT 1 FROM sand_ai_budget_reservation b
              WHERE b.invocation_id=i.id AND (b.state<>'released'
                  OR b.request_id IS DISTINCT FROM i.request_id
                  OR b.request_fingerprint IS DISTINCT FROM i.request_fingerprint
                  OR b.price_revision_id IS DISTINCT FROM i.call_start_price_revision_id))
          AND NOT EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch other
              WHERE other.environment_id=d.environment_id AND other.logical_execution_id=d.logical_execution_id
                AND other.id<>d.id AND (other.dispatch_start_time IS NOT NULL OR other.state<>'not_dispatched'))
    );
$$;

CREATE OR REPLACE FUNCTION sand_ai_meeting_terminal_capture() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE zone text; mode text;
BEGIN
    IF OLD.state=NEW.state OR NEW.state NOT IN ('failed','canceled')
       OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=OLD.id) THEN RETURN NEW; END IF;
    zone:=current_setting('sand_ai.running_timezone',true);
    IF zone IS NULL OR NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name=zone)
       OR OLD.status<>1 OR OLD.delete_time IS NOT NULL OR OLD.content_purged_time IS NOT NULL
       OR NEW.lease_owner IS NOT NULL OR NEW.lease_token IS NOT NULL OR NEW.lease_expire_time IS NOT NULL
       OR NEW.completed_at IS NULL
       OR ROW(NEW.input,NEW.environment_id,NEW.organization_id,NEW.application_id,NEW.workload_client_id,NEW.task_type)
            IS DISTINCT FROM ROW(OLD.input,OLD.environment_id,OLD.organization_id,OLD.application_id,OLD.workload_client_id,OLD.task_type) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROOF_INVALID';
    END IF;
    mode:='inactive_cancel';
    IF OLD.state='running' THEN
        mode:='live';
        IF NEW.state='failed' AND current_setting('sand_ai.meeting_terminal_mode',true)='expired_reclaim' THEN
            mode:='expired_reclaim';
            IF OLD.lease_expire_time IS NULL OR OLD.lease_expire_time > (clock_timestamp() AT TIME ZONE zone)
               OR OLD.lease_owner IS DISTINCT FROM current_setting('sand_ai.worker_owner',true)
               OR OLD.lease_token IS DISTINCT FROM current_setting('sand_ai.worker_token',true)
               OR OLD.lease_fence::text IS DISTINCT FROM current_setting('sand_ai.worker_fence',true)
               OR OLD.attempt_count < OLD.max_attempts
               OR EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch d
                   WHERE d.parent_task_run_id=OLD.id
                     AND NOT sand_ai_meeting_expired_reclaim_dispatch_sealed(d.id)
                     AND NOT sand_ai_meeting_expired_reclaim_dispatch_unsent_closed(d.id)) THEN
                RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
            END IF;
        ELSE
            PERFORM sand_ai_meeting_worker_task(OLD.id);
        END IF;
    ELSIF NEW.state<>'canceled' OR OLD.state NOT IN ('authorization_pending','queued','retrying','recovery_pending')
        OR OLD.lease_owner IS NOT NULL OR OLD.lease_token IS NOT NULL OR OLD.lease_expire_time IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
    END IF;
    IF NEW.lease_fence <> OLD.lease_fence + (CASE WHEN NEW.state='canceled' OR mode='expired_reclaim' THEN 1 ELSE 0 END) THEN
        RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
    END IF;
    INSERT INTO sand_ai_meeting_terminal_transition VALUES(OLD.id,txid_current(),OLD.state,mode,NEW.state,
        OLD.lease_fence,NEW.lease_fence,OLD.lease_owner,
        CASE WHEN OLD.lease_token IS NULL THEN NULL ELSE encode(sha256(convert_to(OLD.lease_token,'UTF8')),'hex') END,
        OLD.lease_expire_time,zone);
    RETURN NEW;
END $$;

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610070143_meeting_expired_reclaim','4c3d2afc7b4eaefc7797b1a1e5ded740dec2c4e303a560f32d3d327794750de3',CURRENT_TIMESTAMP(0));


-- 202610080144_audio_unknown_confidence
-- Preserve legacy numeric review seals while keeping new unknown confidence NULL.
-- No rows or historical seal digests are rewritten.
CREATE OR REPLACE FUNCTION sand_ai_audio_transcript_seal_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    task sand_ai_task_run%ROWTYPE;
    base sand_ai_audio_transcript_seal%ROWTYPE;
    previous sand_ai_audio_transcript_seal%ROWTYPE;
    review sand_ai_audio_segment_review%ROWTYPE;
    projected jsonb;
    candidate jsonb;
    projected_segments jsonb;
BEGIN
    SELECT * INTO task FROM sand_ai_task_run WHERE id = NEW.audio_task_id FOR UPDATE;
    IF NOT FOUND OR NOT task.audio_seal_eligible OR task.state <> 'succeeded'
       OR task.task_type <> 'audio_transcription' OR task.resource_type <> 'audio_transcription'
       OR task.resource_id <> task.id OR task.content_purged_time IS NOT NULL
       OR task.status <> 1 OR task.delete_time IS NOT NULL OR task.result IS NULL
       OR NEW.projection_text IS NULL OR NEW.content_purged_time IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_INVALID';
    END IF;
    candidate := NEW.projection_text::jsonb;
    IF task.result #>> '{schema,code}' IS DISTINCT FROM 'sand_ai.audio_transcription'
       OR task.result #>> '{schema,version}' IS DISTINCT FROM '1.0.0'
       OR jsonb_typeof(task.result->'segments') IS DISTINCT FROM 'array'
       OR task.result #>> '{technical,driver_code}' IS DISTINCT FROM task.input #>> '{execution_driver,code}'
       OR task.result #>> '{technical,driver_version}' IS DISTINCT FROM task.input #>> '{execution_driver,version}'
       OR EXISTS (
           SELECT 1 FROM jsonb_array_elements(task.result->'segments') segment
           WHERE segment #>> '{source,file_ref}' IS DISTINCT FROM task.input #>> '{request,file_ref}'
              OR segment #>> '{source,content_sha256}' IS DISTINCT FROM task.input->>'file_sha256'
       ) THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_INVALID';
    END IF;
    IF NEW.review_revision = 0 THEN
        IF EXISTS (SELECT 1 FROM sand_ai_audio_segment_review WHERE audio_task_id = task.id) THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_BASE_LATE';
        END IF;
        projected := task.result;
        NEW.review_id := NULL;
        NEW.previous_seal_id := NULL;
        NEW.previous_sha256 := NULL;
        NEW.base_seal_id := NULL;
    ELSE
        SELECT * INTO base FROM sand_ai_audio_transcript_seal
            WHERE audio_task_id = task.id AND review_revision = 0 AND content_purged_time IS NULL;
        IF NOT FOUND THEN RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_BASE_MISSING'; END IF;
        SELECT * INTO previous FROM sand_ai_audio_transcript_seal
            WHERE audio_task_id = task.id AND review_revision = NEW.review_revision - 1
              AND content_purged_time IS NULL;
        IF NOT FOUND THEN RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_CHAIN_INVALID'; END IF;
        SELECT * INTO review FROM sand_ai_audio_segment_review
            WHERE audio_task_id = task.id AND revision = NEW.review_revision;
        IF NOT FOUND OR review.expected_revision <> previous.review_revision
           OR review.base_sha256 <> base.transcript_sha256 OR review.owner_scope <> base.owner_scope
           OR review.segment_index >= base.total_segments OR review.content_purged_time IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_REVIEW_INVALID';
        END IF;
        -- Latest text and latest explicit speaker edit reproduce the PHP overlay.
        -- Rebuilding each segment once avoids a full JSON copy per prior review.
        SELECT jsonb_agg(
            CASE WHEN correction.id IS NULL THEN original.segment ELSE
                original.segment || jsonb_build_object(
                    'text', btrim(correction.review_text, E' \t\n\r\013'),
                    'speaker', CASE WHEN speaker.id IS NULL THEN original.segment->'speaker'
                        ELSE to_jsonb(speaker.review_speaker) END,
                    'confidence', CASE WHEN original.segment->'confidence' = 'null'::jsonb
                        THEN 'null'::jsonb ELSE '"0.0000"'::jsonb END,
                    'warnings', (
                        SELECT COALESCE(jsonb_agg(warning.value ORDER BY warning.position), '[]'::jsonb)
                        FROM jsonb_array_elements(original.segment->'warnings')
                            WITH ORDINALITY warning(value,position)
                        WHERE warning.value NOT IN ('"no_recognized_speech"'::jsonb, '"human_reviewed"'::jsonb)
                    ) || CASE WHEN btrim(correction.review_text, E' \t\n\r\013') = ''
                        THEN '["no_recognized_speech","human_reviewed"]'::jsonb
                        ELSE '["human_reviewed"]'::jsonb END,
                    'review_revision', correction.revision
                )
            END ORDER BY original.position
        ) INTO projected_segments
        FROM jsonb_array_elements(task.result->'segments') WITH ORDINALITY original(segment,position)
        LEFT JOIN LATERAL (
            SELECT id,review_text,revision FROM sand_ai_audio_segment_review
            WHERE audio_task_id = task.id AND segment_index = original.position - 1
              AND revision <= NEW.review_revision
            ORDER BY revision DESC LIMIT 1
        ) correction ON true
        LEFT JOIN LATERAL (
            SELECT id,review_speaker FROM sand_ai_audio_segment_review
            WHERE audio_task_id = task.id AND segment_index = original.position - 1
              AND revision <= NEW.review_revision AND speaker_changed
            ORDER BY revision DESC LIMIT 1
        ) speaker ON true;
        projected := task.result || jsonb_build_object(
            'segments', projected_segments, 'review_revision', NEW.review_revision);
        NEW.review_id := review.id;
        NEW.previous_seal_id := previous.id;
        NEW.previous_sha256 := previous.transcript_sha256;
        NEW.base_seal_id := base.id;
    END IF;
    IF candidate IS DISTINCT FROM projected THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_PROJECTION_INVALID';
    END IF;
    -- Hash the exact presenter/overlay serialization submitted by the writer.
    -- JSONB text/key order is never used as the byte digest.
    NEW.transcript_sha256 := encode(sha256(convert_to(NEW.projection_text, 'UTF8')), 'hex');
    NEW.base_sha256 := CASE WHEN NEW.review_revision = 0 THEN NEW.transcript_sha256
        ELSE base.transcript_sha256 END;
    NEW.normalization_version := 'audio_transcript_seal@1';
    NEW.environment_id := task.environment_id;
    NEW.task_version := task.version;
    NEW.file_id := (task.input->>'file_id')::bigint;
    NEW.file_ref := task.input #>> '{request,file_ref}';
    NEW.file_sha256 := task.input->>'file_sha256';
    NEW.owner_scope := task.input->>'_trusted_identity_scope';
    NEW.data_egress_policy := task.input #>> '{request,data_egress_policy}';
    NEW.total_segments := jsonb_array_length(candidate->'segments');
    SELECT count(*) INTO NEW.eligible_segments FROM jsonb_array_elements(candidate->'segments') segment
        WHERE btrim(segment->>'text', E' \t\n\r\013') <> '';
    NEW.projection_text := NULL;
    RETURN NEW;
END $$;

-- The policy is immutable with its original dispatch fingerprint; legacy rows remain unchanged.
ALTER TABLE sand_ai_external_audio_dispatch
    DROP CONSTRAINT ck_sand_ai_external_audio_dispatch_frozen_v031;
ALTER TABLE sand_ai_external_audio_dispatch
    ADD CONSTRAINT ck_sand_ai_external_audio_dispatch_frozen_v031 CHECK (jsonb_typeof(frozen_request) = 'object' AND frozen_request ?& ARRAY['logical_execution_id','trusted_scope','file_ref','file_sha256','media_type','parameters','driver_code','driver_version','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','egress_policy','credential_revision_id','credential_digest','price_snapshot','usage_attribution','execution_target'] AND (frozen_request - ARRAY['logical_execution_id','trusted_scope','file_ref','file_sha256','media_type','parameters','driver_code','driver_version','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','egress_policy','credential_revision_id','credential_digest','price_snapshot','usage_attribution','execution_target','duration_policy']::text[]) = '{}'::jsonb AND frozen_request->>'egress_policy' = 'approved_cloud' AND jsonb_typeof(frozen_request->'price_snapshot') = 'object' AND jsonb_typeof(frozen_request->'usage_attribution') = 'object' AND jsonb_typeof(frozen_request->'execution_target') = 'object' AND (NOT (frozen_request ? 'duration_policy') OR (jsonb_typeof(frozen_request->'duration_policy') = 'string' AND frozen_request->>'duration_policy' = 'verified_private_audio@1'))) NOT VALID;

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610080144_audio_unknown_confidence','b65a7883b85c7f30550a329b3900abeae5d804ac533098d8f66b8d14b4c3f1d3',CURRENT_TIMESTAMP(0));


-- 202610090145_channel_credential_operation_actor
-- Source-only migration; legacy operations retain unknown original ownership.
ALTER TABLE sand_ai_feishu_credential_operation
    ADD COLUMN created_by varchar(64),
    ADD CONSTRAINT sand_ai_feishu_credential_operation_actor_check
        CHECK (created_by IS NULL OR created_by ~ '^[1-9][0-9]{0,18}$');

ALTER TABLE sand_ai_wecom_credential_operation
    ADD COLUMN created_by varchar(64),
    ADD CONSTRAINT sand_ai_wecom_credential_operation_actor_check
        CHECK (created_by IS NULL OR created_by ~ '^[1-9][0-9]{0,18}$');

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610090145_channel_credential_operation_actor','ec593b1ad3c98e5a5e630359d4ffc1ca80ff122fb4f4d5f4c3afa60c528b29e6',CURRENT_TIMESTAMP(0));


-- 202610090146_pricing_operation_receipt
-- New writes only; historical price operations have no inferred ownership or receipt.
CREATE TABLE sand_ai_pricing_operation (
    id bigserial PRIMARY KEY,
    actor_id bigint NOT NULL CHECK (actor_id > 0),
    kind varchar(16) NOT NULL CHECK (kind IN ('save','publish')),
    request_key varchar(128) NOT NULL CHECK (request_key ~ '^[A-Za-z0-9._:-]{1,128}$'),
    input_sha256 char(64) NOT NULL CHECK (input_sha256 ~ '^[a-f0-9]{64}$'),
    provider_id bigint NOT NULL REFERENCES sand_ai_provider(id),
    model_id bigint NOT NULL REFERENCES sand_ai_model(id),
    capability_code varchar(64) NOT NULL,
    target_id bigint REFERENCES sand_ai_price_revision(id),
    response_snapshot jsonb NOT NULL CHECK (jsonb_typeof(response_snapshot) = 'object'),
    create_time timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (actor_id,kind,request_key),
    CHECK (kind <> 'publish' OR target_id IS NOT NULL)
);
CREATE FUNCTION sand_ai_pricing_operation_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'SandAI price operation receipts are immutable';
END;
$$;
CREATE TRIGGER sand_ai_pricing_operation_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_pricing_operation
    FOR EACH ROW EXECUTE FUNCTION sand_ai_pricing_operation_immutable();
INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610090146_pricing_operation_receipt','5a802077c9bdcd33f1b28262023417a75d4a55674c3c2f4b13cb8eba7d8c0f34',CURRENT_TIMESTAMP(0));


-- 202610090147_catalog_operation_receipt_guard
-- Only new SandAI catalogue receipts are unique and immutable; legacy audits are unchanged.
CREATE UNIQUE INDEX sand_ai_catalog_operation_receipt_key
    ON sand_ai_audit_log (actor_ref, action, (context->>'lock_sha256'))
    WHERE actor_type = 'admin'
      AND action IN ('catalog.provider.save','catalog.provider.update','catalog.model.save','catalog.model.update')
      AND context->>'version' = 'catalog_operation@1';

CREATE FUNCTION sand_ai_catalog_operation_receipt_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.actor_type = 'admin'
       AND OLD.action IN ('catalog.provider.save','catalog.provider.update','catalog.model.save','catalog.model.update')
       AND OLD.context->>'version' = 'catalog_operation@1' THEN
        RAISE EXCEPTION 'SandAI catalogue operation receipts are immutable';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.actor_type = 'admin'
       AND NEW.action IN ('catalog.provider.save','catalog.provider.update','catalog.model.save','catalog.model.update')
       AND NEW.context->>'version' = 'catalog_operation@1' THEN
        RAISE EXCEPTION 'SandAI catalogue operation receipts must be appended';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_catalog_operation_receipt_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_audit_log
    FOR EACH ROW EXECUTE FUNCTION sand_ai_catalog_operation_receipt_immutable();
INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610090147_catalog_operation_receipt_guard','6d9d2da698414d1cd534b5eb7ceecbcd558dfd613610fe09f9456b92ec443c4c',CURRENT_TIMESTAMP(0));


-- 202610090148_billing_credential_operation_receipt
-- Only new keyed billing credential receipts are unique and immutable; legacy audits are unchanged.
CREATE UNIQUE INDEX sand_ai_billing_credential_operation_key
    ON sand_ai_audit_log (actor_ref, resource_id, (context->>'lock_sha256'))
    WHERE actor_type = 'admin'
      AND action = 'billing.credential.update'
      AND resource_type = 'provider'
      AND context->>'version' = 'billing_credential_save@1';

CREATE FUNCTION sand_ai_billing_credential_operation_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.actor_type = 'admin'
       AND OLD.action = 'billing.credential.update'
       AND OLD.resource_type = 'provider'
       AND OLD.context->>'version' = 'billing_credential_save@1' THEN
        RAISE EXCEPTION 'SandAI billing credential operation receipts are immutable';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.actor_type = 'admin'
       AND NEW.action = 'billing.credential.update'
       AND NEW.resource_type = 'provider'
       AND NEW.context->>'version' = 'billing_credential_save@1' THEN
        RAISE EXCEPTION 'SandAI billing credential operation receipts must be appended';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_billing_credential_operation_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_audit_log
    FOR EACH ROW EXECUTE FUNCTION sand_ai_billing_credential_operation_immutable();
INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610090148_billing_credential_operation_receipt','0d778d260da6e9613d7e6a2642c4568368b1fe65ebc22c559ec868e37ee477a1',CURRENT_TIMESTAMP(0));


-- 202610090149_legal_profile_operation_receipt_guard
-- Only new keyed legal profile receipts are unique and immutable; legacy audits are unchanged.
CREATE UNIQUE INDEX sand_ai_legal_profile_operation_key
    ON sand_ai_audit_log (actor_ref, environment_id, action, (context->>'lock_sha256'))
    WHERE actor_type = 'admin'
      AND action IN ('legal_analysis_profile.draft','legal_analysis_profile.publish','legal_analysis_profile.revoke')
      AND resource_type = 'legal_analysis_profile'
      AND context->>'version' = 'legal_profile_operation@1';

CREATE FUNCTION sand_ai_legal_profile_operation_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.actor_type = 'admin'
       AND OLD.action IN ('legal_analysis_profile.draft','legal_analysis_profile.publish','legal_analysis_profile.revoke')
       AND OLD.resource_type = 'legal_analysis_profile'
       AND OLD.context->>'version' = 'legal_profile_operation@1' THEN
        RAISE EXCEPTION 'SandAI legal profile operation receipts are immutable';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.actor_type = 'admin'
       AND NEW.action IN ('legal_analysis_profile.draft','legal_analysis_profile.publish','legal_analysis_profile.revoke')
       AND NEW.resource_type = 'legal_analysis_profile'
       AND NEW.context->>'version' = 'legal_profile_operation@1' THEN
        RAISE EXCEPTION 'SandAI legal profile operation receipts must be appended';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_legal_profile_operation_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_audit_log
    FOR EACH ROW EXECUTE FUNCTION sand_ai_legal_profile_operation_immutable();
INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610090149_legal_profile_operation_receipt_guard','9e31953c47eb22d48f3a542529b1156d1c9d424f56c7005452a7bb9520a20e4f',CURRENT_TIMESTAMP(0));

-- 202610090150_local_ocr_deployment_price_budget
-- SOURCE only. No historical OCR event is repriced; execution needs separate authorization.
ALTER TABLE sand_ai_price_rate DROP CONSTRAINT ck_sand_ai_price_rate_meter;
ALTER TABLE sand_ai_price_rate ADD CONSTRAINT ck_sand_ai_price_rate_meter CHECK (
    meter_code IN ('input_tokens','output_tokens','audio_seconds','input_characters','rerank_search_units','rerank_tokens','external_request'));
ALTER TABLE sand_ai_admin_validation_attempt DROP CONSTRAINT sand_ai_admin_validation_attempt_capability_code_check;
ALTER TABLE sand_ai_admin_validation_attempt ADD CONSTRAINT sand_ai_admin_validation_attempt_capability_code_check
    CHECK(capability_code IN ('chat','embedding','audio_transcription','audio_speech','rerank','image_ocr'));
ALTER TABLE sand_ai_ocr_dispatch ADD COLUMN execution_target jsonb, ADD COLUMN pricing jsonb, ADD COLUMN request_fingerprint char(64);
ALTER TABLE sand_ai_ocr_dispatch ADD CONSTRAINT ck_sand_ai_ocr_frozen_v150 CHECK ((
    (execution_target IS NULL AND pricing IS NULL AND request_fingerprint IS NULL)
    OR(jsonb_typeof(execution_target)='object' AND jsonb_typeof(pricing)='object' AND request_fingerprint ~ '^[a-f0-9]{64}$')
) IS TRUE) NOT VALID;

-- Preserve the actual installed non-OCR arms, including later platform and rerank additions.
DO $$
DECLARE prior record; ocr text;
BEGIN
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_usage_event'::regclass AND conname='ck_sand_ai_usage_event_tool_metering';
    ocr=$arm$ocr_dispatch_id IS NOT NULL AND speech_run_id IS NULL AND audio_task_run_id IS NULL
      AND audio_execution_id IS NULL AND external_audio_dispatch_id IS NULL
      AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
      AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND invocation_id IS NULL
      AND admin_validation_attempt_id IS NULL AND admin_actor_id IS NULL
      AND skill_id IS NULL AND skill_version_id IS NULL AND skill_run_id IS NULL
      AND provider_id>0 AND model_id>0 AND deployment_id>0
      AND service_action='sand_ai.document_parse' AND source_kind='capability' AND capability_code='image_ocr'
      AND metering_unit='external_request' AND metering_quantity=1
      AND input_tokens=0 AND output_tokens=0 AND total_tokens=0 AND usage_state='measured'
      AND reconciled_amount IS NULL AND reconciliation_state='unreconciled'
      AND ((pricing_state='priced' AND price_revision_id IS NOT NULL AND metered_amount>=0 AND currency IS NOT NULL)
        OR(pricing_state='unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))$arm$;
    EXECUTE format('ALTER TABLE sand_ai_usage_event DROP CONSTRAINT %I',prior.conname);
    EXECUTE format('ALTER TABLE sand_ai_usage_event ADD CONSTRAINT %I CHECK (((%s) OR (%s)) IS TRUE)%s',
        prior.conname,prior.expression,ocr,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
END $$;

CREATE FUNCTION sand_ai_ocr_frozen_binding_v150() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target jsonb; frozen jsonb; deployment sand_ai_model_deployment%ROWTYPE;
    provider sand_ai_provider%ROWTYPE; model sand_ai_model%ROWTYPE; revision sand_ai_config_revision%ROWTYPE;
    price sand_ai_price_revision%ROWTYPE; rate sand_ai_price_rate%ROWTYPE; route sand_ai_capability_route%ROWTYPE;
    profile sand_ai_capability_profile%ROWTYPE; amount numeric;
BEGIN
    target=NEW.execution_target; frozen=NEW.pricing;
    IF target IS NULL OR frozen IS NULL OR NEW.request_fingerprint IS NULL
      OR jsonb_typeof(target) IS DISTINCT FROM 'object' OR jsonb_typeof(frozen) IS DISTINCT FROM 'object'
      OR (SELECT count(*) FROM jsonb_object_keys(frozen))<>4
      OR NOT(frozen ?& ARRAY['price_revision_id','currency','amount','snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_FROZEN_REQUEST_INVALID';
    END IF;
    IF target='{}'::jsonb THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_LEGACY_PRICE_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    IF (SELECT count(*) FROM jsonb_object_keys(target))<>6
      OR NOT(target ?& ARRAY['provider_id','model_id','deployment_id','provider_snapshot','model_snapshot','deployment_snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_INVALID';
    END IF;
    SELECT * INTO STRICT deployment FROM sand_ai_model_deployment WHERE id=(target->>'deployment_id')::bigint;
    SELECT * INTO STRICT provider FROM sand_ai_provider WHERE id=deployment.provider_id;
    SELECT * INTO STRICT model FROM sand_ai_model WHERE id=deployment.model_id;
    SELECT * INTO STRICT revision FROM sand_ai_config_revision WHERE resource_type='model_deployment'
      AND resource_id=deployment.id AND revision=deployment.revision AND state='published' AND status=1 AND delete_time IS NULL;
    SELECT * INTO STRICT route FROM sand_ai_capability_route WHERE id=(target->'deployment_snapshot'->'route'->>'route_id')::bigint;
    SELECT * INTO STRICT profile FROM sand_ai_capability_profile WHERE id=route.profile_id;
    IF provider.adapter IS DISTINCT FROM 'local_ocr_runtime' OR model.type IS DISTINCT FROM 'image_ocr'
      OR model.capabilities IS DISTINCT FROM '["image_ocr"]'::jsonb OR deployment.remote_model IS DISTINCT FROM 'local_paddle_ocr'
      OR deployment.config IS DISTINCT FROM '{}'::jsonb OR provider.encrypted_config IS DISTINCT FROM '{}'::jsonb
      OR provider.status<>1 OR model.status<>1 OR deployment.status<>1
      OR provider.delete_time IS NOT NULL OR model.delete_time IS NOT NULL OR deployment.delete_time IS NOT NULL
      OR revision.payload->'published_processing_policy'->>'processing_location' IS DISTINCT FROM 'local_runtime'
      OR target->>'provider_id' IS DISTINCT FROM provider.id::text OR target->>'model_id' IS DISTINCT FROM model.id::text
      OR target->'provider_snapshot' IS DISTINCT FROM jsonb_build_object('id',provider.id,'code',provider.code,'name',provider.name,'adapter','local_ocr_runtime')
      OR target->'model_snapshot' IS DISTINCT FROM jsonb_build_object('id',model.id,'code',model.code,'name',model.name,'type','image_ocr')
      OR target->'deployment_snapshot' IS DISTINCT FROM jsonb_build_object('id',deployment.id,'remote_model','local_paddle_ocr','revision',deployment.revision,
         'execution_snapshot_digest',revision.payload->>'published_execution_snapshot_digest',
         'route',jsonb_build_object('profile_id',profile.id,'profile_revision',profile.revision,'route_id',route.id))
      OR profile.environment_id IS DISTINCT FROM NEW.environment_id OR profile.state IS DISTINCT FROM 'published' OR profile.status<>1
      OR profile.delete_time IS NOT NULL OR route.delete_time IS NOT NULL OR route.status<>1
      OR route.deployment_id IS DISTINCT FROM deployment.id OR route.capability_code IS DISTINCT FROM 'image_ocr'
      OR route.driver_code IS DISTINCT FROM 'local_paddle_ocr'
      OR NOT EXISTS(SELECT 1 FROM sand_ai_config_revision WHERE resource_type='capability_profile' AND resource_id=profile.id
          AND revision=profile.revision AND state='published' AND status=1 AND delete_time IS NULL) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_BINDING_INVALID';
    END IF;
    IF frozen->>'price_revision_id' IS DISTINCT FROM (SELECT id::text FROM sand_ai_price_revision
      WHERE provider_id=provider.id AND model_id=model.id AND capability_code='image_ocr'
        AND state='published' AND delete_time IS NULL AND effective_from<=NEW.create_time
        AND (effective_until IS NULL OR effective_until>NEW.create_time)
      ORDER BY effective_from DESC,id DESC LIMIT 1) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_CURRENT_PRICE_CHANGED';
    END IF;
    IF frozen->>'price_revision_id' IS NULL THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_UNPRICED_BINDING_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT price FROM sand_ai_price_revision WHERE id=(frozen->>'price_revision_id')::bigint;
    SELECT * INTO STRICT rate FROM sand_ai_price_rate WHERE price_revision_id=price.id AND meter_code='external_request';
    amount=round(rate.unit_price/rate.per_quantity,6);
    IF price.provider_id IS DISTINCT FROM provider.id OR price.model_id IS DISTINCT FROM model.id
      OR price.capability_code IS DISTINCT FROM 'image_ocr' OR price.state IS DISTINCT FROM 'published' OR price.delete_time IS NOT NULL
      OR price.effective_from>NEW.create_time OR (price.effective_until IS NOT NULL AND price.effective_until<=NEW.create_time)
      OR (SELECT count(*) FROM sand_ai_price_rate WHERE price_revision_id=price.id)<>1
      OR frozen->>'currency' IS DISTINCT FROM price.currency::text OR (frozen->>'amount')::numeric IS DISTINCT FROM amount
      OR frozen->'snapshot' IS DISTINCT FROM jsonb_build_object('snapshot_version','ocr_request_price@1','unit','external_request','quantity',1,
          'price_revision_id',price.id,'currency',price.currency,'effective_from',to_char(price.effective_from,'YYYY-MM-DD HH24:MI:SS'),
          'effective_until',CASE WHEN price.effective_until IS NULL THEN NULL ELSE to_char(price.effective_until,'YYYY-MM-DD HH24:MI:SS') END,
          'rate',jsonb_build_object('unit_price',rate.unit_price::text,'per_quantity',rate.per_quantity),'fee_scope','configured_service_fee') THEN
        RAISE EXCEPTION 'SAND_AI_OCR_PRICE_BINDING_INVALID';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_ocr_frozen_binding_v150 BEFORE INSERT ON sand_ai_ocr_dispatch
    FOR EACH ROW EXECUTE FUNCTION sand_ai_ocr_frozen_binding_v150();

CREATE OR REPLACE FUNCTION sand_ai_ocr_usage_binding_v103() RETURNS trigger AS $$
DECLARE dispatch sand_ai_ocr_dispatch%ROWTYPE;
BEGIN
    IF NEW.ocr_dispatch_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO STRICT dispatch FROM sand_ai_ocr_dispatch WHERE id=NEW.ocr_dispatch_id;
    IF NEW.environment_id IS DISTINCT FROM dispatch.environment_id
      OR NEW.organization_id IS DISTINCT FROM (dispatch.attribution->>'organization_id')::bigint
      OR NEW.application_id IS DISTINCT FROM (dispatch.attribution->>'application_id')::bigint
      OR NEW.workload_client_id IS DISTINCT FROM (dispatch.attribution->>'workload_client_id')::bigint
      OR NEW.identity_snapshot IS DISTINCT FROM dispatch.attribution->'identity_snapshot'
      OR NEW.create_time IS DISTINCT FROM dispatch.create_time OR NEW.idempotency_key IS DISTINCT FROM 'ocr-dispatch:'||dispatch.id::text THEN
        RAISE EXCEPTION 'SAND_AI_OCR_USAGE_BINDING_INVALID';
    END IF;
    IF dispatch.execution_target IS NULL OR dispatch.execution_target='{}'::jsonb THEN
        IF NEW.provider_id IS NOT NULL OR NEW.model_id IS NOT NULL OR NEW.deployment_id IS NOT NULL
          OR NEW.provider_snapshot IS DISTINCT FROM jsonb_build_object('kind','private_ocr_driver','code',dispatch.parser_code)
          OR NEW.model_snapshot IS DISTINCT FROM '{}'::jsonb
          OR NEW.deployment_snapshot IS DISTINCT FROM jsonb_build_object('task_run_id',dispatch.task_run_id,'file_parse_id',dispatch.file_parse_id,
              'file_id',dispatch.file_id,'attempt_no',dispatch.attempt_no,'lease_fence',dispatch.lease_fence,'parser_code',dispatch.parser_code,'parser_version',dispatch.parser_version)
          OR NEW.price_snapshot IS DISTINCT FROM jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)
          OR NEW.price_revision_id IS NOT NULL OR NEW.metered_amount IS NOT NULL OR NEW.currency IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_OCR_LEGACY_USAGE_BINDING_INVALID';
        END IF;
    ELSE
        IF NEW.provider_id IS DISTINCT FROM (dispatch.execution_target->>'provider_id')::bigint
          OR NEW.model_id IS DISTINCT FROM (dispatch.execution_target->>'model_id')::bigint
          OR NEW.deployment_id IS DISTINCT FROM (dispatch.execution_target->>'deployment_id')::bigint
          OR NEW.provider_snapshot IS DISTINCT FROM dispatch.execution_target->'provider_snapshot'
          OR NEW.model_snapshot IS DISTINCT FROM dispatch.execution_target->'model_snapshot'
          OR NEW.deployment_snapshot IS DISTINCT FROM dispatch.execution_target->'deployment_snapshot'
          OR NEW.price_snapshot IS DISTINCT FROM dispatch.pricing->'snapshot'
          OR NEW.price_revision_id IS DISTINCT FROM (dispatch.pricing->>'price_revision_id')::bigint
          OR NEW.metered_amount IS DISTINCT FROM (dispatch.pricing->>'amount')::numeric
          OR NEW.currency IS DISTINCT FROM (dispatch.pricing->>'currency')::char(3) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_PRICED_USAGE_BINDING_INVALID';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

ALTER TABLE sand_ai_budget_reservation ADD COLUMN ocr_dispatch_id bigint REFERENCES sand_ai_ocr_dispatch(id);
ALTER TABLE sand_ai_budget_event ADD COLUMN ocr_dispatch_id bigint REFERENCES sand_ai_ocr_dispatch(id);
ALTER TABLE sand_ai_budget_reservation DROP CONSTRAINT ck_sand_ai_budget_reservation_owner_v093;
ALTER TABLE sand_ai_budget_reservation ADD CONSTRAINT ck_sand_ai_budget_reservation_owner_v093 CHECK (
    num_nonnulls(invocation_id,speech_run_id,external_audio_dispatch_id,audio_task_run_id,ocr_dispatch_id)=1
    AND ((audio_task_run_id IS NULL)=(audio_execution_id IS NULL)) AND (audio_execution_id IS NULL OR length(audio_execution_id)>0)) NOT VALID;
ALTER TABLE sand_ai_budget_event DROP CONSTRAINT ck_sand_ai_budget_event_owner_v093;
ALTER TABLE sand_ai_budget_event ADD CONSTRAINT ck_sand_ai_budget_event_owner_v093 CHECK (
    num_nonnulls(invocation_id,speech_run_id,external_audio_dispatch_id,audio_task_run_id,ocr_dispatch_id)<=1
    AND ((audio_task_run_id IS NULL)=(audio_execution_id IS NULL))) NOT VALID;
CREATE UNIQUE INDEX uq_sand_ai_budget_ocr_v150 ON sand_ai_budget_reservation(policy_id,ocr_dispatch_id)
    WHERE ocr_dispatch_id IS NOT NULL AND delete_time IS NULL;

-- Existing owner branches are preserved; only the OCR owner and its outcome gate are added.
CREATE OR REPLACE FUNCTION sand_ai_budget_owner_guard_v093() RETURNS trigger AS $$
DECLARE policy sand_ai_budget_policy%ROWTYPE; identity jsonb; environment bigint; model bigint; capability text; fingerprint text; price bigint; task_input jsonb; execution_fence bigint;
BEGIN
    IF TG_OP='UPDATE' THEN
        IF to_jsonb(NEW)-'state'-'metered_amount'-'reason'-'expires_at'-'update_time'
           IS DISTINCT FROM to_jsonb(OLD)-'state'-'metered_amount'-'reason'-'expires_at'-'update_time' THEN
            RAISE EXCEPTION 'SAND_AI_BUDGET_OWNER_IMMUTABLE';
        END IF;
        IF NEW.ocr_dispatch_id IS NOT NULL AND (
          (OLD.state='settled' AND to_jsonb(NEW)-'update_time' IS DISTINCT FROM to_jsonb(OLD)-'update_time')
          OR (OLD.state='held' AND NEW.state NOT IN ('held','settled'))
          OR (OLD.state='reserved' AND NEW.state NOT IN ('reserved','held','settled'))
          OR NEW.state='released' OR (NEW.state='settled' AND NOT EXISTS(
              SELECT 1 FROM sand_ai_ocr_dispatch d JOIN sand_ai_ocr_dispatch_outcome o ON o.ocr_dispatch_id=d.id
              WHERE d.id=NEW.ocr_dispatch_id AND o.state='response_known'
                AND NEW.metered_amount=(d.pricing->>'amount')::numeric))) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_BUDGET_OUTCOME_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO policy FROM sand_ai_budget_policy WHERE id=NEW.policy_id;
    IF NEW.invocation_id IS NOT NULL THEN
        SELECT jsonb_build_object('organization_ref',organization_id::text,'application_ref',application_id::text),
            environment_id,model_id,request_fingerprint,call_start_price_revision_id
            INTO identity,environment,model,fingerprint,price FROM sand_ai_invocation WHERE id=NEW.invocation_id;
        SELECT capability_code INTO capability FROM sand_ai_price_revision WHERE id=NEW.price_revision_id;
    ELSIF NEW.speech_run_id IS NOT NULL THEN
        SELECT identity_snapshot->'identity_snapshot',environment_id,model_id,request_fingerprint,price_revision_id
            INTO identity,environment,model,fingerprint,price FROM sand_ai_speech_run WHERE id=NEW.speech_run_id;
        capability := 'audio_speech';
    ELSIF NEW.external_audio_dispatch_id IS NOT NULL THEN
        SELECT frozen_request->'usage_attribution'->'identity_snapshot',environment_id,
            (frozen_request->'execution_target'->'model'->>'id')::bigint,request_fingerprint,
            (frozen_request->'price_snapshot'->>'price_revision_id')::bigint
            INTO identity,environment,model,fingerprint,price FROM sand_ai_external_audio_dispatch WHERE id=NEW.external_audio_dispatch_id;
        capability := 'audio_transcription';
    ELSIF NEW.ocr_dispatch_id IS NOT NULL THEN
        SELECT attribution->'identity_snapshot',environment_id,(execution_target->>'model_id')::bigint,
            request_fingerprint,(pricing->>'price_revision_id')::bigint
            INTO identity,environment,model,fingerprint,price FROM sand_ai_ocr_dispatch WHERE id=NEW.ocr_dispatch_id;
        capability := 'image_ocr';
        IF NEW.state IS DISTINCT FROM 'reserved' OR NEW.expires_at IS NULL OR price IS NULL OR model IS NULL
          OR NEW.request_id IS DISTINCT FROM 'ocr-dispatch:'||NEW.ocr_dispatch_id::text
          OR NEW.estimate_amount IS DISTINCT FROM (SELECT greatest(ceil(
              (pricing->'snapshot'->'rate'->>'unit_price')::numeric /
              (pricing->'snapshot'->'rate'->>'per_quantity')::numeric * 1000000)/1000000,0.000001)
              FROM sand_ai_ocr_dispatch WHERE id=NEW.ocr_dispatch_id) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_BUDGET_PRICE_INVALID';
        END IF;
        IF policy.state IS DISTINCT FROM 'published' OR policy.delete_time IS NOT NULL
          OR policy.period_start>CURRENT_TIMESTAMP(0) AT TIME ZONE 'UTC' OR policy.period_end<=CURRENT_TIMESTAMP(0) AT TIME ZONE 'UTC'
          OR policy.unpriced_policy IS DISTINCT FROM 'block' OR policy.unmeasured_policy IS DISTINCT FROM 'hold' THEN
            RAISE EXCEPTION 'SAND_AI_OCR_BUDGET_POLICY_INVALID';
        END IF;
    ELSE
        SELECT input,environment_id,lease_fence INTO task_input,environment,execution_fence
            FROM sand_ai_task_run WHERE id=NEW.audio_task_run_id AND task_type='audio_transcription';
        identity := task_input->'usage_attribution'->'identity_snapshot';
        model := (task_input->'execution_target'->'model'->>'id')::bigint;
        price := (task_input->'pricing_snapshot'->>'price_revision_id')::bigint;
        capability := 'audio_transcription';
        IF NEW.audio_execution_id IS DISTINCT FROM 'task:'||NEW.audio_task_run_id::text||':attempt:'||execution_fence::text THEN
            RAISE EXCEPTION 'SAND_AI_BUDGET_AUDIO_EXECUTION_INVALID';
        END IF;
        -- Fingerprint verification uses the existing PHP canonical frozen-input digest at the write boundary.
        fingerprint := NEW.request_fingerprint;
    END IF;
    IF environment IS NULL OR identity IS NULL OR fingerprint IS DISTINCT FROM NEW.request_fingerprint
       OR price IS DISTINCT FROM NEW.price_revision_id OR policy.currency IS DISTINCT FROM NEW.currency
       OR NOT sand_ai_budget_scope_matches_v093(policy.scope_kind,policy.scope_ref,identity,environment,model,capability) THEN
        RAISE EXCEPTION 'SAND_AI_BUDGET_OWNER_SCOPE_INVALID';
    END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION sand_ai_budget_event_owner_guard_v093() RETURNS trigger AS $$
BEGIN
    IF NEW.reservation_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM sand_ai_budget_reservation r WHERE r.id=NEW.reservation_id AND r.policy_id=NEW.policy_id
          AND r.invocation_id IS NOT DISTINCT FROM NEW.invocation_id AND r.speech_run_id IS NOT DISTINCT FROM NEW.speech_run_id
          AND r.external_audio_dispatch_id IS NOT DISTINCT FROM NEW.external_audio_dispatch_id
          AND r.audio_task_run_id IS NOT DISTINCT FROM NEW.audio_task_run_id AND r.audio_execution_id IS NOT DISTINCT FROM NEW.audio_execution_id
          AND r.ocr_dispatch_id IS NOT DISTINCT FROM NEW.ocr_dispatch_id AND r.currency=NEW.currency) THEN RAISE EXCEPTION 'SAND_AI_BUDGET_EVENT_OWNER_INVALID'; END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610090150_local_ocr_deployment_price_budget','ce57ad4e9e60d9496b646dcd584dbb4f6e33d38a4fc67f3f49f94850fee1e11c',CURRENT_TIMESTAMP(0));


-- 202610090151_local_ocr_revision_binding
-- Qualify revision columns without changing the immutable OCR binding rules.
CREATE OR REPLACE FUNCTION sand_ai_ocr_frozen_binding_v150() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target jsonb; frozen jsonb; deployment sand_ai_model_deployment%ROWTYPE;
    provider sand_ai_provider%ROWTYPE; model sand_ai_model%ROWTYPE; revision sand_ai_config_revision%ROWTYPE;
    price sand_ai_price_revision%ROWTYPE; rate sand_ai_price_rate%ROWTYPE; route sand_ai_capability_route%ROWTYPE;
    profile sand_ai_capability_profile%ROWTYPE; amount numeric;
BEGIN
    target=NEW.execution_target; frozen=NEW.pricing;
    IF target IS NULL OR frozen IS NULL OR NEW.request_fingerprint IS NULL
      OR jsonb_typeof(target) IS DISTINCT FROM 'object' OR jsonb_typeof(frozen) IS DISTINCT FROM 'object'
      OR (SELECT count(*) FROM jsonb_object_keys(frozen))<>4
      OR NOT(frozen ?& ARRAY['price_revision_id','currency','amount','snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_FROZEN_REQUEST_INVALID';
    END IF;
    IF target='{}'::jsonb THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_LEGACY_PRICE_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    IF (SELECT count(*) FROM jsonb_object_keys(target))<>6
      OR NOT(target ?& ARRAY['provider_id','model_id','deployment_id','provider_snapshot','model_snapshot','deployment_snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_INVALID';
    END IF;
    SELECT * INTO STRICT deployment FROM sand_ai_model_deployment WHERE id=(target->>'deployment_id')::bigint;
    SELECT * INTO STRICT provider FROM sand_ai_provider WHERE id=deployment.provider_id;
    SELECT * INTO STRICT model FROM sand_ai_model WHERE id=deployment.model_id;
    SELECT configuration.* INTO STRICT revision FROM sand_ai_config_revision configuration WHERE configuration.resource_type='model_deployment'
      AND configuration.resource_id=deployment.id AND configuration.revision=deployment.revision
      AND configuration.state='published' AND configuration.status=1 AND configuration.delete_time IS NULL;
    SELECT * INTO STRICT route FROM sand_ai_capability_route WHERE id=(target->'deployment_snapshot'->'route'->>'route_id')::bigint;
    SELECT * INTO STRICT profile FROM sand_ai_capability_profile WHERE id=route.profile_id;
    IF provider.adapter IS DISTINCT FROM 'local_ocr_runtime' OR model.type IS DISTINCT FROM 'image_ocr'
      OR model.capabilities IS DISTINCT FROM '["image_ocr"]'::jsonb OR deployment.remote_model IS DISTINCT FROM 'local_paddle_ocr'
      OR deployment.config IS DISTINCT FROM '{}'::jsonb OR provider.encrypted_config IS DISTINCT FROM '{}'::jsonb
      OR provider.status<>1 OR model.status<>1 OR deployment.status<>1
      OR provider.delete_time IS NOT NULL OR model.delete_time IS NOT NULL OR deployment.delete_time IS NOT NULL
      OR revision.payload->'published_processing_policy'->>'processing_location' IS DISTINCT FROM 'local_runtime'
      OR target->>'provider_id' IS DISTINCT FROM provider.id::text OR target->>'model_id' IS DISTINCT FROM model.id::text
      OR target->'provider_snapshot' IS DISTINCT FROM jsonb_build_object('id',provider.id,'code',provider.code,'name',provider.name,'adapter','local_ocr_runtime')
      OR target->'model_snapshot' IS DISTINCT FROM jsonb_build_object('id',model.id,'code',model.code,'name',model.name,'type','image_ocr')
      OR target->'deployment_snapshot' IS DISTINCT FROM jsonb_build_object('id',deployment.id,'remote_model','local_paddle_ocr','revision',deployment.revision,
         'execution_snapshot_digest',revision.payload->>'published_execution_snapshot_digest',
         'route',jsonb_build_object('profile_id',profile.id,'profile_revision',profile.revision,'route_id',route.id))
      OR profile.environment_id IS DISTINCT FROM NEW.environment_id OR profile.state IS DISTINCT FROM 'published' OR profile.status<>1
      OR profile.delete_time IS NOT NULL OR route.delete_time IS NOT NULL OR route.status<>1
      OR route.deployment_id IS DISTINCT FROM deployment.id OR route.capability_code IS DISTINCT FROM 'image_ocr'
      OR route.driver_code IS DISTINCT FROM 'local_paddle_ocr'
      OR NOT EXISTS(SELECT 1 FROM sand_ai_config_revision configuration WHERE configuration.resource_type='capability_profile'
          AND configuration.resource_id=profile.id AND configuration.revision=profile.revision
          AND configuration.state='published' AND configuration.status=1 AND configuration.delete_time IS NULL) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_BINDING_INVALID';
    END IF;
    IF frozen->>'price_revision_id' IS DISTINCT FROM (SELECT id::text FROM sand_ai_price_revision
      WHERE provider_id=provider.id AND model_id=model.id AND capability_code='image_ocr'
        AND state='published' AND delete_time IS NULL AND effective_from<=NEW.create_time
        AND (effective_until IS NULL OR effective_until>NEW.create_time)
      ORDER BY effective_from DESC,id DESC LIMIT 1) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_CURRENT_PRICE_CHANGED';
    END IF;
    IF frozen->>'price_revision_id' IS NULL THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_UNPRICED_BINDING_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT price FROM sand_ai_price_revision WHERE id=(frozen->>'price_revision_id')::bigint;
    SELECT * INTO STRICT rate FROM sand_ai_price_rate WHERE price_revision_id=price.id AND meter_code='external_request';
    amount=round(rate.unit_price/rate.per_quantity,6);
    IF price.provider_id IS DISTINCT FROM provider.id OR price.model_id IS DISTINCT FROM model.id
      OR price.capability_code IS DISTINCT FROM 'image_ocr' OR price.state IS DISTINCT FROM 'published' OR price.delete_time IS NOT NULL
      OR price.effective_from>NEW.create_time OR (price.effective_until IS NOT NULL AND price.effective_until<=NEW.create_time)
      OR (SELECT count(*) FROM sand_ai_price_rate WHERE price_revision_id=price.id)<>1
      OR frozen->>'currency' IS DISTINCT FROM price.currency::text OR (frozen->>'amount')::numeric IS DISTINCT FROM amount
      OR frozen->'snapshot' IS DISTINCT FROM jsonb_build_object('snapshot_version','ocr_request_price@1','unit','external_request','quantity',1,
          'price_revision_id',price.id,'currency',price.currency,'effective_from',to_char(price.effective_from,'YYYY-MM-DD HH24:MI:SS'),
          'effective_until',CASE WHEN price.effective_until IS NULL THEN NULL ELSE to_char(price.effective_until,'YYYY-MM-DD HH24:MI:SS') END,
          'rate',jsonb_build_object('unit_price',rate.unit_price::text,'per_quantity',rate.per_quantity),'fee_scope','configured_service_fee') THEN
        RAISE EXCEPTION 'SAND_AI_OCR_PRICE_BINDING_INVALID';
    END IF;
    RETURN NEW;
END $$;

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610090151_local_ocr_revision_binding','9d437f6e968987b987817c4ac060cc0acbf054b19baf34d8a3335d2392fbf794',CURRENT_TIMESTAMP(0));


-- 202610090152_local_ocr_empty_configuration_binding
-- Accept both empty JSON representations produced by the published OCR configuration path.
CREATE OR REPLACE FUNCTION sand_ai_ocr_frozen_binding_v150() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target jsonb; frozen jsonb; deployment sand_ai_model_deployment%ROWTYPE;
    provider sand_ai_provider%ROWTYPE; model sand_ai_model%ROWTYPE; revision sand_ai_config_revision%ROWTYPE;
    price sand_ai_price_revision%ROWTYPE; rate sand_ai_price_rate%ROWTYPE; route sand_ai_capability_route%ROWTYPE;
    profile sand_ai_capability_profile%ROWTYPE; amount numeric;
BEGIN
    target=NEW.execution_target; frozen=NEW.pricing;
    IF target IS NULL OR frozen IS NULL OR NEW.request_fingerprint IS NULL
      OR jsonb_typeof(target) IS DISTINCT FROM 'object' OR jsonb_typeof(frozen) IS DISTINCT FROM 'object'
      OR (SELECT count(*) FROM jsonb_object_keys(frozen))<>4
      OR NOT(frozen ?& ARRAY['price_revision_id','currency','amount','snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_FROZEN_REQUEST_INVALID';
    END IF;
    IF target='{}'::jsonb THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_LEGACY_PRICE_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    IF (SELECT count(*) FROM jsonb_object_keys(target))<>6
      OR NOT(target ?& ARRAY['provider_id','model_id','deployment_id','provider_snapshot','model_snapshot','deployment_snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_INVALID';
    END IF;
    SELECT * INTO STRICT deployment FROM sand_ai_model_deployment WHERE id=(target->>'deployment_id')::bigint;
    SELECT * INTO STRICT provider FROM sand_ai_provider WHERE id=deployment.provider_id;
    SELECT * INTO STRICT model FROM sand_ai_model WHERE id=deployment.model_id;
    SELECT configuration.* INTO STRICT revision FROM sand_ai_config_revision configuration WHERE configuration.resource_type='model_deployment'
      AND configuration.resource_id=deployment.id AND configuration.revision=deployment.revision
      AND configuration.state='published' AND configuration.status=1 AND configuration.delete_time IS NULL;
    SELECT * INTO STRICT route FROM sand_ai_capability_route WHERE id=(target->'deployment_snapshot'->'route'->>'route_id')::bigint;
    SELECT * INTO STRICT profile FROM sand_ai_capability_profile WHERE id=route.profile_id;
    IF provider.adapter IS DISTINCT FROM 'local_ocr_runtime' OR model.type IS DISTINCT FROM 'image_ocr'
      OR model.capabilities IS DISTINCT FROM '["image_ocr"]'::jsonb OR deployment.remote_model IS DISTINCT FROM 'local_paddle_ocr'
      OR (deployment.config IS DISTINCT FROM '{}'::jsonb AND deployment.config IS DISTINCT FROM '[]'::jsonb)
      OR provider.encrypted_config IS DISTINCT FROM '{}'::jsonb
      OR provider.status<>1 OR model.status<>1 OR deployment.status<>1
      OR provider.delete_time IS NOT NULL OR model.delete_time IS NOT NULL OR deployment.delete_time IS NOT NULL
      OR revision.payload->'published_processing_policy'->>'processing_location' IS DISTINCT FROM 'local_runtime'
      OR target->>'provider_id' IS DISTINCT FROM provider.id::text OR target->>'model_id' IS DISTINCT FROM model.id::text
      OR target->'provider_snapshot' IS DISTINCT FROM jsonb_build_object('id',provider.id,'code',provider.code,'name',provider.name,'adapter','local_ocr_runtime')
      OR target->'model_snapshot' IS DISTINCT FROM jsonb_build_object('id',model.id,'code',model.code,'name',model.name,'type','image_ocr')
      OR target->'deployment_snapshot' IS DISTINCT FROM jsonb_build_object('id',deployment.id,'remote_model','local_paddle_ocr','revision',deployment.revision,
         'execution_snapshot_digest',revision.payload->>'published_execution_snapshot_digest',
         'route',jsonb_build_object('profile_id',profile.id,'profile_revision',profile.revision,'route_id',route.id))
      OR profile.environment_id IS DISTINCT FROM NEW.environment_id OR profile.state IS DISTINCT FROM 'published' OR profile.status<>1
      OR profile.delete_time IS NOT NULL OR route.delete_time IS NOT NULL OR route.status<>1
      OR route.deployment_id IS DISTINCT FROM deployment.id OR route.capability_code IS DISTINCT FROM 'image_ocr'
      OR route.driver_code IS DISTINCT FROM 'local_paddle_ocr'
      OR NOT EXISTS(SELECT 1 FROM sand_ai_config_revision configuration WHERE configuration.resource_type='capability_profile'
          AND configuration.resource_id=profile.id AND configuration.revision=profile.revision
          AND configuration.state='published' AND configuration.status=1 AND configuration.delete_time IS NULL) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_BINDING_INVALID';
    END IF;
    IF frozen->>'price_revision_id' IS DISTINCT FROM (SELECT id::text FROM sand_ai_price_revision
      WHERE provider_id=provider.id AND model_id=model.id AND capability_code='image_ocr'
        AND state='published' AND delete_time IS NULL AND effective_from<=NEW.create_time
        AND (effective_until IS NULL OR effective_until>NEW.create_time)
      ORDER BY effective_from DESC,id DESC LIMIT 1) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_CURRENT_PRICE_CHANGED';
    END IF;
    IF frozen->>'price_revision_id' IS NULL THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_UNPRICED_BINDING_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT price FROM sand_ai_price_revision WHERE id=(frozen->>'price_revision_id')::bigint;
    SELECT * INTO STRICT rate FROM sand_ai_price_rate WHERE price_revision_id=price.id AND meter_code='external_request';
    amount=round(rate.unit_price/rate.per_quantity,6);
    IF price.provider_id IS DISTINCT FROM provider.id OR price.model_id IS DISTINCT FROM model.id
      OR price.capability_code IS DISTINCT FROM 'image_ocr' OR price.state IS DISTINCT FROM 'published' OR price.delete_time IS NOT NULL
      OR price.effective_from>NEW.create_time OR (price.effective_until IS NOT NULL AND price.effective_until<=NEW.create_time)
      OR (SELECT count(*) FROM sand_ai_price_rate WHERE price_revision_id=price.id)<>1
      OR frozen->>'currency' IS DISTINCT FROM price.currency::text OR (frozen->>'amount')::numeric IS DISTINCT FROM amount
      OR frozen->'snapshot' IS DISTINCT FROM jsonb_build_object('snapshot_version','ocr_request_price@1','unit','external_request','quantity',1,
          'price_revision_id',price.id,'currency',price.currency,'effective_from',to_char(price.effective_from,'YYYY-MM-DD HH24:MI:SS'),
          'effective_until',CASE WHEN price.effective_until IS NULL THEN NULL ELSE to_char(price.effective_until,'YYYY-MM-DD HH24:MI:SS') END,
          'rate',jsonb_build_object('unit_price',rate.unit_price::text,'per_quantity',rate.per_quantity),'fee_scope','configured_service_fee') THEN
        RAISE EXCEPTION 'SAND_AI_OCR_PRICE_BINDING_INVALID';
    END IF;
    RETURN NEW;
END $$;

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610090152_local_ocr_empty_configuration_binding','167fea6796ec75924f3adb2ba1dbecfa9c64b876bf8dfc7936b07fcdaf2cdfd3',CURRENT_TIMESTAMP(0));

-- Migration 202610100153_provider_response_identity (sha256 4d7e97b606bbf60fb6568df0d7fe7d0077e4f0f6e581fe8547a3e384cc34e308)
-- Received identifiers are observations, never usage, payment or billing matches.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
CREATE TABLE sand_ai_provider_response_identity (
    id bigserial PRIMARY KEY,
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT,
    dispatch_id bigint NOT NULL REFERENCES sand_ai_invocation_dispatch(id) ON DELETE RESTRICT,
    organization_id bigint NOT NULL CHECK (organization_id > 0),
    application_id bigint NOT NULL CHECK (application_id > 0),
    environment_id bigint NOT NULL CHECK (environment_id > 0),
    workload_client_id bigint NOT NULL CHECK (workload_client_id > 0),
    provider_id bigint NOT NULL CHECK (provider_id > 0),
    model_id bigint NOT NULL CHECK (model_id > 0),
    deployment_id bigint NOT NULL CHECK (deployment_id > 0),
    attempt_no integer NOT NULL CHECK (attempt_no > 0),
    execution_snapshot_sha256 char(64) NOT NULL CHECK (execution_snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    request_body_digest char(64) NOT NULL CHECK (request_body_digest ~ '^[a-f0-9]{64}$'),
    identity_kind varchar(16) NOT NULL,
    source varchar(32) NOT NULL,
    identifier varchar(128) COLLATE "C" NOT NULL CHECK (identifier ~ '^[A-Za-z0-9_.:-]{1,128}$'),
    received_time timestamp(0) without time zone NOT NULL DEFAULT (clock_timestamp() AT TIME ZONE 'UTC'),
    CONSTRAINT sand_ai_provider_identity_source_v153 CHECK (
        (identity_kind='request_id' AND source IN ('header.x-request-id','header.request-id'))
        OR (identity_kind='response_id' AND source IN ('body.id','stream.id'))),
    CONSTRAINT sand_ai_provider_identity_value_v153 UNIQUE(dispatch_id,source,identifier)
);
CREATE INDEX sand_ai_provider_identity_invocation_v153 ON sand_ai_provider_response_identity(invocation_id,id);

CREATE FUNCTION sand_ai_provider_identity_binding_v153() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    invocation sand_ai_invocation%ROWTYPE;
    dispatch sand_ai_invocation_dispatch%ROWTYPE;
BEGIN
    SELECT d.* INTO STRICT dispatch FROM sand_ai_invocation_dispatch d WHERE d.id=NEW.dispatch_id FOR UPDATE;
    SELECT i.* INTO STRICT invocation FROM sand_ai_invocation i WHERE i.id=dispatch.invocation_id;
    IF NEW.invocation_id IS DISTINCT FROM invocation.id
      OR NEW.organization_id IS DISTINCT FROM invocation.organization_id
      OR NEW.application_id IS DISTINCT FROM invocation.application_id
      OR NEW.environment_id IS DISTINCT FROM invocation.environment_id
      OR NEW.workload_client_id IS DISTINCT FROM invocation.workload_client_id
      OR NEW.provider_id IS DISTINCT FROM dispatch.provider_id
      OR NEW.model_id IS DISTINCT FROM dispatch.model_id OR NEW.model_id IS DISTINCT FROM invocation.model_id
      OR NEW.deployment_id IS DISTINCT FROM dispatch.deployment_id OR NEW.deployment_id IS DISTINCT FROM invocation.deployment_id
      OR NEW.environment_id IS DISTINCT FROM dispatch.environment_id
      OR NEW.attempt_no IS DISTINCT FROM dispatch.attempt_no
      OR NEW.execution_snapshot_sha256 IS DISTINCT FROM dispatch.execution_snapshot_sha256
      OR NEW.request_body_digest IS DISTINCT FROM dispatch.request_body_digest
      OR dispatch.dispatch_start_time IS NULL OR dispatch.state IN ('prepared','not_dispatched') THEN
        RAISE EXCEPTION 'SAND_AI_PROVIDER_IDENTITY_BINDING_INVALID';
    END IF;
    IF (SELECT count(*) FROM sand_ai_provider_response_identity r WHERE r.dispatch_id=dispatch.id)>=8
      OR (SELECT count(*) FROM sand_ai_provider_response_identity r WHERE r.dispatch_id=dispatch.id AND r.source=NEW.source)>=2 THEN
        RAISE EXCEPTION 'SAND_AI_PROVIDER_IDENTITY_LIMIT_EXCEEDED';
    END IF;
    -- Ignore any supplied timestamp; receive time is always generated here.
    NEW.received_time := clock_timestamp() AT TIME ZONE 'UTC';
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_provider_identity_binding_v153 BEFORE INSERT ON sand_ai_provider_response_identity
    FOR EACH ROW EXECUTE FUNCTION sand_ai_provider_identity_binding_v153();

CREATE FUNCTION sand_ai_provider_identity_immutable_v153() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'SandAI received provider identifiers are append-only';
END $$;
CREATE TRIGGER sand_ai_provider_identity_immutable_v153 BEFORE UPDATE OR DELETE ON sand_ai_provider_response_identity
    FOR EACH ROW EXECUTE FUNCTION sand_ai_provider_identity_immutable_v153();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610100153_provider_response_identity','4d7e97b606bbf60fb6568df0d7fe7d0077e4f0f6e581fe8547a3e384cc34e308',CURRENT_TIMESTAMP(0));

-- Migration 202610100154_skill_failed_attempt (sha256 30e896ac94bf71a686281c18d93654568301b65b04c2e141ba0b76da7275655e)
-- Preserve every failed run and its invocation; recovery creates a separate run.
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS original_run_id bigint NULL REFERENCES sand_ai_skill_run(id);
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS previous_run_id bigint NULL REFERENCES sand_ai_skill_run(id);
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS attempt_no smallint NOT NULL DEFAULT 1;
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS retry_feedback text NULL;
ALTER TABLE sand_ai_skill_run ADD CONSTRAINT ck_sand_ai_skill_run_attempt CHECK (
    (attempt_no = 1 AND original_run_id IS NULL AND previous_run_id IS NULL AND retry_feedback IS NULL)
    OR (attempt_no BETWEEN 2 AND 3 AND original_run_id IS NOT NULL AND previous_run_id IS NOT NULL
        AND original_run_id < id AND previous_run_id < id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_skill_run_previous_attempt ON sand_ai_skill_run(previous_run_id)
    WHERE previous_run_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_skill_run_original_attempt ON sand_ai_skill_run(original_run_id, attempt_no)
    WHERE original_run_id IS NOT NULL;

CREATE FUNCTION sand_ai_skill_run_attempt_guard_v154() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original sand_ai_skill_run%ROWTYPE; previous sand_ai_skill_run%ROWTYPE;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.original_run_id IS DISTINCT FROM NEW.original_run_id
            OR OLD.previous_run_id IS DISTINCT FROM NEW.previous_run_id
            OR OLD.attempt_no IS DISTINCT FROM NEW.attempt_no
            OR OLD.retry_feedback IS DISTINCT FROM NEW.retry_feedback THEN
            RAISE EXCEPTION 'Skill attempt identity and correction are immutable';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.attempt_no = 1 THEN RETURN NEW; END IF;
    SELECT * INTO original FROM sand_ai_skill_run WHERE id = NEW.original_run_id;
    SELECT * INTO previous FROM sand_ai_skill_run WHERE id = NEW.previous_run_id;
    IF original.id IS NULL OR previous.id IS NULL OR original.attempt_no <> 1
        OR previous.state <> 'failed' OR previous.delete_time IS NOT NULL OR original.delete_time IS NOT NULL
        OR previous.error_code NOT IN ('SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_SKILL_OUTPUT_INVALID')
        OR COALESCE(previous.original_run_id, previous.id) <> original.id
        OR NEW.attempt_no <> previous.attempt_no + 1
        OR NEW.environment_id <> original.environment_id OR NEW.skill_id <> original.skill_id
        OR NEW.skill_version_id <> original.skill_version_id OR NEW.run_kind <> original.run_kind
        OR NEW.model_id IS DISTINCT FROM original.model_id
        OR NEW.request_fingerprint IS DISTINCT FROM original.request_fingerprint
        OR NEW.input IS DISTINCT FROM original.input
        OR NEW.consumer_context IS DISTINCT FROM original.consumer_context
        OR NEW.citations IS DISTINCT FROM original.citations
        OR NEW.prompt_contract_version <> original.prompt_contract_version THEN
        RAISE EXCEPTION 'Skill recovery must preserve the original request and fixed version';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_sand_ai_skill_run_attempt_guard_v154 BEFORE INSERT OR UPDATE ON sand_ai_skill_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_run_attempt_guard_v154();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610100154_skill_failed_attempt','30e896ac94bf71a686281c18d93654568301b65b04c2e141ba0b76da7275655e',CURRENT_TIMESTAMP(0));

-- Migration 202610120155_billing_line_evidence (sha256 9b8b70bbd2adb5b53633429491e8f14b71b7b7495a14356c6f5ad6a4f649ca77)
CREATE TABLE sand_ai_billing_line_evidence (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    namespace VARCHAR(64) NOT NULL CHECK (namespace = 'manual_evidence_v1'),
    billing_line_id BIGINT NOT NULL REFERENCES sand_ai_billing_line(id) ON DELETE RESTRICT,
    provider_id BIGINT NOT NULL REFERENCES sand_ai_provider(id) ON DELETE RESTRICT,
    evidence_id BIGINT NOT NULL REFERENCES sand_ai_billing_evidence(id) ON DELETE RESTRICT,
    evidence_sha256 CHAR(64) NOT NULL CHECK (evidence_sha256 ~ '^[a-f0-9]{64}$'),
    evidence_locator VARCHAR(256) NOT NULL
        CHECK (evidence_locator <> '' AND evidence_locator = btrim(evidence_locator)
            AND evidence_locator !~ '[[:cntrl:]]'),
    locator_basis VARCHAR(32) NOT NULL CHECK (locator_basis = 'operator_declared'),
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_ai_billing_line_evidence_source
        UNIQUE (namespace, provider_id, evidence_sha256, evidence_locator),
    CONSTRAINT uk_sand_ai_billing_line_evidence_line UNIQUE (billing_line_id)
);

CREATE FUNCTION sand_ai_billing_line_evidence_binding_v155() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    line_provider BIGINT;
    line_identity VARCHAR(24);
    original_evidence sand_ai_billing_evidence%ROWTYPE;
BEGIN
    SELECT original.provider_id, line.identity_state INTO STRICT line_provider, line_identity
        FROM sand_ai_billing_line line
        JOIN sand_ai_billing_import original ON original.id = line.billing_import_id
        WHERE line.id = NEW.billing_line_id FOR SHARE OF line, original;
    SELECT * INTO STRICT original_evidence FROM sand_ai_billing_evidence
        WHERE id = NEW.evidence_id FOR SHARE;
    IF line_provider IS DISTINCT FROM NEW.provider_id
        OR line_identity IS DISTINCT FROM 'identity_unverified'
        OR original_evidence.provider_id IS DISTINCT FROM NEW.provider_id
        OR original_evidence.sha256 IS DISTINCT FROM NEW.evidence_sha256
        OR original_evidence.state IS DISTINCT FROM 'available' THEN
        RAISE EXCEPTION 'SAND_AI_BILLING_EVIDENCE_BINDING_INVALID';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_billing_line_evidence_binding_v155
    BEFORE INSERT ON sand_ai_billing_line_evidence
    FOR EACH ROW EXECUTE FUNCTION sand_ai_billing_line_evidence_binding_v155();
CREATE TRIGGER sand_ai_billing_line_evidence_immutable_v155
    BEFORE UPDATE OR DELETE ON sand_ai_billing_line_evidence
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();

COMMENT ON TABLE sand_ai_billing_line_evidence IS
    'Immutable private-file/declared-locator identity for manual reimports; never provider authentication';
COMMENT ON COLUMN sand_ai_billing_line_evidence.evidence_locator IS
    'Exact operator-declared position after edge trimming; not a parser-certified row';

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610120155_billing_line_evidence','9b8b70bbd2adb5b53633429491e8f14b71b7b7495a14356c6f5ad6a4f649ca77',CURRENT_TIMESTAMP(0));

-- Migration 202610120156_billing_evidence_xlsx (sha256 482950cf515405109433b983307f616482d2c2b61c065b243209eff4a1d15edf)
DO $sand_ai_evidence_xlsx_v156$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_attribute
        WHERE attrelid = 'sand_ai_billing_evidence'::regclass AND attname = 'media_type'
            AND NOT attisdropped AND format_type(atttypid, atttypmod) = 'character varying(64)'
    ) OR NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'sand_ai_billing_evidence'::regclass
            AND conname = 'sand_ai_billing_evidence_media_type_check' AND contype = 'c' AND convalidated
    ) THEN
        RAISE EXCEPTION 'Original billing evidence MIME definition differs; explicit adoption is required';
    END IF;
END $sand_ai_evidence_xlsx_v156$;

ALTER TABLE sand_ai_billing_evidence ALTER COLUMN media_type TYPE VARCHAR(128);
ALTER TABLE sand_ai_billing_evidence DROP CONSTRAINT sand_ai_billing_evidence_media_type_check;
ALTER TABLE sand_ai_billing_evidence ADD CONSTRAINT sand_ai_billing_evidence_media_type_check
    CHECK (media_type IN (
        'application/pdf', 'text/plain', 'text/csv', 'application/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ));

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610120156_billing_evidence_xlsx','482950cf515405109433b983307f616482d2c2b61c065b243209eff4a1d15edf',CURRENT_TIMESTAMP(0));

-- Migration 202610130157_provider_call_limit (sha256 3e484a80f35ec68697d11ee8d674e5b9acd0d79ecaa38d6ba03106c857a4aae2)
-- SandAI dispatch governance, not a SandIAM grant or financial budget.
CREATE TABLE sand_ai_provider_call_limit_pool (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code VARCHAR(64) NOT NULL CONSTRAINT uq_sand_ai_provider_call_limit_code UNIQUE CHECK (code ~ '^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$'),
    max_calls INTEGER NOT NULL CHECK (max_calls > 0),
    state VARCHAR(16) NOT NULL DEFAULT 'active' CHECK (state IN ('active','closed','blocked')),
    start_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    expire_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL CHECK (expire_time > start_time),
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE sand_ai_provider_call_limit_binding (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    pool_id BIGINT NOT NULL REFERENCES sand_ai_provider_call_limit_pool(id),
    organization_id BIGINT NOT NULL CHECK (organization_id > 0),
    application_id BIGINT NOT NULL CHECK (application_id > 0),
    environment_id BIGINT NOT NULL CHECK (environment_id > 0),
    workload_client_id BIGINT NOT NULL CONSTRAINT uq_sand_ai_provider_call_limit_caller UNIQUE CHECK (workload_client_id > 0),
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX sand_ai_provider_call_limit_binding_pool_idx ON sand_ai_provider_call_limit_binding(pool_id);
CREATE TABLE sand_ai_provider_call_limit_claim (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    pool_id BIGINT NOT NULL REFERENCES sand_ai_provider_call_limit_pool(id),
    dispatch_id BIGINT NOT NULL CONSTRAINT uq_sand_ai_provider_call_limit_dispatch UNIQUE REFERENCES sand_ai_invocation_dispatch(id),
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX sand_ai_provider_call_limit_claim_pool_idx ON sand_ai_provider_call_limit_claim(pool_id);

CREATE FUNCTION sand_ai_provider_call_limit_immutable_v157() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'SAND_AI_PROVIDER_CALL_LIMIT_IMMUTABLE';
END $$;
CREATE TRIGGER sand_ai_provider_call_limit_binding_immutable_v157
    BEFORE UPDATE OR DELETE ON sand_ai_provider_call_limit_binding
    FOR EACH ROW EXECUTE FUNCTION sand_ai_provider_call_limit_immutable_v157();
CREATE TRIGGER sand_ai_provider_call_limit_claim_immutable_v157
    BEFORE UPDATE OR DELETE ON sand_ai_provider_call_limit_claim
    FOR EACH ROW EXECUTE FUNCTION sand_ai_provider_call_limit_immutable_v157();

CREATE FUNCTION sand_ai_provider_call_limit_pool_guard_v157() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'SAND_AI_PROVIDER_CALL_LIMIT_IMMUTABLE';
    END IF;
    IF (to_jsonb(NEW) - 'state' - 'update_time') IS DISTINCT FROM
       (to_jsonb(OLD) - 'state' - 'update_time')
       OR (OLD.state <> NEW.state AND NOT (OLD.state = 'active' AND NEW.state IN ('closed','blocked'))) THEN
        RAISE EXCEPTION 'SAND_AI_PROVIDER_CALL_LIMIT_IMMUTABLE';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_provider_call_limit_pool_guard_v157
    BEFORE UPDATE OR DELETE ON sand_ai_provider_call_limit_pool
    FOR EACH ROW EXECUTE FUNCTION sand_ai_provider_call_limit_pool_guard_v157();

CREATE FUNCTION sand_ai_provider_call_limit_unknown_v157() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.state = 'ambiguous' THEN
        UPDATE sand_ai_provider_call_limit_pool SET state = 'blocked', update_time = CURRENT_TIMESTAMP(0)
        WHERE state = 'active' AND id IN (
            SELECT pool_id FROM sand_ai_provider_call_limit_claim WHERE dispatch_id = NEW.id
        );
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_provider_call_limit_unknown_v157
    AFTER UPDATE OF state ON sand_ai_invocation_dispatch
    FOR EACH ROW EXECUTE FUNCTION sand_ai_provider_call_limit_unknown_v157();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610130157_provider_call_limit','3e484a80f35ec68697d11ee8d674e5b9acd0d79ecaa38d6ba03106c857a4aae2',CURRENT_TIMESTAMP(0));

-- Claim time is not provider transmission. The original live send boundary alone authorizes this terminal edge.
-- No historical dispatch is updated; terminal immutability and provider-visible delete guards stay intact.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_state_transition_v026() RETURNS trigger AS $$
BEGIN
    IF OLD.state IS DISTINCT FROM NEW.state
       AND NOT ((OLD.state = 'prepared' AND NEW.state IN ('dispatching', 'not_dispatched'))
                OR (OLD.state = 'dispatching' AND NEW.state IN ('succeeded', 'failed', 'ambiguous', 'not_dispatched'))) THEN
        RAISE EXCEPTION 'invalid SandAI dispatch state transition: % -> %', OLD.state, NEW.state;
    END IF;
    IF OLD.state = 'dispatching' AND NEW.state = 'not_dispatched' THEN
        IF NEW.dispatch_complete_time IS NULL OR NEW.error_code IS NULL OR btrim(NEW.error_code) = ''
           OR EXISTS (SELECT 1 FROM sand_ai_provider_response_identity WHERE dispatch_id = OLD.id)
           OR EXISTS (SELECT 1 FROM sand_ai_usage_event WHERE invocation_id = OLD.invocation_id) THEN
            RAISE EXCEPTION 'claimed SandAI unsent terminal conflicts with provider evidence';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
COMMENT ON FUNCTION sand_ai_invocation_dispatch_state_transition_v026() IS 'sand-ai:202610130158_claimed_unsent_terminal';

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610130158_claimed_unsent_terminal','61bf688f7d0d998ad82548bb5329c3e7e8bb452cf1bbee01dbd236a7f02a8d5f',CURRENT_TIMESTAMP(0));

-- New uploads only. Never infer original request keys from historical hashes/audits.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
ALTER TABLE sand_ai_file ADD COLUMN upload_request_key_sha256 char(64) NULL;
ALTER TABLE sand_ai_file ADD CONSTRAINT sand_ai_file_upload_request_check_v159 CHECK (
    upload_request_key_sha256 IS NULL OR
    (upload_request_key_sha256 ~ '^[a-f0-9]{64}$' AND owner_scope IS NOT NULL
     AND owner_scope ~ '^[a-f0-9]{64}$' AND owner_attribution IS NOT NULL
     AND jsonb_typeof(owner_attribution) = 'object')
);
CREATE UNIQUE INDEX sand_ai_file_upload_request_unique_v159
    ON sand_ai_file(environment_id,owner_scope,upload_request_key_sha256)
    WHERE upload_request_key_sha256 IS NOT NULL;
CREATE FUNCTION sand_ai_file_upload_request_guard_v159() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.upload_request_key_sha256 IS NOT NULL AND
           (NEW.state <> 'upload_pending' OR NEW.status <> 1 OR NEW.delete_time IS NOT NULL) THEN
            RAISE EXCEPTION 'New keyed upload must begin with a durable pending intent';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'DELETE' THEN
        IF OLD.upload_request_key_sha256 IS NOT NULL THEN
            RAISE EXCEPTION 'Retain keyed upload tombstone for original-request recovery';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.upload_request_key_sha256 IS DISTINCT FROM NEW.upload_request_key_sha256 THEN
        RAISE EXCEPTION 'Upload request association is immutable; historical backfill is forbidden';
    END IF;
    IF OLD.upload_request_key_sha256 IS NOT NULL THEN
        IF ROW(OLD.environment_id,OLD.owner_scope,OLD.owner_attribution,OLD.file_ref,
               OLD.original_name,OLD.extension,OLD.media_type,OLD.size_bytes,OLD.sha256,
               OLD.storage_driver,OLD.object_key) IS DISTINCT FROM
           ROW(NEW.environment_id,NEW.owner_scope,NEW.owner_attribution,NEW.file_ref,
               NEW.original_name,NEW.extension,NEW.media_type,NEW.size_bytes,NEW.sha256,
               NEW.storage_driver,NEW.object_key) THEN
            RAISE EXCEPTION 'Keyed upload identity and input facts are immutable';
        END IF;
        IF (OLD.state = 'upload_pending' AND NEW.state NOT IN ('upload_pending','uploaded','deleting'))
           OR (OLD.state <> 'upload_pending' AND NEW.state = 'upload_pending') THEN
            RAISE EXCEPTION 'Invalid keyed upload pending transition';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_file_upload_request_guard_v159
    BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_file
    FOR EACH ROW EXECUTE FUNCTION sand_ai_file_upload_request_guard_v159();

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610130159_file_upload_request','c2fc41e877333478f5ee90e4f0d36f65a40afc26f2fbf01caa0d8fd15ee7c3cc',CURRENT_TIMESTAMP(0));


-- Migration 202610270160_menu_permission_seeds (sha256 760adc5d8a2bfc1e95f5de0d025799f4e4567fed7be63ca720800a5017b8c5e1)
-- SandAI administrator navigation and button permission seeds for the remaining
-- configuration surfaces. Seeds only make permissions grantable; no role receives
-- any new grant here. Roles already holding a page read permission receive only
-- the matching navigation links. The file center stays navigation-only like the
-- task trial page and still requires a real SandIAM workload identity; budget
-- policy stays platform superadmin only.
DO $menu_permission_seeds$
DECLARE
    root_id bigint;
    page_id bigint;
    node_id bigint;
    link_id bigint;
    count_nodes integer;
    page record;
    specification record;
    granted_role record;
    navigation_id bigint;
BEGIN
    IF to_regclass('sand_ai_configuration_navigation_ownership') IS NULL THEN
        RAISE EXCEPTION 'SandAI configuration navigation ownership is missing';
    END IF;
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,root_id FROM sand_system_menu WHERE code='SandAI';
    IF count_nodes<>1 OR NOT EXISTS(
        SELECT 1 FROM sand_system_menu WHERE id=root_id AND parent_id=0 AND type=1
          AND path='/sand-ai' AND COALESCE(component,'')='' AND COALESCE(slug,'')=''
          AND status=1 AND delete_time IS NULL
    ) THEN RAISE EXCEPTION 'SandAI root navigation is missing or incompatible'; END IF;

    FOR page IN SELECT * FROM (VALUES
        ('概览','SandAIOverview','dashboard','/plugin/sand-ai/dashboard/index',95,'sand_ai:overview:index'::text),
        ('模型','SandAIModel','model','/plugin/sand-ai/model/index',65,'sand_ai:model:index'),
        ('调用记录','SandAIInvocation','invocation','/plugin/sand-ai/invocation/index',36,'sand_ai:invocation:index'),
        ('审计','SandAIAudit','audit','/plugin/sand-ai/audit/index',24,'sand_ai:audit:index'),
        ('文件中心','SandAIFileCenter','file','/plugin/sand-ai/file/index',48,NULL),
        ('费用与账单','SandAIUsage','usage','/plugin/sand-ai/usage/index',40,'sand_ai:usage:index'),
        ('能力配置','SandAICapability','capability','/plugin/sand-ai/capability/index',80,'sand_ai:setup:capability:read'),
        ('智能体配置','SandAIAgentConfiguration','agent','/plugin/sand-ai/agent/index',40,'sand_ai:agent:definition:read'),
        ('知识库配置','SandAIKnowledgeConfiguration','knowledge','/plugin/sand-ai/knowledge/index',30,'sand_ai:knowledge:index')
    ) AS pages(name,code,path,component,sort,read_permission) LOOP
        SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu WHERE code=page.code;
        IF count_nodes>1 OR EXISTS(
            SELECT 1 FROM sand_system_menu WHERE parent_id=root_id AND path=page.path AND code IS DISTINCT FROM page.code
        ) THEN RAISE EXCEPTION 'SandAI navigation conflicts: %',page.code; END IF;
        IF count_nodes=0 THEN
            INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
            VALUES(root_id,page.name,page.code,'',2,page.path,page.component,'',page.sort,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            RETURNING id INTO page_id;
            INSERT INTO sand_ai_configuration_navigation_ownership
            SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
            FROM sand_system_menu menu WHERE id=page_id;
        ELSIF NOT EXISTS(
            SELECT 1 FROM sand_system_menu WHERE id=page_id AND parent_id=root_id AND type=2
              AND name=page.name AND path=page.path AND component=page.component
              AND COALESCE(slug,'')='' AND is_hidden=0 AND status=1 AND delete_time IS NULL
        ) THEN RAISE EXCEPTION 'Existing SandAI navigation is incompatible: %',page.code; END IF;

        IF page.read_permission IS NULL THEN
            -- Navigation-only surface: no administrator-to-workload conversion
            -- and no role eligibility guess.
            IF EXISTS(SELECT 1 FROM sand_system_role_menu WHERE menu_id=page_id) THEN
                RAISE EXCEPTION 'SandAI navigation-only page has unexpected role links: %',page.code;
            END IF;
            CONTINUE;
        END IF;

        FOR specification IN SELECT * FROM (VALUES
            ('SandAIOverview','查看概览','sand_ai:overview:index',1),
            ('SandAIModel','模型列表','sand_ai:model:index',1),
            ('SandAIModel','模型读取','sand_ai:model:read',2),
            ('SandAIModel','模型保存','sand_ai:model:save',3),
            ('SandAIModel','模型更新','sand_ai:model:update',4),
            ('SandAIModel','模型删除','sand_ai:model:destroy',5),
            ('SandAIInvocation','查看调用记录','sand_ai:invocation:index',1),
            ('SandAIAudit','查看审计记录','sand_ai:audit:index',1),
            ('SandAIUsage','单次费用','sand_ai:usage:cost',10),
            ('SandAIUsage','费用汇总','sand_ai:usage:summary',11),
            ('SandAIUsage','账单凭证','sand_ai:billing:credential',12),
            ('SandAIUsage','同步账单','sand_ai:billing:sync',13),
            ('SandAIUsage','价格目录','sand_ai:pricing:index',14),
            ('SandAIUsage','保存价格草稿','sand_ai:pricing:save',15),
            ('SandAIUsage','发布价格','sand_ai:pricing:publish',16),
            ('SandAICapability','法律分析配置读取','sand_ai:setup:legal-profile:read',10),
            ('SandAICapability','保存法律分析配置草稿','sand_ai:setup:legal-profile:save',11),
            ('SandAICapability','发布法律分析配置','sand_ai:setup:legal-profile:publish',12),
            ('SandAICapability','撤销法律分析配置','sand_ai:setup:legal-profile:revoke',13),
            ('SandAICapability','录音转写契约','sand_ai:setup:audio-transcription:read',14),
            ('SandAIAgentConfiguration','管理智能体配置','sand_ai:agent:definition:manage',10),
            ('SandAIAgentConfiguration','查看渠道绑定','sand_ai:channel:binding:read',11),
            ('SandAIAgentConfiguration','管理渠道绑定','sand_ai:channel:binding:manage',12),
            ('SandAIAgentConfiguration','查看外部资料工具','sand_ai:tool:read',13),
            ('SandAIAgentConfiguration','创建外部资料连接','sand_ai:tool:connection:create',14),
            ('SandAIAgentConfiguration','创建外部资料工具草稿','sand_ai:tool:create',15),
            ('SandAIAgentConfiguration','发现受管 MCP 工具','sand_ai:tool:mcp:discover',16),
            ('SandAIAgentConfiguration','发布外部资料工具','sand_ai:tool:publish',17),
            ('SandAIAgentConfiguration','绑定外部资料工具','sand_ai:tool:bind',18),
            ('SandAIAgentConfiguration','撤销外部资料工具','sand_ai:tool:revoke',19),
            ('SandAIKnowledgeConfiguration','创建知识库草稿','sand_ai:knowledge:create',10),
            ('SandAIKnowledgeConfiguration','发布知识库','sand_ai:knowledge:publish',11),
            ('SandAIKnowledgeConfiguration','停用知识库','sand_ai:knowledge:disable',12),
            ('SandAIKnowledgeConfiguration','建立知识向量索引','sand_ai:knowledge:index:build',13),
            ('SandAIKnowledgeConfiguration','知识库检索试用','sand_ai:knowledge:query:test',14),
            ('SandAIKnowledgeConfiguration','知识库应用访问列表','sand_ai:knowledge:publication:index',15),
            ('SandAIKnowledgeConfiguration','开放知识库给应用','sand_ai:knowledge:publication:publish',16),
            ('SandAIKnowledgeConfiguration','撤销知识库应用访问','sand_ai:knowledge:publication:revoke',17),
            ('SandAIKnowledgeConfiguration','读取 Skill','sand_ai:skill:read',18),
            ('SandAIKnowledgeConfiguration','创建 Skill 草稿','sand_ai:skill:create',19),
            ('SandAIKnowledgeConfiguration','测试 Skill','sand_ai:skill:test',20),
            ('SandAIKnowledgeConfiguration','发布 Skill','sand_ai:skill:publish',21),
            ('SandAIKnowledgeConfiguration','撤销 Skill','sand_ai:skill:revoke',22),
            ('SandAIKnowledgeConfiguration','管理 Skill 案例','sand_ai:skill:case:manage',23)
        ) AS permissions(page_code,name,code,sort) WHERE page_code=page.code LOOP
            SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu
            WHERE code=specification.code OR slug=specification.code;
            IF count_nodes>1 THEN RAISE EXCEPTION 'SandAI permission is duplicated: %',specification.code; END IF;
            IF count_nodes=0 THEN
                INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
                VALUES(page_id,specification.name,specification.code,specification.code,3,'','','',specification.sort,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                RETURNING id INTO node_id;
                INSERT INTO sand_ai_configuration_navigation_ownership
                SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
                FROM sand_system_menu menu WHERE id=node_id;
            ELSIF NOT EXISTS(
                SELECT 1 FROM sand_system_menu WHERE id=node_id AND code=specification.code
                  AND slug=specification.code AND type=3 AND status=1 AND delete_time IS NULL
            ) THEN RAISE EXCEPTION 'Existing SandAI permission is incompatible: %',specification.code; END IF;
        END LOOP;

        -- The super-administrator already reads all menus through SandAdmin.
        -- Other roles receive only navigation corresponding to a prior read grant.
        FOR granted_role IN
            SELECT DISTINCT role_menu.role_id FROM sand_system_role_menu role_menu
            JOIN sand_system_menu permission ON permission.id=role_menu.menu_id
            JOIN sand_system_role role ON role.id=role_menu.role_id
            WHERE permission.type=3 AND permission.status=1 AND permission.delete_time IS NULL
              AND role.status=1 AND role.delete_time IS NULL AND permission.slug=page.read_permission
        LOOP
            FOREACH navigation_id IN ARRAY ARRAY[root_id,page_id] LOOP
                IF NOT EXISTS(SELECT 1 FROM sand_system_role_menu WHERE role_id=granted_role.role_id AND menu_id=navigation_id) THEN
                    INSERT INTO sand_system_role_menu(role_id,menu_id) VALUES(granted_role.role_id,navigation_id) RETURNING id INTO link_id;
                    INSERT INTO sand_ai_configuration_navigation_ownership
                    SELECT 'role_menu',id,jsonb_build_object('role_id',role_id,'menu_id',menu_id)
                    FROM sand_system_role_menu WHERE id=link_id;
                END IF;
            END LOOP;
        END LOOP;
    END LOOP;
END
$menu_permission_seeds$;

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610270160_menu_permission_seeds','760adc5d8a2bfc1e95f5de0d025799f4e4567fed7be63ca720800a5017b8c5e1',CURRENT_TIMESTAMP(0));

-- Migration 202610300161_provider_identity_admin_scope (sha256 3d1561d6dab24098aa2978e5ee2522ac749edcdde0fdfba522983471b5e1b9ec)
-- Administrator-scope invocations carry no SandIAM workload client. Their
-- received provider identifiers belong to the same dispatch and must stay
-- observable; the binding trigger already compares the invocation value with
-- IS DISTINCT FROM, so the absent client remains bound, never guessed.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
ALTER TABLE sand_ai_provider_response_identity ALTER COLUMN workload_client_id DROP NOT NULL;
ALTER TABLE sand_ai_provider_response_identity DROP CONSTRAINT sand_ai_provider_response_identity_workload_client_id_check;
ALTER TABLE sand_ai_provider_response_identity ADD CONSTRAINT sand_ai_provider_identity_workload_client_v161
    CHECK (workload_client_id IS NULL OR workload_client_id > 0);

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610300161_provider_identity_admin_scope','3d1561d6dab24098aa2978e5ee2522ac749edcdde0fdfba522983471b5e1b9ec',CURRENT_TIMESTAMP(0));

-- Migration 202610310162_meeting_review_workflow (sha256 6b3d40ff99cce6e74233317731b41012c4db3128f1b5ef65f3ff1a7554f1e982)
-- SandAI hands a meeting summary that its own output contract marked
-- review_required to the installed SandWorkflow plugin only when a named
-- administrator submits it. This consumer-owned reference binds the SandAI task
-- and its frozen transcript review revision to the approval instance that was
-- actually started, so the original key finds the original instance back.
--
-- Approval state stays in SandWorkflow and is always read back from it. No
-- decision, assignee action or final business effect is mirrored here, and no
-- row can exist for a task that did not require review: the bridge refuses the
-- handoff before reaching this table.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
CREATE TABLE sand_ai_meeting_review_workflow (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    organization_id bigint NOT NULL,
    application_id bigint NOT NULL,
    environment_id bigint NOT NULL,
    task_run_id bigint NOT NULL,
    audio_review_revision integer NOT NULL,
    request_key varchar(64) NOT NULL,
    workflow_definition_id integer NOT NULL,
    workflow_instance_id varchar(64) NOT NULL,
    definition_sha256 char(64) NOT NULL,
    tenant_id varchar(64) NOT NULL,
    owner_scope varchar(64) NOT NULL,
    business_type varchar(64) NOT NULL,
    business_id varchar(64) NOT NULL,
    initiator_account_space varchar(32) NOT NULL,
    initiator_account_id varchar(64) NOT NULL,
    participants jsonb NOT NULL,
    status smallint NOT NULL DEFAULT 1,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT ck_sand_ai_meeting_review_owner_v162 CHECK (
        organization_id > 0 AND application_id > 0 AND environment_id > 0
        AND task_run_id > 0 AND audio_review_revision >= 0 AND workflow_definition_id > 0),
    CONSTRAINT ck_sand_ai_meeting_review_scope_v162 CHECK (
        length(request_key) > 0 AND length(workflow_instance_id) > 0 AND length(tenant_id) > 0
        AND length(owner_scope) > 0 AND length(business_type) > 0 AND length(business_id) > 0
        AND length(initiator_account_id) > 0),
    CONSTRAINT ck_sand_ai_meeting_review_actor_v162 CHECK (initiator_account_space ~ '^[A-Za-z0-9_-]{1,32}$'),
    CONSTRAINT ck_sand_ai_meeting_review_blueprint_v162 CHECK (definition_sha256 ~ '^[a-f0-9]{64}$'),
    CONSTRAINT ck_sand_ai_meeting_review_participants_v162 CHECK (
        jsonb_typeof(participants) = 'object' AND participants <> '{}'::jsonb),
    CONSTRAINT ck_sand_ai_meeting_review_status_v162 CHECK (status = ANY (ARRAY[0, 1])),
    CONSTRAINT uq_sand_ai_meeting_review_task_v162 UNIQUE (environment_id, task_run_id, audio_review_revision),
    CONSTRAINT uq_sand_ai_meeting_review_key_v162 UNIQUE (environment_id, request_key),
    CONSTRAINT uq_sand_ai_meeting_review_instance_v162 UNIQUE (workflow_instance_id),
    CONSTRAINT fk_sand_ai_meeting_review_task_v162 FOREIGN KEY (task_run_id, environment_id)
        REFERENCES sand_ai_task_run (id, environment_id) ON DELETE RESTRICT
);
CREATE INDEX idx_sand_ai_meeting_review_blueprint_v162
    ON sand_ai_meeting_review_workflow (environment_id, definition_sha256, id);

INSERT INTO sand_ai_schema_migration(version,checksum,applied_time) VALUES('202610310162_meeting_review_workflow','6b3d40ff99cce6e74233317731b41012c4db3128f1b5ef65f3ff1a7554f1e982',CURRENT_TIMESTAMP(0));

COMMIT;
