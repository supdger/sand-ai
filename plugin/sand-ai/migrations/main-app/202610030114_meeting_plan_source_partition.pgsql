-- Preserve migration 111 and its checksum; correct JSONB operator grouping.
CREATE OR REPLACE FUNCTION sand_ai_meeting_plan_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_meeting_plan_checkpoint%ROWTYPE; p sand_ai_meeting_plan_page%ROWTYPE; t sand_ai_task_run%ROWTYPE; s jsonb; item jsonb; pos integer; expected_parts jsonb; actual_parts jsonb; left_parts jsonb; right_parts jsonb; first_parts jsonb; middle integer; codepoints integer; offset_no integer;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_IMMUTABLE'; END IF;
    t:=sand_ai_meeting_worker_task(NEW.task_id);
    SELECT * INTO c FROM sand_ai_meeting_plan_checkpoint WHERE task_id=NEW.task_id FOR UPDATE;
    IF NOT FOUND OR c.phase<>'preparing' OR NEW.event_text IS NULL OR octet_length(NEW.event_text)>1048576 THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_EVENT_INVALID'; END IF;
    NEW.event_payload:=NEW.event_text::jsonb;s:=NEW.event_payload->'state';
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
                OR (p.private_payload#>>'{0,token_count_receipt,prompt_tokens}')::integer>(c.anchor#>>'{capacity,input_token_limit}')::integer
                THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_INVALID'; END IF;
            IF s->'pending' IS DISTINCT FROM ((c.state_json->'pending')-0) OR s->'batch' IS DISTINCT FROM c.state_json->'batch'
                OR s->'batch_chars' IS DISTINCT FROM c.state_json->'batch_chars' THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_LEAF_PARTITION_INVALID'; END IF;
            IF NOT EXISTS(SELECT 1 FROM sand_ai_meeting_token_count_request r JOIN sand_ai_meeting_token_count_outcome o ON o.request_event_id=r.id
                WHERE r.task_id=NEW.task_id AND r.input_sha256=p.private_payload#>>'{0,messages_sha256}'
                    AND r.capacity_sha256=c.anchor#>>'{capacity,snapshot_sha256}' AND o.state='succeeded'
                    AND o.capacity_receipt=p.private_payload#>'{0,token_count_receipt}') THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_TOKEN_RECEIPT_INVALID'; END IF;
        END IF;
    ELSE
        IF NEW.event_payload->>'phase'='complete' THEN
            IF (s->>'source_cursor')::integer<>(c.anchor->>'total_segments')::integer OR s->'pending'<>'[]'::jsonb OR s->'batch'<>'[]'::jsonb
                OR (s->>'eligible_segments')::integer<1 OR (s->>'leaf_count')::integer<1
                OR NEW.event_payload->>'manifest_sha256' IS NULL
                OR s IS DISTINCT FROM jsonb_set(c.state_json,'{tokenizer_call_count}',to_jsonb((SELECT request_count FROM sand_ai_meeting_plan_tokenizer_counter WHERE task_id=NEW.task_id))) THEN RAISE EXCEPTION 'SAND_AI_MEETING_PLAN_COMPLETION_INVALID'; END IF;
        ELSE
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
