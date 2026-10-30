DO $sand_ai_evidence_xlsx_v156$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_attribute
        WHERE attrelid = 'sand_ai_billing_evidence'::regclass AND attname = 'media_type'
            AND NOT attisdropped AND format_type(atttypid, atttypmod) = 'character varying(64)'
    ) OR NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'sand_ai_billing_evidence'::regclass
            AND conname = 'sand_ai_billing_evidence_media_type_check' AND contype = 'c' AND convalidated
    ) THEN
        RAISE EXCEPTION 'Original billing evidence MIME definition differs; explicit adoption is required';
    END IF;
END $sand_ai_evidence_xlsx_v156$;

ALTER TABLE sand_ai_billing_evidence ALTER COLUMN media_type TYPE VARCHAR(128);
ALTER TABLE sand_ai_billing_evidence DROP CONSTRAINT sand_ai_billing_evidence_media_type_check;
ALTER TABLE sand_ai_billing_evidence ADD CONSTRAINT sand_ai_billing_evidence_media_type_check
    CHECK (media_type IN (
        'application/pdf', 'text/plain', 'text/csv', 'application/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ));
