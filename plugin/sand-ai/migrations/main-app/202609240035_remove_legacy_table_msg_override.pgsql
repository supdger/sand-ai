-- The host owns ThinkORM metadata functions. Migration 000 created this
-- text overload before SandAI became a single installable product.
DO $sand_ai_table_msg_cleanup$
DECLARE
    function_body text;
    function_language name;
BEGIN
    SELECT procedure.prosrc, language.lanname INTO function_body, function_language
    FROM pg_proc AS procedure
    JOIN pg_language AS language ON language.oid = procedure.prolang
    WHERE procedure.oid = to_regprocedure(format('%I.table_msg(text)', current_schema()));

    IF function_body IS NOT NULL THEN
        IF function_language <> 'sql' OR md5(function_body) <> '1cdc07c5ec9541d7739fc6923977cfb7' THEN
            RAISE EXCEPTION 'table_msg(text) differs from the historical SandAI definition; manual ownership review required';
        END IF;
        EXECUTE format('DROP FUNCTION %I.table_msg(text) RESTRICT', current_schema());
    END IF;
END
$sand_ai_table_msg_cleanup$;
