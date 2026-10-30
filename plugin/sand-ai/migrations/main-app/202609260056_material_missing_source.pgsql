-- A parsed file can have no supported factual claim. Preserve its model call,
-- immutable result and usage without manufacturing a field citation.
ALTER TABLE sand_ai_workflow_file_result
    DROP CONSTRAINT ck_sand_ai_workflow_file_result_source_count;

ALTER TABLE sand_ai_workflow_file_result
    ADD CONSTRAINT ck_sand_ai_workflow_file_result_source_count
    CHECK (field_source_count >= 0);
