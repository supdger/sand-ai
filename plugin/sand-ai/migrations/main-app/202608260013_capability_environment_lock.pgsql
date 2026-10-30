CREATE TABLE sand_ai_capability_environment_lock (
    environment_id bigint PRIMARY KEY,
    create_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sand_ai_capability_environment_lock_environment_positive CHECK (environment_id > 0)
);

COMMENT ON TABLE sand_ai_capability_environment_lock IS 'Operational row locks for serializing capability writes by full bigint environment reference';

