<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Skill\SkillCaseGateway;
use app\Application\Skill\SkillConsumerContext;
use app\Application\Skill\SkillGateway;
use app\Application\Skill\SkillRunRecoveryRequest;
use app\Application\Skill\SkillKnowledgePublication;
use app\Application\Skill\SkillWorkspaceGateway;
use app\Application\Skill\SkillSourceSuggestionGateway;
use app\Application\Skill\SkillSourceSuggestionContentPurgeGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use Closure;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\SkillInvocationAccessGuard;
use app\Infrastructure\Persistence\Model\Skill;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\SkillCase;
use app\Infrastructure\Persistence\Model\SkillKnowledgeRef;
use app\Infrastructure\Persistence\Model\SkillVersion;
use support\Request;
use support\Response;

/** Workload-authorized consumer management; does not authenticate a human actor. */
final class SkillManagementController
{
    public function index(Request $request): Response { return $this->handle($request, 'list'); }
    public function sources(Request $request): Response { return $this->handle($request, 'sources'); }
    public function read(Request $request, string $code): Response { return $this->handle($request, 'read', $code); }
    public function draft(Request $request): Response { return $this->handle($request, 'draft'); }
    public function cases(Request $request, string $code, string $version): Response { return $this->handle($request, 'cases', $code, $version); }
    public function createCase(Request $request, string $code, string $version): Response { return $this->handle($request, 'case_create', $code, $version); }
    public function test(Request $request, string $code, string $version): Response { return $this->handle($request, 'test', $code, $version); }
    public function readTest(Request $request, string $code, string $version): Response { return $this->handle($request, 'read_test', $code, $version); }
    public function review(Request $request, string $code, string $version, string $caseId): Response { return $this->handle($request, 'case_review', $code, $version, $caseId); }
    public function retire(Request $request, string $code, string $version, string $caseId): Response { return $this->handle($request, 'case_retire', $code, $version, $caseId); }
    public function publish(Request $request, string $code, string $version): Response { return $this->handle($request, 'publish', $code, $version); }
    public function rollback(Request $request, string $code, string $version): Response { return $this->handle($request, 'rollback', $code, $version); }
    public function revoke(Request $request, string $code): Response { return $this->handle($request, 'revoke', $code); }

    public function readDraftReceipt(Request $request): Response { return $this->savedReceipt($request); }
    public function readCreatedCase(Request $request, string $code, string $version): Response { return $this->savedReceipt($request, $code, $version); }

    public function readVersionOperation(Request $request, string $code, string $version, string $operation): Response
    {
        return $this->operationReceipt($request, $operation, $code, $version);
    }

    public function readRevokeOperation(Request $request, string $code): Response
    {
        return $this->operationReceipt($request, 'revoke', $code);
    }

    public function readCaseOperation(Request $request, string $code, string $version, string $caseId, string $operation): Response
    {
        return $this->operationReceipt($request, $operation, $code, $version, $caseId);
    }

    public function readAdoption(Request $request, string $suggestionId): Response
    {
        return $this->operationReceipt($request, 'source_adopt', suggestionId: $suggestionId);
    }

    private function operationReceipt(Request $request, string $operation, ?string $code = null, ?string $version = null, ?string $caseId = null, ?string $suggestionId = null): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $allowed = $suggestionId !== null ? ['source_adopt']
                : ($caseId !== null ? ['review', 'retire'] : ($version === null ? ['revoke'] : ['publish', 'rollback']));
            if (!in_array($operation, $allowed, true) || $request->rawBody() !== ''
                || ($code !== null && preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/D', $code) !== 1)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Saved Skill operation locator is invalid');
            }
            $action = in_array($operation, ['publish', 'rollback', 'revoke'], true) ? 'sand_ai.skill.publish' : 'sand_ai.skill.manage';
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, $action)['context'];
            $authenticator->authenticate($request, 'sand_ai.skill.read');
            $query = $request->get();
            if (!is_array($query) || array_diff(array_keys($query), ['request_id', 'scope_type', 'scope_ref', 'actor_ref', 'input_sha256']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Saved Skill operation contains unsupported fields');
            }
            $inputHash = $query['input_sha256'] ?? null;
            if (array_key_exists('input_sha256', $query)
                && (!is_string($inputHash) || preg_match('/\A[a-f0-9]{64}\z/D', $inputHash) !== 1)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original Skill input hash is invalid');
            }
            SkillConsumerContext::requireSchema();
            $consumer = SkillConsumerContext::fromRequest($context,
                ['type' => $query['scope_type'] ?? null, 'ref' => $query['scope_ref'] ?? null], $query['actor_ref'] ?? null);
            $originalKey = $this->text($query['request_id'] ?? null);
            $key = $consumer->key($originalKey);
            [$authorizeFacts, $revalidateFacts] = $this->resourceAuthorizers($request, $context, $requestId);
            $authorizeResource = function (int $skillId, int $versionId) use ($authorizeFacts, $consumer, $context, $action, $operation): void {
                $skill = Skill::where('id', $skillId)->where('environment_id', $context->environmentId)->whereNull('delete_time')->find();
                $fixed = SkillVersion::where('id', $versionId)->where('skill_id', $skillId)->find();
                if ($skill === null || $fixed === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original Skill target is unavailable');
                SkillConsumerContext::assertAccess($skill, $consumer);
                $resourceKey = $skillId . ':' . (int) $fixed->version_no . ':' . $context->workloadClientId;
                if ($operation === 'revoke') {
                    // Same metadata-only resolver as the original revoke; withdrawn sources are not reopened.
                    $authorizeFacts($action, 'sand_ai.skill_revoke', $resourceKey, 'skill-revoke-receipt:' . bin2hex(random_bytes(16)));
                    return;
                }
                foreach (array_unique([$action, 'sand_ai.skill.read']) as $required) {
                    $authorizeFacts($required, 'sand_ai.skill', $resourceKey, 'skill-operation-read:' . bin2hex(random_bytes(16)));
                }
                $reference = SkillKnowledgeRef::where('skill_version_id', $versionId)->find();
                if ($reference === null) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original Skill source is unavailable');
                $knowledge = (new SkillKnowledgePublication())->resolve($context->environmentId,
                    (int) $reference->knowledge_base_version_id, (string) $reference->snapshot_sha256);
                $authorizeFacts('sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
                    $knowledge['publication_id'] . ':' . $context->workloadClientId . ':' . (int) $reference->knowledge_base_version_id,
                    'skill-operation-source:' . bin2hex(random_bytes(16)));
            };
            if ($operation === 'source_adopt') {
                $authorizeManaged = fn (int $id) => $authorizeFacts('sand_ai.skill.manage', 'sand_ai.skill_source_suggestion',
                    $id . ':' . $context->workloadClientId, 'skill-adoption-read:' . bin2hex(random_bytes(16)));
                $authorizeKnowledge = function (int $publicationId, int $versionId) use ($authorizeFacts, $context): void {
                    $resourceKey = $publicationId . ':' . $context->workloadClientId . ':' . $versionId;
                    $authorizeFacts('sand_ai.skill.manage', 'sand_ai.skill_knowledge', $resourceKey, 'skill-adoption-source:' . bin2hex(random_bytes(16)));
                    $authorizeFacts('sand_ai.knowledge.query', 'sand_ai.knowledge_publication', $resourceKey, 'skill-adoption-knowledge:' . bin2hex(random_bytes(16)));
                };
                $authorizeChat = function (int $invocationId) use ($authenticator, $request, $authorizeFacts): void {
                    $authenticator->authenticate($request, 'sand_ai.chat.complete');
                    $authorizeFacts('sand_ai.chat.complete', 'sand_ai.chat_invocation', (string) $invocationId,
                        'skill-adoption-invocation:' . bin2hex(random_bytes(16)));
                };
                $result = (new SkillSourceSuggestionGateway())->readAdoption($consumer, $this->id($suggestionId),
                    $originalKey, $authorizeManaged, $authorizeKnowledge, $authorizeChat, $authorizeResource, $inputHash);
            } elseif ($caseId !== null) {
                $result = (new SkillCaseGateway())->readOperationReceipt($context->environmentId, $code, $this->id($version),
                    $this->id($caseId), $operation, $key, $consumer, $authorizeResource, $inputHash);
            } else {
                $result = (new SkillGateway())->readOperationReceipt($context->environmentId, $code,
                    $version === null ? null : $this->id($version), $operation, $key, $consumer, $authorizeResource, $inputHash);
            }
            $revalidateFacts();
            return ApiResponder::success($result + ['request_id' => $originalKey,
                'scope' => ['type' => $consumer->scopeType, 'ref' => $consumer->scopeRef],
                'scope_source' => 'consumer_declared', 'actor_ref' => $consumer->actorRef], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    private function savedReceipt(Request $request, ?string $code = null, ?string $version = null): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.skill.manage')['context'];
            $authenticator->authenticate($request, 'sand_ai.skill.read');
            $query = $request->get();
            if (!is_array($query) || array_diff(array_keys($query), ['request_id', 'scope_type', 'scope_ref', 'actor_ref']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Saved Skill receipt contains unsupported fields');
            }
            SkillConsumerContext::requireSchema();
            $consumer = SkillConsumerContext::fromRequest($context,
                ['type' => $query['scope_type'] ?? null, 'ref' => $query['scope_ref'] ?? null], $query['actor_ref'] ?? null);
            $originalKey = $this->text($query['request_id'] ?? null);
            $key = $consumer->key($originalKey);
            if ($code !== null && preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $code) !== 1) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Saved Skill receipt locator is invalid');
            }
            [$authorizeFacts, $revalidateFacts] = $this->resourceAuthorizers($request, $context, $requestId);
            $authorizeResource = function (int $skillId, int $versionId) use ($authorizeFacts, $consumer, $context): void {
                $skill = Skill::where('id', $skillId)->where('environment_id', $context->environmentId)->whereNull('delete_time')->find();
                $fixed = SkillVersion::where('id', $versionId)->where('skill_id', $skillId)->find();
                if ($skill === null || $fixed === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original Skill resource is unavailable');
                SkillConsumerContext::assertAccess($skill, $consumer);
                foreach (['sand_ai.skill.manage', 'sand_ai.skill.read'] as $action) {
                    $authorizeFacts($action, 'sand_ai.skill', $skillId . ':' . (int) $fixed->version_no . ':' . $context->workloadClientId,
                        'skill-saved-receipt:' . bin2hex(random_bytes(16)));
                }
                $reference = SkillKnowledgeRef::where('skill_version_id', $versionId)->find();
                if ($reference === null) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original Skill source is unavailable');
                $knowledge = (new SkillKnowledgePublication())->resolve($context->environmentId,
                    (int) $reference->knowledge_base_version_id, (string) $reference->snapshot_sha256);
                $resourceKey = $knowledge['publication_id'] . ':' . $context->workloadClientId . ':' . (int) $reference->knowledge_base_version_id;
                $authorizeFacts('sand_ai.skill.manage', 'sand_ai.skill_knowledge', $resourceKey, 'skill-saved-source:' . bin2hex(random_bytes(16)));
                $authorizeFacts('sand_ai.knowledge.query', 'sand_ai.knowledge_publication', $resourceKey, 'skill-saved-knowledge:' . bin2hex(random_bytes(16)));
            };
            $result = $code === null
                ? (new SkillGateway())->readDraftReceipt($context->environmentId, $key, $consumer, $authorizeResource)
                : (new SkillCaseGateway())->readCreatedReceipt($context->environmentId, $code, $this->id($version), $key, $consumer, $authorizeResource);
            $revalidateFacts();
            return ApiResponder::success($result + ['request_id' => $originalKey, 'scope' => ['type' => $consumer->scopeType, 'ref' => $consumer->scopeRef],
                'scope_source' => 'consumer_declared'], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function suggest(Request $request): Response { return $this->sourceSuggestion($request); }
    public function adoptSuggestion(Request $request, string $suggestionId): Response { return $this->sourceSuggestion($request, $suggestionId); }

    public function readSuggestion(Request $request): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $identity = $authenticator->authenticate($request, 'sand_ai.skill.manage');
            $context = $identity['context'];
            $query = $request->get();
            if (!is_array($query) || array_diff(array_keys($query), ['request_id', 'scope_type', 'scope_ref', 'actor_ref']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Source suggestion read contains unsupported fields');
            }
            SkillConsumerContext::requireSchema();
            $consumer = SkillConsumerContext::fromRequest($context,
                ['type' => $query['scope_type'] ?? null, 'ref' => $query['scope_ref'] ?? null], $query['actor_ref'] ?? null);
            if (!is_string($query['request_id'] ?? null)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Source suggestion request key is invalid');
            [$authorizeFacts, $revalidateFacts] = $this->resourceAuthorizers($request, $context, $requestId);
            $authorizeManaged = fn (int $id) => $authorizeFacts('sand_ai.skill.manage',
                'sand_ai.skill_source_suggestion', $id . ':' . $context->workloadClientId,
                'skill-source-read:' . bin2hex(random_bytes(16)));
            $authorizeKnowledge = function (int $publicationId, int $versionId) use ($authorizeFacts, $authenticator, $request, $context, $requestId): void {
                $authenticator->authenticate($request, 'sand_ai.chat.complete');
                $authorizeFacts('sand_ai.skill.manage', 'sand_ai.skill_knowledge',
                    $publicationId . ':' . $context->workloadClientId . ':' . $versionId,
                    'skill-source-manage:' . bin2hex(random_bytes(16)));
                $authorizeFacts('sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
                    $publicationId . ':' . $context->workloadClientId . ':' . $versionId,
                    'skill-source-read-knowledge:' . bin2hex(random_bytes(16)));
            };
            $authorizeChat = fn (int $id) => $authorizeFacts('sand_ai.chat.complete',
                'sand_ai.chat_invocation', (string) $id, 'skill-source-read-chat:' . bin2hex(random_bytes(16)));
            $result = (new SkillSourceSuggestionGateway())->readByRequestKey($consumer, $query['request_id'],
                $authorizeManaged, $authorizeKnowledge, $authorizeChat);
            $revalidateFacts();
            return ApiResponder::success($result + ['scope' => ['type' => $consumer->scopeType, 'ref' => $consumer->scopeRef],
                'scope_source' => 'consumer_declared'], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function purgeSuggestionContent(Request $request, string $suggestionId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $identity = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.skill.manage');
            $context = $identity['context'];
            $body = $request->post();
            if (!is_array($body) || array_diff(array_keys($body), ['scope', 'actor_ref']) !== []) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Source content purge request contains unsupported fields');
            SkillConsumerContext::requireSchema();
            $consumer = SkillConsumerContext::fromRequest($context, $body['scope'] ?? null, $body['actor_ref'] ?? null);
            $id = $this->id($suggestionId);
            [$authorizeFacts, $revalidateFacts] = $this->resourceAuthorizers($request, $context, $requestId);
            $authorize = fn () => $authorizeFacts('sand_ai.skill.manage', 'sand_ai.skill_source_suggestion',
                $id . ':' . $context->workloadClientId, 'skill-source-purge:' . bin2hex(random_bytes(16)));
            $authorize();
            $result = (new SkillSourceSuggestionContentPurgeGateway())->purge($consumer, $id, (string) $request->header('idempotency-key', ''));
            $authorize();
            $revalidateFacts();
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    private function sourceSuggestion(Request $request, ?string $suggestionId = null): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $identity = $authenticator->authenticate($request, 'sand_ai.skill.manage');
            $context = $identity['context'];
            $body = $request->post();
            $allowed = $suggestionId === null ? ['scope', 'actor_ref', 'source'] : ['scope', 'actor_ref', 'selected', 'draft'];
            if (!is_array($body) || array_diff(array_keys($body), $allowed) !== []) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Source suggestion request contains unsupported fields');
            SkillConsumerContext::requireSchema();
            $consumer = SkillConsumerContext::fromRequest($context, $body['scope'] ?? null, $body['actor_ref'] ?? null);
            [$authorizeFacts, $revalidateFacts] = $this->resourceAuthorizers($request, $context, $requestId);
            $authorizeKnowledge = function (int $publicationId, int $versionId) use ($authorizeFacts, $authenticator, $request, $context, $requestId): void {
                $authenticator->authenticate($request, 'sand_ai.chat.complete');
                $authorizeFacts('sand_ai.skill.manage', 'sand_ai.skill_knowledge',
                    $publicationId . ':' . $context->workloadClientId . ':' . $versionId,
                    'skill-source-manage:' . bin2hex(random_bytes(16)));
                $authorizeFacts('sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
                    $publicationId . ':' . $context->workloadClientId . ':' . $versionId,
                    'skill-source-knowledge:' . bin2hex(random_bytes(16)));
            };
            $authorizeChat = function (int $invocationId) use ($authorizeKnowledge, $authorizeFacts, $request, $context, $requestId): void {
                $invocation = Invocation::where('id', $invocationId)->find();
                if ($invocation === null) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Source suggestion invocation is unavailable');
                $authorizeKnowledge((int) $invocation->knowledge_publication_id, (int) $invocation->knowledge_base_version_id);
                $authorizeFacts('sand_ai.chat.complete', 'sand_ai.chat_invocation', (string) $invocationId,
                    'skill-source-chat:' . bin2hex(random_bytes(16)));
            };
            $key = (string) $request->header('idempotency-key', '');
            $gateway = new SkillSourceSuggestionGateway();
            if ($suggestionId === null) {
                $result = $gateway->suggest($consumer, $this->object($body['source'] ?? null), $key, $authorizeKnowledge, $authorizeChat,
                    fn (int $id) => $authorizeFacts('sand_ai.skill.manage', 'sand_ai.skill_source_suggestion',
                        $id . ':' . $context->workloadClientId, 'skill-source-owner:' . bin2hex(random_bytes(16))));
            } else {
                $authorizeFacts('sand_ai.skill.manage', 'sand_ai.skill_source_suggestion',
                    $this->id($suggestionId) . ':' . $context->workloadClientId, 'skill-source-adopt:' . bin2hex(random_bytes(16)));
                if (!is_array($body['selected'] ?? null)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Selected source suggestions must be a list');
                $result = $gateway->adopt($consumer, $this->id($suggestionId), $body['selected'], $this->object($body['draft'] ?? null), $key, $authorizeKnowledge, $authorizeChat);
            }
            $revalidateFacts();
            return ApiResponder::success($result + ['scope' => ['type' => $consumer->scopeType, 'ref' => $consumer->scopeRef], 'scope_source' => 'consumer_declared'], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    private function handle(Request $request, string $operation, ?string $code = null, ?string $version = null, ?string $caseId = null): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $read = in_array($operation, ['list', 'sources', 'read', 'cases', 'read_test'], true);
            $action = $operation === 'read_test' ? 'sand_ai.skill.test' : ($read ? 'sand_ai.skill.read' : match ($operation) {
                'test' => 'sand_ai.skill.test',
                'publish', 'rollback', 'revoke' => 'sand_ai.skill.publish',
                default => 'sand_ai.skill.manage',
            });
            $identity = (new SandIamContextAuthenticator())->authenticate($request, $action);
            if ($operation === 'read_test') {
                (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.skill.read');
            }
            $context = $identity['context'];
            $body = $read ? [
                'scope' => ['type' => $request->get('scope_type'), 'ref' => $request->get('scope_ref')],
                'actor_ref' => $request->get('actor_ref'),
            ] : $request->post();
            if (!is_array($body)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill request must be an object');
            $allowed = match ($operation) {
                'draft' => ['scope', 'actor_ref', 'draft', 'replace_draft', 'expected_draft_version'],
                'test' => ['scope', 'actor_ref', 'input', 'resume_failed', 'failed_run_id'],
                'case_create' => ['scope', 'actor_ref', 'name', 'input', 'expected_criteria'],
                'case_review' => ['scope', 'actor_ref', 'skill_run_id', 'verdict', 'note'],
                'case_retire' => ['scope', 'actor_ref', 'reason'],
                default => ['scope', 'actor_ref'],
            };
            if (array_diff(array_keys($body), $allowed) !== []) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill request contains unsupported fields');
            $recovery = $operation === 'test' ? SkillRunRecoveryRequest::fromPayload($body) : null;
            SkillConsumerContext::requireSchema();
            $consumer = SkillConsumerContext::fromRequest($context, $body['scope'] ?? null, $body['actor_ref'] ?? null);
            $environment = $context->environmentId;
            $expectedDraftVersion = null;
            if ($operation === 'draft' && (array_key_exists('replace_draft', $body) || array_key_exists('expected_draft_version', $body))) {
                if (($body['replace_draft'] ?? null) !== true || !is_int($body['expected_draft_version'] ?? null)
                    || $body['expected_draft_version'] < 1) {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Draft replacement requires true replace_draft and a positive expected_draft_version');
                }
                $expectedDraftVersion = $body['expected_draft_version'];
                $revision = $this->object($body['draft'] ?? null);
                $code = $this->text($revision['code'] ?? null);
                $version = (string) $expectedDraftVersion;
            }
            [$authorizeFacts, $revalidateFacts] = $this->resourceAuthorizers($request, $context, $requestId);
            if ($operation === 'draft') {
                $draft = $this->object($body['draft'] ?? null);
                $reference = $this->object($draft['知识库版本引用'] ?? null);
                $versionId = $this->id($reference['knowledge_base_version_id'] ?? null);
                $knowledge = (new SkillKnowledgePublication())->resolve($environment, $versionId, $this->text($reference['snapshot_sha256'] ?? null));
                $authorizeFacts('sand_ai.skill.manage', 'sand_ai.skill_knowledge',
                    $knowledge['publication_id'] . ':' . $context->workloadClientId . ':' . $versionId,
                    'skill-draft-source:' . bin2hex(random_bytes(16)));
                $authorizeFacts('sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
                    $knowledge['publication_id'] . ':' . $context->workloadClientId . ':' . $versionId,
                    'skill-draft-knowledge:' . bin2hex(random_bytes(16)));
            }
            $key = $read ? null : $consumer->key((string) $request->header('idempotency-key', ''));
            $skill = null;
            $fixed = null;
            $authorizeResource = null;
            if ($code !== null) {
                if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $code) !== 1) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill code is invalid');
                $skill = Skill::where('environment_id', $environment)->where('code', $code)->whereNull('delete_time')->find();
                if ($skill === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill not found');
                SkillConsumerContext::assertAccess($skill, $consumer);
                if ($operation === 'revoke') $version = (string) (new SkillGateway())->revokeTargetVersion($environment, (int) $skill->id, $key, $consumer);
                $fixed = $version === null
                    ? SkillVersion::where('skill_id', (int) $skill->id)->order('version_no', 'desc')->find()
                    : SkillVersion::where('skill_id', (int) $skill->id)->where('version_no', $this->id($version))->find();
                if ($fixed === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill version not found');
                $authorizeResource = function () use ($authorizeFacts, $request, $context, $action, $requestId, $skill, $fixed, $operation): void {
                    $resolver = $operation === 'revoke' ? 'sand_ai.skill_revoke' : 'sand_ai.skill';
                    $authorizeFacts($action, $resolver,
                        (int) $skill->id . ':' . (int) $fixed->version_no . ':' . $context->workloadClientId,
                        'skill-resource:' . bin2hex(random_bytes(16)));
                    if ($operation === 'read_test') {
                        $authorizeFacts('sand_ai.skill.read', 'sand_ai.skill',
                            (int) $skill->id . ':' . (int) $fixed->version_no . ':' . $context->workloadClientId,
                            'skill-test-read:' . bin2hex(random_bytes(16)));
                    }
                };
                $authorizeResource();
            }
            if ($caseId !== null && SkillCase::where('id', $this->id($caseId))->where('skill_version_id', (int) $fixed->id)->find() === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill case not found');
            }
            $beforeId = null;
            if (in_array($operation, ['list', 'sources'], true) && $request->get('before_id') !== null) {
                $beforeId = $this->id($request->get('before_id'));
            }
            $gateway = new SkillGateway();
            $cases = new SkillCaseGateway();
            $workspace = new SkillWorkspaceGateway();
            $actor = $consumer->auditRef();
            $result = match ($operation) {
                'list' => $workspace->list($environment, $this->id($request->get('page', 1)), $this->id($request->get('limit', 20)), $consumer, $beforeId),
                'sources' => $workspace->sources($environment, $this->id($request->get('page', 1)), $this->id($request->get('limit', 20)), $beforeId, false),
                'read' => $workspace->read($environment, (int) $skill->id, $consumer),
                'draft' => $gateway->createDraft($environment, $this->object($body['draft'] ?? null), $key, $actor, $consumer, expectedDraftVersion: $expectedDraftVersion, consumerRequestId: (string) $request->header('idempotency-key', '')),
                'cases' => $cases->list($environment, (int) $fixed->id, $consumer),
                'case_create' => $cases->create($environment, (int) $skill->id, (int) $fixed->id, $this->text($body['name'] ?? null), $this->object($body['input'] ?? null), $this->text($body['expected_criteria'] ?? null), $key, $actor, $consumer),
                'case_review' => $cases->review($environment, $this->id($caseId), $this->id($body['skill_run_id'] ?? null), $this->text($body['verdict'] ?? null), $this->text($body['note'] ?? ''), $key, $actor, $consumer),
                'case_retire' => $cases->retire($environment, $this->id($caseId), $this->text($body['reason'] ?? null), $key, $actor, $consumer),
                'publish' => $gateway->publishVersion($environment, (int) $skill->id, (int) $fixed->version_no, $key, $consumer),
                'rollback' => $gateway->rollbackVersion($environment, (int) $skill->id, (int) $fixed->version_no, $key, $consumer),
                'revoke' => $gateway->revoke($environment, (int) $skill->id, $key, $actor, $consumer, (int) $fixed->version_no),
                'read_test' => $gateway->readTestRun($environment, $code, (int) $fixed->version_no,
                    $consumer->key($this->text($request->get('request_id'))),
                    UsageAttribution::fromIdentity($context, 'skill_test', 'sand_ai.skill.test'),
                    fn (int $invocationId) => (new SkillInvocationAccessGuard())->authorize($request, $context, $invocationId, $requestId, 'sand_ai.skill.test'),
                    function (int $publicationId, int $knowledgeVersion) use ($authorizeFacts, $request, $context, $requestId): void {
                        $authorizeFacts('sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
                            $publicationId . ':' . $context->workloadClientId . ':' . $knowledgeVersion,
                            'skill-test-knowledge:' . bin2hex(random_bytes(16)));
                    }, $consumer, $authorizeResource, $this->text($request->get('request_id')),
                    SkillRunRecoveryRequest::attemptNumber($request->get('attempt_no'))),
                'test' => $gateway->run($environment, $code, (int) $fixed->version_no, $this->object($body['input'] ?? null),
                    $key, UsageAttribution::fromIdentity($context, 'skill_test', 'sand_ai.skill.test'), true,
                    fn (int $invocationId) => (new SkillInvocationAccessGuard())->authorize($request, $context, $invocationId, $requestId, 'sand_ai.skill.test'),
                    function (int $publicationId, int $knowledgeVersion) use ($authorizeFacts, $request, $context, $requestId): void {
                        $authorizeFacts('sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
                            $publicationId . ':' . $context->workloadClientId . ':' . $knowledgeVersion,
                            'skill-knowledge:' . bin2hex(random_bytes(16)));
                    }, $consumer, $authorizeResource, $recovery->resumeFailed, $recovery->failedRunId),
                default => throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill operation is unsupported'),
            };
            if ($operation === 'draft' && ($result['draft_validation_rejected'] ?? false) === true) {
                // Only the fresh, exact consumer POST rejected under its original operation lock has this proof.
                // Ordinary validation errors, existing keys and missing GET receipts retain their prior envelope.
                $revalidateFacts();
                return json([
                    'code' => 400, 'message' => $result['error_message'],
                    'data' => ['error_code' => $result['error_code'], 'retryable' => false,
                        'submission' => $result['submission']],
                    'request_id' => $requestId,
                ])->withStatus(400)->header('Cache-Control', 'no-store');
            }
            if ($operation === 'list') {
                foreach ($result['data'] as $row) {
                    $versions = array_values(array_unique(array_filter([$row['active_version'], $row['draft_version']],
                        static fn (mixed $number): bool => is_int($number) && $number > 0)));
                    if ($versions === []) {
                        $latest = SkillVersion::where('skill_id', (int) $row['id'])->order('version_no', 'desc')->find();
                        if ($latest === null) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Skill metadata has no fixed version');
                        $versions = [(int) $latest->version_no];
                    }
                    foreach ($versions as $number) {
                        $authorizeFacts('sand_ai.skill.read', 'sand_ai.skill',
                            (int) $row['id'] . ':' . $number . ':' . $context->workloadClientId,
                            'skill-list:' . bin2hex(random_bytes(16)));
                    }
                }
            }
            if ($operation === 'sources') {
                foreach ($result['data'] as $source) {
                    $resourceKey = (int) $source['publication_id'] . ':' . $context->workloadClientId . ':' . (int) $source['knowledge_base_version_id'];
                    $authorizeFacts('sand_ai.skill.read', 'sand_ai.skill_knowledge', $resourceKey,
                        'skill-source-list:' . bin2hex(random_bytes(16)));
                    $authorizeFacts('sand_ai.knowledge.query', 'sand_ai.knowledge_publication', $resourceKey,
                        'skill-source-list-knowledge:' . bin2hex(random_bytes(16)));
                }
            }
            if ($operation === 'read') {
                // Each returned historical body can refer to a different protected data class.
                foreach ($result['versions'] as $returnedVersion) {
                    $authorizeFacts('sand_ai.skill.read', 'sand_ai.skill',
                        (int) $skill->id . ':' . (int) $returnedVersion['version'] . ':' . $context->workloadClientId,
                        'skill-history:' . bin2hex(random_bytes(16)));
                }
            }
            if ($authorizeResource !== null) $authorizeResource();
            if (in_array($operation, ['list', 'sources'], true)) {
                unset($result['total']);
                $last = $result['data'] === [] ? null : $result['data'][array_key_last($result['data'])];
                $result['next_cursor'] = $last === null ? null : (int) $last[$operation === 'list' ? 'id' : 'publication_id'];
            }
            if (in_array($operation, ['list', 'sources'], true) && $result['data'] === []) {
                (new SandIamContextAuthenticator())->authenticate($request, $action);
            }
            if ($operation === 'list') {
                $result['workload_identity'] = [
                    'organization_id' => $context->organizationId,
                    'application_id' => $context->applicationId,
                    'environment_id' => (string) $context->environmentId,
                    'workload_client_id' => $context->workloadClientId,
                ];
            }
            $revalidateFacts();
            return ApiResponder::success($result + ['scope' => ['type' => $consumer->scopeType, 'ref' => $consumer->scopeRef], 'scope_source' => 'consumer_declared'], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    /** @return array{0:Closure,1:Closure} Request-local authorizations retain exact resource facts and do not consume quota twice. */
    private function resourceAuthorizers(Request $request, IdentityContext $context, string $requestId): array
    {
        $authorizer = new HostSandIamInvocationAuthorizer();
        $receipts = [];
        $signedContext = trim(substr(trim((string) $request->header('authorization', '')), 7));
        $revalidate = static function (array $receipt) use ($authorizer, $request, $context, $requestId, $signedContext): array {
            return $authorizer->revalidate($receipt['authorization_id'], $signedContext, $context,
                $receipt['action'], $receipt['resolver'], $receipt['resource_key'], $request->getRemoteIp(), $requestId);
        };
        $authorize = static function (string $action, string $resolver, string $resourceKey, string $operationId)
            use ($authorizer, $request, $context, $requestId, &$receipts, $revalidate): array {
            $key = $action . "\0" . $resolver . "\0" . $resourceKey;
            if (isset($receipts[$key])) return $revalidate($receipts[$key]);
            $decision = $authorizer->authorize($request, $context, $action, $resolver, $resourceKey, $operationId, $requestId);
            $receipts[$key] = ['authorization_id' => $decision['authorization_id'], 'action' => $action,
                'resolver' => $resolver, 'resource_key' => $resourceKey];
            return $decision;
        };
        $verifyAll = static function () use (&$receipts, $revalidate): void {
            foreach ($receipts as $receipt) $revalidate($receipt);
        };
        return [$authorize, $verifyAll];
    }

    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill identifier is invalid');
        return (int) $id;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill text field is invalid');
        return $value;
    }

    private function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill structured field must be an object');
        return $value;
    }
}
