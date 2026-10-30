-- Private, bounded native provider text deltas. No identity or fee ledger.
CREATE TABLE sand_ai_agent_stream_event (
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    event_no integer NOT NULL CHECK (event_no BETWEEN 1 AND 4096),
    round_no integer NOT NULL CHECK (round_no BETWEEN 1 AND 3),
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT,
    dispatch_id bigint NOT NULL REFERENCES sand_ai_invocation_dispatch(id) ON DELETE RESTRICT,
    ciphertext_envelope jsonb NOT NULL CHECK (jsonb_typeof(ciphertext_envelope)='object'),
    plaintext_bytes integer NOT NULL CHECK (plaintext_bytes BETWEEN 1 AND 262144),
    text_bytes integer NOT NULL CHECK (text_bytes BETWEEN 1 AND 8192),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CONSTRAINT sand_ai_agent_stream_event_pk PRIMARY KEY (task_id,event_no)
);
CREATE INDEX sand_ai_agent_stream_event_invocation_idx ON sand_ai_agent_stream_event(invocation_id,event_no);
