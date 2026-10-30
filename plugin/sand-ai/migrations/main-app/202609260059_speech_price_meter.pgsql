-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_price_rate
    DROP CONSTRAINT ck_sand_ai_price_rate_meter;
ALTER TABLE sand_ai_price_rate
    ADD CONSTRAINT ck_sand_ai_price_rate_meter
    CHECK (meter_code IN ('input_tokens', 'output_tokens', 'audio_seconds', 'input_characters'));
