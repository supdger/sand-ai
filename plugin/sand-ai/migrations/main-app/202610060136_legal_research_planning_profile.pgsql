-- SOURCE ONLY: apply to an existing authorized database through the normal upgrade path.
-- This adds an independent research planning profile; existing published modes keep their semantics.
ALTER TABLE sand_ai_legal_analysis_profile
    DROP CONSTRAINT sand_ai_legal_analysis_profile_code_ck;
ALTER TABLE sand_ai_legal_analysis_profile
    ADD CONSTRAINT sand_ai_legal_analysis_profile_code_ck
    CHECK (code IN ('legal_research', 'litigation_preparation', 'judgment_analysis', 'complaint_drafting', 'legal_research_planning'));
