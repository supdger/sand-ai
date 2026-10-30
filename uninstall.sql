-- The sand_ai_public010_archive schema retains legacy data, private-object locators,
-- ciphertext and the committed backup intent. Normal uninstall does not remove it.
-- Cleanup inventory follows the migrations registered in install.sql and update.sql.
-- Explicit destructive operation. External dependencies stop this transaction.
BEGIN;
DO $sand_ai_uninstall$
DECLARE
    baseline_checksum char(64);
    unknown_tables text[];
BEGIN
    IF to_regclass(format('%I.sand_ai_schema_migration', current_schema())) IS NULL THEN
        RAISE EXCEPTION 'SandAI migration ledger is missing; refusing unified-product uninstall';
    END IF;
    SELECT checksum INTO baseline_checksum FROM sand_ai_schema_migration
    WHERE version = '202608200000_create_main_application_baseline';
    IF baseline_checksum IS DISTINCT FROM '9f49b93ae998206c88da49b5a08ab89963d18b938db11178b4a7e4d894699fec' THEN
        RAISE EXCEPTION 'SandAI baseline checksum mismatch; refusing uninstall';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610060133_knowledge_query_intent' AND checksum IS DISTINCT FROM '4860e90eb160290f3c1333ddeec125b2b6587c0b0db2d3f88bd3f83d8f167c26')
       OR ((to_regclass(format('%I.sand_ai_knowledge_query_intent',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_knowledge_query_intent_immutable()',current_schema())) IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610060133_knowledge_query_intent' AND checksum='4860e90eb160290f3c1333ddeec125b2b6587c0b0db2d3f88bd3f83d8f167c26')) THEN
        RAISE EXCEPTION 'SandAI knowledge query intent cleanup requires its exact registered checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610060138_knowledge_professional_rerank_soft_budget' AND checksum IS DISTINCT FROM 'f8092a02855fa55546b93321f37b35901b7e919a24728f1a3bb3a4ab2f22cdb3')
       OR ((to_regclass(format('%I.sand_ai_rerank_soft_budget',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_rerank_soft_budget_guard_v138()',current_schema())) IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610060138_knowledge_professional_rerank_soft_budget' AND checksum='f8092a02855fa55546b93321f37b35901b7e919a24728f1a3bb3a4ab2f22cdb3')) THEN
        RAISE EXCEPTION 'Soft budget cleanup requires its exact registered checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610060140_tool_fulltext_file_lifecycle' AND checksum IS DISTINCT FROM '3654997477ca5682d29807de9a7b474b5d8a3353eef9298ca8d528cfb841f3fb')
       OR ((to_regclass(format('%I.sand_ai_tool_fulltext_file',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_tool_fulltext_settled_v140(bigint,boolean)',current_schema())) IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610060140_tool_fulltext_file_lifecycle' AND checksum='3654997477ca5682d29807de9a7b474b5d8a3353eef9298ca8d528cfb841f3fb')) THEN
        RAISE EXCEPTION 'Private tool fulltext cleanup requires its exact registered checksum';
    END IF;
    -- Only registered exact migrations authorize cleanup of these new owned objects.
    -- Unregistered candidate objects require a separately reviewed adoption path.
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610030120_meeting_terminal_transition' AND checksum IS DISTINCT FROM '760ce10f04018ef58b9fc86bc239600338c8f497ecb3443bd35d9a59f58f2cf9')
       OR ((to_regclass(format('%I.sand_ai_meeting_terminal_transition',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_meeting_terminal_proof_guard()',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_meeting_terminal_capture()',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_meeting_terminal_step(bigint,text)',current_schema())) IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610030120_meeting_terminal_transition' AND checksum='760ce10f04018ef58b9fc86bc239600338c8f497ecb3443bd35d9a59f58f2cf9')) THEN
        RAISE EXCEPTION 'SandAI 202610030120_meeting_terminal_transition cleanup requires its exact registered checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610030122_agent_stream_event' AND checksum IS DISTINCT FROM '72a128052af06479a558919eeee9c6fdd871b1ac993b3ec223bbbbb680cd362c')
       OR ((to_regclass(format('%I.sand_ai_agent_stream_event',current_schema())) IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610030122_agent_stream_event' AND checksum='72a128052af06479a558919eeee9c6fdd871b1ac993b3ec223bbbbb680cd362c')) THEN
        RAISE EXCEPTION 'SandAI 202610030122_agent_stream_event cleanup requires its exact registered checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610040124_meeting_execution_registration' AND checksum IS DISTINCT FROM 'c02b8f998bf9d7faa6ef30aba6bc1452663e7c1b19697952a9cbe05d88742591')
       OR (to_regclass(format('%I.sand_ai_meeting_execution_registration',current_schema())) IS NOT NULL
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610040124_meeting_execution_registration' AND checksum='c02b8f998bf9d7faa6ef30aba6bc1452663e7c1b19697952a9cbe05d88742591')) THEN
        RAISE EXCEPTION 'SandAI 202610040124_meeting_execution_registration cleanup requires its exact registered checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610310162_meeting_review_workflow' AND checksum IS DISTINCT FROM '6b3d40ff99cce6e74233317731b41012c4db3128f1b5ef65f3ff1a7554f1e982')
       OR (to_regclass(format('%I.sand_ai_meeting_review_workflow',current_schema())) IS NOT NULL
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610310162_meeting_review_workflow' AND checksum='6b3d40ff99cce6e74233317731b41012c4db3128f1b5ef65f3ff1a7554f1e982')) THEN
        RAISE EXCEPTION 'SandAI 202610310162_meeting_review_workflow cleanup requires its exact registered checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610040125_usage_refund_adjustment' AND checksum IS DISTINCT FROM '66b1bc89fe3d692152d28661f057764038a3c348da444abee39ad866f86cfec1') THEN
        RAISE EXCEPTION 'SandAI 202610040125_usage_refund_adjustment cleanup requires its exact checksum';
    END IF;
    IF to_regclass(format('%I.sand_ai_usage_adjustment',current_schema())) IS NOT NULL
       AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610040125_usage_refund_adjustment' AND checksum='66b1bc89fe3d692152d28661f057764038a3c348da444abee39ad866f86cfec1') THEN
        RAISE EXCEPTION 'Unregistered SandAI refund table; explicit adoption required';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610040126_usage_adjustment_permissions' AND checksum IS DISTINCT FROM 'fc8774c619ccde0ce6b83383068fa92d89ef41bbc7688b6af6c2c73cd778f6a8') THEN
        RAISE EXCEPTION 'SandAI 202610040126_usage_adjustment_permissions cleanup requires its exact checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610040127_meeting_local_planning' AND checksum IS DISTINCT FROM 'ece6e307bf4975f8706624452ffd59adbfcbac744963891fb9343435ff6e3ba1') THEN
        RAISE EXCEPTION 'SandAI 202610040127_meeting_local_planning cleanup requires its exact checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610040128_mcp_stable_protocol_profiles' AND checksum IS DISTINCT FROM '292303bb7f8fdca184c6376b724442c1ce638842bd69588d2aff32f9711c327b') THEN
        RAISE EXCEPTION 'SandAI 202610040128_mcp_stable_protocol_profiles cleanup requires its exact checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610040130_admin_validation_usage' AND checksum IS DISTINCT FROM 'fed113b08a61b9330d4c9d29f345a6b09f6cdc64227130a779246dbd41e7935a')
       OR ((to_regclass(format('%I.sand_ai_admin_validation_attempt',current_schema())) IS NOT NULL
            OR to_regclass(format('%I.sand_ai_admin_validation_receipt',current_schema())) IS NOT NULL
            OR to_regclass(format('%I.sand_ai_admin_validation_draft_binding',current_schema())) IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610040130_admin_validation_usage' AND checksum='fed113b08a61b9330d4c9d29f345a6b09f6cdc64227130a779246dbd41e7935a')) THEN
        RAISE EXCEPTION 'SandAI admin validation cleanup requires its exact registered checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610070141_composition_workflow' AND checksum IS DISTINCT FROM 'ed3e884f59d6bcb357bc5969fe1c99f1bb5c4eccb35966553b91540fbedfda0a')
       OR ((to_regclass(format('%I.sand_ai_composition_definition',current_schema())) IS NOT NULL
         OR to_regprocedure(format('%I.sand_ai_composition_immutable_published()',current_schema())) IS NOT NULL)
         AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610070141_composition_workflow' AND checksum='ed3e884f59d6bcb357bc5969fe1c99f1bb5c4eccb35966553b91540fbedfda0a')) THEN
        RAISE EXCEPTION 'AI composition cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610070142_billing_operation_receipt' AND checksum IS DISTINCT FROM '901eb42d0fd771367feaf664d27718a67f4c5c9c0d2c1a8ead35bc36d4591163')
       OR ((to_regclass(format('%I.sand_ai_billing_operation_receipt',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_billing_operation_receipt_guard()',current_schema())) IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610070142_billing_operation_receipt' AND checksum='901eb42d0fd771367feaf664d27718a67f4c5c9c0d2c1a8ead35bc36d4591163')) THEN
        RAISE EXCEPTION 'Billing original operation cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610070143_meeting_expired_reclaim' AND checksum IS DISTINCT FROM '4c3d2afc7b4eaefc7797b1a1e5ded740dec2c4e303a560f32d3d327794750de3')
       OR ((to_regprocedure(format('%I.sand_ai_meeting_expired_reclaim_dispatch_sealed(bigint)',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_meeting_expired_reclaim_dispatch_unsent_closed(bigint)',current_schema())) IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610070143_meeting_expired_reclaim' AND checksum='4c3d2afc7b4eaefc7797b1a1e5ded740dec2c4e303a560f32d3d327794750de3')) THEN
        RAISE EXCEPTION 'Meeting expired reclaim cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090146_pricing_operation_receipt' AND checksum IS DISTINCT FROM '5a802077c9bdcd33f1b28262023417a75d4a55674c3c2f4b13cb8eba7d8c0f34')
       OR ((to_regclass(format('%I.sand_ai_pricing_operation',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_pricing_operation_immutable()',current_schema())) IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090146_pricing_operation_receipt' AND checksum='5a802077c9bdcd33f1b28262023417a75d4a55674c3c2f4b13cb8eba7d8c0f34')) THEN
        RAISE EXCEPTION 'Pricing original operation cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090147_catalog_operation_receipt_guard' AND checksum IS DISTINCT FROM '6d9d2da698414d1cd534b5eb7ceecbcd558dfd613610fe09f9456b92ec443c4c')
       OR ((to_regclass(format('%I.sand_ai_catalog_operation_receipt_key',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_catalog_operation_receipt_immutable()',current_schema())) IS NOT NULL
            OR EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='sand_ai_catalog_operation_receipt_immutable'
                      AND tgrelid=to_regclass(format('%I.sand_ai_audit_log',current_schema())) AND NOT tgisinternal))
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090147_catalog_operation_receipt_guard' AND checksum='6d9d2da698414d1cd534b5eb7ceecbcd558dfd613610fe09f9456b92ec443c4c')) THEN
        RAISE EXCEPTION 'Catalogue original operation cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090148_billing_credential_operation_receipt' AND checksum IS DISTINCT FROM '0d778d260da6e9613d7e6a2642c4568368b1fe65ebc22c559ec868e37ee477a1')
       OR ((to_regclass(format('%I.sand_ai_billing_credential_operation_key',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_billing_credential_operation_immutable()',current_schema())) IS NOT NULL
            OR EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='sand_ai_billing_credential_operation_immutable'
                      AND tgrelid=to_regclass(format('%I.sand_ai_audit_log',current_schema())) AND NOT tgisinternal))
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090148_billing_credential_operation_receipt' AND checksum='0d778d260da6e9613d7e6a2642c4568368b1fe65ebc22c559ec868e37ee477a1')) THEN
        RAISE EXCEPTION 'Billing credential original operation cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090149_legal_profile_operation_receipt_guard' AND checksum IS DISTINCT FROM '9e31953c47eb22d48f3a542529b1156d1c9d424f56c7005452a7bb9520a20e4f')
       OR ((to_regclass(format('%I.sand_ai_legal_profile_operation_key',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_legal_profile_operation_immutable()',current_schema())) IS NOT NULL
            OR EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='sand_ai_legal_profile_operation_immutable'
                      AND tgrelid=to_regclass(format('%I.sand_ai_audit_log',current_schema())) AND NOT tgisinternal))
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090149_legal_profile_operation_receipt_guard' AND checksum='9e31953c47eb22d48f3a542529b1156d1c9d424f56c7005452a7bb9520a20e4f')) THEN
        RAISE EXCEPTION 'Legal profile original operation cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090150_local_ocr_deployment_price_budget' AND checksum IS DISTINCT FROM 'ce57ad4e9e60d9496b646dcd584dbb4f6e33d38a4fc67f3f49f94850fee1e11c')
       OR ((to_regprocedure(format('%I.sand_ai_ocr_frozen_binding_v150()',current_schema())) IS NOT NULL
            OR to_regclass(format('%I.uq_sand_ai_budget_ocr_v150',current_schema())) IS NOT NULL
            OR EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='trg_sand_ai_ocr_frozen_binding_v150'
                      AND tgrelid=to_regclass(format('%I.sand_ai_ocr_dispatch',current_schema())) AND NOT tgisinternal))
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090150_local_ocr_deployment_price_budget' AND checksum='ce57ad4e9e60d9496b646dcd584dbb4f6e33d38a4fc67f3f49f94850fee1e11c')) THEN
        RAISE EXCEPTION 'Local OCR deployment price budget cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090151_local_ocr_revision_binding' AND checksum IS DISTINCT FROM '9d437f6e968987b987817c4ac060cc0acbf054b19baf34d8a3335d2392fbf794') THEN
        RAISE EXCEPTION 'Local OCR revision binding cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610090152_local_ocr_empty_configuration_binding' AND checksum IS DISTINCT FROM '167fea6796ec75924f3adb2ba1dbecfa9c64b876bf8dfc7936b07fcdaf2cdfd3') THEN
        RAISE EXCEPTION 'Local OCR empty configuration binding cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610100153_provider_response_identity' AND checksum IS DISTINCT FROM '4d7e97b606bbf60fb6568df0d7fe7d0077e4f0f6e581fe8547a3e384cc34e308')
       OR ((to_regclass(format('%I.sand_ai_provider_response_identity',current_schema())) IS NOT NULL
            OR to_regclass(format('%I.sand_ai_provider_identity_invocation_v153',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_provider_identity_binding_v153()',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_provider_identity_immutable_v153()',current_schema())) IS NOT NULL
            OR EXISTS(SELECT 1 FROM pg_trigger WHERE tgname IN ('sand_ai_provider_identity_binding_v153','sand_ai_provider_identity_immutable_v153')
                      AND tgrelid=to_regclass(format('%I.sand_ai_provider_response_identity',current_schema())) AND NOT tgisinternal))
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610100153_provider_response_identity' AND checksum='4d7e97b606bbf60fb6568df0d7fe7d0077e4f0f6e581fe8547a3e384cc34e308')) THEN
        RAISE EXCEPTION 'Provider response identity cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610100154_skill_failed_attempt' AND checksum IS DISTINCT FROM '30e896ac94bf71a686281c18d93654568301b65b04c2e141ba0b76da7275655e')
       OR ((to_regprocedure(format('%I.sand_ai_skill_run_attempt_guard_v154()',current_schema())) IS NOT NULL
            OR to_regclass(format('%I.uq_sand_ai_skill_run_previous_attempt',current_schema())) IS NOT NULL
            OR to_regclass(format('%I.uq_sand_ai_skill_run_original_attempt',current_schema())) IS NOT NULL
            OR EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid=to_regclass(format('%I.sand_ai_skill_run',current_schema()))
                      AND attname IN ('original_run_id','previous_run_id','attempt_no','retry_feedback') AND NOT attisdropped)
            OR EXISTS(SELECT 1 FROM pg_constraint WHERE conname='ck_sand_ai_skill_run_attempt'
                      AND conrelid=to_regclass(format('%I.sand_ai_skill_run',current_schema())))
            OR EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='trg_sand_ai_skill_run_attempt_guard_v154'
                      AND tgrelid=to_regclass(format('%I.sand_ai_skill_run',current_schema())) AND NOT tgisinternal))
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610100154_skill_failed_attempt' AND checksum='30e896ac94bf71a686281c18d93654568301b65b04c2e141ba0b76da7275655e')) THEN
        RAISE EXCEPTION 'Skill failed attempt cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610120155_billing_line_evidence' AND checksum IS DISTINCT FROM '9b8b70bbd2adb5b53633429491e8f14b71b7b7495a14356c6f5ad6a4f649ca77')
       OR ((to_regclass(format('%I.sand_ai_billing_line_evidence',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_billing_line_evidence_binding_v155()',current_schema())) IS NOT NULL) AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610120155_billing_line_evidence' AND checksum='9b8b70bbd2adb5b53633429491e8f14b71b7b7495a14356c6f5ad6a4f649ca77')) THEN
        RAISE EXCEPTION 'Billing line evidence cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610120156_billing_evidence_xlsx' AND checksum IS DISTINCT FROM '482950cf515405109433b983307f616482d2c2b61c065b243209eff4a1d15edf')
       OR (EXISTS(SELECT 1 FROM pg_attribute
            WHERE attrelid=to_regclass(format('%I.sand_ai_billing_evidence',current_schema()))
                AND attname='media_type' AND NOT attisdropped
                AND format_type(atttypid,atttypmod) IS DISTINCT FROM 'character varying(64)') AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610120156_billing_evidence_xlsx' AND checksum='482950cf515405109433b983307f616482d2c2b61c065b243209eff4a1d15edf')) THEN
        RAISE EXCEPTION 'Billing evidence MIME cleanup requires its exact registered migration checksum';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610130159_file_upload_request' AND checksum IS DISTINCT FROM 'c2fc41e877333478f5ee90e4f0d36f65a40afc26f2fbf01caa0d8fd15ee7c3cc')
       OR ((EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid=to_regclass('sand_ai_file') AND attname='upload_request_key_sha256' AND NOT attisdropped)
            OR to_regprocedure('sand_ai_file_upload_request_guard_v159()') IS NOT NULL
            OR to_regclass('sand_ai_file_upload_request_unique_v159') IS NOT NULL)
           AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610130159_file_upload_request' AND checksum='c2fc41e877333478f5ee90e4f0d36f65a40afc26f2fbf01caa0d8fd15ee7c3cc')) THEN
        RAISE EXCEPTION 'SandAI upload association cleanup requires exact migration ledger';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610130158_claimed_unsent_terminal' AND checksum IS DISTINCT FROM '61bf688f7d0d998ad82548bb5329c3e7e8bb452cf1bbee01dbd236a7f02a8d5f') THEN
        RAISE EXCEPTION 'SandAI migration 158 checksum mismatch';
    END IF;
    IF to_regprocedure('sand_ai_invocation_dispatch_state_transition_v026()') IS NOT NULL
       AND obj_description(to_regprocedure('sand_ai_invocation_dispatch_state_transition_v026()'),'pg_proc') = 'sand-ai:202610130158_claimed_unsent_terminal'
       AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610130158_claimed_unsent_terminal' AND checksum='61bf688f7d0d998ad82548bb5329c3e7e8bb452cf1bbee01dbd236a7f02a8d5f') THEN
        RAISE EXCEPTION 'SandAI migration 158 function exists without its ledger';
    END IF;
    IF EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610130157_provider_call_limit' AND checksum IS DISTINCT FROM '3e484a80f35ec68697d11ee8d674e5b9acd0d79ecaa38d6ba03106c857a4aae2') THEN
        RAISE EXCEPTION 'Provider call limit migration checksum differs';
    END IF;
    IF (to_regclass(format('%I.sand_ai_provider_call_limit_claim',current_schema())) IS NOT NULL
            OR to_regclass(format('%I.sand_ai_provider_call_limit_binding',current_schema())) IS NOT NULL
            OR to_regclass(format('%I.sand_ai_provider_call_limit_pool',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_provider_call_limit_immutable_v157()',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_provider_call_limit_pool_guard_v157()',current_schema())) IS NOT NULL
            OR to_regprocedure(format('%I.sand_ai_provider_call_limit_unknown_v157()',current_schema())) IS NOT NULL)
       AND NOT EXISTS(SELECT 1 FROM sand_ai_schema_migration WHERE version='202610130157_provider_call_limit' AND checksum='3e484a80f35ec68697d11ee8d674e5b9acd0d79ecaa38d6ba03106c857a4aae2') THEN
        RAISE EXCEPTION 'Provider call limit objects require their exact registered migration';
    END IF;
    SELECT array_agg(object.relname) INTO unknown_tables
    FROM pg_class AS object
    JOIN pg_namespace AS namespace ON namespace.oid = object.relnamespace
    WHERE namespace.nspname = current_schema()
      AND object.relname LIKE 'sand\_ai\_%' ESCAPE '\'
      AND object.relkind IN ('r', 'p', 'v', 'm', 'f')
      AND object.relname NOT IN ('sand_ai_provider_call_limit_claim','sand_ai_provider_call_limit_binding','sand_ai_provider_call_limit_pool','sand_ai_billing_line_evidence','sand_ai_provider_response_identity','sand_ai_pricing_operation','sand_ai_billing_operation_receipt','sand_ai_composition_run_operation','sand_ai_composition_node','sand_ai_composition_run','sand_ai_composition_operation','sand_ai_composition_version','sand_ai_composition_definition', 'sand_ai_rerank_soft_budget', 'sand_ai_admin_validation_attempt', 'sand_ai_admin_validation_receipt', 'sand_ai_admin_validation_draft_binding', 'sand_ai_usage_adjustment', 'sand_ai_meeting_review_workflow', 'sand_ai_meeting_execution_registration', 'sand_ai_agent_stream_event', 'sand_ai_meeting_terminal_transition', 'sand_ai_agent_definition', 'sand_ai_agent_run_file', 'sand_ai_agent_version', 'sand_ai_agent_operation', 'sand_ai_artifact', 'sand_ai_artifact_field_source', 'sand_ai_audio_chunk_manifest','sand_ai_audio_chunk','sand_ai_audio_chunk_store','sand_ai_audio_chunk_preparation','sand_ai_audio_chunk_object', 'sand_ai_audio_segment_review', 'sand_ai_audio_transcript_seal', 'sand_ai_audit_log', 'sand_ai_billing_evidence', 'sand_ai_billing_review', 'sand_ai_billing_navigation_ownership', 'sand_ai_configuration_navigation_ownership', 'sand_ai_billing_import', 'sand_ai_billing_import_line', 'sand_ai_billing_line', 'sand_ai_budget_event', 'sand_ai_budget_policy_operation', 'sand_ai_budget_policy', 'sand_ai_budget_reservation', 'sand_ai_budget_window', 'sand_ai_capability_environment_lock', 'sand_ai_capability_profile', 'sand_ai_capability_route', 'sand_ai_channel_binding', 'sand_ai_channel_operation', 'sand_ai_feishu_delivery', 'sand_ai_config_revision', 'sand_ai_external_audio_dispatch', 'sand_ai_external_audio_receipt', 'sand_ai_file', 'sand_ai_file_parse', 'sand_ai_invocation', 'sand_ai_invocation_dispatch', 'sand_ai_invocation_outcome_receipt', 'sand_ai_knowledge_base', 'sand_ai_knowledge_conversation', 'sand_ai_knowledge_conversation_turn', 'sand_ai_knowledge_conversation_segment', 'sand_ai_knowledge_conversation_summary', 'sand_ai_knowledge_conversation_summary_call', 'sand_ai_knowledge_base_source', 'sand_ai_knowledge_base_version', 'sand_ai_knowledge_query_intent', 'sand_ai_knowledge_operation', 'sand_ai_knowledge_publication', 'sand_ai_knowledge_publication_operation', 'sand_ai_legal_analysis_profile', 'sand_ai_material_analysis_profile', 'sand_ai_model', 'sand_ai_model_deployment', 'sand_ai_pre_dispatch_rejection', 'sand_ai_price_rate', 'sand_ai_price_revision', 'sand_ai_provider', 'sand_ai_schema_migration', 'sand_ai_skill_source_suggestion_purge', 'sand_ai_skill_source_suggestion', 'sand_ai_skill', 'sand_ai_skill_case', 'sand_ai_skill_case_evaluation', 'sand_ai_skill_knowledge_ref', 'sand_ai_skill_operation', 'sand_ai_skill_run', 'sand_ai_skill_version', 'sand_ai_source_block', 'sand_ai_agent_checkpoint', 'sand_ai_task_run', 'sand_ai_task_step', 'sand_ai_tool', 'sand_ai_tool_attempt', 'sand_ai_tool_connection', 'sand_ai_tool_connection_revision', 'sand_ai_tool_environment_binding', 'sand_ai_tool_mcp_snapshot', 'sand_ai_tool_mcp_snapshot_item', 'sand_ai_tool_operation', 'sand_ai_tool_fulltext_file', 'sand_ai_tool_outcome_receipt', 'sand_ai_tool_protocol_request', 'sand_ai_tool_run', 'sand_ai_tool_source', 'sand_ai_tool_usage_seal', 'sand_ai_tool_version', 'sand_ai_usage', 'sand_ai_usage_event', 'sand_ai_usage_reconciliation', 'sand_ai_ocr_dispatch', 'sand_ai_ocr_dispatch_outcome', 'sand_ai_meeting_token_count_request', 'sand_ai_meeting_token_count_outcome', 'sand_ai_workflow_file_output_failure', 'sand_ai_workflow_file_result', 'sand_ai_workflow_file_result_source', 'sand_ai_workflow_cross_file_review', 'sand_ai_workflow_cross_file_review_result', 'sand_ai_workflow_cross_file_review_source', 'sand_ai_workflow_cross_file_review_withheld', 'sand_ai_workflow_run', 'sand_ai_workflow_run_file', 'sand_ai_channel_conversation_close', 'sand_ai_feishu_channel_credential', 'sand_ai_feishu_channel_status', 'sand_ai_feishu_credential_operation', 'sand_ai_knowledge_embedding', 'sand_ai_knowledge_embedding_active', 'sand_ai_knowledge_embedding_index', 'sand_ai_meeting_summary_node', 'sand_ai_speech_run', 'sand_ai_wecom_channel_credential', 'sand_ai_wecom_channel_status', 'sand_ai_wecom_credential_operation', 'sand_ai_wecom_delivery', 'sand_ai_meeting_leaf_part_page', 'sand_ai_meeting_plan_checkpoint', 'sand_ai_meeting_plan_event', 'sand_ai_meeting_plan_page', 'sand_ai_meeting_plan_tokenizer_counter', 'sand_ai_meeting_worker_checkpoint', 'sand_ai_meeting_worker_event', 'sand_ai_meeting_worker_page');
    IF unknown_tables IS NOT NULL THEN
        RAISE EXCEPTION 'Unknown SandAI schema objects remain: %', unknown_tables;
    END IF;
END
$sand_ai_uninstall$;
DO $configuration_uninstall$
DECLARE
    owned record;
BEGIN
    IF to_regclass(format('%I.sand_ai_configuration_navigation_ownership',current_schema())) IS NOT NULL THEN
        LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
        LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
        FOR owned IN SELECT * FROM sand_ai_configuration_navigation_ownership WHERE object_kind='role_menu' LOOP
            DELETE FROM sand_system_role_menu
            WHERE id=owned.object_id AND jsonb_build_object('role_id',role_id,'menu_id',menu_id)=owned.definition;
        END LOOP;
        -- Only unchanged nodes inserted by this package are removed. Borrowed,
        -- externally edited, child-bearing or role-linked nodes remain in the host.
        FOR owned IN
            SELECT ownership.object_id,ownership.definition FROM sand_ai_configuration_navigation_ownership ownership
            JOIN sand_system_menu menu ON menu.id=ownership.object_id
            WHERE ownership.object_kind='menu' ORDER BY menu.type DESC,menu.id DESC
        LOOP
            DELETE FROM sand_system_menu menu
            WHERE menu.id=owned.object_id
              AND (to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by'])=owned.definition
              AND NOT EXISTS(SELECT 1 FROM sand_system_menu child WHERE child.parent_id=menu.id)
              AND NOT EXISTS(SELECT 1 FROM sand_system_role_menu role_menu WHERE role_menu.menu_id=menu.id);
        END LOOP;
    END IF;
END
$configuration_uninstall$;
DO $billing_uninstall$
DECLARE
    owned record;
BEGIN
    IF to_regclass(format('%I.sand_ai_billing_evidence',current_schema())) IS NOT NULL THEN
        LOCK TABLE sand_ai_billing_evidence IN ACCESS EXCLUSIVE MODE;
        IF EXISTS(SELECT 1 FROM sand_ai_billing_evidence WHERE object_key IS NOT NULL) THEN
            RAISE EXCEPTION 'Clear billing evidence through its private content lifecycle before uninstall';
        END IF;
    END IF;
    IF to_regclass(format('%I.sand_ai_billing_navigation_ownership',current_schema())) IS NOT NULL THEN
        LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
        LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
        FOR owned IN SELECT * FROM sand_ai_billing_navigation_ownership WHERE object_kind='role_menu' LOOP
            DELETE FROM sand_system_role_menu
            WHERE id=owned.object_id
              AND jsonb_build_object('role_id',role_id,'menu_id',menu_id)=owned.definition;
        END LOOP;
        -- Permission leaves, then the page, then the root. Borrowed or externally edited nodes remain.
        FOR owned IN
            SELECT ownership.object_id,ownership.definition FROM sand_ai_billing_navigation_ownership ownership
            JOIN sand_system_menu menu ON menu.id=ownership.object_id
            WHERE ownership.object_kind='menu' ORDER BY menu.type DESC,menu.id DESC
        LOOP
            DELETE FROM sand_system_menu menu
            WHERE menu.id=owned.object_id
              AND (to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by'])=owned.definition
              AND NOT EXISTS(SELECT 1 FROM sand_system_menu child WHERE child.parent_id=menu.id)
              AND NOT EXISTS(SELECT 1 FROM sand_system_role_menu role_menu WHERE role_menu.menu_id=menu.id);
        END LOOP;
    END IF;
END
$billing_uninstall$;

DROP FUNCTION IF EXISTS sand_ai_skill_source_redaction_allowed(sand_ai_skill_version,sand_ai_skill_version);

-- Physical private audio must be removed by the normal lifecycle before its ownership ledger is dropped.
-- Lock order matches preparation/cleanup: task -> preparation -> object. No file deletion occurs here.
DO $sand_ai_audio_chunk_uninstall_guard$
BEGIN
    IF to_regclass('sand_ai_audio_chunk_preparation') IS NOT NULL
       OR to_regclass('sand_ai_audio_chunk_object') IS NOT NULL THEN
        LOCK TABLE sand_ai_task_run IN ACCESS EXCLUSIVE MODE;
        IF to_regclass('sand_ai_audio_chunk_preparation') IS NOT NULL THEN
            LOCK TABLE sand_ai_audio_chunk_preparation IN ACCESS EXCLUSIVE MODE;
        END IF;
        IF to_regclass('sand_ai_audio_chunk_object') IS NOT NULL THEN
            LOCK TABLE sand_ai_audio_chunk_object IN ACCESS EXCLUSIVE MODE;
        END IF;
        IF to_regclass('sand_ai_audio_chunk_preparation') IS NOT NULL THEN
            IF EXISTS (SELECT 1 FROM sand_ai_audio_chunk_preparation WHERE state <> 'cleaned') THEN
                RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_CLEANUP_REQUIRED: remove private audio through its lifecycle before uninstall';
            END IF;
        END IF;
        IF to_regclass('sand_ai_audio_chunk_object') IS NOT NULL THEN
            IF EXISTS (SELECT 1 FROM sand_ai_audio_chunk_object WHERE state <> 'deleted') THEN
                RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_CLEANUP_REQUIRED: remove private audio through its lifecycle before uninstall';
            END IF;
        END IF;
    END IF;
END
$sand_ai_audio_chunk_uninstall_guard$;

-- This function returns the task row type; remove it before dropping that table.
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_task(bigint);
DROP TABLE IF EXISTS
    sand_ai_meeting_review_workflow,
    sand_ai_provider_response_identity,
    sand_ai_pricing_operation,
    sand_ai_billing_operation_receipt,
    sand_ai_composition_run_operation,
    sand_ai_composition_node,
    sand_ai_composition_run,
    sand_ai_composition_operation,
    sand_ai_composition_version,
    sand_ai_composition_definition,
    sand_ai_rerank_soft_budget,
    sand_ai_admin_validation_draft_binding,
    sand_ai_admin_validation_receipt,
    sand_ai_admin_validation_attempt,
    sand_ai_usage_adjustment,
    sand_ai_meeting_execution_registration,
    sand_ai_meeting_terminal_transition,
    sand_ai_agent_stream_event,
    sand_ai_meeting_leaf_part_page,
    sand_ai_meeting_worker_event,
    sand_ai_meeting_plan_event,
    sand_ai_meeting_worker_page,
    sand_ai_meeting_plan_page,
    sand_ai_meeting_plan_tokenizer_counter,
    sand_ai_meeting_worker_checkpoint,
    sand_ai_meeting_plan_checkpoint,
    sand_ai_ocr_dispatch_outcome,
    sand_ai_ocr_dispatch,
    sand_ai_meeting_token_count_outcome,
    sand_ai_meeting_token_count_request,
    sand_ai_workflow_file_output_failure,
    sand_ai_configuration_navigation_ownership,
    sand_ai_agent_definition,
    sand_ai_agent_run_file,
    sand_ai_agent_version,
    sand_ai_agent_operation,
    sand_ai_artifact,
    sand_ai_artifact_field_source,
    sand_ai_audio_chunk_manifest,
    sand_ai_audio_chunk,
    sand_ai_audio_chunk_store,
    sand_ai_audio_chunk_preparation,
    sand_ai_audio_chunk_object,
    sand_ai_audio_segment_review,
    sand_ai_audio_transcript_seal,
    sand_ai_audit_log,
    sand_ai_provider_call_limit_claim,
    sand_ai_provider_call_limit_binding,
    sand_ai_provider_call_limit_pool,
    sand_ai_billing_line_evidence,
    sand_ai_billing_review,
    sand_ai_billing_evidence,
    sand_ai_billing_navigation_ownership,
    sand_ai_billing_import,
    sand_ai_billing_import_line,
    sand_ai_billing_line,
    sand_ai_budget_event,
    sand_ai_budget_policy_operation,
    sand_ai_budget_policy,
    sand_ai_budget_reservation,
    sand_ai_budget_window,
    sand_ai_capability_environment_lock,
    sand_ai_capability_profile,
    sand_ai_capability_route,
    sand_ai_channel_binding,
    sand_ai_channel_operation,
    sand_ai_feishu_delivery,
    sand_ai_config_revision,
    sand_ai_external_audio_dispatch,
    sand_ai_external_audio_receipt,
    sand_ai_file,
    sand_ai_file_parse,
    sand_ai_invocation,
    sand_ai_invocation_dispatch,
    sand_ai_invocation_outcome_receipt,
    sand_ai_knowledge_base,
    sand_ai_knowledge_conversation,
    sand_ai_knowledge_conversation_turn,
    sand_ai_knowledge_conversation_segment,
    sand_ai_knowledge_conversation_summary,
    sand_ai_knowledge_conversation_summary_call,
    sand_ai_knowledge_base_source,
    sand_ai_knowledge_query_intent,
    sand_ai_knowledge_base_version,
    sand_ai_knowledge_operation,
    sand_ai_knowledge_publication,
    sand_ai_knowledge_publication_operation,
    sand_ai_legal_analysis_profile,
    sand_ai_material_analysis_profile,
    sand_ai_model,
    sand_ai_model_deployment,
    sand_ai_pre_dispatch_rejection,
    sand_ai_price_rate,
    sand_ai_price_revision,
    sand_ai_provider,
    sand_ai_schema_migration,
    sand_ai_skill_source_suggestion_purge,
    sand_ai_skill_source_suggestion,
    sand_ai_skill,
    sand_ai_skill_case,
    sand_ai_skill_case_evaluation,
    sand_ai_skill_knowledge_ref,
    sand_ai_skill_operation,
    sand_ai_skill_run,
    sand_ai_skill_version,
    sand_ai_source_block,
    sand_ai_agent_checkpoint,
    sand_ai_task_run,
    sand_ai_task_step,
    sand_ai_tool,
    sand_ai_tool_attempt,
    sand_ai_tool_connection,
    sand_ai_tool_connection_revision,
    sand_ai_tool_environment_binding,
    sand_ai_tool_mcp_snapshot,
    sand_ai_tool_mcp_snapshot_item,
    sand_ai_tool_operation,
    sand_ai_tool_fulltext_file,
    sand_ai_tool_outcome_receipt,
    sand_ai_tool_protocol_request,
    sand_ai_tool_run,
    sand_ai_tool_source,
    sand_ai_tool_usage_seal,
    sand_ai_tool_version,
    sand_ai_usage,
    sand_ai_usage_event,
    sand_ai_usage_reconciliation,
    sand_ai_workflow_run,
    sand_ai_workflow_run_file,
    sand_ai_workflow_file_result,
    sand_ai_workflow_file_result_source,
    sand_ai_workflow_cross_file_review,
    sand_ai_workflow_cross_file_review_result,
    sand_ai_workflow_cross_file_review_source,
    sand_ai_workflow_cross_file_review_withheld,
    sand_ai_channel_conversation_close,
    sand_ai_feishu_channel_credential,
    sand_ai_feishu_channel_status,
    sand_ai_feishu_credential_operation,
    sand_ai_knowledge_embedding,
    sand_ai_knowledge_embedding_active,
    sand_ai_knowledge_embedding_index,
    sand_ai_meeting_summary_node,
    sand_ai_speech_run,
    sand_ai_wecom_channel_credential,
    sand_ai_wecom_channel_status,
    sand_ai_wecom_credential_operation,
    sand_ai_wecom_delivery;
DROP FUNCTION IF EXISTS sand_ai_legal_profile_operation_immutable();
DROP FUNCTION IF EXISTS sand_ai_billing_credential_operation_immutable();
DROP FUNCTION IF EXISTS sand_ai_catalog_operation_receipt_immutable();
DROP FUNCTION IF EXISTS sand_ai_pricing_operation_immutable();
DROP FUNCTION IF EXISTS sand_ai_meeting_token_count_immutable();
DROP FUNCTION IF EXISTS sand_ai_workflow_file_output_failure_immutable();
DROP FUNCTION IF EXISTS sand_ai_billing_line_evidence_binding_v155();
DROP FUNCTION IF EXISTS sand_ai_billing_review_guard();
DROP FUNCTION IF EXISTS sand_ai_billing_evidence_guard();
DROP FUNCTION IF EXISTS sand_ai_audio_segment_review_immutable();
DROP FUNCTION IF EXISTS sand_ai_audio_usage_task_guard_v029();
DROP FUNCTION IF EXISTS sand_ai_enforce_active_knowledge_version();
DROP FUNCTION IF EXISTS sand_ai_external_audio_dispatch_immutable_v031();
DROP FUNCTION IF EXISTS sand_ai_external_audio_receipt_immutable_v031();
DROP FUNCTION IF EXISTS sand_ai_external_audio_sync_completion_v032();
DROP FUNCTION IF EXISTS sand_ai_invocation_dispatch_no_provider_visible_delete_v026();
DROP FUNCTION IF EXISTS sand_ai_invocation_dispatch_no_terminal_overwrite();
DROP FUNCTION IF EXISTS sand_ai_invocation_dispatch_state_transition_v026();
DROP FUNCTION IF EXISTS sand_ai_invocation_outcome_receipt_prevent_overwrite();
DROP FUNCTION IF EXISTS sand_ai_invocation_skill_identity_immutable_v019();
DROP FUNCTION IF EXISTS sand_ai_knowledge_source_immutable();
DROP FUNCTION IF EXISTS sand_ai_pre_dispatch_rejection_immutable_v027();
DROP FUNCTION IF EXISTS sand_ai_reject_billing_mutation();
DROP FUNCTION IF EXISTS sand_ai_reject_budget_evidence_mutation();
DROP FUNCTION IF EXISTS sand_ai_reject_call_start_pricing_mutation();
DROP FUNCTION IF EXISTS sand_ai_reject_published_budget_policy_mutation();
DROP FUNCTION IF EXISTS sand_ai_reject_published_knowledge_version_mutation();
DROP FUNCTION IF EXISTS sand_ai_reject_published_price_mutation();
DROP FUNCTION IF EXISTS sand_ai_reject_published_source_block_mutation();
DROP FUNCTION IF EXISTS sand_ai_reject_usage_event_mutation();
DROP FUNCTION IF EXISTS sand_ai_skill_active_version_guard_v019();
DROP FUNCTION IF EXISTS sand_ai_skill_case_guard();
DROP FUNCTION IF EXISTS sand_ai_skill_case_evaluation_guard();
DROP FUNCTION IF EXISTS sand_ai_skill_knowledge_ref_immutable_v019();
DROP FUNCTION IF EXISTS sand_ai_skill_operation_immutable_v019();
DROP FUNCTION IF EXISTS sand_ai_skill_publish_guard_v019();
DROP FUNCTION IF EXISTS sand_ai_skill_published_version_guard();
DROP FUNCTION IF EXISTS sand_ai_skill_run_invocation_match_v019();
DROP FUNCTION IF EXISTS sand_ai_skill_run_prompt_contract_immutable_v100();
DROP FUNCTION IF EXISTS sand_ai_skill_version_immutable();
DROP FUNCTION IF EXISTS sand_ai_tool_attempt_terminal_immutable_v023();
DROP FUNCTION IF EXISTS sand_ai_tool_binding_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_binding_published_guard_v023();
DROP FUNCTION IF EXISTS sand_ai_tool_immutable_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_mcp_binding_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_mcp_immutable_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_mcp_run_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_mcp_snapshot_item_insert_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_mcp_snapshot_terminal_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_mcp_version_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_protocol_request_insert_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_run_guard();
DROP FUNCTION IF EXISTS sand_ai_tool_run_terminal_immutable_v023();
DROP FUNCTION IF EXISTS sand_ai_tool_usage_protocol_quantity_guard();
DROP FUNCTION IF EXISTS sand_ai_usage_event_invocation_skill_match_v019();
DROP FUNCTION IF EXISTS sand_ai_workflow_cross_file_review_count_guard();
DROP FUNCTION IF EXISTS sand_ai_workflow_cross_file_review_evidence_immutable();
DROP FUNCTION IF EXISTS sand_ai_workflow_file_result_immutable();
DROP FUNCTION IF EXISTS sand_ai_workflow_file_result_source_count_guard();
DROP FUNCTION IF EXISTS sand_ai_embedding_cosine(real[], real[]);
DROP FUNCTION IF EXISTS sand_ai_guard_knowledge_embedding_active();
DROP FUNCTION IF EXISTS sand_ai_guard_knowledge_embedding_index();
DROP FUNCTION IF EXISTS sand_ai_guard_knowledge_embedding_vector();
DROP FUNCTION IF EXISTS sand_ai_meeting_summary_node_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_summary_node_parent_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_summary_node_purge();
DROP FUNCTION IF EXISTS sand_ai_speech_budget_link_guard_v062();
DROP FUNCTION IF EXISTS sand_ai_speech_usage_guard_v061();
DROP FUNCTION IF EXISTS sand_ai_budget_event_owner_guard_v093();
DROP FUNCTION IF EXISTS sand_ai_budget_owner_guard_v093();
DROP FUNCTION IF EXISTS sand_ai_budget_scope_matches_v093(text,text,jsonb,bigint,bigint,text);
DROP FUNCTION IF EXISTS sand_ai_skill_scope_immutable_v092();
DROP FUNCTION IF EXISTS sand_ai_skill_consumer_context_guard_v092();
DROP FUNCTION IF EXISTS sand_ai_skill_publish_consumer_guard_v092();
DROP FUNCTION IF EXISTS sand_ai_skill_source_suggestion_guard();
DROP FUNCTION IF EXISTS sand_ai_skill_provenance_immutable();
DROP FUNCTION IF EXISTS sand_ai_skill_source_purge_request_immutable();
DROP FUNCTION IF EXISTS sand_ai_skill_adopted_source_guard();
DROP FUNCTION IF EXISTS sand_ai_skill_source_suggestion_source_guard();
DROP FUNCTION IF EXISTS sand_ai_audio_transcript_seal_insert();
DROP FUNCTION IF EXISTS sand_ai_audio_transcript_seal_immutable();
DROP FUNCTION IF EXISTS sand_ai_audio_task_seal_guard();
DROP FUNCTION IF EXISTS sand_ai_audio_task_seal_purge();
DROP FUNCTION IF EXISTS sand_ai_ocr_usage_binding_v103();
DROP FUNCTION IF EXISTS sand_ai_ocr_dispatch_binding_v103();
DROP FUNCTION IF EXISTS sand_ai_reject_ocr_mutation_v103();
DROP FUNCTION IF EXISTS sand_ai_audio_manifest_coverage_v106();
DROP FUNCTION IF EXISTS sand_ai_audio_manifest_immutable_v106();
DROP FUNCTION IF EXISTS sand_ai_audio_manifest_source_guard_v106();
DROP FUNCTION IF EXISTS sand_ai_audio_chunk_lease_live_v107(timestamp without time zone);
DROP FUNCTION IF EXISTS sand_ai_external_audio_chunk_guard_v107();
DROP FUNCTION IF EXISTS sand_ai_audio_chunk_store_immutable_v108();
DROP FUNCTION IF EXISTS sand_ai_audio_chunk_object_guard_v108();
DROP FUNCTION IF EXISTS sand_ai_audio_chunk_preparation_guard_v108();
DROP FUNCTION IF EXISTS sand_ai_audio_chunk_durable_guard_v108();

-- Meeting triggers are removed with their owning SandAI tables above.
DROP FUNCTION IF EXISTS sand_ai_meeting_terminal_proof_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_terminal_capture();
DROP FUNCTION IF EXISTS sand_ai_meeting_expired_reclaim_dispatch_sealed(bigint);
DROP FUNCTION IF EXISTS sand_ai_meeting_expired_reclaim_dispatch_unsent_closed(bigint);
DROP FUNCTION IF EXISTS sand_ai_meeting_terminal_step(bigint,text);
DROP FUNCTION IF EXISTS sand_ai_meeting_leaf_part_commit();
DROP FUNCTION IF EXISTS sand_ai_meeting_leaf_part_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_node_message_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_checkpoint_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_commit_lease();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_event_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_event_head();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_page_commit();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_page_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_purge();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_task_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_tokenizer_counter_add();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_tokenizer_counter_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_plan_tokenizer_counter_init();
DROP FUNCTION IF EXISTS sand_ai_meeting_verification_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_verified_graph_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_assert_node_sealed(bigint);
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_checkpoint_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_completion_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_event_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_event_head();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_node_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_node_seal();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_page_commit();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_page_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_purge();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_step_guard();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_step_seal();
DROP FUNCTION IF EXISTS sand_ai_meeting_worker_task_anchor();
DROP FUNCTION IF EXISTS sand_ai_usage_adjustment_guard_v125();
-- Existing uninstall drops all owned tables in one DROP TABLE command, so new FKs do not change that order.
-- After table drop, remove only these new owned functions.
DROP FUNCTION IF EXISTS sand_ai_agent_tool_parent_guard_v129();
DROP FUNCTION IF EXISTS sand_ai_agent_version_published_guard_v129();

DROP FUNCTION IF EXISTS sand_ai_admin_validation_immutable_v130();
DROP FUNCTION IF EXISTS sand_ai_admin_validation_json_v130(jsonb);
DROP FUNCTION IF EXISTS sand_ai_admin_validation_attempt_guard_v130();
DROP FUNCTION IF EXISTS sand_ai_admin_validation_receipt_guard_v130();
DROP FUNCTION IF EXISTS sand_ai_admin_validation_event_guard_v130();
DROP FUNCTION IF EXISTS sand_ai_admin_validation_binding_guard_v130();
DROP FUNCTION IF EXISTS sand_ai_admin_validation_event_coverage_v130();

DROP FUNCTION IF EXISTS sand_ai_knowledge_query_intent_immutable();

-- The professional rerank trigger is removed with sand_ai_usage_event above.
DROP FUNCTION IF EXISTS sand_ai_professional_rerank_usage_guard_v137();

DROP FUNCTION IF EXISTS sand_ai_rerank_soft_budget_guard_v138();

DROP FUNCTION IF EXISTS sand_ai_tool_fulltext_settled_v140(bigint,boolean);
DROP FUNCTION IF EXISTS sand_ai_tool_fulltext_file_insert_v140();
DROP FUNCTION IF EXISTS sand_ai_tool_fulltext_deleted_file_v140(bigint);
DROP FUNCTION IF EXISTS sand_ai_tool_fulltext_receipt_purge_v140();

DROP FUNCTION IF EXISTS sand_ai_composition_immutable_published();
DROP FUNCTION IF EXISTS sand_ai_billing_operation_receipt_guard();
DROP FUNCTION IF EXISTS sand_ai_ocr_frozen_binding_v150();
DROP FUNCTION IF EXISTS sand_ai_provider_identity_binding_v153();
DROP FUNCTION IF EXISTS sand_ai_provider_identity_immutable_v153();
-- The attempt trigger, constraints and indexes are removed with sand_ai_skill_run above.
DROP FUNCTION IF EXISTS sand_ai_skill_run_attempt_guard_v154();

DROP FUNCTION IF EXISTS sand_ai_provider_call_limit_immutable_v157();
DROP FUNCTION IF EXISTS sand_ai_provider_call_limit_pool_guard_v157();
DROP FUNCTION IF EXISTS sand_ai_provider_call_limit_unknown_v157();
DROP FUNCTION IF EXISTS sand_ai_file_upload_request_guard_v159();
COMMIT;
