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
