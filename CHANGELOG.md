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
