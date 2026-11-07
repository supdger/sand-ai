# SandAI 私有对象存储

SAND-101 规定 SandAI 的文件通过 `StorageDriver` 访问。应用数据库保存驱动 code 和相对 `object_key`，不保存永久 URL、bucket、endpoint 或访问密钥。

## 生产：阿里云 OSS 私有桶

生产运行环境设置以下变量；实际值由部署环境注入，禁止提交 `.env`：

```dotenv
SAND_AI_STORAGE_DRIVER=oss-private
SAND_AI_OSS_ACCESS_KEY_ID=
SAND_AI_OSS_ACCESS_KEY_SECRET=
SAND_AI_OSS_ENDPOINT=
SAND_AI_OSS_BUCKET=
SAND_AI_OSS_PREFIX=
SAND_AI_OSS_SIGN_TTL=900
SAND_AI_OSS_MAX_SIGN_TTL=900
```

- Bucket 必须是私有读写，禁止 `public-read` 和公开域名。
- RAM 身份最小权限限制到该 bucket 与 `SAND_AI_OSS_PREFIX`；首期只需要对象读、写、删、查询和签名所需权限。
- 业务表只保存例如 `files/2026/07/29/<random>.pdf` 的相对键；实际 OSS key 是 `{SAND_AI_OSS_PREFIX}/{object_key}`。
- 对象下载默认经未来的 SandAI 文件授权接口；确需直连时才返回最长 15 分钟的 GET 签名 URL。

## 开发：本地私有目录

默认驱动是 `local-private`，文件存放在 `runtime/sand-ai/private`，不在 `public/` 下，也不会生成可直接访问的 URL。

验证命令：

```bash
cd server
composer test:sand-ai-storage
```

## 联调前置条件

真实 OSS 联调由用户提供一个专用私有 bucket 和一个仅供 SandAI 使用的目录前缀后执行。届时只需要填入本文的 `SAND_AI_OSS_*` 环境变量；不需要修改业务代码。
