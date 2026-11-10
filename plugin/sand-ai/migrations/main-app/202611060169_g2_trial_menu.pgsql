-- 0.4.10 Phase 2 (G2): unified SandAI navigation for the new administrator
-- "试一试" page. The trial surface becomes a first-level entry under the
-- SandAI root (sort 90 places it directly below 首页/95 and above 模型/65 in
-- the sort-desc menu), matching the G2 daily-entry order
-- 首页 → 试用 → 服务商 → 文件中心 → 费用与账单 → 高级.
--
-- Scope notes:
-- * 文件中心 (SandAIFileCenter) and 费用与账单 (SandAIUsage) already sit at
--   the top level from earlier seeds; this block leaves their codes, names,
--   paths, components, permission slugs and role links untouched.
-- * 任务与会议试用 (SandAITaskTrial) and the other low-frequency surfaces
--   were already folded under the visible 高级 group (SandAIAdvanced) by
--   migration 164; menu rows are still delivered, so every absolute route
--   keeps registering and old deep links keep working. This block asserts
--   nothing new there on purpose.
-- * 首页 (SandAIOverview) stays a top-level entry per the minimal-change
--   decision; it is no longer the only newcomer path because 试用 now sits
--   beside it.
-- * The two B2 button permissions (sand_ai:trial:options / sand_ai:trial:chat)
--   seeded by migration 168 are re-parented under the new page so role
--   configuration groups them with the surface they guard; codes, slugs and
--   role links are unchanged. sand_ai:trial:context stays under the legacy
--   trial/task surface because it still serves the old admin-trial channel.
--
-- The block is idempotent: re-running it converges to the same rows without
-- duplicating menus, re-parenting twice or duplicating role links.
DO $g2_trial_menu$
DECLARE
    root_id bigint;
    page_id bigint;
    node_id bigint;
    link_id bigint;
    count_nodes integer;
    granted_role record;
    navigation_id bigint;
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

    -- 试用 page: direct child of the SandAI root with an absolute stored path
    -- ('/sand-ai/trial'), matching the 164 convention so the effective route
    -- stays byte-stable if the node is ever regrouped again.
    SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu WHERE code='SandAITrial';
    IF count_nodes>1 THEN
        RAISE EXCEPTION 'SandAITrial menu is duplicated';
    END IF;
    IF count_nodes=0 THEN
        IF EXISTS(
            SELECT 1 FROM sand_system_menu
            WHERE parent_id=root_id AND type=2 AND path='/sand-ai/trial'
              AND code IS DISTINCT FROM 'SandAITrial'
        ) THEN
            RAISE EXCEPTION 'SandAI trial navigation conflicts with an existing page';
        END IF;
        INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
        VALUES(root_id,'试用','SandAITrial','',2,'/sand-ai/trial','/plugin/sand-ai/trial/index','',90,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        RETURNING id INTO page_id;
        INSERT INTO sand_ai_configuration_navigation_ownership
        SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
        FROM sand_system_menu menu WHERE id=page_id;
    ELSIF NOT EXISTS(
        SELECT 1 FROM sand_system_menu WHERE id=page_id AND parent_id=root_id AND type=2
          AND name='试用' AND path='/sand-ai/trial' AND component='/plugin/sand-ai/trial/index'
          AND COALESCE(slug,'')='' AND is_hidden=0 AND status=1 AND delete_time IS NULL
    ) THEN
        RAISE EXCEPTION 'Existing SandAITrial menu is incompatible';
    END IF;

    -- Re-parent the B2 button permissions under the new trial page. Slugs,
    -- codes and every existing role_menu link stay untouched.
    UPDATE sand_system_menu
    SET parent_id=page_id,
        update_time=CURRENT_TIMESTAMP
    WHERE code IN ('sand_ai:trial:options','sand_ai:trial:chat')
      AND type=3 AND status=1 AND delete_time IS NULL
      AND parent_id IS DISTINCT FROM page_id;

    -- Roles that already hold the trial options read permission also receive
    -- the page and root links, matching the page-seed convention; seeds grant
    -- nothing by themselves and this only repairs drift.
    FOR granted_role IN
        SELECT DISTINCT role_menu.role_id FROM sand_system_role_menu role_menu
        JOIN sand_system_menu permission ON permission.id=role_menu.menu_id
        JOIN sand_system_role role ON role.id=role_menu.role_id
        WHERE permission.type=3 AND permission.status=1 AND permission.delete_time IS NULL
          AND role.status=1 AND role.delete_time IS NULL
          AND permission.slug='sand_ai:trial:options'
    LOOP
        FOREACH navigation_id IN ARRAY ARRAY[root_id,page_id] LOOP
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
$g2_trial_menu$;
