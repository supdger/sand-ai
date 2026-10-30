-- SOURCE only; apply through the authorized normal plugin migration lifecycle.
ALTER TABLE sand_ai_feishu_delivery DROP CONSTRAINT sand_ai_feishu_delivery_state_check;
ALTER TABLE sand_ai_feishu_delivery ADD CONSTRAINT sand_ai_feishu_delivery_state_check
    CHECK (state IN ('pending','sending','sent','failed','uncertain'));

-- Legacy attempts have no durable distinction between token failure and an ambiguous text POST.
-- Preserve successful deliveries and untouched pending rows; never replay an unproven old send.
UPDATE sand_ai_feishu_delivery
SET state='uncertain',error_code='SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',
    lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0)
WHERE state='sending' OR (state IN ('pending','failed') AND attempt_count>0);
