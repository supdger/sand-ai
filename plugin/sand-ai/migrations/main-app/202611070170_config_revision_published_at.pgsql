-- 0.4.10 audit A4: sand_ai_config_revision gains the published_at audit
-- timestamp that the capability-profile publish path and the onboarding
-- publication paths already write (CapabilityProfileGateway,
-- OnboardingPublicationService). Before this migration no persistent schema
-- declared the column, so the ORM silently dropped the value on save. New
-- publishes stamp it; existing rows keep NULL because their publish time was
-- never recorded.
ALTER TABLE sand_ai_config_revision
    ADD COLUMN IF NOT EXISTS published_at timestamp(0) without time zone NULL;
