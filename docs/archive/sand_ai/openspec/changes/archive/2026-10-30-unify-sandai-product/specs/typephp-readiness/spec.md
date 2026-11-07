## Purpose

使 SandAI 的业务代码能够按固定 TypePHP 工具链进行编译和运行，并将源码规则检查与实际编译产物验收分别留证。确保编译目标不会通过隐藏业务代码、修改第三方依赖或弱化权限来获得表面成功。

## ADDED Requirements

### Requirement: Explicit compilation contract
SandAI MUST 固定 PHP、TypePHP、目标平台、扩展及依赖版本，业务代码使用显式类型与可追踪依赖，初始化有明确生命周期，请求和租户状态不进入共享可变属性。

#### Scenario: Dependency or compiler drift
- **WHEN** 依赖版本与编译矩阵不符
- **THEN** 明确报告兼容性未验证，不沿用旧编译成功证据。

### Requirement: Compiled behavior parity
SandAI MUST 对实际编译产物与普通 PHP 的核心成功、拒绝、金额精度、幂等和状态隔离进行对照，不允许将业务源码忽略后回到解释运行来宣称兼容。

#### Scenario: Unsupported dependency
- **WHEN** 编译器无法处理某个框架或依赖结构
- **THEN** 记录具体阻塞；仅在可证明等价且生成于构建目录时应用兼容改写，不直接修改 vendor。
