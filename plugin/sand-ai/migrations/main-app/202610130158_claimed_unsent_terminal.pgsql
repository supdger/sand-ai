-- Claim time is not provider transmission. The original live send boundary alone authorizes this terminal edge.
-- No historical dispatch is updated; terminal immutability and provider-visible delete guards stay intact.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_state_transition_v026() RETURNS trigger AS $$
BEGIN
    IF OLD.state IS DISTINCT FROM NEW.state
       AND NOT ((OLD.state = 'prepared' AND NEW.state IN ('dispatching', 'not_dispatched'))
                OR (OLD.state = 'dispatching' AND NEW.state IN ('succeeded', 'failed', 'ambiguous', 'not_dispatched'))) THEN
        RAISE EXCEPTION 'invalid SandAI dispatch state transition: % -> %', OLD.state, NEW.state;
    END IF;
    IF OLD.state = 'dispatching' AND NEW.state = 'not_dispatched' THEN
        IF NEW.dispatch_complete_time IS NULL OR NEW.error_code IS NULL OR btrim(NEW.error_code) = ''
           OR EXISTS (SELECT 1 FROM sand_ai_provider_response_identity WHERE dispatch_id = OLD.id)
           OR EXISTS (SELECT 1 FROM sand_ai_usage_event WHERE invocation_id = OLD.invocation_id) THEN
            RAISE EXCEPTION 'claimed SandAI unsent terminal conflicts with provider evidence';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
COMMENT ON FUNCTION sand_ai_invocation_dispatch_state_transition_v026() IS 'sand-ai:202610130158_claimed_unsent_terminal';
