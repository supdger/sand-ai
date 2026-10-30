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
