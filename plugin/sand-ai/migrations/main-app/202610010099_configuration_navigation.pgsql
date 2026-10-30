-- SandAI administrator configuration navigation. API permissions are declared,
-- never automatically granted; only existing read grants receive navigation links.
CREATE TABLE sand_ai_configuration_navigation_ownership (
    object_kind VARCHAR(16) NOT NULL CHECK (object_kind IN ('menu','role_menu')),
    object_id BIGINT NOT NULL CHECK (object_id > 0),
    definition JSONB NOT NULL CHECK (jsonb_typeof(definition) = 'object'),
    PRIMARY KEY (object_kind,object_id)
);
COMMENT ON TABLE sand_ai_configuration_navigation_ownership IS 'Exact ownership of configuration menu nodes and navigation-only role links; no automatic API grants';
CREATE TRIGGER sand_ai_configuration_navigation_ownership_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_configuration_navigation_ownership
    FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_billing_mutation();

DO $configuration_navigation$
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
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,root_id FROM sand_system_menu WHERE code='SandAI';
    IF count_nodes <> 1 OR NOT EXISTS (
        SELECT 1 FROM sand_system_menu WHERE id=root_id AND parent_id=0 AND type=1
          AND path='/sand-ai' AND COALESCE(component,'')='' AND COALESCE(slug,'')=''
          AND status=1 AND delete_time IS NULL
    ) THEN
        RAISE EXCEPTION 'SandAI root navigation is missing or incompatible; review the installed billing navigation declaration';
    END IF;
    FOR page IN SELECT * FROM (VALUES
        ('首次开通','SandAIOnboarding','onboarding','/plugin/sand-ai/onboarding/index',90,'sand_ai:onboarding:read'),
        ('能力配置','SandAICapability','capability','/plugin/sand-ai/capability/index',80,'sand_ai:setup:capability:read'),
        ('材料配置','SandAIMaterialConfig','material-config','/plugin/sand-ai/capability/index',70,'sand_ai:setup:material-profile:read'),
        ('服务商','SandAIProvider','provider','/plugin/sand-ai/provider/index',60,'sand_ai:provider:index')
    ) AS pages(name,code,path,component,sort,read_permission) LOOP
        SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu WHERE code=page.code;
        IF count_nodes > 1 OR EXISTS (
            SELECT 1 FROM sand_system_menu WHERE parent_id=root_id AND path=page.path AND code IS DISTINCT FROM page.code
        ) THEN RAISE EXCEPTION 'SandAI configuration navigation conflicts: %',page.code; END IF;
        IF count_nodes=0 THEN
            INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
            VALUES(root_id,page.name,page.code,'',2,page.path,page.component,'',page.sort,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            RETURNING id INTO page_id;
            INSERT INTO sand_ai_configuration_navigation_ownership
            SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
            FROM sand_system_menu menu WHERE id=page_id;
        ELSIF NOT EXISTS (
            SELECT 1 FROM sand_system_menu WHERE id=page_id AND parent_id=root_id AND type=2
              AND path=page.path AND component=page.component AND COALESCE(slug,'')=''
              AND status=1 AND delete_time IS NULL
        ) THEN RAISE EXCEPTION 'Existing SandAI configuration navigation is incompatible: %',page.code;
        END IF;
        FOR specification IN SELECT * FROM (VALUES
            ('SandAIOnboarding','查看首次开通','sand_ai:onboarding:read',1),
            ('SandAIOnboarding','发现服务商模型','sand_ai:onboarding:discover',2),
            ('SandAIOnboarding','验证服务商模型','sand_ai:onboarding:verify',3),
            ('SandAIOnboarding','发布已验证模型','sand_ai:onboarding:publish',4),
            ('SandAIOnboarding','查看服务目录状态','sand_ai:setup:service-catalog:read',5),
            ('SandAIOnboarding','登记服务动作','sand_ai:setup:service-catalog:register',6),
            ('SandAICapability','查看能力配置元数据','sand_ai:setup:capability:metadata',1),
            ('SandAICapability','查看能力配置','sand_ai:setup:capability:read',2),
            ('SandAICapability','保存能力配置草稿','sand_ai:setup:capability:save',3),
            ('SandAICapability','发布能力配置','sand_ai:setup:capability:publish',4),
            ('SandAICapability','查看模型部署','sand_ai:setup:deployment:read',5),
            ('SandAICapability','保存模型部署草稿','sand_ai:setup:deployment:save',6),
            ('SandAICapability','验证模型部署','sand_ai:setup:deployment:verify',7),
            ('SandAIMaterialConfig','查看材料配置','sand_ai:setup:material-profile:read',1),
            ('SandAIMaterialConfig','保存材料配置草稿','sand_ai:setup:material-profile:save',2),
            ('SandAIMaterialConfig','发布材料配置','sand_ai:setup:material-profile:publish',3),
            ('SandAIMaterialConfig','撤销材料配置','sand_ai:setup:material-profile:revoke',4),
            ('SandAIProvider','查看服务商列表','sand_ai:provider:index',1),
            ('SandAIProvider','读取服务商','sand_ai:provider:read',2),
            ('SandAIProvider','新增服务商','sand_ai:provider:save',3),
            ('SandAIProvider','修改服务商','sand_ai:provider:update',4),
            ('SandAIProvider','删除服务商','sand_ai:provider:destroy',5)
        ) AS permissions(page_code,name,code,sort) WHERE page_code=page.code LOOP
            SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu
            WHERE code=specification.code OR slug=specification.code;
            IF count_nodes > 1 THEN RAISE EXCEPTION 'SandAI configuration permission is duplicated: %',specification.code; END IF;
            IF count_nodes=0 THEN
                INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
                VALUES(page_id,specification.name,specification.code,specification.code,3,'','','',specification.sort,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                RETURNING id INTO node_id;
                INSERT INTO sand_ai_configuration_navigation_ownership
                SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
                FROM sand_system_menu menu WHERE id=node_id;
            ELSIF NOT EXISTS (
                SELECT 1 FROM sand_system_menu WHERE id=node_id AND code=specification.code AND slug=specification.code
                  AND type=3 AND status=1 AND delete_time IS NULL
            ) THEN RAISE EXCEPTION 'Existing SandAI configuration permission is incompatible: %',specification.code;
            END IF;
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
$configuration_navigation$;
