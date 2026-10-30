-- 112 is after 111: concrete prepared-plan and aggregation enrollment replace the 109 seal-only guard.
CREATE OR REPLACE FUNCTION sand_ai_meeting_worker_checkpoint_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t sand_ai_task_run%ROWTYPE; s sand_ai_audio_transcript_seal%ROWTYPE; prepared record;
    binding jsonb; source_audio record; source_manifest record; review_row record;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_IMMUTABLE'; END IF;
    IF TG_OP='INSERT' THEN
        t := sand_ai_meeting_worker_task(NEW.task_id);
        IF NEW.state <> 'staging' OR NEW.event_sequence <> 0 OR NEW.last_event_sha256 IS NOT NULL
           OR NEW.anchor_text IS NULL OR octet_length(NEW.anchor_text) > 8388608
           OR NEW.anchor_text::jsonb IS DISTINCT FROM NEW.anchor_snapshot
           OR NEW.anchor_snapshot IS DISTINCT FROM jsonb_build_object(
               'source',(t.input->'_meeting_summary')-'audio_authorization','plan',t.input->'_meeting_plan',
               'capacity',t.input->'_meeting_capacity','route',t.input->'_meeting_route')
           OR t.input->'_meeting_plan' IS NULL
           OR EXISTS (SELECT 1 FROM sand_ai_meeting_summary_node WHERE task_id=NEW.task_id) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_ENROLLMENT_INVALID';
        END IF;
        IF NEW.anchor_snapshot #> '{plan,prepared_plan_ref}' IS NOT NULL THEN
            SELECT * INTO prepared FROM sand_ai_meeting_plan_checkpoint WHERE task_id=NEW.task_id FOR SHARE;
            IF NOT FOUND OR prepared.phase<>'complete' OR prepared.content_purged_time IS NOT NULL
                OR NEW.anchor_snapshot #>> '{plan,prepared_plan_ref,version}' IS DISTINCT FROM 'meeting_prepared_plan@1'
                OR NEW.anchor_snapshot #>> '{plan,prepared_plan_ref,task_id}' IS DISTINCT FROM NEW.task_id::text
                OR NEW.anchor_snapshot #>> '{plan,prepared_plan_ref,anchor_sha256}' IS DISTINCT FROM prepared.anchor_sha256
                OR NEW.anchor_snapshot #>> '{plan,prepared_plan_ref,manifest_sha256}' IS DISTINCT FROM prepared.manifest_sha256
                OR NEW.anchor_snapshot #>> '{plan,manifest_sha256}' IS DISTINCT FROM prepared.manifest_sha256
                OR NEW.anchor_snapshot->'source' IS DISTINCT FROM prepared.anchor->'source'
                OR NEW.anchor_snapshot->'capacity' IS DISTINCT FROM prepared.anchor->'capacity'
                OR NEW.anchor_snapshot->'route' IS DISTINCT FROM prepared.anchor->'route'
                OR NEW.anchor_snapshot #>> '{plan,total_segments}' IS DISTINCT FROM prepared.anchor->>'total_segments'
                OR NEW.anchor_snapshot #>> '{plan,eligible_segments}' IS DISTINCT FROM prepared.state_json->>'eligible_segments'
                OR NEW.anchor_snapshot #>> '{plan,leaf_count}' IS DISTINCT FROM prepared.state_json->>'leaf_count' THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_PREPARED_PLAN_INVALID';
            END IF;
        END IF;
        IF NEW.source_kind='seal' THEN
          SELECT * INTO s FROM sand_ai_audio_transcript_seal WHERE id=NEW.source_seal_id FOR SHARE;
          IF NOT FOUND OR s.content_purged_time IS NOT NULL OR s.environment_id <> t.environment_id
           OR s.audio_task_id::text IS DISTINCT FROM t.input #>> '{_meeting_summary,audio_task_id}'
           OR s.file_id::text IS DISTINCT FROM t.input #>> '{_meeting_summary,file_id}'
           OR s.transcript_sha256 IS DISTINCT FROM t.input #>> '{_meeting_summary,sha256}'
           OR s.review_revision::text IS DISTINCT FROM t.input #>> '{_meeting_summary,review_revision}'
           OR s.id::text IS DISTINCT FROM t.input #>> '{_meeting_summary,transcript_seal,id}' THEN
              RAISE EXCEPTION 'SAND_AI_SCOPE_SNAPSHOT_INVALID';
          END IF;
        ELSE
            binding:=t.input #> '{_meeting_summary,aggregation_source}';
            IF NEW.source_seal_id IS NOT NULL OR jsonb_typeof(binding) IS DISTINCT FROM 'object'
                OR binding->>'version' IS DISTINCT FROM 'meeting_aggregation_source@1'
                OR binding->>'sha_algorithm' IS DISTINCT FROM 'sha256/aggregation-review-anchor@1'
                OR NEW.anchor_snapshot #> '{plan,prepared_plan_ref}' IS NULL THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_AGGREGATION_BINDING_INVALID';
            END IF;
            SELECT id,environment_id,version,xmin::text AS row_xmin,state,status,delete_time,content_purged_time,
                result->'_audio_chunk_aggregation' AS marker INTO source_audio
                FROM sand_ai_task_run WHERE id=(binding->>'audio_task_id')::bigint FOR SHARE;
            IF NOT FOUND OR source_audio.environment_id<>t.environment_id OR source_audio.state<>'succeeded'
                OR source_audio.status<>1 OR source_audio.delete_time IS NOT NULL OR source_audio.content_purged_time IS NOT NULL
                OR source_audio.version::text IS DISTINCT FROM binding->>'task_version'
                OR source_audio.row_xmin IS DISTINCT FROM binding->>'task_xmin'
                OR binding->>'audio_task_id' IS DISTINCT FROM t.input #>> '{_meeting_summary,audio_task_id}'
                OR binding->>'review_revision' IS DISTINCT FROM t.input #>> '{_meeting_summary,review_revision}'
                OR binding->>'total_segments' IS DISTINCT FROM t.input #>> '{_meeting_summary,total_segments}'
                OR source_audio.marker->>'manifest_sha256' IS DISTINCT FROM binding->>'manifest_sha256'
                OR source_audio.marker->>'transcript_sha256' IS DISTINCT FROM binding->>'completion_sha256'
                OR source_audio.marker->>'chunk_count' IS DISTINCT FROM binding->>'chunk_count' THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_AGGREGATION_BINDING_INVALID';
            END IF;
            SELECT id,task_run_id,environment_id,manifest_sha256,chunk_count INTO source_manifest
                FROM sand_ai_audio_chunk_manifest WHERE id=(binding->>'manifest_id')::bigint FOR SHARE;
            IF NOT FOUND OR source_manifest.task_run_id<>source_audio.id OR source_manifest.environment_id<>t.environment_id
                OR source_manifest.manifest_sha256 IS DISTINCT FROM binding->>'manifest_sha256'
                OR source_manifest.chunk_count::text IS DISTINCT FROM binding->>'chunk_count' THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_AGGREGATION_BINDING_INVALID';
            END IF;
            IF (binding->>'review_revision')::integer>0 THEN
                SELECT id,xmin::text AS row_xmin,base_sha256,owner_scope,content_purged_time INTO review_row
                    FROM sand_ai_audio_segment_review WHERE audio_task_id=source_audio.id
                        AND revision=(binding->>'review_revision')::integer FOR SHARE;
                IF NOT FOUND OR review_row.id::text IS DISTINCT FROM binding->>'review_last_id'
                    OR review_row.row_xmin IS DISTINCT FROM binding->>'review_last_xmin'
                    OR review_row.base_sha256 IS DISTINCT FROM binding->>'review_base_sha256'
                    OR review_row.owner_scope IS DISTINCT FROM binding->>'owner_scope'
                    OR review_row.content_purged_time IS NOT NULL THEN
                    RAISE EXCEPTION 'SAND_AI_MEETING_AGGREGATION_BINDING_INVALID';
                END IF;
            END IF;
        END IF;
        NEW.anchor_sha256 := encode(sha256(convert_to(NEW.anchor_text,'UTF8')),'hex');
        NEW.anchor_text := NULL;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    ELSE
        IF ROW(NEW.id,NEW.task_id,NEW.source_kind,NEW.source_seal_id,NEW.anchor_snapshot,
            NEW.anchor_sha256,NEW.anchor_text,NEW.create_time) IS DISTINCT FROM
           ROW(OLD.id,OLD.task_id,OLD.source_kind,OLD.source_seal_id,OLD.anchor_snapshot,
            OLD.anchor_sha256,OLD.anchor_text,OLD.create_time) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_IMMUTABLE';
        END IF;
        SELECT * INTO t FROM sand_ai_task_run WHERE id=NEW.task_id FOR UPDATE;
        IF t.content_purged_time IS NOT NULL AND NEW.state='purged'
           AND NEW.event_sequence=OLD.event_sequence
           AND NEW.last_event_sha256 IS NOT DISTINCT FROM OLD.last_event_sha256 THEN RETURN NEW; END IF;
        IF pg_trigger_depth() < 2 OR NEW.state <> OLD.state OR OLD.state <> 'staging'
           OR NEW.event_sequence <> OLD.event_sequence+1
           OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_event e
               WHERE e.task_id=NEW.task_id AND e.sequence_no=NEW.event_sequence
               AND e.event_sha256=NEW.last_event_sha256
               AND e.previous_sha256 IS NOT DISTINCT FROM OLD.last_event_sha256) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_WORKER_CHAIN_INVALID';
        END IF;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    END IF;
    RETURN NEW;
END $$;

-- Registered isolated candidate; requires 109 and 111. No SQL execution evidence.
CREATE TABLE sand_ai_meeting_leaf_part_page (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES sand_ai_meeting_worker_checkpoint(task_id) ON DELETE RESTRICT,
    leaf_sequence_no integer NOT NULL CHECK (leaf_sequence_no BETWEEN 1 AND 100000),
    part_page_no integer NOT NULL CHECK (part_page_no BETWEEN 1 AND 16),
    page_id bigint NOT NULL,
    page_sequence_no integer NOT NULL CHECK (page_sequence_no BETWEEN 1 AND 200000),
    part_start integer NOT NULL CHECK (part_start BETWEEN 0 AND 511),
    part_count integer NOT NULL CHECK (part_count BETWEEN 1 AND 32),
    anchor_sha256 char(64) NOT NULL CHECK (anchor_sha256 ~ '^[a-f0-9]{64}$'),
    UNIQUE(task_id,leaf_sequence_no,part_page_no), UNIQUE(page_id),
    FOREIGN KEY(page_id,task_id) REFERENCES sand_ai_meeting_worker_page(id,task_id) ON DELETE RESTRICT
);
CREATE FUNCTION sand_ai_meeting_leaf_part_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_worker_checkpoint%ROWTYPE; p sand_ai_meeting_worker_page%ROWTYPE;
    leaf jsonb; entry jsonb; part jsonb; proof jsonb; i integer; source_index integer; part_total integer;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_IMMUTABLE'; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id AND state='staging';
    IF NOT FOUND OR NEW.anchor_sha256 IS DISTINCT FROM c.anchor_sha256
        OR NEW.part_start<>(NEW.part_page_no-1)*32 THEN RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID'; END IF;
    SELECT private_payload->0 INTO leaf FROM sand_ai_meeting_plan_page
        WHERE task_id=NEW.task_id AND page_kind='leaf' AND sequence_no=NEW.leaf_sequence_no;
    IF leaf IS NULL OR jsonb_typeof(leaf->'parts') IS DISTINCT FROM 'array'
        OR leaf->>'node_code' IS DISTINCT FROM format('chunk_%s',lpad(NEW.leaf_sequence_no::text,greatest(5,length(NEW.leaf_sequence_no::text)),'0'))
        OR leaf->>'sequence_no' IS DISTINCT FROM NEW.leaf_sequence_no::text THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID';
    END IF;
    part_total := jsonb_array_length(leaf->'parts');
    IF part_total NOT BETWEEN 1 AND 512 OR NEW.part_start>=part_total
        OR NEW.part_count<>least(32,part_total-NEW.part_start)
        OR (NEW.part_page_no>1 AND NOT EXISTS(SELECT 1 FROM sand_ai_meeting_leaf_part_page
            WHERE task_id=NEW.task_id AND leaf_sequence_no=NEW.leaf_sequence_no AND part_page_no=NEW.part_page_no-1)) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID';
    END IF;
    SELECT * INTO p FROM sand_ai_meeting_worker_page WHERE id=NEW.page_id AND task_id=NEW.task_id;
    IF NOT FOUND OR p.page_kind<>'leaf' OR p.round_no<>0 OR p.sequence_no<>NEW.page_sequence_no
        OR p.private_payload IS NULL OR p.entry_count<>NEW.part_count THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID';
    END IF;
    FOR i IN 0..NEW.part_count-1 LOOP
        entry := p.private_payload->i;
        part := leaf->'parts'->(NEW.part_start+i);
        IF entry->>'leaf_sequence_no' IS DISTINCT FROM NEW.leaf_sequence_no::text
            OR entry->>'part_index' IS DISTINCT FROM (NEW.part_start+i)::text
            OR entry->'part' IS DISTINCT FROM part OR jsonb_typeof(entry->'line') IS DISTINCT FROM 'string'
            OR jsonb_typeof(part->'segment_index') IS DISTINCT FROM 'number'
            OR (part->>'segment_index') !~ '^[0-9]+$' THEN RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID'; END IF;
        source_index := (part->>'segment_index')::integer;
        IF source_index NOT BETWEEN 0 AND 9999 THEN RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID'; END IF;
        SELECT private_payload->(source_index%32)->'proof' INTO proof FROM sand_ai_meeting_plan_page
            WHERE task_id=NEW.task_id AND page_kind='source' AND sequence_no=source_index/32+1;
        IF proof IS NULL OR entry->'source_proof' IS DISTINCT FROM proof
            OR NOT EXISTS(SELECT 1 FROM sand_ai_meeting_worker_page sp WHERE sp.task_id=NEW.task_id
                AND sp.page_kind='source' AND sp.round_no=0 AND sp.sequence_no=source_index/32+1
                AND sp.private_payload->(source_index%32) IS NOT DISTINCT FROM jsonb_build_object('segment_index',source_index,'proof',proof)) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_INVALID';
        END IF;
    END LOOP;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_leaf_part_guard BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_meeting_leaf_part_page
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_leaf_part_guard();
CREATE FUNCTION sand_ai_meeting_leaf_part_commit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.page_kind='leaf' THEN
        IF NOT EXISTS(SELECT 1 FROM sand_ai_meeting_leaf_part_page WHERE page_id=NEW.id AND task_id=NEW.task_id) THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_LEAF_PAGE_MAPPING_MISSING';
        END IF;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    END IF;
    RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER sand_ai_meeting_leaf_part_commit AFTER INSERT ON sand_ai_meeting_worker_page
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_leaf_part_commit();

-- New immutable nodes also bind exact message bytes to the official planned
-- leaf receipt. Old 088 nodes retain NULL and are never rewritten/backfilled.
ALTER TABLE sand_ai_meeting_summary_node ADD COLUMN worker_messages_sha256 char(64) NULL
    CHECK(worker_messages_sha256 ~ '^[a-f0-9]{64}$'),
    ADD COLUMN worker_messages_text text NULL CHECK(worker_messages_text IS NULL);
CREATE FUNCTION sand_ai_meeting_node_message_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_worker_checkpoint%ROWTYPE; leaf jsonb; expected_ids jsonb;
BEGIN
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF TG_OP='UPDATE' THEN
        IF NEW.worker_messages_sha256 IS DISTINCT FROM OLD.worker_messages_sha256 OR NEW.worker_messages_text IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_MESSAGES_IMMUTABLE';
        END IF;
        RETURN NEW;
    END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    IF NEW.worker_messages_text IS NULL OR octet_length(NEW.worker_messages_text)>262144
        OR NEW.worker_messages_text::jsonb IS DISTINCT FROM NEW.input_snapshot->'messages' THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_NODE_MESSAGES_INVALID';
    END IF;
    NEW.worker_messages_sha256 := encode(sha256(convert_to(NEW.worker_messages_text,'UTF8')),'hex');
    NEW.worker_messages_text := NULL;
    IF NEW.node_kind='chunk' THEN
        SELECT private_payload->0 INTO leaf FROM sand_ai_meeting_plan_page
            WHERE task_id=NEW.task_id AND page_kind='leaf' AND sequence_no=NEW.sequence_no;
        IF leaf IS NULL OR leaf->>'node_code' IS DISTINCT FROM NEW.node_code
            OR leaf->>'sequence_no' IS DISTINCT FROM NEW.sequence_no::text
            OR jsonb_typeof(leaf->'parts') IS DISTINCT FROM 'array'
            OR jsonb_array_length(leaf->'parts') NOT BETWEEN 1 AND 512
            OR leaf->>'messages_sha256' IS DISTINCT FROM NEW.worker_messages_sha256
            OR leaf #>> '{token_count_receipt,input_sha256}' IS DISTINCT FROM NEW.worker_messages_sha256
            OR NEW.segment_start_index::text IS DISTINCT FROM leaf #>> '{parts,0,segment_index}'
            OR NEW.segment_end_index::text IS DISTINCT FROM
                leaf->'parts'->(jsonb_array_length(leaf->'parts')-1)->>'segment_index'
            OR NEW.level_no<>0 THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PLAN_INVALID'; END IF;
        SELECT jsonb_agg('S'||(segment_position+1)::text ORDER BY first_position) INTO expected_ids
            FROM(SELECT (part->>'segment_index')::integer AS segment_position,min(position) AS first_position
                FROM jsonb_array_elements(leaf->'parts') WITH ORDINALITY selected(part,position)
                GROUP BY (part->>'segment_index')::integer) sources;
        IF expected_ids IS DISTINCT FROM NEW.input_snapshot->'segment_ids' THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PLAN_INVALID';
        END IF;
    ELSIF NEW.node_kind<>'merge' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_PLAN_INVALID'; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_node_message_guard BEFORE INSERT OR UPDATE ON sand_ai_meeting_summary_node
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_node_message_guard();
CREATE INDEX sand_ai_meeting_unfinished_step_idx ON sand_ai_task_step(task_id,sequence_no,id)
    WHERE sequence_no>1 AND state<>'succeeded';
CREATE INDEX sand_ai_meeting_root_idx ON sand_ai_meeting_summary_node(task_id,id) WHERE parent_node_code IS NULL;

-- A verification page is the actual next 32 immutable nodes. Every cumulative
-- count is computed here from those rows and the previous guarded page.
-- Supplying a PHP last-id/covered-count cannot skip a single node.
CREATE FUNCTION sand_ai_meeting_verification_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior jsonb; expected jsonb:='[]'::jsonb; n sand_ai_meeting_summary_node%ROWTYPE;
    total integer:=0; leaves integer:=0; roots integer:=0; last_id bigint:=0; child jsonb; child_count integer;
BEGIN
    IF NEW.page_kind<>'frontier' OR NEW.round_no<>32 THEN RETURN NEW; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    SELECT private_payload->(entry_count-1) INTO prior FROM sand_ai_meeting_worker_page
        WHERE task_id=NEW.task_id AND page_kind='frontier' AND round_no=32 ORDER BY sequence_no DESC LIMIT 1;
    IF FOUND THEN
        last_id:=(prior->>'node_id')::bigint; total:=(prior->>'total_nodes')::integer;
        leaves:=(prior->>'leaf_nodes')::integer; roots:=(prior->>'root_nodes')::integer;
    END IF;
    FOR n IN SELECT * FROM sand_ai_meeting_summary_node WHERE task_id=NEW.task_id AND id>last_id ORDER BY id LIMIT 32 LOOP
        PERFORM sand_ai_meeting_worker_assert_node_sealed(n.id);
        IF n.worker_messages_sha256 IS NULL THEN RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INVALID'; END IF;
        total:=total+1;
        IF n.node_kind='chunk' THEN leaves:=leaves+1; END IF;
        IF n.parent_node_code IS NULL THEN roots:=roots+1; END IF;
        IF n.node_kind='merge' THEN
            IF jsonb_typeof(n.input_snapshot->'children') IS DISTINCT FROM 'array'
                OR jsonb_array_length(n.input_snapshot->'children')<>2 THEN
                RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INVALID';
            END IF;
            SELECT count(*) INTO child_count FROM sand_ai_meeting_summary_node cn
                WHERE cn.task_id=n.task_id AND cn.node_code IN (n.input_snapshot #>> '{children,0}',n.input_snapshot #>> '{children,1}')
                    AND cn.parent_node_code=n.node_code AND cn.level_no<n.level_no;
            IF child_count<>2 THEN RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INVALID'; END IF;
        END IF;
        expected:=expected||jsonb_build_array(jsonb_build_object(
            'node_id',n.id,'task_step_id',n.task_step_id,'node_code',n.node_code,'input_sha256',n.input_sha256,
            'messages_sha256',n.worker_messages_sha256,'result_sha256',n.result_sha256,'last_invocation_id',n.last_invocation_id,
            'total_nodes',total,'leaf_nodes',leaves,'root_nodes',roots));
    END LOOP;
    IF jsonb_array_length(expected)=0 OR NEW.private_payload IS DISTINCT FROM expected THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INVALID';
    END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_verification_guard BEFORE INSERT ON sand_ai_meeting_worker_page
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_verification_guard();

-- Freeze topology when the first verification page begins. Legal task purge
-- still clears private fields through 109 and leaves proof identities intact.
CREATE FUNCTION sand_ai_meeting_verified_graph_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS(SELECT 1 FROM sand_ai_meeting_worker_page WHERE task_id=NEW.task_id
        AND page_kind='frontier' AND round_no=32) THEN
        IF TG_OP='INSERT' OR NEW.parent_node_code IS DISTINCT FROM OLD.parent_node_code THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_VERIFIED_GRAPH_IMMUTABLE';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_verified_graph_guard BEFORE INSERT OR UPDATE ON sand_ai_meeting_summary_node
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_verified_graph_guard();

CREATE FUNCTION sand_ai_meeting_worker_completion_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_worker_checkpoint%ROWTYPE; head jsonb; n sand_ai_meeting_summary_node%ROWTYPE;
    leaf_total integer; root_total integer; expected_summary jsonb;
BEGIN
    IF NEW.state<>'succeeded' OR OLD.state='succeeded' THEN RETURN NEW; END IF;
    SELECT * INTO c FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    PERFORM sand_ai_meeting_worker_task(NEW.id);
    leaf_total:=(c.anchor_snapshot #>> '{plan,leaf_count}')::integer;
    SELECT private_payload->(entry_count-1) INTO head FROM sand_ai_meeting_worker_page
        WHERE task_id=NEW.id AND page_kind='frontier' AND round_no=32 ORDER BY sequence_no DESC LIMIT 1;
    IF head IS NULL OR leaf_total NOT BETWEEN 1 AND 100000
        OR (head->>'total_nodes')::integer<>2*leaf_total-1 OR (head->>'leaf_nodes')::integer<>leaf_total
        OR (head->>'root_nodes')::integer<>1
        OR EXISTS(SELECT 1 FROM sand_ai_meeting_summary_node WHERE task_id=NEW.id AND id>(head->>'node_id')::bigint LIMIT 1) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INCOMPLETE';
    END IF;
    SELECT count(*) INTO root_total FROM(SELECT id FROM sand_ai_meeting_summary_node WHERE task_id=NEW.id
        AND parent_node_code IS NULL LIMIT 2) roots;
    IF root_total<>1 THEN RAISE EXCEPTION 'SAND_AI_MEETING_COVERAGE_INCOMPLETE'; END IF;
    SELECT * INTO n FROM sand_ai_meeting_summary_node WHERE task_id=NEW.id AND parent_node_code IS NULL LIMIT 1;
    expected_summary:=jsonb_set(n.result_snapshot->'summary','{schema,version}','"1.1.0"'::jsonb);
    expected_summary:=expected_summary||jsonb_build_object('coverage',jsonb_build_object(
        'total_segments',(c.anchor_snapshot #>> '{plan,total_segments}')::integer,
        'eligible_segments',(c.anchor_snapshot #>> '{plan,eligible_segments}')::integer,
        'covered_segments',(c.anchor_snapshot #>> '{plan,eligible_segments}')::integer,
        'uncovered_segment_ids','[]'::jsonb,'complete',true));
    IF NEW.result->'meeting_summary' IS DISTINCT FROM expected_summary
        OR NEW.result #>> '{output,content}' IS DISTINCT FROM n.result_snapshot->>'content'
        OR NEW.result #>> '{invocation,request_id}' IS DISTINCT FROM n.result_snapshot->>'request_id'
        OR NEW.result->>'audio_review_revision' IS DISTINCT FROM c.anchor_snapshot #>> '{source,review_revision}' THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_RESULT_CHANGED';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_worker_completion_guard BEFORE UPDATE OF state,result ON sand_ai_task_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_worker_completion_guard();
