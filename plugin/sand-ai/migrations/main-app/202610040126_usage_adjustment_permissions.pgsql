DO $usage_adjustment_permissions$
DECLARE root_id bigint; node_id bigint; matches integer; specification record;
BEGIN
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO matches,root_id FROM sand_system_menu
        WHERE code='SandAI' AND parent_id=0 AND type=1 AND path='/sand-ai'
          AND status=1 AND delete_time IS NULL;
    IF matches<>1 THEN RAISE EXCEPTION 'Exact SandAI root menu is required'; END IF;
    FOR specification IN SELECT * FROM (VALUES
        ('SandAI refund adjustment read','sand_ai:usage:adjustment:index',1),
        ('SandAI record reviewed refund','sand_ai:usage:adjustment:refund',2)
    ) AS definitions(name,code,sort) LOOP
        SELECT count(*),min(id) INTO matches,node_id FROM sand_system_menu
            WHERE code=specification.code OR slug=specification.code;
        IF matches>0 THEN RAISE EXCEPTION 'Refund permission already exists; explicit adoption required: %',specification.code; END IF;
        INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
        VALUES(root_id,specification.name,specification.code,specification.code,3,'','','',specification.sort,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        RETURNING id INTO node_id;
        INSERT INTO sand_ai_configuration_navigation_ownership
        SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
        FROM sand_system_menu menu WHERE id=node_id;
    END LOOP;
END
$usage_adjustment_permissions$;
