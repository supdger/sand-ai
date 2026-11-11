-- 0.5.0 UX declutter: the 高级 child page SandAICapability is renamed from
-- "能力与路线" to "能力配置". Code, path, component, sort and role links are
-- untouched; the update is idempotent on re-run.
UPDATE sand_system_menu
SET name='能力配置',
    update_time=CURRENT_TIMESTAMP
WHERE code='SandAICapability' AND type=2 AND status=1 AND delete_time IS NULL
  AND name IS DISTINCT FROM '能力配置';
