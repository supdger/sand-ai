-- Forward repair for applied 120: avoid collision between the local row variable
-- n and a relation alias. Terminal proof, exact lease and projection checks remain.
CREATE OR REPLACE FUNCTION sand_ai_meeting_worker_step_seal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s sand_ai_task_step%ROWTYPE; n sand_ai_meeting_summary_node%ROWTYPE;
BEGIN
    IF TG_OP='UPDATE' AND to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD) THEN RETURN NULL; END IF;
    SELECT * INTO s FROM sand_ai_task_step WHERE id=NEW.id FOR SHARE;
    IF NOT FOUND OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=s.task_id) THEN RETURN NULL; END IF;
    IF s.sequence_no=1 AND s.step_code='invoke_model' THEN RETURN NULL; END IF;
    IF s.state IN ('failed','canceled') AND sand_ai_meeting_terminal_step(s.task_id,s.state) THEN
        IF EXISTS (SELECT 1 FROM sand_ai_task_step WHERE task_id=s.task_id AND state NOT IN ('succeeded',s.state))
           OR EXISTS (SELECT 1 FROM sand_ai_meeting_summary_node node_row JOIN sand_ai_task_step child ON child.id=node_row.task_step_id
               WHERE node_row.task_id=s.task_id AND node_row.result_sha256 IS NOT NULL AND child.state<>'succeeded') THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROJECTION_INVALID';
        END IF;
        RETURN NULL;
    END IF;
    -- Lease/cleanup boundary is repeated at commit after any node locks, even
    -- when the changed step is still pending. No late status projection survives.
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    IF s.state='succeeded' THEN
        SELECT * INTO n FROM sand_ai_meeting_summary_node WHERE task_id=s.task_id AND task_step_id=s.id FOR SHARE;
        IF NOT FOUND OR n.node_code IS DISTINCT FROM s.step_code THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_UNOWNED';
        END IF;
        PERFORM sand_ai_meeting_worker_assert_node_sealed(n.id);
    END IF;
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    RETURN NULL;
END $$;
