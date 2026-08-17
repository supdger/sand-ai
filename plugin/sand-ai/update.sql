-- SandAI full-plugin package lifecycle baseline: 0.1.0
--
-- This package has not been released. install.sql is therefore the only
-- schema creation path for its first install. The previous update history
-- belonged to the in-repository management integration and included legacy
-- application/environment/credential compatibility records now owned by
-- SandIAM; it must not be replayed by an independently installable package.
--
-- Future published versions append a versioned, PostgreSQL-only, monotonic,
-- repeat-safe migration here (or in a versioned migration file invoked by the
-- package installer). They must never create, alter or delete SandIAM-owned
-- identity/application/credential tables.
BEGIN;
COMMIT;
