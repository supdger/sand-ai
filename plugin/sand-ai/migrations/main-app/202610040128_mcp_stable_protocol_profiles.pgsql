-- Preserve the installed November profile and admit one reviewed stable negotiated profile.
ALTER TABLE sand_ai_tool_mcp_snapshot
    DROP CONSTRAINT ck_sand_ai_tool_mcp_snapshot_protocol;
ALTER TABLE sand_ai_tool_mcp_snapshot
    ADD CONSTRAINT ck_sand_ai_tool_mcp_snapshot_protocol
    CHECK (protocol_version IN ('2025-11-25', '2025-06-18'));
