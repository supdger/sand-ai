-- UX Phase 2 shared migration. Each work package appends its own idempotent
-- DO block below; blocks are independent and may run in any order.

-- P8 administrator trial channel permission seed: `sand_ai:trial:context`
-- makes the POST /app/sand-ai/admin/trial/context endpoint grantable to
-- administrator roles. The seed only declares the button permission; no role
-- receives a grant here (the platform super-administrator already passes
-- CheckAuth), and no navigation entry is added. The node hangs under the
-- trial page when present, otherwise under the onboarding page or the SandAI
-- root, so role configuration screens keep it grouped with trial surfaces.
DO $ux_phase2_trial_permission$
DECLARE
    root_id bigint;
    page_id bigint;
    node_id bigint;
    count_nodes integer;
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

    SELECT count(*),min(id) INTO count_nodes,node_id FROM sand_system_menu
    WHERE code='sand_ai:trial:context' OR slug='sand_ai:trial:context';
    IF count_nodes>1 THEN
        RAISE EXCEPTION 'SandAI trial permission is duplicated';
    END IF;
    IF count_nodes=0 THEN
        INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
        VALUES(page_id,'管理员试用身份','sand_ai:trial:context','sand_ai:trial:context',3,'','','',30,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        RETURNING id INTO node_id;
        INSERT INTO sand_ai_configuration_navigation_ownership
        SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
        FROM sand_system_menu menu WHERE id=node_id;
    ELSIF NOT EXISTS(
        SELECT 1 FROM sand_system_menu WHERE id=node_id AND code='sand_ai:trial:context'
          AND slug='sand_ai:trial:context' AND type=3 AND status=1 AND delete_time IS NULL
    ) THEN
        RAISE EXCEPTION 'Existing SandAI trial permission is incompatible';
    END IF;
END
$ux_phase2_trial_permission$;
