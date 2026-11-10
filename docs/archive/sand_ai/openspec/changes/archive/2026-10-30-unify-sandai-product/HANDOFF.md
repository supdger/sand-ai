# SandAI接续交接（2026-10-11）

本文件仅提供接续入口和必要事实；唯一需求/进度来源仍为 [tasks.md](tasks.md)，证据仍为 [evidence.md](evidence.md)，不建立第二任务台账。

## 目标与阶段

- 原完整32条、四个真实场景保留：当前13/32、1/4，仅原5.6新增完成；历史12/32、0/4基线保留，不勾5.2全范围/6.6整体，不将局部SOURCE、安装或恢复PASS等同整体完成。
- 原32范围：1.1–1.4、2.1–2.5、3.1–3.6、4.1–4.5、5.1–5.6、6.1–6.6。
- 既有27项业务SOURCE正向链及后继修复交付保留。1.4/2.5/3.6/6.5/6.6为纯后阶段门；6.5明确暂缓。
- 已不使用旧24工作包、组长层或旧任务看板；围绕原任务派发有界完整动作。

## 源码、运行环境与责任

| 对象 | 权威/已确认状态 |
|---|---|
| SandAI源码 | `/path/to/project/sand_plugins/sand-ai/`；本workspace用于候选和证据，不是权威源 |
| Lawyer源码 | `/path/to/project/lawyer/`；个人Skill前端在`sandadmin-artd`，BFF及合同沿原owner边界 |
| 原SandAI临时宿主 | `/path/to/project/sand_ai/tmp/sandai-public-host-source-20261009`，API18951；SandAI0.2.21 state1、Package0.2.8 |
| 最后确认PID | master67299/task worker67304、HTTP67300/67301；仅据消费safe，不表示本文件另查进程 |
| 载荷/数据库 | 993 host成员匹配；迁移count150/max154，保护数据一致；不是业务验收 |
| Lawyer原入口 | API18941、前端`https://localhost:18943/#/lawyer/skills`；本文件未新查PID/会话 |
| 主控 | 授权、协调、最终业务闭环判定 |
| 唯一runtime/ledger owner | 同现场消费、原任务恢复、预算许可和结算；其他actor不并写ledger |
| 源码owner | 对应SandAI/Lawyer范围实施与权威交付；共享文件交唯一整合者 |

[021消费safe](/path/to/evidence/sandai-owned0221-upgrade-20261011/receipt-safe.json)保留正式升级、完整restart及保护事实。旧019漏154、0218声明/消费失败及Package官方恢复历史不删除。

## 当前SOURCE与已消费021的差异

021消费后另有两批实际SOURCE交付：[材料文件重试两成员](/path/to/project/sand_ai/tmp/sandai-material34-file-retry-source-20261011/SOURCE-DELIVERY-RESULT.json)（file/index.vue＋release，release由5a856…改3765…）；[Agent重试代次三成员](/path/to/project/sand_ai/tmp/sandai-agent-retry-generation-source-20261011/SOURCE-DELIVERY-RESULT.json)（useAgentTaskRun.ts＋PublishedAgentRunPanel.vue＋release），当前authority release SHA为`094665f305053b8be941ac648ee921bbdaaa05dec7af1468d5dbb75dec6ad301`。三个前端成员差异均未进入原不可变021 ZIP或临时宿主；尚未同步/构新包/运行验证，不能以registry021证明当前SOURCE一致，不推测新版本号。用户是否整个项目转Claude的问题已弹出，停止范围待其明确，会议路线仍冻结，不自行判整个Goal停止或扩大等待到其他原已授权后阶段；原14调用计量、最终预算/unknown及授权清理边界保持。

## 最新真实业务结果

### 个人Skill（主5.6，关联5.2/6.6）

- Skill2/v1/version_id3；原failed run4/inv16与费用627tokens/.000742CNY保留。
- 同原逻辑请求恢复为run5/inv17、attempt2/max3 succeeded，3引用、仅`分析`string、日期2026-09-01、付款未知。
- 原draft来源采用API200、case3 UI保存200、run3/inv13真实评测成功3引用/review_required、evaluation2显式pass及publish200均有八份实际历史证据；agent acting testperson，不冒真人或生产法律审查。主控结合独立恢复/撤销页面判原5.6及个人场景完成（13/32、1/4），不扩大至团队/跨人、安全或部署/线上。
- 原anonymous会话预检/撤销POST0保历史；后继今天一次正常person1 login后唯一revokePOST200，Skill2 revoked/status2，原revoke GET replaytrue、原result/source409 VERSION_REVOKED，两历史fee GET200仍bill/cash null。独立当前UI确认revoked/newrun disabled/无resume旧output/source，0POST/model/login。
- [撤销完整及八份历史证据safe](/path/to/evidence/sandai-lawyer-skill-revoke-20261011/revoke-complete.safe.json)、[独立撤销/生命周期回执](/path/to/evidence/sandai-skill-revoke-independent-20261011/receipt-safe.json)。独立安全note记录短期CSRF误工具输出及自有raw/browser清理，不记录值、不称工具历史撤回或全安全PASS。

### 原会议task23（4.5）

- 末节点`merge_03_00001`失败：`SAND_AI_AUDIO_SUMMARY_RESULT_INVALID`。7 chunk、前5 merge成功；inv29 Provider succeeded不能代替业务结果。
- 14实际调用、71314tokens（54859输入/16455输出）、产品计量.089963CNY；本轮新12调用.079082CNY，现金未知。
- [终态safe](/path/to/evidence/sandai-owned0221-upgrade-20261011/task23-terminal-error-cost.safe.json)是原owner DB只读观察，不冒API验收。
- 该失败路线冻结：不再Provider、恢复POST或补丁盲retry，不扩成整个Goal暂停/blocked/complete。

### 费用与机器人

- run4费用四GET限定API PASS；[后继费用页面safe](/path/to/evidence/sandai-failed-cost-page-finish-20261011/receipt-safe.json)为LIMITED_UI_PASS，原过滤overview/rank/detail业务failed1/.000742CNY/627tokens/event20/inv16，bill/refund/cash未知；原401/captcha400保历史，4.2完整仍未完成。
- 原批准三条机器人消息全部已发送，未发列表为空；A20成功原证保留，B21/A22未知无完整receipt。无第四条消息授权，不重发、不改计数。
- [费用safe](/path/to/evidence/sandai-failed-cost-recheck-20261011/receipt-safe.json)、[原三消息mapping](/path/to/evidence/sandai-bot-receipt-recovery-20261011/original-three-send-mapping.safe.json)。

## 预算与未知

- [会议最终soleledger safe](/path/to/evidence/sandai-owned0221-upgrade-20261011/meeting-settlement.safe.json)已closed_metered_failed；task failed/lease absent/13 plan nodes终态/all14 dispatch succeeded及完整计量是释放正证，不因API400清账。
- cap16/held3.313186/free12.686814/starts38；新增18–29共12次逐inv入账，ledger SHA `c4b09cd094e2088faaecb9cc71ce0781f898c6b174194c47ce00375ed14af5f9`。
- 会议14 calls产品.089963、新12 calls.079082、保守留存.089972；释放未耗10.964318是预占释放，不是现金退款。原11.054290 fullhold仅历史，当前已结量关闭。
- 旧unknown2.885019保持，账单/现金null，不写0；Skill inv16 .000742、新inv17 .001003均保留。
- [Skill结算safe](/path/to/evidence/sandai-skill-failed-attempt-permit-20261011/settlement.safe.json)的held14.277504/free1.722496/starts26仅为会议结算前历史。

## 授权与限制

- “授权写入示例项目接入源码”覆盖原个人Skill接入及同范围必要修复；“授权上述隔离示例项目闭环及清理”沿原owned宿主/身份/目标。
- 用户后继两次“授权”覆盖原四项：client1凭据轮换及27同scope续24h、原GLM Key一次models目录、新clone两JWT官方初始化/生命周期清理、cap10→16完整会议。不是无限新增host/Key/预算授权。
- 用户明确源码完成后可审计、验收、测试；重载规则针对测试提速，没有撤回后阶段许可。仍禁止unit/frontend build/vue-tsc/TypePHP，6.5暂缓；无生产/Git授权。
- 用户原话：“你如果再搞不过这里，就别搞了！你要是实现不了sandAI，那就别实现了，我找claude做！”主控本轮执行解释为停止当前失败路线并事实交接；这是主控解释，不能写成用户明确仅停止会议，也不自行变更整个Goal状态。
- 原27服务授权有效至2026-10-11 20:00:59。原临时资源授权收尾涉及撤销credential3、将27 expiry恢复2026-10-10 17:25:40；当前有人接续，尚不执行cleanup，不泄露/重造凭据。
- CLI能做必须CLI；真实页面验收例外只覆盖界面步骤。不得绕auto_review；同范围有效授权无需逐补丁重索。

## 接续必要动作

1. 复用已完成的会议终态soleledger结算，不重做结算或清unknown；接续前由唯一ledger owner按现预算发具体操作许可。
2. 会议保持冻结，向用户交代末节点失败和上述证据；不再消耗Provider或同处盲修。原目标仍保留。
3. 原5.6个人生命周期已完成，不重做撤销或恢复派发；其他5.2团队/跨人范围沿原真实业务条件另行接续，不借此勾整体。
4. 复用已完成的失败费用API/UI有限证据，4.2剩余账单/退款/比较等门按真实条件推进，不重复原run4窗口。
5. B21/A22仅沿原完整receipt/合法当前caller条件恢复，缺receipt保unknown；不新发消息。
6. 其他原目标仍保真实门：6.1 Workflow definition/caller、真实账单/退款、专业能力、团队范围、合法法律源及整四场景；不虚构配置、caller或样本。

主控当前冻结会议失败路线、保留原Goal；用户是否要求整个项目停止须沿其真实后续指令处理，不能把主控限定路线的解释冒称用户明确授权继续整个项目或明确只停止会议。

metadata021最新七成员已authority实交；未公开Release/提交/Git。

本文件不包含凭据、输入正文或模型原响应；不实施任何运行操作。
