-- Only new keyed billing credential receipts are unique and immutable; legacy audits are unchanged.
CREATE UNIQUE INDEX sand_ai_billing_credential_operation_key
    ON sand_ai_audit_log (actor_ref, resource_id, (context->>'lock_sha256'))
    WHERE actor_type = 'admin'
      AND action = 'billing.credential.update'
      AND resource_type = 'provider'
      AND context->>'version' = 'billing_credential_save@1';

CREATE FUNCTION sand_ai_billing_credential_operation_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.actor_type = 'admin'
       AND OLD.action = 'billing.credential.update'
       AND OLD.resource_type = 'provider'
       AND OLD.context->>'version' = 'billing_credential_save@1' THEN
        RAISE EXCEPTION 'SandAI billing credential operation receipts are immutable';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.actor_type = 'admin'
       AND NEW.action = 'billing.credential.update'
       AND NEW.resource_type = 'provider'
       AND NEW.context->>'version' = 'billing_credential_save@1' THEN
        RAISE EXCEPTION 'SandAI billing credential operation receipts must be appended';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_billing_credential_operation_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_audit_log
    FOR EACH ROW EXECUTE FUNCTION sand_ai_billing_credential_operation_immutable();
