-- Read-only aggregate for a protected backup manifest; no private row content is returned.
-- Execute only on the existing public 0.1.0 database after stopping consumers.
BEGIN READ ONLY;
SET LOCAL search_path TO public, pg_catalog;
SELECT jsonb_agg(item ORDER BY item->>'table') AS table_snapshot FROM (
SELECT jsonb_build_object('table','sand_ai_provider','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_provider_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_provider_id_seq)) AS item FROM public.sand_ai_provider t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_model','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_model_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_model_id_seq)) AS item FROM public.sand_ai_model t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_model_deployment','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_model_deployment_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_model_deployment_id_seq)) AS item FROM public.sand_ai_model_deployment t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_config_revision','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_config_revision_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_config_revision_id_seq)) AS item FROM public.sand_ai_config_revision t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_invocation','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_invocation_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_invocation_id_seq)) AS item FROM public.sand_ai_invocation t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_usage','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_usage_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_usage_id_seq)) AS item FROM public.sand_ai_usage t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_audit_log','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_audit_log_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_audit_log_id_seq)) AS item FROM public.sand_ai_audit_log t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_file','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_file_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_file_id_seq)) AS item FROM public.sand_ai_file t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_file_parse','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_file_parse_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_file_parse_id_seq)) AS item FROM public.sand_ai_file_parse t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_source_block','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_source_block_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_source_block_id_seq)) AS item FROM public.sand_ai_source_block t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_capability_profile','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_capability_profile_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_capability_profile_id_seq)) AS item FROM public.sand_ai_capability_profile t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_capability_route','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_capability_route_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_capability_route_id_seq)) AS item FROM public.sand_ai_capability_route t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_task_run','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_task_run_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_task_run_id_seq)) AS item FROM public.sand_ai_task_run t
UNION ALL
SELECT jsonb_build_object('table','sand_ai_task_step','row_count',count(*)::text,'rows_sha256',encode(sha256(convert_to(coalesce(string_agg(encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex'),'' ORDER BY id),''),'UTF8')),'hex'),'sequence_last_value',(SELECT last_value::text FROM public.sand_ai_task_step_id_seq),'sequence_is_called',(SELECT is_called FROM public.sand_ai_task_step_id_seq)) AS item FROM public.sand_ai_task_step t
) snapshot;
COMMIT;
