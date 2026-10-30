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
