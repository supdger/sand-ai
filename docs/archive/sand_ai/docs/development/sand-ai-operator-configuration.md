# SandAI 运维配置交接

> 本文只告诉运维人员“填什么、填到哪里、由谁使用”。它不把密钥写进 Git，也不把任何
> 密钥返回给 API、管理端或 Agent。

## 1. 三类值，不是同一种 Key

| 目的 | 谁签发/拥有 | 填写位置 | 谁使用 |
| --- | --- | --- | --- |
| SAND-112 私有文件验收 Key | 当前演示站的 SandAI 管理端（迁移期兼容凭证） | `server/.env` | 验收脚本调用 SandAI 自己的 `/api/sand-ai/v1/files` 与 `/tasks` |
| GLM Provider Key | 智谱开放平台 | `server/.env` | 一次性受控导入，写入 PostgreSQL 的加密 Provider 配置；运行时不直接读取该文件 |
| OCR 服务配置 | 本地 PaddleOCR 服务 | `server/.env` | SAND-103B 的本地 OCR Driver；服务仅监听本机 |

所有配置写入被 Git 忽略的 `server/.env`，并设为 `600`。`server/runtime/` 是可以清理的
缓存和日志目录，绝不能保存配置或密钥。不得在聊天、截图、代码、前端环境变量、浏览器控制台
或日志中填写任何 secret。

## 2. SAND-112：演示环境 Key

从 `server/.env.example` 创建 `server/.env`，设置权限 `600` 后填写：

- `SAND_AI_ACCEPTANCE_BASE_URL`：演示站 SandAI 地址；
- `SAND_AI_ACCEPTANCE_PRIMARY_KEY`：**SandAI 自己签发**、属于主演示环境的短期
  Bearer Key，scope 为 `files:read`、`files:write`、`tasks:read`、`tasks:write`；
- `SAND_AI_ACCEPTANCE_ISOLATED_KEY`：**SandAI 自己签发**、属于另一个隔离环境的短期
  Bearer Key，至少 `files:read`；
- `SAND_AI_ACCEPTANCE_DELETE_AFTER=1`：只在希望验收文件结束后受控删除时设置。

它们不是阿里 OSS、GLM、数据库或 SaiAdmin 登录 Key。验收脚本只输出任务/来源块数量等
非敏感结果；主环境成功、隔离环境读取同一文件返回 `SAND_AI_RESOURCE_NOT_FOUND` 才通过。

## 3. GLM：先用配置文件交接，后受控导入

在 `server/.env` 填写确认过的 endpoint、API Key、远端模型 ID 和目标环境 ID。

`SAND_AI_CONFIG_KEY` 是另一个必要值，保存在主应用的 `server/.env`，长度至少 32 字符；
它只用于加密/解密数据库中 Provider 的只写配置，不能与 GLM API Key 相同。Provider
导入前先执行无写入检查；只有管理员确认应用时，才将 GLM Key 加密写入
`sand_ai_provider.encrypted_config`。这保留了“环境变量填写”的交接体验，同时避免把多个
项目的 Provider 固化成代码里的全局唯一模型。

## 4. OCR：推荐先部署本地 PaddleOCR

已选 `local_paddle_ocr`：在 SandAI 同机或内网独立主机部署 PaddleOCR/PP-Structure 服务。
不需要云账号或 API Key；需要明确的部署地址、Python/模型安装授权，以及图片、扫描 PDF、
表格 PDF 三类可丢弃测试样本。填入 `server/.env` 的本地服务地址和限制。

若选择云 OCR，则不要同时填本地与云端凭证；只提供所选厂商的 endpoint 与最小权限专用
RAM/子账号凭证。无论哪种方案，文件始终先在私有存储中受控下载，不产生公开 OSS URL。

当前插件已实现 `local_paddle_ocr` 驱动，并有既往演示宿主的图片和扫描 PDF 异步验收记录；填写配置本身不代表当前新宿主已装好 OCR 服务或完成真实材料验收。云端 OCR Driver 尚未实现。新宿主仍需按所选能力档配置并实际验证本机服务、私有文件路径、来源块和错误恢复。

## 5. 录音转写

### 本地 whisper.cpp

使用本地 `whisper.cpp` 时，`server/.env` 需分别指定可执行程序、模型文件、FFprobe 和 FFmpeg 可执行程序的绝对路径：`SAND_AI_WHISPER_CPP_EXECUTABLE`、`SAND_AI_WHISPER_CPP_MODEL_PATH`、`SAND_AI_FFPROBE_EXECUTABLE`、`SAND_AI_FFMPEG_EXECUTABLE`。可按录音长度设置 `SAND_AI_WHISPER_CPP_TIMEOUT_SECONDS`（1–3600 秒），分别约束转码和转写进程。缺少任一路径时后台预检显示未配置，Worker 不运行本地转写。

FFprobe 只读取受控私有音频的临时文件，测得媒体容器时长供本地转写计量；测不到有效时长时任务失败，不按最后一段识别文字的结束时间计费。配置状态和源码回归不代替真实录音、媒体格式、SandIAM 授权及费用核对。

FFmpeg 将 MP3、M4A、OGG、WAV、WebM 或 FLAC 等受控私有录音统一转为临时 16 kHz 单声道 PCM WAV，再交给 whisper.cpp；转写结束或失败时删除转码文件。转码失败、超时或输出无效时不执行转写；需在实际 Worker 主机用真实录音核验解码能力与磁盘空间。

### OpenAI 兼容外部转写

外部转写也需要 Worker 本机的 FFprobe。沿用 `server/.env` 的 `SAND_AI_FFPROBE_EXECUTABLE`，配置为可信可执行文件的绝对路径，并让 Worker 运行账户能够读取和执行；默认空值表示未配置。OpenAI 兼容适配器从既有 `sand_ai_audio_execution.local_whisper_cpp.probe_executable` 读取该值，但不要求配置本地 Whisper 程序、模型或 FFmpeg 转码器。

每次新外部转写都会先读取受控私有录音的媒体容器时长和首音轨时间线，再冻结核实后的时长并发送服务商；没有预算策略时也如此。未配置或不可执行时返回 `SAND_AI_AUDIO_NOT_CONFIGURED`；读取失败或时长无效时返回 `SAND_AI_AUDIO_UNAVAILABLE`。这两种情形均在外发前停止，不能用识别文字的结束时间估算原录音时长。

后台元数据的 `adapter_kinds.external` 仅在现有 `external_enabled` 开启且 OpenAI 兼容适配器符合 `enabled`、`provider_idempotency_mode=none`、`synchronous_only=true` 并有可执行 FFprobe，或既有 GLM 适配器已完整配置时显示 `configured`，否则为 `not_configured`。遇到未配置，先由 Worker 管理员补齐该绝对路径及运行账户权限，再重新读取配置状态。GLM 沿其现有 `glm_asr_audio.probe_executable` 使用同一环境变量，仍保留自身 WAV／MP3 格式限制；不会因 OpenAI 的配置改变而放宽。

配置可用后，仍需既有已发布音频部署、环境能力路由和 SandIAM 服务授权。已经提交且结果未知的任务只按原任务／请求号读取，不换号或自动重发；未派发的 prepared 原任务可在管理员修复执行配置后，沿原任务已有执行／重试边界继续；重新读取元数据只确认配置，不确认未知任务的外发结果。旧冻结请求、回执和费用保留原事实。本次只同步源码配置说明，没有执行 FFprobe、安装、命令示例或运行验收。
