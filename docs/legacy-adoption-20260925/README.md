# Existing `sandai` SandAI table adoption candidate

This is a test-workspace candidate, not an executed migration. It is specific to
the existing PostgreSQL database named `sandai`. It never creates or connects to
a database named `sandadmin`.

## Verified read-only snapshot

- `public` has exactly 18 old `sand_ai_*` tables, each with one owned identity
  sequence, and no `sand_ai_schema_migration` ledger.
- `sand_ai_application`, `sand_ai_environment`, and `sand_ai_api_credential`
  each have one row; the other 15 old tables are empty.
- The 2026-09-26 read-only recheck found no external foreign key, dependent
  view, user trigger, or database function source mention. The target archive
  schema does not exist. The current database role belongs to the table and
  sequence owner roles. The archival candidate now refuses to run if user
  triggers or database function references appear before execution.
- These checks do not prove that a running legacy application has stopped
  using the tables, or that old application and credential records represent
  the existing SandIAM application and environment. The read-only snapshot is
  in `read-only-snapshot.json`. The snapshot contains object names and row
  counts only; it does not contain credential values or business records.

## Order for a future authorized maintenance window

1. Resolve SandAdmin #20 (attach a fresh host to an already installed database)
   and #21 (install `host-payload.json` application files). Freeze the exact
   host, SandPackage, SandIAM, and SandAI candidate revisions.
2. Obtain explicit authorization for service stop/start, full database backup,
   legacy table archival, and SandAI migrations. Identify and stop every
   consumer of the old `sand_ai_*` tables. Do not copy the old SandAI API
   credential into SandIAM; provision and grant a current SandIAM workload
   client through SandIAM's supported flow.
3. Take a full, protected `sandai` backup containing data and schema. Verify the
   backup can be listed and rehearse restore in an authorized isolated
   environment. Keep the old credential hash restricted.
4. Rerun the read-only audit and compare with this snapshot. If any table,
   sequence, row count, dependency, owner, or archive-schema check differs,
   revise the candidate rather than bypassing its guard.
5. Review and execute `archive-legacy-sandai.sql` against **only** `sandai`.
   It locks all 18 old tables without waiting, validates the exact row counts,
   and moves the tables plus their owned identity sequences to
   `sand_ai_legacy_20260925` in one transaction. Any error rolls it back.
6. Before a new SandAI baseline is installed, `restore-before-install.sql` is
   the guarded reversal path. It refuses to run after new public SandAI tables
   or sequences appear. After baseline installation, use the protected backup
   and a separately reviewed restore plan instead.
7. Install the new SandAI package through the corrected SandPackage lifecycle,
   run its approved migrations, verify the migration ledger and all host
   payloads, then configure SandIAM service actions and grants. Use real
   PostgreSQL, HTTP, Provider, fee, and browser checks before marking any
   business loop complete.

The two SQL files and current table inventory matched statically, but the SQL
has not been executed or rehearsed. Do not treat this candidate or its
read-only checks as installation acceptance.
