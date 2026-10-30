-- Qualify revision columns without changing the immutable OCR binding rules.
CREATE OR REPLACE FUNCTION sand_ai_ocr_frozen_binding_v150() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target jsonb; frozen jsonb; deployment sand_ai_model_deployment%ROWTYPE;
    provider sand_ai_provider%ROWTYPE; model sand_ai_model%ROWTYPE; revision sand_ai_config_revision%ROWTYPE;
    price sand_ai_price_revision%ROWTYPE; rate sand_ai_price_rate%ROWTYPE; route sand_ai_capability_route%ROWTYPE;
    profile sand_ai_capability_profile%ROWTYPE; amount numeric;
BEGIN
    target=NEW.execution_target; frozen=NEW.pricing;
    IF target IS NULL OR frozen IS NULL OR NEW.request_fingerprint IS NULL
      OR jsonb_typeof(target) IS DISTINCT FROM 'object' OR jsonb_typeof(frozen) IS DISTINCT FROM 'object'
      OR (SELECT count(*) FROM jsonb_object_keys(frozen))<>4
      OR NOT(frozen ?& ARRAY['price_revision_id','currency','amount','snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_FROZEN_REQUEST_INVALID';
    END IF;
    IF target='{}'::jsonb THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_LEGACY_PRICE_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    IF (SELECT count(*) FROM jsonb_object_keys(target))<>6
      OR NOT(target ?& ARRAY['provider_id','model_id','deployment_id','provider_snapshot','model_snapshot','deployment_snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_INVALID';
    END IF;
    SELECT * INTO STRICT deployment FROM sand_ai_model_deployment WHERE id=(target->>'deployment_id')::bigint;
    SELECT * INTO STRICT provider FROM sand_ai_provider WHERE id=deployment.provider_id;
    SELECT * INTO STRICT model FROM sand_ai_model WHERE id=deployment.model_id;
    SELECT configuration.* INTO STRICT revision FROM sand_ai_config_revision configuration WHERE configuration.resource_type='model_deployment'
      AND configuration.resource_id=deployment.id AND configuration.revision=deployment.revision
      AND configuration.state='published' AND configuration.status=1 AND configuration.delete_time IS NULL;
    SELECT * INTO STRICT route FROM sand_ai_capability_route WHERE id=(target->'deployment_snapshot'->'route'->>'route_id')::bigint;
    SELECT * INTO STRICT profile FROM sand_ai_capability_profile WHERE id=route.profile_id;
    IF provider.adapter IS DISTINCT FROM 'local_ocr_runtime' OR model.type IS DISTINCT FROM 'image_ocr'
      OR model.capabilities IS DISTINCT FROM '["image_ocr"]'::jsonb OR deployment.remote_model IS DISTINCT FROM 'local_paddle_ocr'
      OR deployment.config IS DISTINCT FROM '{}'::jsonb OR provider.encrypted_config IS DISTINCT FROM '{}'::jsonb
      OR provider.status<>1 OR model.status<>1 OR deployment.status<>1
      OR provider.delete_time IS NOT NULL OR model.delete_time IS NOT NULL OR deployment.delete_time IS NOT NULL
      OR revision.payload->'published_processing_policy'->>'processing_location' IS DISTINCT FROM 'local_runtime'
      OR target->>'provider_id' IS DISTINCT FROM provider.id::text OR target->>'model_id' IS DISTINCT FROM model.id::text
      OR target->'provider_snapshot' IS DISTINCT FROM jsonb_build_object('id',provider.id,'code',provider.code,'name',provider.name,'adapter','local_ocr_runtime')
      OR target->'model_snapshot' IS DISTINCT FROM jsonb_build_object('id',model.id,'code',model.code,'name',model.name,'type','image_ocr')
      OR target->'deployment_snapshot' IS DISTINCT FROM jsonb_build_object('id',deployment.id,'remote_model','local_paddle_ocr','revision',deployment.revision,
         'execution_snapshot_digest',revision.payload->>'published_execution_snapshot_digest',
         'route',jsonb_build_object('profile_id',profile.id,'profile_revision',profile.revision,'route_id',route.id))
      OR profile.environment_id IS DISTINCT FROM NEW.environment_id OR profile.state IS DISTINCT FROM 'published' OR profile.status<>1
      OR profile.delete_time IS NOT NULL OR route.delete_time IS NOT NULL OR route.status<>1
      OR route.deployment_id IS DISTINCT FROM deployment.id OR route.capability_code IS DISTINCT FROM 'image_ocr'
      OR route.driver_code IS DISTINCT FROM 'local_paddle_ocr'
      OR NOT EXISTS(SELECT 1 FROM sand_ai_config_revision configuration WHERE configuration.resource_type='capability_profile'
          AND configuration.resource_id=profile.id AND configuration.revision=profile.revision
          AND configuration.state='published' AND configuration.status=1 AND configuration.delete_time IS NULL) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_BINDING_INVALID';
    END IF;
    IF frozen->>'price_revision_id' IS DISTINCT FROM (SELECT id::text FROM sand_ai_price_revision
      WHERE provider_id=provider.id AND model_id=model.id AND capability_code='image_ocr'
        AND state='published' AND delete_time IS NULL AND effective_from<=NEW.create_time
        AND (effective_until IS NULL OR effective_until>NEW.create_time)
      ORDER BY effective_from DESC,id DESC LIMIT 1) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_CURRENT_PRICE_CHANGED';
    END IF;
    IF frozen->>'price_revision_id' IS NULL THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_UNPRICED_BINDING_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT price FROM sand_ai_price_revision WHERE id=(frozen->>'price_revision_id')::bigint;
    SELECT * INTO STRICT rate FROM sand_ai_price_rate WHERE price_revision_id=price.id AND meter_code='external_request';
    amount=round(rate.unit_price/rate.per_quantity,6);
    IF price.provider_id IS DISTINCT FROM provider.id OR price.model_id IS DISTINCT FROM model.id
      OR price.capability_code IS DISTINCT FROM 'image_ocr' OR price.state IS DISTINCT FROM 'published' OR price.delete_time IS NOT NULL
      OR price.effective_from>NEW.create_time OR (price.effective_until IS NOT NULL AND price.effective_until<=NEW.create_time)
      OR (SELECT count(*) FROM sand_ai_price_rate WHERE price_revision_id=price.id)<>1
      OR frozen->>'currency' IS DISTINCT FROM price.currency::text OR (frozen->>'amount')::numeric IS DISTINCT FROM amount
      OR frozen->'snapshot' IS DISTINCT FROM jsonb_build_object('snapshot_version','ocr_request_price@1','unit','external_request','quantity',1,
          'price_revision_id',price.id,'currency',price.currency,'effective_from',to_char(price.effective_from,'YYYY-MM-DD HH24:MI:SS'),
          'effective_until',CASE WHEN price.effective_until IS NULL THEN NULL ELSE to_char(price.effective_until,'YYYY-MM-DD HH24:MI:SS') END,
          'rate',jsonb_build_object('unit_price',rate.unit_price::text,'per_quantity',rate.per_quantity),'fee_scope','configured_service_fee') THEN
        RAISE EXCEPTION 'SAND_AI_OCR_PRICE_BINDING_INVALID';
    END IF;
    RETURN NEW;
END $$;
