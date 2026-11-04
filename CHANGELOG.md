## 0.4.0 (release)

- UX-P2: provider preset cards and test-connection endpoint with plain-language results (P6); merged home/onboarding wizard with one-click default deployment orchestration (P7); administrator trial channel — backend session issues a short-lived SandIAM runtime identity so admins can try chat/file upload without pasting API keys, one-shot 330s credential with zero persistence (P8). Delivered trees mirrored from SandAI source commit 6d1e3d93d; SandIAM dependency pin moves to the 0.9.0 artifact (home access-matrix grid + terminology pass, industry-neutral payload). `release-payload.json` regenerated.
## 0.3.5 (release)

- UX-P1d zero-step + i18n: write paths lazily initialise internal security material on demand and resolve the idempotency key id only after the material is ready, so a fresh install's first write no longer needs a separate setup step; the .env refresh is scoped to material-owned keys (non-material keys never loaded, declared process values not overridden), keeping cold-process key rotation correct. Audit entries render through a Chinese display dictionary and provider types via admin display labels. Delivered trees mirrored from SandAI source commit 798f973a2; SandIAM dependency pin unchanged at the 0.8.6 artifact. `release-payload.json` regenerated.
## 0.3.4 (release)

- B1/S1 fixes: setup/bootstrap auto-completes internal security material on fresh installs (idempotent .env reload, protect-config, first initialize) so writes no longer fail with SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE; init failure surfaces as the Chinese retryable reason security_init_failed. Admin-visible English/internal identifiers cleared to plain Chinese copy; SAND_AI_ error codes moved into the collapsed technical-details block with a unified Chinese mapping. Delivered trees and admin UI source mirrored from SandAI source commit 8f143bd45; SandIAM dependency pin unchanged at the 0.8.6 artifact. `release-payload.json` regenerated.
## 0.3.3 (release)

- Packaging fix only, no payload change: the SandIAM dependency pin moves to the 0.8.6 artifact (sand-iam-0.8.6.zip, sha256 21ab8aae); the 0.8.5 bundle was uninstallable after its top-level app/ layout regression, 0.8.6 restores the portal/ payload root. Delivered trees mirrored from SandAI source commit ac8c14eaa; `release-payload.json` regenerated.
## 0.3.2 (release)

- Admin UX phase-1: shared `sand-ai-page` layout class fixes admin page scroll clipping; user-facing copy and internal identifiers cleaned (display labels, catalog codes); machine-oriented setup steps collapsed; admin navigation regrouped into grouped sections; new idempotent setup/bootstrap endpoint backed by the SandIAM service catalog. Admin UI source mirrored from SandAI source commit 835316b4a. SandIAM dependency pin raised to the 0.8.5 grouped-navigation artifact.
## 0.3.1 (release)

- Packaging fix only, no payload change: the release ZIP now carries `dependencies/sand-iam-0.8.4.zip` injected at build time from the locked public artifact; the 0.3.0 public asset was missing the dependency bundle. `release-payload.json` and `host-payload.json` regenerated against the delivered tree (source commit 4768a815).
## 0.3.0 (source candidate)

- Version bump to 0.3.0; dropped historical bundled SandIAM zips (0.7.6/0.8.3) to fit the 16 MiB intake limit; the pinned 0.8.4 artifact identity stays declared in `config.json` `plugin_dependencies`. Dependency artifacts are injected at build time (`tools/build-package.php --iam-bundle=`) instead of being committed; `release-payload.json` regenerated from the official builder member set (1398 members) by `tools/build-release-payload.php`; stale root `tools/` duplicates removed. Not yet repackaged, installed, or acceptance-verified.
## 0.2.42 (source candidate)

- Seed the remaining administrator navigation nodes and button permissions (overview/model/invocation/audit pages, usage/capability/agent/knowledge buttons, navigation-only file center); seeds only make permissions grantable, budget stays superadmin-only.
## 0.2.41 (source candidate)

- Bind new private uploads to trusted caller scope and original request, with durable pending recovery and read-only lookup. Existing files are not backfilled.

# 更新日志

正文随统一安装包单一维护，请阅读 [完整文档](plugin/sand-ai/CHANGELOG.md)。此路径保留为源码入口，不重复维护正文。
