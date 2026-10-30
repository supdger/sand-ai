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
