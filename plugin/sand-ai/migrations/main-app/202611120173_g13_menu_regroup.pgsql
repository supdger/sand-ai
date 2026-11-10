-- 0.5.0 第一批 G13/Y3: the SandAI root becomes "AI 能力" with five daily
-- pages (首页 / 模型服务商 / 试用 / 文件 / 费用) plus the 高级 group. Codes,
-- slugs, components and routes are preserved; SandAIModel and 首次开通 move
-- under 高级, 费用核对 and 应用试用 join as new pages, and 高级 collapse
-- relies on host default-open behaviour. Idempotent on re-run.
DO $g13_menu_regroup$
DECLARE
    root_id bigint;
    group_id bigint;
    page_id bigint;
    link_id bigint;
    count_nodes integer;
    page_row record;
    granted_role record;
    navigation_id bigint;
    usage_review_id bigint;
    app_trial_id bigint;
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

    -- Root display name only; code, path and type are untouched.
    UPDATE sand_system_menu
    SET name='AI 能力',
        update_time=CURRENT_TIMESTAMP
    WHERE id=root_id AND name IS DISTINCT FROM 'AI 能力';

    -- The 高级 group already exists as a visible component-less node (type=2,
    -- component='') since migration 164; a directory row would force the host
    -- layout component '/index/index' and render a second layout frame.
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
          AND COALESCE(component,'')='' AND is_hidden<>1 AND status=1 AND delete_time IS NULL
    ) THEN
        RAISE EXCEPTION 'Existing SandAIAdvanced menu is incompatible';
    END IF;
    UPDATE sand_system_menu
    SET name='高级',
        sort=10,
        update_time=CURRENT_TIMESTAMP
    WHERE id=group_id AND (name IS DISTINCT FROM '高级' OR sort IS DISTINCT FROM 10);

    -- The five daily entries stay direct children of the root. Stored paths
    -- are untouched ('provider'/'file'/'usage' stay relative, the absolute
    -- '/sand-ai/home' and '/sand-ai/trial' routes are already stable), so
    -- every effective URL is byte-identical.
    UPDATE sand_system_menu menu
    SET parent_id=root_id,
        name=page.name,
        sort=page.sort,
        update_time=CURRENT_TIMESTAMP
    FROM (VALUES
        ('SandAIOverview','首页',95),
        ('SandAIProvider','模型服务商',92),
        ('SandAITrial','试用',90),
        ('SandAIFileCenter','文件',88),
        ('SandAIUsage','费用',86)
    ) AS page(code,name,sort)
    WHERE menu.code=page.code AND menu.type=2 AND menu.status=1 AND menu.delete_time IS NULL
      AND (menu.parent_id IS DISTINCT FROM root_id
        OR menu.name IS DISTINCT FROM page.name
        OR menu.sort IS DISTINCT FROM page.sort);

    -- 高级 children: renames follow the 2026-11-10 page specs, paths stay
    -- absolute so regrouping never changes an effective URL, and is_hidden
    -- keeps a deliberate hide (首次开通 stays 1) while normalising 0 to the
    -- schema default 2 (visible).
    UPDATE sand_system_menu menu
    SET parent_id=group_id,
        name=page.name,
        path=page.absolute_path,
        sort=page.sort,
        is_hidden=CASE WHEN menu.is_hidden=0 THEN 2 ELSE menu.is_hidden END,
        update_time=CURRENT_TIMESTAMP
    FROM (VALUES
        ('SandAIKnowledgeConfiguration','知识库','/sand-ai/knowledge',90),
        ('SandAICapability','能力与路线','/sand-ai/capability',80),
        ('SandAIAgentConfiguration','智能体与渠道','/sand-ai/agent',70),
        ('SandAIMaterialConfig','材料整理','/sand-ai/material-config',50),
        ('SandAITaskTrial','任务排障','/sand-ai/task',45),
        ('SandAIInvocation','调用记录','/sand-ai/invocation',40),
        ('SandAIAudit','审计','/sand-ai/audit',30),
        ('SandAIComposition','AI 工作流','/sand-ai/ai-workflows',20),
        ('SandAIModel','模型','/sand-ai/model',10),
        ('SandAIOnboarding','首次开通','/sand-ai/onboarding',5)
    ) AS page(code,name,absolute_path,sort)
    WHERE menu.code=page.code AND menu.type=2 AND menu.status=1 AND menu.delete_time IS NULL
      AND (menu.parent_id IS DISTINCT FROM group_id
        OR menu.name IS DISTINCT FROM page.name
        OR menu.path IS DISTINCT FROM page.absolute_path
        OR menu.sort IS DISTINCT FROM page.sort
        OR menu.is_hidden=0);

    -- New 高级 pages, inserted only when the code is absent and converged
    -- otherwise. No new permission codes are introduced: navigation reuses
    -- the read permissions documented below.
    FOR page_row IN SELECT * FROM (VALUES
        ('费用核对','SandAIUsageReview','/sand-ai/usage-review','/plugin/sand-ai/usage-review/index',60),
        ('应用试用','SandAIAppTrial','/sand-ai/app-trial','/plugin/sand-ai/app-trial/index',55)
    ) AS pages(name,code,path,component,sort) LOOP
        SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu WHERE code=page_row.code;
        IF count_nodes>1 OR EXISTS(
            SELECT 1 FROM sand_system_menu
            WHERE parent_id=group_id AND type=2 AND path=page_row.path AND code IS DISTINCT FROM page_row.code
        ) THEN
            RAISE EXCEPTION 'SandAI advanced navigation conflicts: %',page_row.code;
        END IF;
        IF count_nodes=0 THEN
            INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
            VALUES(group_id,page_row.name,page_row.code,'',2,page_row.path,page_row.component,'',page_row.sort,2,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            RETURNING id INTO page_id;
            INSERT INTO sand_ai_configuration_navigation_ownership
            SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
            FROM sand_system_menu menu WHERE id=page_id;
        ELSE
            UPDATE sand_system_menu
            SET parent_id=group_id,
                name=page_row.name,
                path=page_row.path,
                component=page_row.component,
                sort=page_row.sort,
                is_hidden=CASE WHEN is_hidden=0 THEN 2 ELSE is_hidden END,
                update_time=CURRENT_TIMESTAMP
            WHERE id=page_id
              AND (parent_id IS DISTINCT FROM group_id
                OR name IS DISTINCT FROM page_row.name
                OR path IS DISTINCT FROM page_row.path
                OR component IS DISTINCT FROM page_row.component
                OR sort IS DISTINCT FROM page_row.sort
                OR is_hidden=0);
        END IF;
        IF page_row.code='SandAIUsageReview' THEN
            usage_review_id:=page_id;
        ELSE
            app_trial_id:=page_id;
        END IF;
    END LOOP;

    -- Ownership snapshots stay immutable: renamed and reparented pages keep
    -- their original recorded definition, matching the append-only ownership
    -- contract. Only newly inserted rows receive snapshots.

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

    -- Roles that can already see a page inside 高级 also receive the group
    -- link, so the group entry renders for them (covers the 模型 move and the
    -- two new pages once they hold links).
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

    -- 费用核对 has no dedicated permission code yet; roles that can read the
    -- 费用 page (sand_ai:usage:index) also receive the review page plus the
    -- root and group links, matching the page-seed convention.
    FOR granted_role IN
        SELECT DISTINCT role_menu.role_id FROM sand_system_role_menu role_menu
        JOIN sand_system_menu permission ON permission.id=role_menu.menu_id
        JOIN sand_system_role role ON role.id=role_menu.role_id
        WHERE permission.type=3 AND permission.status=1 AND permission.delete_time IS NULL
          AND role.status=1 AND role.delete_time IS NULL
          AND permission.slug='sand_ai:usage:index'
    LOOP
        FOREACH navigation_id IN ARRAY ARRAY[root_id,group_id,usage_review_id] LOOP
            IF NOT EXISTS(
                SELECT 1 FROM sand_system_role_menu
                WHERE role_id=granted_role.role_id AND menu_id=navigation_id
            ) THEN
                INSERT INTO sand_system_role_menu(role_id,menu_id)
                VALUES(granted_role.role_id,navigation_id) RETURNING id INTO link_id;
                INSERT INTO sand_ai_configuration_navigation_ownership
                SELECT 'role_menu',id,jsonb_build_object('role_id',role_id,'menu_id',menu_id)
                FROM sand_system_role_menu WHERE id=link_id;
            END IF;
        END LOOP;
    END LOOP;

    -- 应用试用 likewise has no dedicated code; roles holding any existing
    -- trial permission (the closest read/action family) receive the page plus
    -- the root and group links. This grants menu visibility only; no API
    -- permission is widened.
    FOR granted_role IN
        SELECT DISTINCT role_menu.role_id FROM sand_system_role_menu role_menu
        JOIN sand_system_menu permission ON permission.id=role_menu.menu_id
        JOIN sand_system_role role ON role.id=role_menu.role_id
        WHERE permission.type=3 AND permission.status=1 AND permission.delete_time IS NULL
          AND role.status=1 AND role.delete_time IS NULL
          AND permission.slug IN ('sand_ai:trial:options','sand_ai:trial:chat','sand_ai:trial:context','sand_ai:trial:knowledge','sand_ai:trial:agent')
    LOOP
        FOREACH navigation_id IN ARRAY ARRAY[root_id,group_id,app_trial_id] LOOP
            IF NOT EXISTS(
                SELECT 1 FROM sand_system_role_menu
                WHERE role_id=granted_role.role_id AND menu_id=navigation_id
            ) THEN
                INSERT INTO sand_system_role_menu(role_id,menu_id)
                VALUES(granted_role.role_id,navigation_id) RETURNING id INTO link_id;
                INSERT INTO sand_ai_configuration_navigation_ownership
                SELECT 'role_menu',id,jsonb_build_object('role_id',role_id,'menu_id',menu_id)
                FROM sand_system_role_menu WHERE id=link_id;
            END IF;
        END LOOP;
    END LOOP;
END
$g13_menu_regroup$;
