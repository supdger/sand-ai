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
