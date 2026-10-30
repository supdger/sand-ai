-- Only new SandAI catalogue receipts are unique and immutable; legacy audits are unchanged.
CREATE UNIQUE INDEX sand_ai_catalog_operation_receipt_key
    ON sand_ai_audit_log (actor_ref, action, (context->>'lock_sha256'))
    WHERE actor_type = 'admin'
      AND action IN ('catalog.provider.save','catalog.provider.update','catalog.model.save','catalog.model.update')
      AND context->>'version' = 'catalog_operation@1';

CREATE FUNCTION sand_ai_catalog_operation_receipt_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.actor_type = 'admin'
       AND OLD.action IN ('catalog.provider.save','catalog.provider.update','catalog.model.save','catalog.model.update')
       AND OLD.context->>'version' = 'catalog_operation@1' THEN
        RAISE EXCEPTION 'SandAI catalogue operation receipts are immutable';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.actor_type = 'admin'
       AND NEW.action IN ('catalog.provider.save','catalog.provider.update','catalog.model.save','catalog.model.update')
       AND NEW.context->>'version' = 'catalog_operation@1' THEN
        RAISE EXCEPTION 'SandAI catalogue operation receipts must be appended';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_catalog_operation_receipt_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_audit_log
    FOR EACH ROW EXECUTE FUNCTION sand_ai_catalog_operation_receipt_immutable();
