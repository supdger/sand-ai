-- 0.4.10 Phase 2 (B2): administrator "试一试" button permission seeds.
-- `sand_ai:trial:options` makes GET /app/sand-ai/admin/trial/options grantable
-- and `sand_ai:trial:chat` makes POST /app/sand-ai/admin/trial/chat grantable.
-- The seeds only declare the permissions; no role receives a grant here (the
-- platform super-administrator already passes CheckAuth), and no navigation
-- entry is added. Nodes hang under the trial page when present, otherwise
-- under the onboarding page or the SandAI root.
DO $ux_phase2_trial_managed_permissions$
DECLARE
    root_id bigint;
    page_id bigint;
    node_id bigint;
    count_nodes integer;
    specification record;
BEGIN
    IF to_regclass('sand_ai_configuration_navigation_ownership') IS NULL THEN
        RAISE EXCEPTION 'SandAI configuration navigation ownership is missing';
    END IF;
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,root_id FROM sand_system_menu
    WHERE code='SandAI' AND parent_id=0 AND type=1 AND status=1 AND delete_time IS NULL;
    IF count_nodes<>1 THEN
        RAISE EXCEPTION 'SandAI root navigation is missing or duplicated';
    END IF;

    SELECT id INTO page_id FROM sand_system_menu
    WHERE code IN ('SandAITaskTrial','SandAIOnboarding') AND type=2
      AND status=1 AND delete_time IS NULL
    ORDER BY CASE code WHEN 'SandAITaskTrial' THEN 0 ELSE 1 END, id
    LIMIT 1;
    page_id := COALESCE(page_id, root_id);

    FOR specification IN SELECT * FROM (VALUES
        ('sand_ai:trial:options','试用服务商选项',31),
        ('sand_ai:trial:chat','试用对话',32)
    ) AS permissions(code,name,sort) LOOP
        SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu
        WHERE code=specification.code OR slug=specification.code;
        IF count_nodes>1 THEN
            RAISE EXCEPTION 'SandAI trial permission is duplicated: %',specification.code;
        END IF;
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
        ) THEN
            RAISE EXCEPTION 'Existing SandAI trial permission is incompatible: %',specification.code;
        END IF;
    END LOOP;
END
$ux_phase2_trial_managed_permissions$;
