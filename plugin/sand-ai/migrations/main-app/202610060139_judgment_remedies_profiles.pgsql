-- SOURCE ONLY: existing authorized database upgrade; does not publish an environment profile.
-- Independent planning, complete analysis, and private-template drafting retain the existing Neuron workflow.
ALTER TABLE sand_ai_legal_analysis_profile
    DROP CONSTRAINT sand_ai_legal_analysis_profile_code_ck;
ALTER TABLE sand_ai_legal_analysis_profile
    ADD CONSTRAINT sand_ai_legal_analysis_profile_code_ck
    CHECK (code IN ('legal_research', 'litigation_preparation', 'judgment_analysis', 'complaint_drafting', 'legal_research_planning', 'judgment_remedy_planning', 'judgment_remedy_analysis', 'judgment_remedy_drafting'));
