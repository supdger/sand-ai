-- New uploads only. Never infer original request keys from historical hashes/audits.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
ALTER TABLE sand_ai_file ADD COLUMN upload_request_key_sha256 char(64) NULL;
ALTER TABLE sand_ai_file ADD CONSTRAINT sand_ai_file_upload_request_check_v159 CHECK (
    upload_request_key_sha256 IS NULL OR
    (upload_request_key_sha256 ~ '^[a-f0-9]{64}$' AND owner_scope IS NOT NULL
     AND owner_scope ~ '^[a-f0-9]{64}$' AND owner_attribution IS NOT NULL
     AND jsonb_typeof(owner_attribution) = 'object')
);
CREATE UNIQUE INDEX sand_ai_file_upload_request_unique_v159
    ON sand_ai_file(environment_id,owner_scope,upload_request_key_sha256)
    WHERE upload_request_key_sha256 IS NOT NULL;
CREATE FUNCTION sand_ai_file_upload_request_guard_v159() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.upload_request_key_sha256 IS NOT NULL AND
           (NEW.state <> 'upload_pending' OR NEW.status <> 1 OR NEW.delete_time IS NOT NULL) THEN
            RAISE EXCEPTION 'New keyed upload must begin with a durable pending intent';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'DELETE' THEN
        IF OLD.upload_request_key_sha256 IS NOT NULL THEN
            RAISE EXCEPTION 'Retain keyed upload tombstone for original-request recovery';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.upload_request_key_sha256 IS DISTINCT FROM NEW.upload_request_key_sha256 THEN
        RAISE EXCEPTION 'Upload request association is immutable; historical backfill is forbidden';
    END IF;
    IF OLD.upload_request_key_sha256 IS NOT NULL THEN
        IF ROW(OLD.environment_id,OLD.owner_scope,OLD.owner_attribution,OLD.file_ref,
               OLD.original_name,OLD.extension,OLD.media_type,OLD.size_bytes,OLD.sha256,
               OLD.storage_driver,OLD.object_key) IS DISTINCT FROM
           ROW(NEW.environment_id,NEW.owner_scope,NEW.owner_attribution,NEW.file_ref,
               NEW.original_name,NEW.extension,NEW.media_type,NEW.size_bytes,NEW.sha256,
               NEW.storage_driver,NEW.object_key) THEN
            RAISE EXCEPTION 'Keyed upload identity and input facts are immutable';
        END IF;
        IF (OLD.state = 'upload_pending' AND NEW.state NOT IN ('upload_pending','uploaded','deleting'))
           OR (OLD.state <> 'upload_pending' AND NEW.state = 'upload_pending') THEN
            RAISE EXCEPTION 'Invalid keyed upload pending transition';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sand_ai_file_upload_request_guard_v159
    BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_file
    FOR EACH ROW EXECUTE FUNCTION sand_ai_file_upload_request_guard_v159();
