-- Configuration navigation never grants workload service actions.
-- SandAdmin UserMenuCache grants all navigation to its existing superadmin;
-- task trial has no role links and still requires a real IAM workload identity.
DO $configuration_trial_navigation$
DECLARE
    root_id bigint;
    page_id bigint;
    node_id bigint;
    count_nodes integer;
    page record;
    granted_role record;
    navigation_id bigint;
    link_id bigint;
BEGIN
    IF to_regclass('sand_ai_configuration_navigation_ownership') IS NULL THEN
        RAISE EXCEPTION 'SandAI configuration navigation ownership is missing';
    END IF;
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,root_id FROM sand_system_menu WHERE code='SandAI';
    IF count_nodes<>1 OR NOT EXISTS(
        SELECT 1 FROM sand_system_menu WHERE id=root_id AND parent_id=0 AND type=1
          AND path='/sand-ai' AND COALESCE(component,'')='' AND COALESCE(slug,'')=''
          AND status=1 AND delete_time IS NULL
    ) THEN RAISE EXCEPTION 'SandAI root navigation is missing or incompatible'; END IF;

    FOR page IN SELECT * FROM (VALUES
        ('任务与会议试用','SandAITaskTrial','task','/plugin/sand-ai/task/index',50,NULL::text,''),
        ('智能体配置','SandAIAgentConfiguration','agent','/plugin/sand-ai/agent/index',40,'sand_ai:agent:definition:read','查看智能体配置'),
        ('知识库配置','SandAIKnowledgeConfiguration','knowledge','/plugin/sand-ai/knowledge/index',30,'sand_ai:knowledge:index','查看知识库配置')
    ) AS pages(name,code,path,component,sort,read_permission,permission_name) LOOP
        SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu WHERE code=page.code;
        IF count_nodes>1 OR EXISTS(
            SELECT 1 FROM sand_system_menu WHERE parent_id=root_id AND path=page.path AND code IS DISTINCT FROM page.code
        ) THEN RAISE EXCEPTION 'SandAI navigation conflicts: %',page.code; END IF;
        IF count_nodes=0 THEN
            INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
            VALUES(root_id,page.name,page.code,'',2,page.path,page.component,'',page.sort,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            RETURNING id INTO page_id;
            INSERT INTO sand_ai_configuration_navigation_ownership
            SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
            FROM sand_system_menu menu WHERE id=page_id;
        ELSIF NOT EXISTS(
            SELECT 1 FROM sand_system_menu WHERE id=page_id AND parent_id=root_id AND type=2
              AND name=page.name AND path=page.path AND component=page.component
              AND COALESCE(slug,'')='' AND is_hidden=0 AND status=1 AND delete_time IS NULL
        ) THEN RAISE EXCEPTION 'Existing SandAI navigation is incompatible: %',page.code; END IF;

        IF page.read_permission IS NULL THEN
            -- No administrator-to-workload conversion and no role eligibility guess.
            IF EXISTS(SELECT 1 FROM sand_system_role_menu WHERE menu_id=page_id) THEN
                RAISE EXCEPTION 'SandAI task trial navigation has unexpected role links';
            END IF;
            CONTINUE;
        END IF;

        -- Declare only the exact read permission already present on the public
        -- backend controller; never grant it to a role during navigation setup.
        SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu
        WHERE code=page.read_permission OR slug=page.read_permission;
        IF count_nodes>1 THEN RAISE EXCEPTION 'SandAI read permission is duplicated: %',page.read_permission; END IF;
        IF count_nodes=0 THEN
            INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
            VALUES(page_id,page.permission_name,page.read_permission,page.read_permission,3,'','','',1,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            RETURNING id INTO node_id;
            INSERT INTO sand_ai_configuration_navigation_ownership
            SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
            FROM sand_system_menu menu WHERE id=node_id;
        ELSIF NOT EXISTS(
            SELECT 1 FROM sand_system_menu WHERE id=node_id AND code=page.read_permission
              AND slug=page.read_permission AND type=3 AND status=1 AND delete_time IS NULL
        ) THEN RAISE EXCEPTION 'Existing SandAI read permission is incompatible: %',page.read_permission; END IF;

        FOR granted_role IN
            SELECT DISTINCT role_menu.role_id FROM sand_system_role_menu role_menu
            JOIN sand_system_menu permission ON permission.id=role_menu.menu_id
            JOIN sand_system_role role ON role.id=role_menu.role_id
            WHERE permission.id=node_id AND permission.type=3 AND permission.status=1
              AND permission.delete_time IS NULL AND role.status=1 AND role.delete_time IS NULL
              AND permission.slug=page.read_permission
        LOOP
            FOREACH navigation_id IN ARRAY ARRAY[root_id,page_id] LOOP
                IF NOT EXISTS(SELECT 1 FROM sand_system_role_menu WHERE role_id=granted_role.role_id AND menu_id=navigation_id) THEN
                    INSERT INTO sand_system_role_menu(role_id,menu_id) VALUES(granted_role.role_id,navigation_id) RETURNING id INTO link_id;
                    INSERT INTO sand_ai_configuration_navigation_ownership
                    SELECT 'role_menu',id,jsonb_build_object('role_id',role_id,'menu_id',menu_id)
                    FROM sand_system_role_menu WHERE id=link_id;
                END IF;
            END LOOP;
        END LOOP;
    END LOOP;
END
$configuration_trial_navigation$;
