<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

use NeuronAI\RAG\DataLoader\PdfReader;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\XObject\Form;
use Smalot\PdfParser\XObject\Image;
use Symfony\Component\Process\Process;
use Throwable;

final class PdfParseDriver implements ParseDriver
{
    public function code(): string
    {
        return 'pdf/neuron';
    }

    public function version(): string
    {
        return '4.0.2';
    }

    public function parse(ParseInput $input): ParseResult
    {
        $reader = $this->reader();
        try {
            // Page order and image facts remain SandAI's source/OCR boundary.
            // Smalot does not extract the page body in this driver.
            $pages = (new Parser())->parseFile($input->path)->getPages();
        } catch (Throwable $exception) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'PDF file could not be parsed', $exception);
        }

        $blocks = [];
        $requiredSourcePages = [];
        $requiresOcr = false;
        foreach ($pages as $pageIndex => $page) {
            $pageNumber = $pageIndex + 1;
            try {
                // Read each actual page independently: SDK trim() may remove
                // form-feed separators, so whole-file splitting loses blank pages.
                $text = $reader->setOptions(['f ' . $pageNumber, 'l ' . $pageNumber, 'enc UTF-8'])
                    ->read($input->path);
            } catch (Throwable) {
                // SDK process exceptions can contain private paths and native stderr.
                throw new ParseException(
                    'SAND_AI_PARSE_FAILED',
                    'PDF 正文读取失败，请检查文件是否损坏、受密码保护或超过单页读取时限',
                );
            }
            $content = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            $pageRequiresOcr = false;
            foreach ($page->getXObjects() as $xObject) {
                // A page number or header does not prove that image body text
                // was extracted. Forms may also wrap unverified image content.
                if ($xObject instanceof Image || $xObject instanceof Form) {
                    $pageRequiresOcr = true;
                    $requiresOcr = true;
                    break;
                }
            }
            if ($content !== '' || $pageRequiresOcr) {
                $requiredSourcePages[] = $pageNumber;
            }
            if ($content === '') {
                continue;
            }
            $blocks[] = new ParsedSourceBlock(count($blocks) + 1, 'page', ['page' => $pageNumber], $content);
        }

        if ($requiresOcr) {
            throw new ParseException(
                'SAND_AI_OCR_REQUIRED',
                'PDF 图像或嵌入内容的正文尚未确认，需要 OCR；可提取的页码或页眉不能作为完整材料',
                null, $requiredSourcePages, count($pages),
            );
        }
        if ($blocks === []) {
            throw new ParseException('SAND_AI_PARSE_EMPTY', 'The PDF has no extractable source content');
        }

        return new ParseResult($this->code(), $this->version(), $blocks);
    }

    private function reader(): PdfReader
    {
        if (!class_exists(PdfReader::class) || !class_exists(Process::class) || !function_exists('proc_open')) {
            throw new ParseException(
                'SAND_AI_CAPABILITY_UNSUPPORTED',
                'PDF 解析依赖未就绪，请管理员完成 Neuron AI 与 Symfony Process 依赖安装，并允许 PHP 执行本地进程',
            );
        }
        $configuration = config('sand_ai_parse.pdf', []);
        if (!is_array($configuration)) {
            throw new ParseException('SAND_AI_CAPABILITY_UNSUPPORTED', 'PDF 解析配置无效，请管理员检查本地解析配置');
        }
        $binPath = $configuration['pdftotext_path'] ?? '';
        $timeout = $configuration['timeout_seconds'] ?? 60;
        if (!is_string($binPath) || trim($binPath) === '') {
            throw new ParseException(
                'SAND_AI_CAPABILITY_UNSUPPORTED',
                'PDF 文本读取工具未配置，请管理员安装 Poppler 并设置 SAND_AI_PDFTOTEXT_PATH 为 pdftotext 可执行文件路径',
            );
        }
        $absolutePath = str_starts_with($binPath, '/')
            || str_starts_with($binPath, '\\\\')
            || (preg_match('/^[A-Za-z]:/', $binPath) === 1
                && in_array($binPath[2] ?? '', ['/', '\\'], true));
        if (!$absolutePath) {
            throw new ParseException(
                'SAND_AI_CAPABILITY_UNSUPPORTED',
                'PDF 工具路径无效，请管理员将 SAND_AI_PDFTOTEXT_PATH 设置为可执行文件的完整绝对路径',
            );
        }
        if (!is_int($timeout) || $timeout < 1 || $timeout > 300) {
            throw new ParseException(
                'SAND_AI_CAPABILITY_UNSUPPORTED',
                'PDF 单页读取时限无效，请管理员将 SAND_AI_PDF_READ_TIMEOUT_SECONDS 设置为 1 到 300 秒',
            );
        }
        try {
            return (new PdfReader($binPath))->setTimeout($timeout);
        } catch (Throwable) {
            throw new ParseException(
                'SAND_AI_CAPABILITY_UNSUPPORTED',
                'PDF 文本读取工具不可执行，请管理员检查 SAND_AI_PDFTOTEXT_PATH 指向的文件及其执行权限',
            );
        }
    }
}
