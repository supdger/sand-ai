# SandIAM 与 SandAI 服务接入边界

> 状态：需求与架构边界已确认；首期功能需求见
> [SandIAM 产品需求](../product/sand-iam-product-requirements.md)
> 确认日期：2026-08-04；2026-08-13 确认 SandAdmin 插件宿主、平台后台账号与应用用户身份域分离
> 适用范围：Sand 平台、SandIAM、SandAI 以及律序等业务应用

## 1. 产品关系

SandAdmin 是 SandAI 主应用随仓消费的管理宿主和平台后台入口；SandAI 主应用通过
`server/app/**` 向业务应用提供模型、文档解析、OCR、检索、Agent 等能力，SandIAM 作为同一
管理宿主中的身份、用户类型、授权与服务访问控制插件提供上下文。另行分发的完整 SandAI 插件
由 `sand_plugins/sand-ai` 维护，只进入消费宿主。依托宿主运行不等于律序等应用的人类用户必须
复用宿主后台账号。

站在 Sand 平台全局视角：

| 主体 | 定义 | 是否属于 SandIAM 管理范围 |
| --- | --- | --- |
| 平台超级管理员 | SandAdmin 系统用户 `admin` 或被授予等价角色的系统用户；拥有平台级治理权限 | 账号由 SandAdmin 管理，用于平台后台与插件治理 |
| 平台客户主体 | 接入 Sand 平台的客户或业务主体，例如律序所属组织 | 是，建模为 `organization` |
| 业务应用 | 客户主体运行并调用平台服务的系统，例如律序 | 是，建模为 `application` |
| 工作负载客户端 | 律序生产服务用于调用 SandAI 的机器身份 | 是，建模为 `workload_client` |
| 应用人类用户 | 律序内部的律师、客户、律所管理员等 | 是，SandIAM 提供身份目录/身份源绑定、用户类型、角色、策略、数据范围和审计能力；律序配置具体规则，不复用宿主后台账号 |

“律序是 SandIAM 的一个平台用户”是产品视角的表达；数据模型中不能把律序存成普通
后台用户或角色。律序应表示为 `organization + application`，机器调用身份表示为
`workload_client`。律序负责人如需管理平台插件，可另有 SandAdmin 后台委派账号；如需登录
律序、管理案件或使用业务功能，则应作为 SandIAM 的律序应用用户。两个身份域可绑定同一自然人，
但认证凭据、权限与审计记录不能混用。

## 2. 权威层级

```text
platform operator
└── organization（平台客户主体）
    └── application（律序等业务应用）
        └── environment（开发 / 测试 / 生产）
            └── workload_client（机器调用身份）
                └── service_grant（服务授权）
                    └── service_action（可调用动作）
```

服务动作使用稳定的语义代码，例如：

- `sand_ai.chat.complete`
- `sand_ai.document_parse`
- `sand_ai.retrieval.search`
- `sand_ai.agent.run`

服务授权不能直接使用 HTTP 路径作为权限标识。URL 可以升级，语义服务动作必须保持稳定。

## 3. 调用与控制流程

1. 平台超级管理员在 SandIAM 创建或审核平台客户主体。
2. 在该主体下登记律序 application，并分别创建开发、测试、生产 environment。
3. 为律序生产环境创建 workload client；凭证只展示一次，支持轮换和撤销。
4. 平台管理员将允许的 SandAI service action 授予该客户端，并配置 audience、限额、数据等级、网络和有效期约束。
5. 律序后端以工作负载身份从 SandIAM 获取短期调用上下文，调用 SandAI。
6. SandAI 验证 application、environment、audience、service action 和策略后执行 AI 能力。
7. SandIAM记录身份、授权、拒绝与撤销审计；SandAI记录模型、文件、任务、来源、用量和 AI 执行审计。

律序内部律师、客户和律所管理员可使用 SandIAM 提供的身份与授权能力；具体用户类型、登录
方式、案件资源与规则由律序配置。案件、文书和业务状态仍由律序保存。律序调用 SandAI 时，
携带经过授权的用户与案件作用域上下文；SandAI 只验证该上下文并执行 AI，不接管律序业务规则。

## 4. 管理面职责

| 管理动作 | 入口与权威 |
| --- | --- |
| 平台后台账号、插件菜单与全局治理 | SandAdmin 宿主 |
| 律序应用用户、用户类型、登录、角色与数据范围 | SandIAM 提供通用能力；律序配置规则并保留案件等业务实体与业务条件 |
| 创建客户主体、应用、环境和工作负载客户端 | SandIAM 管理面 |
| 签发、轮换、吊销调用凭证 | SandIAM 管理面 |
| 注册 SandAI 服务动作并授权给律序 | SandIAM 管理面 |
| 配置 Provider、模型、OCR/解析驱动和能力路由 | SandAI 管理面 |
| 管理 AI 文件、任务、来源、用量与运行观测 | SandAI 管理面 |
| 查看某应用获得的 SandAI 授权 | SandAI 可只读展示；修改必须跳转 SandIAM |

SandAI 不能创建平行的 application、workload client、credential 或 service grant 权威表，
也不能直接跨库联表依赖 SandIAM。运行时通过 `IdentityContextProvider` 端口消费 SandIAM
签发并验证的上下文；同机部署和远程部署仅更换适配器，不改变 SandAI 领域逻辑。

## 5. 插件宿主和实例边界

- SandAdmin 是管理宿主的唯一权威来源；本仓主动拉取并保护 SandAI 覆盖层。SandAI 主应用的
  目标运行面是 `server/app/**` 与 `server/config/**`，SandIAM 以插件运行。另行分发的完整
  SandAI 插件归 `sand_plugins/sand-ai`，不得把本仓迁移期 `server/plugin/sand-ai/**` 当成第二运行面。
- SandIAM 既管理 platform organization/application/workload client/service grant，也以插件能力
  提供身份目录或身份源绑定、用户类型、Casbin 式策略判定和数据权限；平台后台账号仍由
  SandAdmin 管理。它不内置律序或其他项目的业务用户类型、资源和规则。
- SandAI 依赖已安装的 SandIAM，消费其应用与服务授权；SandAI 不另建 application、credential
  或 grant 表，也不绕开宿主账号/权限。
- 律序不安装或复制第二套 SandIAM。它使用 SandAI 时，由该宿主中的 SandIAM 为其登记
  application 并授权；律序自身的律师、客户等用户也使用同一 SandIAM 身份与授权能力，案件等
  业务实体仍保留在律序。

## 6. 迁移要求

当前 SandAI 已实现的 application、environment、credential 管理接口属于早期自包含链路。
在 SandIAM 对应契约和迁移验收完成前，这些接口可以为演示站保持兼容，但不得继续扩展为
第二套 IAM。迁移必须覆盖：

1. 现有 application/environment 到 SandIAM 稳定标识的映射；
2. 旧凭证的受控轮换、过渡期和撤销；
3. SandAI 资源对 `application_id`、`environment_id` 的引用兼容；
4. 管理页入口、权限代码和审计归属调整；
5. 双读或兼容适配器的退出条件、回滚路径和真实调用验收。

在迁移任务完成前，文档必须区分“已确认的目标架构”和“仍在运行的兼容接口”，不能把
架构决定误报为已经完成的数据或代码迁移。
