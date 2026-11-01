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
