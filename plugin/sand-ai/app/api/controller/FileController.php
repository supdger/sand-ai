<?php

declare(strict_types=1);

namespace plugin\SandAi\app\api\controller;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\api\support\ApiResponder;
use plugin\SandAi\app\api\support\IdentityContextProblem;
use plugin\SandAi\app\contract\IdentityContextException;
use plugin\SandAi\app\contract\IdentityContextProvider;
use plugin\SandAi\app\domain\file\FileGateway;
use plugin\SandAi\app\domain\file\FileParseGateway;
use support\Request;
use support\Response;
use Webman\Http\UploadFile;

final class FileController
{
    public function __construct(private readonly IdentityContextProvider $identityContexts)
    {
    }

    public function upload(Request $request): Response
    {
        return $this->respond($request, function () use ($request): array {
            $upload = $request->file('file');
            if (!$upload instanceof UploadFile) {
                throw new ApiProblem('SAND_AI_FILE_INVALID', 'A single multipart file field named file is required');
            }

            return (new FileGateway())->upload($this->identityContexts->requireContext($request, 'sand_ai.file.write')->requireAction('sand_ai.file.write'), $upload);
        });
    }

    public function read(Request $request, string $fileId): Response
    {
        return $this->respond($request, fn (): array => (new FileGateway())->read(
            $this->identityContexts->requireContext($request, 'sand_ai.file.read')->requireAction('sand_ai.file.read'),
            $this->id($fileId),
        ));
    }

    public function destroy(Request $request, string $fileId): Response
    {
        return $this->respond($request, fn (): array => (new FileGateway())->destroy(
            $this->identityContexts->requireContext($request, 'sand_ai.file.write')->requireAction('sand_ai.file.write'),
            $this->id($fileId),
        ));
    }

    public function parse(Request $request, string $fileId): Response
    {
        return $this->respond($request, fn (): array => (new FileParseGateway())->parse(
            $this->identityContexts->requireContext($request, 'sand_ai.document_parse')->requireAction('sand_ai.document_parse'),
            $this->id($fileId),
            $this->requestId($request),
        ));
    }

    private function id(string $fileId): int
    {
        $id = filter_var($fileId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid file id is required');
        }

        return $id;
    }

    /** @param callable(): array<string, mixed> $action */
    private function respond(Request $request, callable $action): Response
    {
        $requestId = $this->requestId($request);
        try {
            return ApiResponder::success($action(), $requestId);
        } catch (IdentityContextException $exception) {
            return ApiResponder::error(IdentityContextProblem::from($exception), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    private function requestId(Request $request): string
    {
        return trim((string) $request->header('x-request-id', '')) ?: 'req_' . bin2hex(random_bytes(12));
    }
}
