-- Explicit local SDK planning variant; legacy remote guards and immutable migration checksums remain.
CREATE OR REPLACE FUNCTION sand_ai_meeting_plan_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE; p sand_ai_meeting_plan_page%ROWTYPE; t sand_ai_task_run%ROWTYPE; s jsonb; item jsonb; pos integer; expected_parts jsonb; actual_parts jsonb; left_parts jsonb; right_parts jsonb; first_parts jsonb; middle integer; codepoints integer; offset_no integer; local_mode boolean;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_IMMUTABLE'; END IF;
    t:=sand_ai_meeting_worker_task(NEW.task_id);
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=NEW.task_id FOR UPDATE;
    IF NOT FOUND OR c.phase<>'preparing' OR NEW.event_text IS NULL OR octet_length(NEW.event_text)>1048576 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_EVENT_INVALID'; END IF;
    NEW.event_payload:=NEW.event_text::jsonb;s:=NEW.event_payload->'state';
    local_mode:=coalesce(c.anchor#>>'{capacity,planning_driver}'='neuron_delimiter_splitter@1',false);
    IF local_mode AND (c.anchor#>'{capacity,counter}' IS DISTINCT FROM 'null'::jsonb
        OR c.anchor#>'{capacity,endpoint}' IS DISTINCT FROM 'null'::jsonb
        OR c.anchor#>'{capacity,max_tokenizer_requests}' IS DISTINCT FROM '0'::jsonb
        OR s->'tokenizer_call_count' IS DISTINCT FROM '0'::jsonb
        OR EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request WHERE task_id=NEW.task_id))
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_LOCAL_COUNTER_INVALID'; END IF;
    IF NOT NEW.event_payload ?& ARRAY['task_id','sequence_no','previous_sha256','anchor_sha256','previous_state_sha256','state','state_text','state_sha256','phase','page_id','payload_sha256','manifest_sha256','lease_fence']
        OR NOT s ?& ARRAY['source_cursor','eligible_segments','leaf_count','source_page_count','batch','batch_chars','pending','tokenizer_call_count','source_chain_sha256','leaf_chain_sha256']
        OR NEW.sequence_no<>c.event_sequence+1 OR NEW.previous_sha256 IS DISTINCT FROM c.last_event_sha256 OR NEW.anchor_sha256<>c.anchor_sha256
        OR NEW.lease_fence<>t.lease_fence OR NEW.event_payload->>'previous_state_sha256'<>c.state_sha256
        OR NEW.event_payload->>'anchor_sha256'<>c.anchor_sha256 OR (NEW.event_payload->>'sequence_no')::bigint<>NEW.sequence_no
        OR (NEW.event_payload->>'task_id')::bigint<>NEW.task_id OR (NEW.event_payload->>'lease_fence')::bigint<>NEW.lease_fence
        OR NEW.event_payload->>'previous_sha256' IS DISTINCT FROM c.last_event_sha256
        OR (NEW.event_payload->>'page_id')::bigint IS DISTINCT FROM NEW.page_id
        OR octet_length(NEW.event_payload->>'state_text')>262144
        OR (NEW.event_payload->>'state_text')::jsonb IS DISTINCT FROM s
        OR NEW.event_payload->>'state_sha256' IS DISTINCT FROM encode(sha256(convert_to(NEW.event_payload->>'state_text','UTF8')),'hex')
        OR jsonb_typeof(s)<>'object' OR jsonb_typeof(s->'pending')<>'array' OR jsonb_typeof(s->'batch')<>'array'
        OR jsonb_array_length(s->'pending')>128 OR jsonb_array_length(s->'batch')>512 OR octet_length(s::text)>524288
        OR (s->>'source_cursor')::integer<(c.state_json->>'source_cursor')::integer OR (s->>'source_cursor')::integer>(c.anchor->>'total_segments')::integer
        OR (s->>'leaf_count')::integer<(c.state_json->>'leaf_count')::integer OR (s->>'leaf_count')::integer>100000
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_EVENT_INVALID'; END IF;
    IF NEW.page_id IS NOT NULL THEN
        SELECT * INTO p FROM sand_ai_meeting_plan_page WHERE id=NEW.page_id AND task_id=NEW.task_id;
        IF NOT FOUND OR p.private_payload IS NULL OR NEW.event_payload->>'payload_sha256'<>p.payload_sha256
            OR NEW.event_payload->>'phase'<>'preparing' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_PAGE_EVENT_INVALID'; END IF;
        IF p.page_kind='source' THEN
            IF p.entry_count<>least(32,(c.anchor->>'total_segments')::integer-(c.state_json->>'source_cursor')::integer)
                OR (s->>'source_cursor')::integer<>(c.state_json->>'source_cursor')::integer+p.entry_count
                OR (s->>'source_page_count')::integer<>p.sequence_no
                OR (s->>'leaf_count')::integer<>(c.state_json->>'leaf_count')::integer
                OR s->'tokenizer_call_count' IS DISTINCT FROM c.state_json->'tokenizer_call_count'
                OR s->>'leaf_chain_sha256'<>c.state_json->>'leaf_chain_sha256'
                OR s->>'source_chain_sha256'<>encode(sha256(convert_to((c.state_json->>'source_chain_sha256')||':'||p.payload_sha256,'UTF8')),'hex')
                OR (s->>'eligible_segments')::integer<>(c.state_json->>'eligible_segments')::integer+(SELECT count(*) FROM jsonb_array_elements(p.private_payload) entry WHERE entry#>>'{proof,exclusion}' IS NULL) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_COVERAGE_INVALID'; END IF;
            IF c.state_json->'pending'<>'[]'::jsonb THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_WHILE_PENDING'; END IF;
            expected_parts:=c.state_json->'batch';
            pos:=(c.state_json->>'source_cursor')::integer;
            FOR item IN SELECT value FROM jsonb_array_elements(p.private_payload) LOOP
                IF (item->>'segment_index')::integer<>pos OR item#>>'{proof,segment_id}'<>'S'||(pos+1)::text
                    OR item#>>'{proof,text_sha256}'<>encode(sha256(convert_to(item#>>'{private_segment,text}','UTF8')),'hex')
                    OR (item#>>'{proof,unicode_characters}')::integer<>char_length(item#>>'{private_segment,text}')
                    OR (item#>>'{proof,unicode_characters}')::integer>10000
                    OR ((item#>>'{proof,exclusion}' IS NULL)=(btrim(item#>>'{private_segment,text}',E' \t\n\r\013')=''))
                    OR (item#>>'{proof,exclusion}' IS NOT NULL AND item#>>'{proof,exclusion}'<>'no_usable_text') THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_COVERAGE_INVALID'; END IF;
                IF item#>>'{proof,exclusion}' IS NULL THEN
                    codepoints:=(item#>>'{proof,unicode_characters}')::integer;offset_no:=0;
                    WHILE offset_no<codepoints LOOP
                        expected_parts:=expected_parts||jsonb_build_array(jsonb_build_object('segment_index',pos,'start_offset',offset_no,'end_offset',least(codepoints,offset_no+8000)));
                        offset_no:=offset_no+8000;
                    END LOOP;
                END IF;
                pos:=pos+1;
            END LOOP;
            actual_parts:='[]'::jsonb;
            FOR item IN SELECT value FROM jsonb_array_elements(s->'pending') LOOP actual_parts:=actual_parts||item; END LOOP;
            actual_parts:=actual_parts||(s->'batch');
            IF actual_parts IS DISTINCT FROM expected_parts THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SOURCE_PARTITION_INVALID'; END IF;
        ELSE
            IF (s->>'source_cursor')::integer<>(c.state_json->>'source_cursor')::integer
                OR (s->>'source_page_count')::integer<>(c.state_json->>'source_page_count')::integer
                OR (s->>'leaf_count')::integer<>p.sequence_no
                OR s->>'source_chain_sha256'<>c.state_json->>'source_chain_sha256'
                OR (s->>'eligible_segments')::integer<>(c.state_json->>'eligible_segments')::integer
                OR s->'tokenizer_call_count' IS DISTINCT FROM c.state_json->'tokenizer_call_count'
                OR s->>'leaf_chain_sha256'<>encode(sha256(convert_to((c.state_json->>'leaf_chain_sha256')||':'||p.payload_sha256,'UTF8')),'hex')
                OR p.private_payload->0->'parts' IS DISTINCT FROM c.state_json#>'{pending,0}'
                OR p.private_payload#>>'{0,node_code}'<> 'chunk_'||lpad(p.sequence_no::text,greatest(5,length(p.sequence_no::text)),'0')
                OR (p.private_payload#>>'{0,sequence_no}')::integer<>p.sequence_no
                OR (NOT local_mode AND (p.private_payload#>>'{0,token_count_receipt,prompt_tokens}')::integer>(c.anchor#>>'{capacity,input_token_limit}')::integer)
                THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_INVALID'; END IF;
            IF s->'pending' IS DISTINCT FROM ((c.state_json->'pending')-0) OR s->'batch' IS DISTINCT FROM c.state_json->'batch'
                OR s->'batch_chars' IS DISTINCT FROM c.state_json->'batch_chars' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_PARTITION_INVALID'; END IF;
            IF local_mode THEN
                IF (p.private_payload->0) ? 'token_count_receipt'
                    OR p.private_payload#>'{0,planning_evidence}' IS DISTINCT FROM (jsonb_build_object('driver','neuron_delimiter_splitter@1','sdk_package','neuron-core/neuron-ai','sdk_version','4.0.2','max_length',12000,'word_overlap',0,'min_length',0,'token_count',NULL,'token_count_state','not_measured','counter_request_count',0) || jsonb_build_object('messages_sha256',p.private_payload#>>'{0,messages_sha256}'))
                    OR (p.private_payload#>>'{0,messages_sha256}') !~ '^[a-f0-9]{64}$'
                    THEN RAISE EXCEPTION 'SAND_AI_MEETING_LOCAL_EVIDENCE_INVALID'; END IF;
            ELSE
            IF NOT EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request r JOIN sand_ai_meeting_token_count_outcome o ON o.request_event_id=r.id
                WHERE r.task_id=NEW.task_id AND r.input_sha256=p.private_payload#>>'{0,messages_sha256}'
                    AND r.capacity_sha256=c.anchor#>>'{capacity,snapshot_sha256}' AND o.state='succeeded'
                    AND o.capacity_receipt=p.private_payload#>'{0,token_count_receipt}') THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TOKEN_RECEIPT_INVALID'; END IF;
            END IF;
        END IF;
    ELSE
        IF NEW.event_payload->>'phase'='complete' THEN
            IF (s->>'source_cursor')::integer<>(c.anchor->>'total_segments')::integer OR s->'pending'<>'[]'::jsonb OR s->'batch'<>'[]'::jsonb
                OR (s->>'eligible_segments')::integer<1 OR (s->>'leaf_count')::integer<1
                OR NEW.event_payload->>'manifest_sha256' IS NULL
                OR s IS DISTINCT FROM jsonb_set(c.state_json,'{tokenizer_call_count}',to_jsonb((SELECT request_count FROM sand_ai_meeting_plan_tokenizer_counter WHERE task_id=NEW.task_id))) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COMPLETION_INVALID'; END IF;
        ELSE
            IF local_mode THEN RAISE EXCEPTION 'SAND_AI_MEETING_LOCAL_UNEXPECTED_FIT_SPLIT'; END IF;
            first_parts:=c.state_json#>'{pending,0}';
            IF NEW.event_payload->>'phase'<>'preparing' OR first_parts IS NULL OR jsonb_array_length(first_parts)<1 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SPLIT_INVALID'; END IF;
            IF jsonb_array_length(first_parts)>1 THEN
                middle:=jsonb_array_length(first_parts)/2;
                SELECT jsonb_agg(value ORDER BY ordinality) INTO left_parts FROM jsonb_array_elements(first_parts) WITH ORDINALITY WHERE ordinality<=middle;
                SELECT jsonb_agg(value ORDER BY ordinality) INTO right_parts FROM jsonb_array_elements(first_parts) WITH ORDINALITY WHERE ordinality>middle;
            ELSE
                item:=first_parts->0;codepoints:=(item->>'end_offset')::integer-(item->>'start_offset')::integer;
                IF codepoints<2 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_UNSPLITTABLE'; END IF;
                middle:=(item->>'start_offset')::integer+codepoints/2;
                left_parts:=jsonb_build_array(jsonb_set(item,'{end_offset}',to_jsonb(middle)));
                right_parts:=jsonb_build_array(jsonb_set(item,'{start_offset}',to_jsonb(middle)));
            END IF;
            IF s IS DISTINCT FROM jsonb_set(c.state_json,'{pending}',jsonb_build_array(left_parts,right_parts)||((c.state_json->'pending')-0)) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_SPLIT_PARTITION_INVALID'; END IF;
        END IF;
    END IF;
    NEW.event_sha256:=encode(sha256(convert_to(NEW.event_text,'UTF8')),'hex');NEW.event_text:=NULL;RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_meeting_plan_task_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE; expected_plan jsonb;
BEGIN
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=OLD.id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF ((NEW.input-ARRAY['_meeting_plan','iam_authorization'])#-ARRAY['_meeting_summary','audio_authorization'])
        IS DISTINCT FROM ((OLD.input-ARRAY['_meeting_plan','iam_authorization'])#-ARRAY['_meeting_summary','audio_authorization'])
        AND NEW.content_purged_time IS NULL
        THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TASK_ANCHOR_CHANGED'; END IF;
    IF NEW.input->'_meeting_plan' IS DISTINCT FROM OLD.input->'_meeting_plan' AND NEW.content_purged_time IS NULL THEN
        PERFORM sand_ai_meeting_worker_task(OLD.id);
        expected_plan:=jsonb_build_object(
            'version','meeting-summary-v3','normalization','none_unicode_codepoint@1',
            'transcript_sha256',c.anchor#>>'{source,sha256}','capacity_sha256',c.anchor#>>'{capacity,snapshot_sha256}',
            'total_segments',(c.anchor->>'total_segments')::integer,'eligible_segments',(c.state_json->>'eligible_segments')::integer,
            'leaf_count',(c.state_json->>'leaf_count')::integer,'source_page_count',(c.state_json->>'source_page_count')::integer,
            'source_chain_sha256',c.state_json->>'source_chain_sha256','leaf_chain_sha256',c.state_json->>'leaf_chain_sha256',
            'tokenizer_call_count',(c.state_json->>'tokenizer_call_count')::integer,'tokenizer_pricing_state','unpriced',
            'manifest_sha256',c.manifest_sha256,'prepared_plan_ref',jsonb_build_object(
                'version','meeting_prepared_plan@1','task_id',OLD.id,'anchor_sha256',c.anchor_sha256,'manifest_sha256',c.manifest_sha256));
        IF c.anchor#>>'{capacity,planning_driver}'='neuron_delimiter_splitter@1' THEN
            IF (c.state_json->>'tokenizer_call_count')::integer<>0
                OR EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request WHERE task_id=OLD.id)
                THEN RAISE EXCEPTION 'SAND_AI_MEETING_LOCAL_COUNTER_INVALID'; END IF;
            expected_plan:=jsonb_set(expected_plan,'{tokenizer_pricing_state}','"not_applicable"'::jsonb)
                || jsonb_build_object('planning',jsonb_build_object('driver','neuron_delimiter_splitter@1','sdk_package','neuron-core/neuron-ai','sdk_version','4.0.2','max_length',12000,'word_overlap',0,'min_length',0,'token_count',NULL,'token_count_state','not_measured','counter_request_count',0));
        END IF;
        IF c.phase<>'complete' OR NEW.input->'_meeting_plan' IS DISTINCT FROM expected_plan THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TASK_PROOF_INVALID';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_meeting_node_message_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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
            OR (CASE WHEN c.anchor_snapshot#>>'{capacity,planning_driver}'='neuron_delimiter_splitter@1'
                THEN (leaf ? 'token_count_receipt' OR leaf#>'{planning_evidence}' IS DISTINCT FROM (jsonb_build_object('driver','neuron_delimiter_splitter@1','sdk_package','neuron-core/neuron-ai','sdk_version','4.0.2','max_length',12000,'word_overlap',0,'min_length',0,'token_count',NULL,'token_count_state','not_measured','counter_request_count',0) || jsonb_build_object('messages_sha256',NEW.worker_messages_sha256)))
                ELSE leaf #>> '{token_count_receipt,input_sha256}' IS DISTINCT FROM NEW.worker_messages_sha256 END)
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
