CREATE OR REPLACE FUNCTION sand_ai_skill_published_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref record;
BEGIN
    IF NEW.state = 'published' THEN
        FOR ref IN SELECT * FROM sand_ai_skill_knowledge_ref WHERE skill_version_id = NEW.id LOOP
            IF NOT EXISTS (
                SELECT 1
                FROM sand_ai_skill skill
                JOIN sand_ai_knowledge_publication publication
                    ON publication.consumer_environment_id = skill.environment_id
                   AND publication.knowledge_base_id = ref.knowledge_base_id
                   AND publication.state = 'published'
                JOIN sand_ai_knowledge_base kb
                    ON kb.id = publication.knowledge_base_id
                   AND kb.environment_id = publication.owner_environment_id
                JOIN sand_ai_knowledge_base_version version
                    ON version.id = ref.knowledge_base_version_id
                   AND version.knowledge_base_id = kb.id
                WHERE skill.id = NEW.skill_id
                  AND kb.status = 1
                  AND kb.delete_time IS NULL
                  AND kb.state = 'published'
                  AND version.state = 'published'
                  AND version.snapshot_sha256 = ref.snapshot_sha256
                  AND version.source_scope_sha256 IS NOT NULL
            ) THEN
                RAISE EXCEPTION 'SAND_AI_SKILL_KNOWLEDGE_REFERENCE_INVALID';
            END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END $$;
