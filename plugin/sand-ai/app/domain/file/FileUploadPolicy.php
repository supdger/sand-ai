<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\file;

use plugin\SandAi\app\api\support\ApiProblem;
use Webman\Http\UploadFile;

/**
 * Validates only the uploaded temporary object. It deliberately has no
 * application, credential, or storage-provider dependency: SandIAM performs
 * caller authorization and the plugin storage adapter owns private persistence.
 */
final class FileUploadPolicy
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('plugin.sand-ai.file', []);
    }

    /**
     * @return array{
     *   original_name: string,
     *   extension: string,
     *   media_type: string,
     *   size_bytes: int,
     *   sha256: string
     * }
     */
    public function inspect(UploadFile $upload): array
    {
        if (!$upload->isValid() || !is_file($upload->getPathname())) {
            throw new ApiProblem('SAND_AI_FILE_INVALID', 'A valid uploaded file is required');
        }

        $originalName = $this->originalName((string) $upload->getUploadName());
        $extension = strtolower($upload->getUploadExtension());
        $allowed = (array) ($this->config['allowed'] ?? []);
        if ($extension === '' || !array_key_exists($extension, $allowed)) {
            throw new ApiProblem('SAND_AI_FILE_INVALID', 'The uploaded file extension is not supported');
        }

        $size = $upload->getSize();
        $maxBytes = max(1, (int) ($this->config['max_bytes'] ?? 50 * 1024 * 1024));
        if ($size <= 0 || $size > $maxBytes) {
            throw new ApiProblem('SAND_AI_FILE_INVALID', 'The uploaded file size is not allowed');
        }

        $mediaType = $this->detectMime($upload->getPathname());
        $allowedTypes = array_values(array_filter((array) $allowed[$extension], 'is_string'));
        if ($mediaType === '' || !in_array($mediaType, $allowedTypes, true)) {
            throw new ApiProblem('SAND_AI_FILE_INVALID', 'The uploaded file MIME type does not match its extension');
        }

        $sha256 = hash_file('sha256', $upload->getPathname());
        if (!is_string($sha256) || $sha256 === '') {
            throw new ApiProblem('SAND_AI_FILE_INVALID', 'The uploaded file hash could not be calculated');
        }

        return [
            'original_name' => $originalName,
            'extension' => $extension,
            'media_type' => $mediaType,
            'size_bytes' => $size,
            'sha256' => $sha256,
        ];
    }

    private function originalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = trim(str_replace("\0", '', $name));
        if ($name === '') {
            throw new ApiProblem('SAND_AI_FILE_INVALID', 'The uploaded file name is invalid');
        }

        return mb_strcut($name, 0, 512, 'UTF-8');
    }

    private function detectMime(string $path): string
    {
        if (!function_exists('finfo_open')) {
            throw new ApiProblem('SAND_AI_FILE_INVALID', 'The server cannot inspect uploaded file types');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new ApiProblem('SAND_AI_FILE_INVALID', 'The server cannot inspect uploaded file types');
        }
        try {
            return (string) finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }
    }
}
