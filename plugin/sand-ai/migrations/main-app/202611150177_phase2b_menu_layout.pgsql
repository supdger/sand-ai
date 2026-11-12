-- 0.5.0 第二段：侧栏改成「首页、试用、文件、费用」，模型并进服务商，
-- 「模型」菜单隐藏，「模型服务商」归到「高级」下，身份菜单去掉括号。
-- 按 code 更新，重复执行结果相同。
UPDATE sand_system_menu
SET name = '身份与权限',
    update_time = CURRENT_TIMESTAMP
WHERE code = 'SandIAM' AND type = 1 AND delete_time IS NULL
  AND name IS DISTINCT FROM '身份与权限';

UPDATE sand_system_menu
SET parent_id = parent.id,
    sort = 50,
    update_time = CURRENT_TIMESTAMP
FROM sand_system_menu AS parent
WHERE sand_system_menu.code = 'SandAIProvider'
  AND parent.code = 'SandAIAdvanced'
  AND sand_system_menu.delete_time IS NULL
  AND parent.delete_time IS NULL
  AND (sand_system_menu.parent_id IS DISTINCT FROM parent.id
       OR sand_system_menu.sort IS DISTINCT FROM 50);

UPDATE sand_system_menu
SET is_hidden = 1,
    update_time = CURRENT_TIMESTAMP
WHERE code = 'SandAIModel' AND delete_time IS NULL
  AND is_hidden IS DISTINCT FROM 1;

UPDATE sand_system_menu AS menu
SET sort = target.sort,
    update_time = CURRENT_TIMESTAMP
FROM (VALUES
    ('SandAIOverview', 50),
    ('SandAITrial', 40),
    ('SandAIFileCenter', 30),
    ('SandAIUsage', 20),
    ('SandAIKnowledgeConfiguration', 80),
    ('SandAICapability', 70),
    ('SandAIAgentConfiguration', 60),
    ('SandAIInvocation', 40),
    ('SandAIMaterialConfig', 30),
    ('SandAITaskTrial', 20),
    ('SandAIAudit', 10)
) AS target(code, sort)
WHERE menu.code = target.code
  AND menu.delete_time IS NULL
  AND menu.sort IS DISTINCT FROM target.sort;
