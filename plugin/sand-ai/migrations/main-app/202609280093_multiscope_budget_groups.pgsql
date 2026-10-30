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
