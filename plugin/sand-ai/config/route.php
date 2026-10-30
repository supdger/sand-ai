<?php

use plugin\sandadmin\app\middleware\CheckAuth;
use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\app\middleware\SystemLog;
use plugin\SandAi\app\admin\controller\AuditController;
use plugin\SandAi\app\admin\controller\IndexController;
use plugin\SandAi\app\admin\controller\InvocationController;
use plugin\SandAi\app\admin\controller\ModelController;
use plugin\SandAi\app\admin\controller\ProviderController;
use plugin\SandAi\app\admin\controller\PricingRevisionController;
use plugin\SandAi\app\admin\controller\UsageController;
use plugin\SandAi\app\admin\controller\BillingImportController;
use plugin\SandAi\app\admin\controller\BillingSyncController;
use plugin\SandAi\app\admin\controller\BillingCredentialController;
use plugin\SandAi\app\admin\controller\BillingReviewController;
use plugin\SandAi\app\admin\controller\SetupController;
use plugin\SandAi\app\admin\controller\MaterialProfileController;
use plugin\SandAi\app\admin\controller\LegalProfileController;
use plugin\SandAi\app\admin\controller\UsageSummaryController;
use plugin\SandAi\app\admin\controller\PlatformValidationUsageController;
use plugin\SandAi\app\admin\controller\BudgetPolicyController;
use plugin\SandAi\app\admin\middleware\OnboardingSensitiveRequestMiddleware;
use plugin\SandAi\app\admin\middleware\ToolConnectionSensitiveRequestMiddleware;
use plugin\SandAi\app\admin\middleware\FeishuCredentialSensitiveRequestMiddleware;
use plugin\SandAi\app\admin\middleware\WecomCredentialSensitiveRequestMiddleware;
use plugin\SandAi\app\admin\controller\KnowledgeBaseController as AdminKnowledgeBaseController;
use plugin\SandAi\app\admin\controller\AgentDefinitionController as AdminAgentDefinitionController;
use plugin\SandAi\app\admin\controller\ChannelBindingController as AdminChannelBindingController;
use plugin\SandAi\app\admin\controller\SkillController as AdminSkillController;
use plugin\SandAi\app\admin\controller\ToolController as AdminToolController;
use Webman\Route;

// Foundation compatibility is checked before any SandAI business route is registered.
// Keep explicit error endpoints available; throwing here would break the shared host.
$foundation = \plugin\SandAi\app\support\HostFoundationCompatibility::status();
if (!$foundation['ready']) {
    $compatibility = \plugin\SandAi\app\admin\controller\HostCompatibilityController::class;
    Route::any('/api/sand-ai', [$compatibility, 'unavailable']);
    Route::any('/api/sand-ai/{path:.*}', [$compatibility, 'unavailable']);
    Route::any('/app/sand-ai', [$compatibility, 'unavailable']);
    Route::any('/app/sand-ai/{path:.*}', [$compatibility, 'unavailable']);
    return;
}

// The plugin installs the routes; the outward controllers and behavior live in app/Api.
Route::any('/api/sand-ai/mcp', [\app\Api\Controller\McpController::class, 'handle']);
Route::get('/api/sand-ai/health', [\app\Api\Controller\HealthController::class, 'index']);
Route::get('/api/sand-ai/v1/health', [\app\Api\Controller\HealthController::class, 'index']);
Route::get('/api/sand-ai/v1/models', [\app\Api\Controller\ModelsController::class, 'index']);
Route::get('/api/sand-ai/v1/chat/completions/by-request-key', [\app\Api\Controller\InvocationController::class, 'readByRequestKey']);
Route::post('/api/sand-ai/v1/chat/completions', [\app\Api\Controller\ChatController::class, 'complete']);
Route::post('/api/sand-ai/v1/ai-tasks', [\app\Api\Controller\AiTaskController::class, 'submit']);
Route::post('/api/sand-ai/v1/audio-transcriptions', [\app\Api\Controller\AudioTaskController::class, 'submit']);
Route::get('/api/sand-ai/v1/audio-transcriptions/by-request-key', [\app\Api\Controller\AudioTaskController::class, 'readByRequestKey']);
Route::post('/api/sand-ai/v1/speech-syntheses', [\app\Api\Controller\SpeechController::class, 'submit']);
Route::get('/api/sand-ai/v1/speech-syntheses/by-request-key', [\app\Api\Controller\SpeechController::class, 'readByRequestKey']);
Route::get('/api/sand-ai/v1/speech-syntheses/{runId}', [\app\Api\Controller\SpeechController::class, 'read']);
Route::get('/api/sand-ai/v1/speech-syntheses/{runId}/audio', [\app\Api\Controller\SpeechController::class, 'audio']);
Route::get('/api/sand-ai/v1/audio-transcriptions/{taskId}/summary/by-request-key', [\app\Api\Controller\MeetingSummaryController::class, 'readByRequestKey']);
Route::post('/api/sand-ai/v1/audio-transcriptions/{taskId}/summary', [\app\Api\Controller\MeetingSummaryController::class, 'submit']);
Route::get('/api/sand-ai/v1/audio-transcriptions/{taskId}/reviews', [\app\Api\Controller\AudioSegmentReviewController::class, 'read']);
Route::get('/api/sand-ai/v1/audio-transcriptions/{taskId}/reviews/by-request-key', [\app\Api\Controller\AudioSegmentReviewController::class, 'readByRequestKey']);
Route::get('/api/sand-ai/v1/audio-transcriptions/{taskId}/source', [\app\Api\Controller\AudioSegmentReviewController::class, 'source']);
Route::post('/api/sand-ai/v1/audio-transcriptions/{taskId}/reviews', [\app\Api\Controller\AudioSegmentReviewController::class, 'save']);
Route::post('/api/sand-ai/v1/channels/{code}/messages', [\app\Api\Controller\ChannelController::class, 'submit']);
Route::get('/api/sand-ai/v1/channels/{code}/messages/by-request-key', [\app\Api\Controller\ChannelController::class, 'readByRequestKey']);
Route::post('/api/sand-ai/v1/channels/{code}/feishu/events', [\app\Api\Controller\FeishuWebhookController::class, 'receive']);
Route::get('/api/sand-ai/v1/channels/{code}/wecom/events', [\app\Api\Controller\WecomWebhookController::class, 'verify']);
Route::post('/api/sand-ai/v1/channels/{code}/wecom/events', [\app\Api\Controller\WecomWebhookController::class, 'receive']);
Route::get('/api/sand-ai/v1/channels/{code}/conversations/{conversationId}/messages', [\app\Api\Controller\ChannelController::class, 'conversation']);
Route::post('/api/sand-ai/v1/channels/{code}/conversations/{conversationId}/close', [\app\Api\Controller\ChannelController::class, 'closeConversation']);
Route::get('/api/sand-ai/v1/channels/{code}/conversations/{conversationId}/close/by-request-key', [\app\Api\Controller\ChannelController::class, 'readCloseByRequestKey']);
Route::get('/api/sand-ai/v1/channels/{code}/messages/{taskId}', [\app\Api\Controller\ChannelController::class, 'read']);
Route::post('/api/sand-ai/v1/channels/{code}/messages/{taskId}/receipt-recovery', [\app\Api\Controller\ChannelController::class, 'recoverReceipt']);
Route::get('/api/sand-ai/v1/channels/{code}/messages/{taskId}/receipt-recovery/by-request-key', [\app\Api\Controller\ChannelController::class, 'readReceiptRecovery']);
Route::get('/api/sand-ai/v1/channels/{code}/messages/{taskId}/events', [\app\Api\Controller\ChannelController::class, 'stream']);
Route::get('/api/sand-ai/v1/channels/{code}/messages/{taskId}/sources/{sourceBlockId}', [\app\Api\Controller\ChannelController::class, 'source']);
Route::get('/api/sand-ai/v1/agents/runs/by-request-key', [\app\Api\Controller\AgentController::class, 'readAdHocByRequestKey']);
Route::post('/api/sand-ai/v1/agents/{code}/runs', [\app\Api\Controller\AgentController::class, 'submitPublished']);
Route::get('/api/sand-ai/v1/agents/{code}/runs/by-request-key', [\app\Api\Controller\AgentController::class, 'readPublishedByRequestKey']);
Route::post('/api/sand-ai/v1/agents/runs', [\app\Api\Controller\AgentController::class, 'submit']);
Route::post('/api/sand-ai/v1/agents/tasks/{taskId}/continue', [\app\Api\Controller\AgentController::class, 'continueTask']);
Route::get('/api/sand-ai/v1/agents/tasks/{taskId}/sources/{sourceBlockId}', [\app\Api\Controller\AgentController::class, 'source']);
Route::get('/api/sand-ai/v1/agents/tasks/{taskId}/events', [\app\Api\Controller\AgentStreamController::class, 'adHocEvents']);
Route::get('/api/sand-ai/v1/agents/{code}/tasks/{taskId}/events', [\app\Api\Controller\AgentStreamController::class, 'events']);
Route::get('/api/sand-ai/v1/agents/{code}/conversations/{conversationId}/tasks', [\app\Api\Controller\AgentStreamController::class, 'conversation']);
Route::post('/api/sand-ai/v1/retrieval/search', [\app\Api\Controller\RetrievalController::class, 'search']);
Route::get('/api/sand-ai/v1/knowledge-bases/{code}/rerank-models', [\app\Api\Controller\KnowledgeBaseController::class, 'rerankModels']);
Route::post('/api/sand-ai/v1/knowledge-bases/{code}/query', [\app\Api\Controller\KnowledgeBaseController::class, 'query']);
Route::post('/api/sand-ai/v1/knowledge-bases/{code}/query/by-request-key', [\app\Api\Controller\KnowledgeBaseController::class, 'queryByRequestKey']);
Route::get('/api/sand-ai/v1/knowledge-bases/{code}/versions/{version_no}/sources/{source_block_id}', [\app\Api\Controller\KnowledgeBaseController::class, 'source']);
Route::post('/api/sand-ai/v1/knowledge-bases/{code}/answer', [\app\Api\Controller\KnowledgeAnswerController::class, 'answer']);
Route::post('/api/sand-ai/v1/knowledge-bases/{code}/conversations', [\app\Api\Controller\KnowledgeAnswerController::class, 'createConversation']);
Route::get('/api/sand-ai/v1/knowledge-bases/{code}/conversations', [\app\Api\Controller\KnowledgeAnswerController::class, 'listConversations']);
Route::get('/api/sand-ai/v1/knowledge-bases/{code}/conversations/by-request-key', [\app\Api\Controller\KnowledgeAnswerController::class, 'readCreatedConversation']);
Route::get('/api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}', [\app\Api\Controller\KnowledgeAnswerController::class, 'readConversation']);
Route::get('/api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/turns/by-request-key', [\app\Api\Controller\KnowledgeAnswerController::class, 'readConversationTurnByRequest']);
Route::post('/api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/close', [\app\Api\Controller\KnowledgeAnswerController::class, 'closeConversation']);
Route::post('/api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/answer', [\app\Api\Controller\KnowledgeAnswerController::class, 'answerConversation']);
Route::post('/api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/turns/{sequenceNo}/resume', [\app\Api\Controller\KnowledgeAnswerController::class, 'resumeConversation']);
Route::post('/api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/turns/{sequenceNo}/cancel', [\app\Api\Controller\KnowledgeAnswerController::class, 'cancelConversationTurn']);
Route::get('/api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/turns/{sequenceNo}/events', [\app\Api\Controller\KnowledgeAnswerController::class, 'streamConversationTurn']);
Route::get('/api/sand-ai/v1/skills/source-suggestions/by-request-key', [\app\Api\Controller\SkillManagementController::class, 'readSuggestion']);
Route::post('/api/sand-ai/v1/skills/source-suggestions', [\app\Api\Controller\SkillManagementController::class, 'suggest']);
Route::post('/api/sand-ai/v1/skills/source-suggestions/{suggestionId}/adopt', [\app\Api\Controller\SkillManagementController::class, 'adoptSuggestion']);
Route::get('/api/sand-ai/v1/skills/source-suggestions/{suggestionId}/adoption/by-request-key', [\app\Api\Controller\SkillManagementController::class, 'readAdoption']);
Route::get('/api/sand-ai/v1/skills', [\app\Api\Controller\SkillManagementController::class, 'index']);
Route::get('/api/sand-ai/v1/skills/sources', [\app\Api\Controller\SkillManagementController::class, 'sources']);
Route::get('/api/sand-ai/v1/skills/drafts/by-request-key', [\app\Api\Controller\SkillManagementController::class, 'readDraftReceipt']);
Route::post('/api/sand-ai/v1/skills/drafts', [\app\Api\Controller\SkillManagementController::class, 'draft']);
Route::get('/api/sand-ai/v1/skills/{code}', [\app\Api\Controller\SkillManagementController::class, 'read']);
Route::get('/api/sand-ai/v1/skills/{code}/versions/{version}/cases/by-request-key', [\app\Api\Controller\SkillManagementController::class, 'readCreatedCase']);
Route::get('/api/sand-ai/v1/skills/{code}/versions/{version}/cases', [\app\Api\Controller\SkillManagementController::class, 'cases']);
Route::post('/api/sand-ai/v1/skills/{code}/versions/{version}/cases', [\app\Api\Controller\SkillManagementController::class, 'createCase']);
Route::post('/api/sand-ai/v1/skills/{code}/versions/{version}/tests', [\app\Api\Controller\SkillManagementController::class, 'test']);
Route::get('/api/sand-ai/v1/skills/{code}/versions/{version}/tests/by-request-key', [\app\Api\Controller\SkillManagementController::class, 'readTest']);
Route::get('/api/sand-ai/v1/skills/{code}/versions/{version}/tests/by-request-key/cost', [\app\Api\Controller\SkillRunCostController::class, 'test']);
Route::post('/api/sand-ai/v1/skills/{code}/versions/{version}/cases/{caseId}/review', [\app\Api\Controller\SkillManagementController::class, 'review']);
Route::post('/api/sand-ai/v1/skills/{code}/versions/{version}/cases/{caseId}/retire', [\app\Api\Controller\SkillManagementController::class, 'retire']);
Route::get('/api/sand-ai/v1/skills/{code}/versions/{version}/cases/{caseId}/operations/{operation}/by-request-key', [\app\Api\Controller\SkillManagementController::class, 'readCaseOperation']);
Route::post('/api/sand-ai/v1/skills/{code}/versions/{version}/publish', [\app\Api\Controller\SkillManagementController::class, 'publish']);
Route::post('/api/sand-ai/v1/skills/{code}/versions/{version}/rollback', [\app\Api\Controller\SkillManagementController::class, 'rollback']);
Route::post('/api/sand-ai/v1/skills/{code}/revoke', [\app\Api\Controller\SkillManagementController::class, 'revoke']);
Route::get('/api/sand-ai/v1/skills/{code}/versions/{version}/operations/{operation}/by-request-key', [\app\Api\Controller\SkillManagementController::class, 'readVersionOperation']);
Route::get('/api/sand-ai/v1/skills/{code}/operations/revoke/by-request-key', [\app\Api\Controller\SkillManagementController::class, 'readRevokeOperation']);
Route::post('/api/sand-ai/v1/skills/{code}/versions/{version}/runs', [\app\Api\Controller\SkillController::class, 'run']);
Route::get('/api/sand-ai/v1/skills/{code}/versions/{version}/runs/by-request-key', [\app\Api\Controller\SkillController::class, 'readRun']);
Route::get('/api/sand-ai/v1/skills/{code}/versions/{version}/runs/by-request-key/cost', [\app\Api\Controller\SkillRunCostController::class, 'run']);
Route::get('/api/sand-ai/v1/tools/{code}/versions/{version}', [\app\Api\Controller\ToolController::class, 'readVersion']);
Route::post('/api/sand-ai/v1/tools/{code}/versions/{version}/runs', [\app\Api\Controller\ToolController::class, 'run']);
Route::get('/api/sand-ai/v1/tool-runs/by-key', [\app\Api\Controller\ToolController::class, 'readByKey']);
Route::get('/api/sand-ai/v1/tool-runs/by-key/private-fulltext', [\app\Api\Controller\ToolController::class, 'readPrivateFulltextByKey']);
Route::post('/api/sand-ai/v1/tool-runs/by-key/private-fulltext/file', [\app\Api\Controller\ToolController::class, 'bindPrivateFulltextFile']);
Route::get('/api/sand-ai/v1/tool-runs/{runId}', [\app\Api\Controller\ToolController::class, 'read']);
Route::get('/api/sand-ai/v1/invocations/{requestId}', [\app\Api\Controller\InvocationController::class, 'read']);
Route::get('/api/sand-ai/v1/invocations/{requestId}/cost', [\app\Api\Controller\InvocationCostController::class, 'read']);
Route::post('/api/sand-ai/v1/files', [\app\Api\Controller\FileController::class, 'upload']);
Route::get('/api/sand-ai/v1/files/upload-context', [\app\Api\Controller\FileController::class, 'uploadContext']);
Route::get('/api/sand-ai/v1/files/by-request-key', [\app\Api\Controller\FileController::class, 'uploadByRequestKey']);
Route::get('/api/sand-ai/v1/files/{fileId}/source-blocks', [\app\Api\Controller\FileController::class, 'sourceBlocks']);
Route::get('/api/sand-ai/v1/files/{fileId}', [\app\Api\Controller\FileController::class, 'read']);
Route::post('/api/sand-ai/v1/files/{fileId}/parse', [\app\Api\Controller\FileController::class, 'parse']);
Route::get('/api/sand-ai/v1/files/{fileId}/parse/by-request-key', [\app\Api\Controller\FileController::class, 'parseByRequestKey']);
Route::delete('/api/sand-ai/v1/files/{fileId}', [\app\Api\Controller\FileController::class, 'destroy']);
Route::get('/api/sand-ai/v1/tasks/{taskId}', [\app\Api\Controller\TaskController::class, 'read']);
Route::post('/api/sand-ai/v1/tasks/{taskId}/cancel', [\app\Api\Controller\TaskController::class, 'cancel']);
Route::post('/api/sand-ai/v1/tasks/{taskId}/retry', [\app\Api\Controller\TaskController::class, 'retry']);
Route::post('/api/sand-ai/v1/structured-workflows/runs', [\app\Api\Controller\StructuredWorkflowController::class, 'submit']);
Route::get('/api/sand-ai/v1/structured-workflows/runs/by-request-key', [\app\Api\Controller\StructuredWorkflowController::class, 'runByRequestKey']);
Route::post('/api/sand-ai/v1/material-analysis/samples', [\app\Api\Controller\StructuredWorkflowController::class, 'sample']);
Route::get('/api/sand-ai/v1/material-analysis/samples/by-request-key', [\app\Api\Controller\StructuredWorkflowController::class, 'sampleByRequestKey']);
Route::get('/api/sand-ai/v1/structured-workflows/runs/{runId}', [\app\Api\Controller\StructuredWorkflowController::class, 'read']);
Route::get('/api/sand-ai/v1/structured-workflows/runs/{runId}/files/{fileId}/result', [\app\Api\Controller\StructuredWorkflowController::class, 'fileResult']);
Route::get('/api/sand-ai/v1/structured-workflows/runs/{runId}/sources/{sourceBlockId}', [\app\Api\Controller\StructuredWorkflowController::class, 'source']);
Route::post('/api/sand-ai/v1/legal-sources/snapshots', [\app\Api\Controller\LegalSourceController::class, 'snapshot']);

Route::group('/app/sand-ai/admin', function (): void {
    Route::get('/index', [IndexController::class, 'index']);
    Route::get('/provider/index', [ProviderController::class, 'index']);
    Route::get('/provider/read', [ProviderController::class, 'read']);
    Route::post('/provider/destroy', [ProviderController::class, 'destroy']);
    Route::get('/model/index', [ModelController::class, 'index']);
    Route::get('/model/read', [ModelController::class, 'read']);
    Route::post('/model/save', [ModelController::class, 'save']);
    Route::post('/model/update', [ModelController::class, 'update']);
    Route::post('/model/destroy', [ModelController::class, 'destroy']);
    Route::get('/invocation/index', [InvocationController::class, 'index']);
    Route::get('/usage/index', [UsageController::class, 'index']);
    Route::get('/usage/platform-validation/index', [PlatformValidationUsageController::class, 'index']);
    Route::get('/usage/platform-validation/summary', [PlatformValidationUsageController::class, 'summary']);
    Route::get('/usage/cost', [UsageController::class, 'cost']);
    Route::get('/usage/comparison', [UsageController::class, 'comparison']);
    Route::post('/usage/adjustment/refund', [\plugin\SandAi\app\admin\controller\UsageAdjustmentController::class, 'refund']);
    Route::get('/usage/adjustment/index', [\plugin\SandAi\app\admin\controller\UsageAdjustmentController::class, 'index']);
    Route::get('/usage/adjustment/read', [\plugin\SandAi\app\admin\controller\UsageAdjustmentController::class, 'read']);
    Route::get('/audit/index', [AuditController::class, 'index']);
})->middleware([
    CheckLogin::class,
    CheckAuth::class,
    SystemLog::class,
]);

// Original catalogue receipts are safe reads and do not enter the secret POST guard or SystemLog.
Route::get('/app/sand-ai/admin/provider/read-operation-by-request-key', [ProviderController::class, 'readOperationByRequestKey'])->middleware([CheckLogin::class, CheckAuth::class]);
Route::get('/app/sand-ai/admin/model/read-operation-by-request-key', [ModelController::class, 'readOperationByRequestKey'])->middleware([CheckLogin::class, CheckAuth::class]);

// Provider keys are removed from the request before authentication or logging.
Route::group('/app/sand-ai/admin/provider', function (): void {
    Route::post('/save', [ProviderController::class, 'save']);
    Route::post('/update', [ProviderController::class, 'update']);
})->middleware([OnboardingSensitiveRequestMiddleware::class, CheckLogin::class, CheckAuth::class]);

Route::group('/app/sand-ai/admin/pricing/revision', function (): void {
    Route::get('/index', [PricingRevisionController::class, 'index']);
    Route::get('/read', [PricingRevisionController::class, 'read']);
    Route::get('/read-operation-by-request-key', [PricingRevisionController::class, 'readOperationByRequestKey']);
    Route::post('/save-draft', [PricingRevisionController::class, 'saveDraft']);
    Route::post('/publish', [PricingRevisionController::class, 'publish']);
})->middleware([CheckLogin::class, CheckAuth::class]);

// “sand-ai” 是合法插件标识，但不是合法 PHP 命名空间。
// 管理路由必须显式绑定到 plugin\SandAi，禁止依赖 Webman 默认路由推导。
// These routes remain explicit because default routes are disabled. The secret
// guard is outermost and SystemLog is deliberately absent: onboarding bodies
// can contain a temporary API Key and must be redacted before auth/errors.
Route::group('/app/sand-ai/admin/setup/onboarding', function (): void {
    Route::get('/solution-guide/index', [SetupController::class, 'solutionGuideIndex']);
    Route::get('/status', [SetupController::class, 'onboardingStatus']);
    Route::get('/provider-guides', [SetupController::class, 'onboardingProviderGuides']);
    Route::post('/discover-models', [SetupController::class, 'onboardingDiscoverModels']);
    Route::post('/verify-model', [SetupController::class, 'onboardingVerifyModel']);
    Route::post('/publish-deployment', [SetupController::class, 'onboardingPublishDeployment']);
    Route::post('/bind-default-chat', [SetupController::class, 'onboardingBindDefaultChat']);
})->middleware([
    OnboardingSensitiveRequestMiddleware::class,
    CheckLogin::class,
    CheckAuth::class,
]);
Route::group('/app/sand-ai/admin/setup/service-catalog', function (): void {
    Route::get('/preflight', [SetupController::class, 'serviceCatalogPreflight']);
    Route::post('/register', [SetupController::class, 'serviceCatalogRegister']);
    Route::get('/skill/preflight', [SetupController::class, 'skillServiceCatalogPreflight']);
    Route::post('/skill/register', [SetupController::class, 'skillServiceCatalogRegister']);
    Route::get('/audio/preflight', [SetupController::class, 'audioServiceCatalogPreflight']);
    Route::post('/audio/register', [SetupController::class, 'audioServiceCatalogRegister']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/setup/meeting-execution', function (): void {
    Route::get('/read', [\plugin\SandAi\app\admin\controller\MeetingExecutionController::class, 'read']);
    Route::post('/register', [\plugin\SandAi\app\admin\controller\MeetingExecutionController::class, 'register']);
    Route::post('/state', [\plugin\SandAi\app\admin\controller\MeetingExecutionController::class, 'state']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/meeting-review/workflow', function (): void {
    Route::get('/read', [\plugin\SandAi\app\admin\controller\MeetingReviewWorkflowController::class, 'read']);
    Route::post('/submit', [\plugin\SandAi\app\admin\controller\MeetingReviewWorkflowController::class, 'submit']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/setup/deployment', function (): void {
    Route::get('/index', [SetupController::class, 'deploymentIndex']);
    Route::get('/by-request-key', [SetupController::class, 'deploymentDraftReadByRequestKey']);
    Route::get('/read', [SetupController::class, 'deploymentDraftRead']);
    Route::post('/save', [SetupController::class, 'deploymentDraftSave']);
    Route::get('/verification/read', [SetupController::class, 'deploymentVerificationRead']);
    Route::post('/verify', [SetupController::class, 'deploymentVerify']);
})->middleware([OnboardingSensitiveRequestMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/setup/capability', function (): void {
    Route::get('/metadata', [SetupController::class, 'capabilityMetadata']);
    Route::get('/profile/read', [SetupController::class, 'capabilityProfileRead']);
    Route::post('/profile/save', [SetupController::class, 'capabilityProfileSave']);
    Route::post('/profile/publish', [SetupController::class, 'capabilityProfilePublish']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/setup/material-analysis/profiles', function (): void {
    Route::get('/index', [MaterialProfileController::class, 'index']);
    Route::get('/read', [MaterialProfileController::class, 'read']);
    Route::get('/samples', [MaterialProfileController::class, 'samples']);
    Route::post('/draft/save', [MaterialProfileController::class, 'saveDraft']);
    Route::post('/publish', [MaterialProfileController::class, 'publish']);
    Route::post('/revoke', [MaterialProfileController::class, 'revoke']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/setup/legal-analysis/profiles', function (): void {
    Route::get('/templates', [LegalProfileController::class, 'templates']);
    Route::get('/index', [LegalProfileController::class, 'index']);
    Route::get('/operations/by-request-key', [LegalProfileController::class, 'readOperation']);
    Route::get('/read', [LegalProfileController::class, 'read']);
    Route::post('/draft/save', [LegalProfileController::class, 'saveDraft']);
    Route::post('/publish', [LegalProfileController::class, 'publish']);
    Route::post('/revoke', [LegalProfileController::class, 'revoke']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/setup/audio-transcription', function (): void {
    Route::get('/metadata', [SetupController::class, 'audioTranscriptionMetadata']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/usage', function (): void {
    Route::get('/scopes', [UsageSummaryController::class, 'scopes']);
    Route::get('/summary', [UsageSummaryController::class, 'summary']);
    Route::get('/summary/export.csv', [UsageSummaryController::class, 'export']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/budget', function (): void {
    Route::get('/references', [BudgetPolicyController::class, 'references']);
    Route::get('/policy/index', [BudgetPolicyController::class, 'index']);
    Route::get('/policy/read', [BudgetPolicyController::class, 'read']);
    Route::get('/policy/read-draft-by-request-key', [BudgetPolicyController::class, 'readDraftByRequestKey']);
    Route::post('/policy/save-draft', [BudgetPolicyController::class, 'saveDraft']);
    Route::post('/policy/publish', [BudgetPolicyController::class, 'publish']);
    Route::post('/policy/archive', [BudgetPolicyController::class, 'archive']);
    Route::get('/summary', [BudgetPolicyController::class, 'summary']);
    Route::get('/active', [BudgetPolicyController::class, 'active']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/billing', function (): void {
    Route::get('/import-by-request-key', [BillingImportController::class, 'recover']);
    Route::get('/sync/by-request-key', [BillingSyncController::class, 'recover']);
    Route::get('/reviews/by-request-key', [BillingReviewController::class, 'recover']);
    Route::post('/import', [BillingImportController::class, 'import']);
    Route::get('/index', [BillingImportController::class, 'index']);
    Route::get('/read', [BillingImportController::class, 'read']);
    Route::post('/sync', [BillingSyncController::class, 'sync']);
    Route::post('/evidence', [BillingReviewController::class, 'evidenceUpload']);
    Route::get('/evidence', [BillingReviewController::class, 'evidenceRead']);
    Route::delete('/evidence', [BillingReviewController::class, 'evidenceClear']);
    Route::post('/reviews', [BillingReviewController::class, 'review']);
    Route::get('/reviews', [BillingReviewController::class, 'index']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/billing/credential', function (): void {
    Route::post('/save', [BillingCredentialController::class, 'save']);
    Route::get('/by-request-key', [BillingCredentialController::class, 'recover']);
    Route::get('/status', [BillingCredentialController::class, 'status']);
})->middleware([OnboardingSensitiveRequestMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/knowledge-bases', function (): void {
    Route::get('/index', [AdminKnowledgeBaseController::class, 'index']);
    Route::get('/eligible-files', [AdminKnowledgeBaseController::class, 'eligibleFiles']);
    Route::post('/embedding-index/start', [AdminKnowledgeBaseController::class, 'startEmbeddingIndex']);
    Route::post('/embedding-index/advance', [AdminKnowledgeBaseController::class, 'advanceEmbeddingIndex']);
    Route::get('/embedding-index/status', [AdminKnowledgeBaseController::class, 'embeddingIndexStatus']);
    Route::get('/embedding-index/by-request-key', [AdminKnowledgeBaseController::class, 'embeddingIndexByRequestKey']);
    Route::post('/embedding-index/purge', [AdminKnowledgeBaseController::class, 'purgeEmbeddingIndex']);
    Route::post('/sources/purge', [AdminKnowledgeBaseController::class, 'purgeSources']);
    Route::get('/disable/by-request-key', [AdminKnowledgeBaseController::class, 'disableByRequestKey']);
    Route::get('/embedding-index/purge/by-request-key', [AdminKnowledgeBaseController::class, 'embeddingPurgeByRequestKey']);
    Route::get('/sources/purge/by-request-key', [AdminKnowledgeBaseController::class, 'sourcePurgeByRequestKey']);
    Route::get('/publications/publish/by-request-key', [AdminKnowledgeBaseController::class, 'publishPublicationByRequestKey']);
    Route::get('/publications/revoke/by-request-key', [AdminKnowledgeBaseController::class, 'revokePublicationByRequestKey']);
    Route::post('/create-draft', [AdminKnowledgeBaseController::class, 'createDraft']);
    Route::get('/create-draft/by-request-key', [AdminKnowledgeBaseController::class, 'draftByRequestKey']);
    Route::get('/publish/by-request-key', [AdminKnowledgeBaseController::class, 'publishByRequestKey']);
    Route::post('/publish', [AdminKnowledgeBaseController::class, 'publish']);
    Route::post('/disable', [AdminKnowledgeBaseController::class, 'disable']);
    Route::post('/trial', [AdminKnowledgeBaseController::class, 'trial']);
    Route::post('/publications/publish', [AdminKnowledgeBaseController::class, 'publishPublication']);
    Route::post('/publications/revoke', [AdminKnowledgeBaseController::class, 'revokePublication']);
    Route::get('/publications/publishable', [AdminKnowledgeBaseController::class, 'publishableIndex']);
    Route::get('/publications/index', [AdminKnowledgeBaseController::class, 'publicationIndex']);
    Route::get('/publications/consumer', [AdminKnowledgeBaseController::class, 'consumerIndex']);
})->middleware([CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/skills', function (): void {
    Route::get('/cases', [AdminSkillController::class, 'cases']);
    Route::post('/cases/create', [AdminSkillController::class, 'caseCreate']);
    Route::post('/cases/review', [AdminSkillController::class, 'caseReview']);
    Route::post('/cases/retire', [AdminSkillController::class, 'caseRetire']);
    Route::get('/index', [AdminSkillController::class, 'index']);
    Route::get('/read', [AdminSkillController::class, 'read']);
    Route::get('/sources', [AdminSkillController::class, 'sources']);
    Route::post('/create-draft', [AdminSkillController::class, 'createDraft']);
    Route::post('/test-run', [AdminSkillController::class, 'testRun']);
    Route::get('/tests/by-request-key', [AdminSkillController::class, 'readTest']);
    Route::get('/drafts/by-request-key', [AdminSkillController::class, 'readDraftReceipt']);
    Route::get('/publish/by-request-key', [AdminSkillController::class, 'readPublishReceipt']);
    Route::get('/revoke/by-request-key', [AdminSkillController::class, 'readRevokeReceipt']);
    Route::get('/cases/create/by-request-key', [AdminSkillController::class, 'readCaseCreateReceipt']);
    Route::get('/cases/review/by-request-key', [AdminSkillController::class, 'readCaseReviewReceipt']);
    Route::get('/cases/retire/by-request-key', [AdminSkillController::class, 'readCaseRetireReceipt']);
    Route::post('/publish', [AdminSkillController::class, 'publish']);
    Route::post('/revoke', [AdminSkillController::class, 'revoke']);
})->middleware([CheckLogin::class, CheckAuth::class]);
// The connection route is isolated so the credential guard always runs before
// auth/error middleware and the body is never eligible for SystemLog capture.
Route::group('/app/sand-ai/admin/tools/connections', function (): void {
    Route::post('/create', [AdminToolController::class, 'createConnection']);
})->middleware([ToolConnectionSensitiveRequestMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::group('/app/sand-ai/admin/tools', function (): void {
    Route::get('/available', [AdminToolController::class, 'available']);
    Route::get('/connections/index', [AdminToolController::class, 'connections']);
    Route::get('/connections/read', [AdminToolController::class, 'readConnection']);
    Route::get('/connections/by-code', [AdminToolController::class, 'connectionByCode']);
    Route::get('/mcp/latest', [AdminToolController::class, 'latestMcp']);
    Route::get('/workspace', [AdminToolController::class, 'workspace']);
    Route::get('/operations/connection', [AdminToolController::class, 'readConnectionOperation']);
    Route::get('/operations/draft', [AdminToolController::class, 'readDraftOperation']);
    Route::get('/operations/publish', [AdminToolController::class, 'readPublishOperation']);
    Route::get('/operations/bind', [AdminToolController::class, 'readBindOperation']);
    Route::get('/operations/revoke', [AdminToolController::class, 'readRevokeOperation']);
    Route::get('/operations/discovery', [AdminToolController::class, 'readDiscoveryOperation']);
    Route::post('/mcp/discover', [AdminToolController::class, 'discoverMcp']);
    Route::post('/create-draft', [AdminToolController::class, 'createDraft']);
    Route::post('/publish', [AdminToolController::class, 'publish']);
    Route::post('/bind-environment', [AdminToolController::class, 'bindEnvironment']);
    Route::post('/revoke', [AdminToolController::class, 'revoke']);
})->middleware([CheckLogin::class, CheckAuth::class]);

Route::disableDefaultRoute(IndexController::class);

Route::group('/app/sand-ai/admin/agents/definitions', function (): void {
    Route::get('/operations/by-request-key', [AdminAgentDefinitionController::class, 'readOperationByRequestKey']);
    Route::post('/draft', [AdminAgentDefinitionController::class, 'draft']);
    Route::post('/publish', [AdminAgentDefinitionController::class, 'publish']);
    Route::post('/disable', [AdminAgentDefinitionController::class, 'disable']);
    Route::get('/read', [AdminAgentDefinitionController::class, 'read']);
    Route::get('/index', [AdminAgentDefinitionController::class, 'index']);
    Route::get('/versions', [AdminAgentDefinitionController::class, 'versions']);
    Route::post('/rollback', [AdminAgentDefinitionController::class, 'rollback']);
    Route::get('/models', [AdminAgentDefinitionController::class, 'models']);
})->middleware([CheckLogin::class, CheckAuth::class, SystemLog::class]);

Route::group('/app/sand-ai/admin/channels/bindings', function (): void {
    Route::get('/operations/by-request-key', [AdminChannelBindingController::class, 'readOperationByRequestKey']);
    Route::post('/save', [AdminChannelBindingController::class, 'save']);
    Route::post('/disable', [AdminChannelBindingController::class, 'disable']);
    Route::post('/deliveries/retry', [AdminChannelBindingController::class, 'retryDelivery']);
    Route::get('/deliveries/retry/by-request-key', [AdminChannelBindingController::class, 'readDeliveryRetryByRequestKey']);
    Route::post('/wecom-deliveries/retry', [AdminChannelBindingController::class, 'retryWecomDelivery']);
    Route::get('/wecom-deliveries/retry/by-request-key', [AdminChannelBindingController::class, 'readWecomDeliveryRetryByRequestKey']);
    Route::get('/read', [AdminChannelBindingController::class, 'read']);
    Route::get('/index', [AdminChannelBindingController::class, 'index']);
    Route::get('/deliveries', [AdminChannelBindingController::class, 'deliveries']);
    Route::get('/wecom-deliveries', [AdminChannelBindingController::class, 'wecomDeliveries']);
    Route::get('/feishu-status', [AdminChannelBindingController::class, 'feishuStatus']);
    Route::get('/wecom-status', [AdminChannelBindingController::class, 'wecomStatus']);
})->middleware([CheckLogin::class, CheckAuth::class, SystemLog::class]);

Route::group('/app/sand-ai/admin/channels/bindings/feishu-credentials', function (): void {
    Route::post('/save', [AdminChannelBindingController::class, 'saveFeishuCredentials']);
})->middleware([FeishuCredentialSensitiveRequestMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::get('/app/sand-ai/admin/channels/bindings/feishu-credentials/by-request-key',
    [AdminChannelBindingController::class, 'readFeishuCredentialsByRequestKey'])
    ->middleware([CheckLogin::class, CheckAuth::class]);

Route::group('/app/sand-ai/admin/channels/bindings/wecom-credentials', function (): void {
    Route::post('/save', [AdminChannelBindingController::class, 'saveWecomCredentials']);
})->middleware([WecomCredentialSensitiveRequestMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::get('/app/sand-ai/admin/channels/bindings/wecom-credentials/by-request-key',
    [AdminChannelBindingController::class, 'readWecomCredentialsByRequestKey'])
    ->middleware([CheckLogin::class, CheckAuth::class]);

Route::delete('/api/sand-ai/v1/skills/source-suggestions/{suggestionId}/content', [\app\Api\Controller\SkillManagementController::class, 'purgeSuggestionContent']);

Route::group('/app/sand-ai/admin/ai-workflows', function (): void {
    $controller = \plugin\SandAi\app\admin\controller\CompositionController::class;
    Route::get('/index', [$controller, 'index']);
    Route::get('/read', [$controller, 'read']);
    Route::get('/catalog', [$controller, 'catalog']);
    Route::get('/by-request-key', [$controller, 'byRequestKey']);
    Route::post('/save-draft', [$controller, 'saveDraft']);
    Route::post('/publish', [$controller, 'publish']);
    Route::post('/disable', [$controller, 'disable']);
})->middleware([CheckLogin::class, CheckAuth::class]);

Route::post('/api/sand-ai/v1/ai-workflows/{code}/versions/{version}/runs', [\app\Api\Controller\CompositionController::class, 'submit']);
Route::get('/api/sand-ai/v1/ai-workflow-runs/by-request-key', [\app\Api\Controller\CompositionController::class, 'byRequestKey']);
Route::get('/api/sand-ai/v1/ai-workflow-runs/{runId}/operations/by-request-key', [\app\Api\Controller\CompositionController::class, 'readOperation']);
Route::get('/api/sand-ai/v1/ai-workflow-runs/{runId}', [\app\Api\Controller\CompositionController::class, 'read']);
Route::post('/api/sand-ai/v1/ai-workflow-runs/{runId}/advance', [\app\Api\Controller\CompositionController::class, 'advance']);
Route::post('/api/sand-ai/v1/ai-workflow-runs/{runId}/cancel', [\app\Api\Controller\CompositionController::class, 'cancel']);
Route::delete('/api/sand-ai/v1/ai-workflow-runs/{runId}/input', [\app\Api\Controller\CompositionController::class, 'purgeInput']);
Route::post('/api/sand-ai/v1/ai-workflow-runs/{runId}/nodes/{nodeId}/retry', [\app\Api\Controller\CompositionController::class, 'retry']);

Route::get('/api/sand-ai/v1/ai-workflow-runs/{runId}/cost', [\app\Api\Controller\CompositionCostController::class, 'read']);
