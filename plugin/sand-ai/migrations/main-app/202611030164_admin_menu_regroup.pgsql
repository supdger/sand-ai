-- SandAI administrator navigation regroup: fold the low-frequency configuration
-- and diagnostics surfaces under one visible "高级" group, leaving the daily
-- entries (概览 / 首次开通 / 模型 / 服务商 / 文件中心 / 费用与账单) at the top
-- level. Permission codes, slugs and components are unchanged. Moved pages
-- switch their stored path to the absolute route (for example '/sand-ai/task')
-- so the front-end hierarchical path join keeps every effective URL identical;
-- direct links to the old routes keep working. The block is idempotent:
-- re-running it converges to the same tree without duplicating menus or role
-- links.
DO $sand_ai_menu_regroup$
DECLARE
    root_id bigint;
    group_id bigint;
    count_nodes integer;
BEGIN
    IF to_regclass('sand_ai_configuration_navigation_ownership') IS NULL THEN
        RAISE EXCEPTION 'SandAI configuration navigation ownership is missing';
    END IF;
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,root_id FROM sand_system_menu
    WHERE code='SandAI' AND parent_id=0 AND type=1 AND status=1 AND delete_time IS NULL;
    IF count_nodes<>1 THEN
        RAISE EXCEPTION 'SandAI root navigation is missing or duplicated';
    END IF;

    -- The group is a visible component-less menu node (type=2, component='').
    -- A directory row (type=1) would force the host layout component
    -- '/index/index' on the nested level and render a second layout frame.
    SELECT count(*),min(id) INTO count_nodes,group_id FROM sand_system_menu WHERE code='SandAIAdvanced';
    IF count_nodes>1 THEN
        RAISE EXCEPTION 'SandAIAdvanced menu is duplicated';
    END IF;
    IF count_nodes=0 THEN
        INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
        VALUES(root_id,'高级','SandAIAdvanced','',2,'advanced','','',10,2,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        RETURNING id INTO group_id;
        INSERT INTO sand_ai_configuration_navigation_ownership
        SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
        FROM sand_system_menu menu WHERE id=group_id;
    ELSIF NOT EXISTS(
        SELECT 1 FROM sand_system_menu WHERE id=group_id AND parent_id=root_id AND type=2
          AND COALESCE(component,'')='' AND is_hidden=2 AND status=1 AND delete_time IS NULL
    ) THEN
        RAISE EXCEPTION 'Existing SandAIAdvanced menu is incompatible';
    END IF;

    -- Moved pages keep their effective URL byte-for-byte: the stored path
    -- becomes the absolute route, which the front-end path joiner returns
    -- unchanged instead of prefixing it with the group path. is_hidden is
    -- normalised to the schema default 2 (visible); a hidden page keeps 1.
    UPDATE sand_system_menu menu
    SET parent_id=group_id,
        path=page.absolute_path,
        is_hidden=CASE WHEN menu.is_hidden=0 THEN 2 ELSE menu.is_hidden END,
        update_time=CURRENT_TIMESTAMP
    FROM (VALUES
        ('SandAITaskTrial','/sand-ai/task'),
        ('SandAIInvocation','/sand-ai/invocation'),
        ('SandAIAudit','/sand-ai/audit'),
        ('SandAIKnowledgeConfiguration','/sand-ai/knowledge'),
        ('SandAIAgentConfiguration','/sand-ai/agent'),
        ('SandAIComposition','/sand-ai/ai-workflows'),
        ('SandAICapability','/sand-ai/capability'),
        ('SandAIMaterialConfig','/sand-ai/material-config')
    ) AS page(code,absolute_path)
    WHERE menu.code=page.code AND menu.type=2 AND menu.status=1 AND menu.delete_time IS NULL
      AND (menu.parent_id IS DISTINCT FROM group_id
        OR menu.path IS DISTINCT FROM page.absolute_path
        OR menu.is_hidden=0);

    -- Ownership snapshots stay immutable: reparented pages keep their original
    -- recorded definition, matching the append-only ownership contract.

    -- Roles that hold a permission button under a regrouped page but miss the
    -- page link receive it; seeds grant both, this only repairs drift.
    WITH ins AS (
        INSERT INTO sand_system_role_menu(role_id,menu_id)
        SELECT DISTINCT role_menu.role_id, page.id
        FROM sand_system_role_menu role_menu
        JOIN sand_system_menu permission ON permission.id=role_menu.menu_id AND permission.type=3
        JOIN sand_system_menu page ON page.id=permission.parent_id AND page.parent_id=group_id
        WHERE NOT EXISTS(
            SELECT 1 FROM sand_system_role_menu existing
            WHERE existing.role_id=role_menu.role_id AND existing.menu_id=page.id
        )
        RETURNING id,role_id,menu_id
    )
    INSERT INTO sand_ai_configuration_navigation_ownership
    SELECT 'role_menu',ins.id,jsonb_build_object('role_id',ins.role_id,'menu_id',ins.menu_id) FROM ins;

    -- Roles that can already see a moved page also receive the group link, so
    -- the group entry renders for them.
    WITH ins AS (
        INSERT INTO sand_system_role_menu(role_id,menu_id)
        SELECT DISTINCT role_menu.role_id, group_id
        FROM sand_system_role_menu role_menu
        JOIN sand_system_menu child ON child.id=role_menu.menu_id AND child.parent_id=group_id
        WHERE NOT EXISTS(
            SELECT 1 FROM sand_system_role_menu existing
            WHERE existing.role_id=role_menu.role_id AND existing.menu_id=group_id
        )
        RETURNING id,role_id,menu_id
    )
    INSERT INTO sand_ai_configuration_navigation_ownership
    SELECT 'role_menu',ins.id,jsonb_build_object('role_id',ins.role_id,'menu_id',ins.menu_id) FROM ins;
END
$sand_ai_menu_regroup$;
