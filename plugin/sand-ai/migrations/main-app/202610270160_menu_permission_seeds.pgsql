-- SandAI administrator navigation and button permission seeds for the remaining
-- configuration surfaces. Seeds only make permissions grantable; no role receives
-- any new grant here. Roles already holding a page read permission receive only
-- the matching navigation links. The file center stays navigation-only like the
-- task trial page and still requires a real SandIAM workload identity; budget
-- policy stays platform superadmin only.
DO $menu_permission_seeds$
DECLARE
    root_id bigint;
    page_id bigint;
    node_id bigint;
    link_id bigint;
    count_nodes integer;
    page record;
    specification record;
    granted_role record;
    navigation_id bigint;
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
        ('概览','SandAIOverview','dashboard','/plugin/sand-ai/dashboard/index',95,'sand_ai:overview:index'::text),
        ('模型','SandAIModel','model','/plugin/sand-ai/model/index',65,'sand_ai:model:index'),
        ('调用记录','SandAIInvocation','invocation','/plugin/sand-ai/invocation/index',36,'sand_ai:invocation:index'),
        ('审计','SandAIAudit','audit','/plugin/sand-ai/audit/index',24,'sand_ai:audit:index'),
        ('文件中心','SandAIFileCenter','file','/plugin/sand-ai/file/index',48,NULL),
        ('费用与账单','SandAIUsage','usage','/plugin/sand-ai/usage/index',40,'sand_ai:usage:index'),
        ('能力配置','SandAICapability','capability','/plugin/sand-ai/capability/index',80,'sand_ai:setup:capability:read'),
        ('智能体配置','SandAIAgentConfiguration','agent','/plugin/sand-ai/agent/index',40,'sand_ai:agent:definition:read'),
        ('知识库配置','SandAIKnowledgeConfiguration','knowledge','/plugin/sand-ai/knowledge/index',30,'sand_ai:knowledge:index')
    ) AS pages(name,code,path,component,sort,read_permission) LOOP
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
            -- Navigation-only surface: no administrator-to-workload conversion
            -- and no role eligibility guess.
            IF EXISTS(SELECT 1 FROM sand_system_role_menu WHERE menu_id=page_id) THEN
                RAISE EXCEPTION 'SandAI navigation-only page has unexpected role links: %',page.code;
            END IF;
            CONTINUE;
        END IF;

        FOR specification IN SELECT * FROM (VALUES
            ('SandAIOverview','查看概览','sand_ai:overview:index',1),
            ('SandAIModel','模型列表','sand_ai:model:index',1),
            ('SandAIModel','模型读取','sand_ai:model:read',2),
            ('SandAIModel','模型保存','sand_ai:model:save',3),
            ('SandAIModel','模型更新','sand_ai:model:update',4),
            ('SandAIModel','模型删除','sand_ai:model:destroy',5),
            ('SandAIInvocation','查看调用记录','sand_ai:invocation:index',1),
            ('SandAIAudit','查看审计记录','sand_ai:audit:index',1),
            ('SandAIUsage','单次费用','sand_ai:usage:cost',10),
            ('SandAIUsage','费用汇总','sand_ai:usage:summary',11),
            ('SandAIUsage','账单凭证','sand_ai:billing:credential',12),
            ('SandAIUsage','同步账单','sand_ai:billing:sync',13),
            ('SandAIUsage','价格目录','sand_ai:pricing:index',14),
            ('SandAIUsage','保存价格草稿','sand_ai:pricing:save',15),
            ('SandAIUsage','发布价格','sand_ai:pricing:publish',16),
            ('SandAICapability','法律分析配置读取','sand_ai:setup:legal-profile:read',10),
            ('SandAICapability','保存法律分析配置草稿','sand_ai:setup:legal-profile:save',11),
            ('SandAICapability','发布法律分析配置','sand_ai:setup:legal-profile:publish',12),
            ('SandAICapability','撤销法律分析配置','sand_ai:setup:legal-profile:revoke',13),
            ('SandAICapability','录音转写契约','sand_ai:setup:audio-transcription:read',14),
            ('SandAIAgentConfiguration','管理智能体配置','sand_ai:agent:definition:manage',10),
            ('SandAIAgentConfiguration','查看渠道绑定','sand_ai:channel:binding:read',11),
            ('SandAIAgentConfiguration','管理渠道绑定','sand_ai:channel:binding:manage',12),
            ('SandAIAgentConfiguration','查看外部资料工具','sand_ai:tool:read',13),
            ('SandAIAgentConfiguration','创建外部资料连接','sand_ai:tool:connection:create',14),
            ('SandAIAgentConfiguration','创建外部资料工具草稿','sand_ai:tool:create',15),
            ('SandAIAgentConfiguration','发现受管 MCP 工具','sand_ai:tool:mcp:discover',16),
            ('SandAIAgentConfiguration','发布外部资料工具','sand_ai:tool:publish',17),
            ('SandAIAgentConfiguration','绑定外部资料工具','sand_ai:tool:bind',18),
            ('SandAIAgentConfiguration','撤销外部资料工具','sand_ai:tool:revoke',19),
            ('SandAIKnowledgeConfiguration','创建知识库草稿','sand_ai:knowledge:create',10),
            ('SandAIKnowledgeConfiguration','发布知识库','sand_ai:knowledge:publish',11),
            ('SandAIKnowledgeConfiguration','停用知识库','sand_ai:knowledge:disable',12),
            ('SandAIKnowledgeConfiguration','建立知识向量索引','sand_ai:knowledge:index:build',13),
            ('SandAIKnowledgeConfiguration','知识库检索试用','sand_ai:knowledge:query:test',14),
            ('SandAIKnowledgeConfiguration','知识库应用访问列表','sand_ai:knowledge:publication:index',15),
            ('SandAIKnowledgeConfiguration','开放知识库给应用','sand_ai:knowledge:publication:publish',16),
            ('SandAIKnowledgeConfiguration','撤销知识库应用访问','sand_ai:knowledge:publication:revoke',17),
            ('SandAIKnowledgeConfiguration','读取 Skill','sand_ai:skill:read',18),
            ('SandAIKnowledgeConfiguration','创建 Skill 草稿','sand_ai:skill:create',19),
            ('SandAIKnowledgeConfiguration','测试 Skill','sand_ai:skill:test',20),
            ('SandAIKnowledgeConfiguration','发布 Skill','sand_ai:skill:publish',21),
            ('SandAIKnowledgeConfiguration','撤销 Skill','sand_ai:skill:revoke',22),
            ('SandAIKnowledgeConfiguration','管理 Skill 案例','sand_ai:skill:case:manage',23)
        ) AS permissions(page_code,name,code,sort) WHERE page_code=page.code LOOP
            SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu
            WHERE code=specification.code OR slug=specification.code;
            IF count_nodes>1 THEN RAISE EXCEPTION 'SandAI permission is duplicated: %',specification.code; END IF;
            IF count_nodes=0 THEN
                INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
                VALUES(page_id,specification.name,specification.code,specification.code,3,'','','',specification.sort,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                RETURNING id INTO node_id;
                INSERT INTO sand_ai_configuration_navigation_ownership
                SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
                FROM sand_system_menu menu WHERE id=node_id;
            ELSIF NOT EXISTS(
                SELECT 1 FROM sand_system_menu WHERE id=node_id AND code=specification.code
                  AND slug=specification.code AND type=3 AND status=1 AND delete_time IS NULL
            ) THEN RAISE EXCEPTION 'Existing SandAI permission is incompatible: %',specification.code; END IF;
        END LOOP;

        -- The super-administrator already reads all menus through SandAdmin.
        -- Other roles receive only navigation corresponding to a prior read grant.
        FOR granted_role IN
            SELECT DISTINCT role_menu.role_id FROM sand_system_role_menu role_menu
            JOIN sand_system_menu permission ON permission.id=role_menu.menu_id
            JOIN sand_system_role role ON role.id=role_menu.role_id
            WHERE permission.type=3 AND permission.status=1 AND permission.delete_time IS NULL
              AND role.status=1 AND role.delete_time IS NULL AND permission.slug=page.read_permission
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
$menu_permission_seeds$;
