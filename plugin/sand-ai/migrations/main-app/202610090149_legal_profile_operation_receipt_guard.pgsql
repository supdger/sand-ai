-- Only new keyed legal profile receipts are unique and immutable; legacy audits are unchanged.
CREATE UNIQUE INDEX sand_ai_legal_profile_operation_key
    ON sand_ai_audit_log (actor_ref, environment_id, action, (context->>'lock_sha256'))
    WHERE actor_type = 'admin'
      AND action IN ('legal_analysis_profile.draft','legal_analysis_profile.publish','legal_analysis_profile.revoke')
      AND resource_type = 'legal_analysis_profile'
      AND context->>'version' = 'legal_profile_operation@1';

CREATE FUNCTION sand_ai_legal_profile_operation_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.actor_type = 'admin'
       AND OLD.action IN ('legal_analysis_profile.draft','legal_analysis_profile.publish','legal_analysis_profile.revoke')
       AND OLD.resource_type = 'legal_analysis_profile'
       AND OLD.context->>'version' = 'legal_profile_operation@1' THEN
        RAISE EXCEPTION 'SandAI legal profile operation receipts are immutable';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.actor_type = 'admin'
       AND NEW.action IN ('legal_analysis_profile.draft','legal_analysis_profile.publish','legal_analysis_profile.revoke')
       AND NEW.resource_type = 'legal_analysis_profile'
       AND NEW.context->>'version' = 'legal_profile_operation@1' THEN
        RAISE EXCEPTION 'SandAI legal profile operation receipts must be appended';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_legal_profile_operation_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_audit_log
    FOR EACH ROW EXECUTE FUNCTION sand_ai_legal_profile_operation_immutable();
