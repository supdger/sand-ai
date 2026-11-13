-- 0.5.0 fix6: the admin file list endpoint carries a grantable button
-- permission so roles can be allowed explicitly. Declaration only; no role
-- grant is added here. Idempotent on re-run.
DO $sandai_050_file_index_permission$
DECLARE
    page_id bigint;
    node_id bigint;
    count_nodes integer;
BEGIN
    IF to_regclass('sand_ai_configuration_navigation_ownership') IS NULL THEN
        RAISE EXCEPTION 'SandAI configuration navigation ownership is missing';
    END IF;
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu
    WHERE code='SandAIFileCenter' AND type=2 AND status=1 AND delete_time IS NULL;
    IF count_nodes<>1 THEN
        RAISE EXCEPTION 'SandAI file center navigation is missing or duplicated';
    END IF;

    SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu
    WHERE code='sand_ai:file:index' OR slug='sand_ai:file:index';
    IF count_nodes>1 THEN
        RAISE EXCEPTION 'SandAI file permission is duplicated';
    END IF;
    IF count_nodes=0 THEN
        INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
        VALUES(page_id,'文件列表','sand_ai:file:index','sand_ai:file:index',3,'','','',1,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        RETURNING id INTO node_id;
        INSERT INTO sand_ai_configuration_navigation_ownership
        SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
        FROM sand_system_menu menu WHERE id=node_id;
    ELSIF NOT EXISTS(
        SELECT 1 FROM sand_system_menu WHERE id=node_id AND code='sand_ai:file:index'
          AND slug='sand_ai:file:index' AND type=3 AND status=1 AND delete_time IS NULL
    ) THEN
        RAISE EXCEPTION 'Existing SandAI file permission is incompatible';
    END IF;
END
$sandai_050_file_index_permission$;
