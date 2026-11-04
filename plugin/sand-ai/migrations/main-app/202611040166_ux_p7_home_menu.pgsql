-- UX Phase 2 P7: the merged home page replaces the old 概览 entry and the
-- wizard folds into it. SandAIOverview is renamed to 首页 and repointed to
-- the new component; its stored path becomes the absolute route /sand-ai/home,
-- matching the 164 convention, so the menu entry renders the merged home.
-- SandAIOnboarding leaves the visible navigation (is_hidden=1) while keeping
-- its code, path and component, so the legacy route /sand-ai/onboarding stays
-- directly reachable for bookmarks and advanced flows. Permission codes,
-- slugs and role links are unchanged. The block is idempotent: re-running it
-- converges to the same rows without duplicating menus or links.
DO $ux_p7_home_menu$
DECLARE
    root_id bigint;
    page_id bigint;
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

    -- 首页: same entry node, new display name/component, absolute route.
    SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu
    WHERE code='SandAIOverview' AND parent_id=root_id AND type=2
      AND status=1 AND delete_time IS NULL;
    IF count_nodes<>1 THEN
        RAISE EXCEPTION 'SandAIOverview menu is missing or duplicated';
    END IF;
    UPDATE sand_system_menu
    SET name='首页',
        path='/sand-ai/home',
        component='/plugin/sand-ai/home/index',
        is_hidden=CASE WHEN is_hidden=0 THEN 2 ELSE is_hidden END,
        update_time=CURRENT_TIMESTAMP
    WHERE id=page_id
      AND (name IS DISTINCT FROM '首页'
        OR path IS DISTINCT FROM '/sand-ai/home'
        OR component IS DISTINCT FROM '/plugin/sand-ai/home/index'
        OR is_hidden=0);

    -- 首次开通: hidden from navigation; code/path/component untouched so the
    -- direct URL keeps resolving to the existing onboarding component.
    SELECT count(*),min(id) INTO count_nodes,page_id FROM sand_system_menu
    WHERE code='SandAIOnboarding' AND parent_id=root_id AND type=2
      AND status=1 AND delete_time IS NULL;
    IF count_nodes<>1 THEN
        RAISE EXCEPTION 'SandAIOnboarding menu is missing or duplicated';
    END IF;
    UPDATE sand_system_menu
    SET is_hidden=1,
        update_time=CURRENT_TIMESTAMP
    WHERE id=page_id AND is_hidden<>1;

    -- Ownership snapshots stay immutable: updated pages keep their original
    -- recorded definition, matching the append-only ownership contract.
END
$ux_p7_home_menu$;
