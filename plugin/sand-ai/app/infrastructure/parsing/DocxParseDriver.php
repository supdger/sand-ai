<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\parsing;

use DOMDocument;
use DOMXPath;
use ZipArchive;

final class DocxParseDriver implements ParseDriver
{
    public function code(): string
    {
        return 'docx/zip-xml';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function parse(ParseInput $input): ParseResult
    {
        $zip = new ZipArchive();
        if ($zip->open($input->path) !== true) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX archive could not be opened');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        if (!is_string($xml) || $xml === '') {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX document XML was not found');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX document XML is invalid');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = $xpath->query('//w:body/w:p');
        $blocks = [];
        if ($paragraphs !== false) {
            foreach ($paragraphs as $paragraphIndex => $paragraph) {
                $texts = $xpath->query('.//w:t', $paragraph);
                $content = '';
                if ($texts !== false) {
                    foreach ($texts as $text) {
                        $content .= $text->textContent;
                    }
                }
                $content = trim($content);
                if ($content !== '') {
                    $blocks[] = new ParsedSourceBlock(
                        count($blocks) + 1,
                        'paragraph',
                        ['paragraph' => $paragraphIndex + 1],
                        $content,
                    );
                }
            }
        }

        if ($blocks === []) {
            throw new ParseException('SAND_AI_PARSE_EMPTY', 'The DOCX file contains no extractable paragraphs');
        }

        return new ParseResult($this->code(), $this->version(), $blocks);
    }
}
