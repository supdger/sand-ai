<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

final class XlsxParseDriver implements ParseDriver
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function code(): string
    {
        return 'xlsx/zip-xml';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function parse(ParseInput $input): ParseResult
    {
        $zip = new ZipArchive();
        if ($zip->open($input->path) !== true) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'XLSX archive could not be opened');
        }
        try {
            if ($zip->numFiles > 2000 || $zip->locateName('[Content_Types].xml') === false) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'XLSX package structure is invalid');
            }
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relationships = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $sharedStrings = $zip->locateName('xl/sharedStrings.xml') === false
                ? []
                : $this->sharedStrings($this->xml($zip, 'xl/sharedStrings.xml'));
            $targets = $this->worksheetTargets($relationships);
            $xpath = $this->xpath($workbook);
            $blocks = [];
            $sheetCount = 0;
            $sheetNames = [];
            $rowCount = 0;
            $cellCount = 0;
            foreach ($xpath->query('//main:sheets/main:sheet') ?: [] as $sheet) {
                if (!$sheet instanceof DOMElement || $sheet->getAttribute('state') !== '' && $sheet->getAttribute('state') !== 'visible') {
                    continue;
                }
                $sheetCount++;
                if ($sheetCount > 32) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'The XLSX workbook has too many visible sheets');
                }
                $name = trim($sheet->getAttribute('name'));
                $relationshipId = $sheet->getAttributeNS(self::REL_NS, 'id');
                if ($name === '' || isset($sheetNames[$name]) || !isset($targets[$relationshipId])) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX worksheet relationship is invalid');
                }
                $sheetNames[$name] = true;
                $document = $this->xml($zip, $targets[$relationshipId]);
                $sheetXpath = $this->xpath($document);
                $rows = $sheetXpath->query('//main:sheetData/main:row');
                foreach ($rows ?: [] as $rowNode) {
                    if (!$rowNode instanceof DOMElement) {
                        continue;
                    }
                    $rowCount++;
                    if ($rowCount > 10000) {
                        throw new ParseException('SAND_AI_PARSE_FAILED', 'The XLSX workbook has too many rows');
                    }
                    $row = $rowNode->getAttribute('r');
                    if (!ctype_digit($row) || (int) $row < 1 || (int) $row > 1048576) {
                        throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX row has no valid location');
                    }
                    $values = [];
                    foreach ($sheetXpath->query('./main:c', $rowNode) ?: [] as $cell) {
                        if (!$cell instanceof DOMElement) {
                            continue;
                        }
                        $cellCount++;
                        if ($cellCount > 50000) {
                            throw new ParseException('SAND_AI_PARSE_FAILED', 'The XLSX workbook has too many cells');
                        }
                        $coordinate = $cell->getAttribute('r');
                        if (preg_match('/^[A-Z]{1,3}[1-9][0-9]{0,6}$/D', $coordinate) !== 1
                            || preg_replace('/^[A-Z]+/', '', $coordinate) !== $row) {
                            throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX cell does not match its source row');
                        }
                        if ($sheetXpath->query('./main:f', $cell)?->length > 0 || $cell->getAttribute('t') === 'e') {
                            throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'XLSX formulas and error cells require a verified value export');
                        }
                        $value = $this->cellValue($sheetXpath, $cell, $sharedStrings);
                        if ($value !== '') {
                            $values[$coordinate] = $value;
                        }
                    }
                    if ($values !== []) {
                        $blocks[] = new ParsedSourceBlock(
                            count($blocks) + 1,
                            'table_row',
                            ['sheet' => $name, 'row' => (int) $row],
                            json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        );
                    }
                }
            }
        } finally {
            $zip->close();
        }

        if ($blocks === []) {
            throw new ParseException('SAND_AI_PARSE_EMPTY', 'The XLSX workbook contains no extractable cells');
        }
        return new ParseResult($this->code(), $this->version(), $blocks);
    }

    /** @return array<string,string> */
    private function worksheetTargets(DOMDocument $relationships): array
    {
        $targets = [];
        $xpath = new DOMXPath($relationships);
        $xpath->registerNamespace('pkg', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($xpath->query('/pkg:Relationships/pkg:Relationship') ?: [] as $relationship) {
            if (!$relationship instanceof DOMElement || !str_ends_with($relationship->getAttribute('Type'), '/worksheet')) {
                continue;
            }
            if ($relationship->getAttribute('TargetMode') !== '') {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'External XLSX worksheets are not allowed');
            }
            $target = ltrim($relationship->getAttribute('Target'), '/');
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . $target;
            }
            if (preg_match('~^xl/worksheets/[A-Za-z0-9_-]+\.xml$~D', $target) !== 1) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX worksheet path is invalid');
            }
            $id = $relationship->getAttribute('Id');
            if ($id === '' || isset($targets[$id])) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX worksheet relationship is duplicated');
            }
            $targets[$id] = $target;
        }
        return $targets;
    }

    /** @return list<string> */
    private function sharedStrings(DOMDocument $document): array
    {
        $xpath = $this->xpath($document);
        $strings = [];
        foreach ($xpath->query('/main:sst/main:si') ?: [] as $item) {
            if (count($strings) >= 50000) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'The XLSX shared string table is too large');
            }
            $strings[] = $this->textNodes($xpath, './main:t | ./main:r/main:t', $item);
        }
        return $strings;
    }

    /** @param list<string> $sharedStrings */
    private function cellValue(DOMXPath $xpath, DOMElement $cell, array $sharedStrings): string
    {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $value = $this->textNodes($xpath, './main:is//main:t', $cell);
        } else {
            $raw = $xpath->query('./main:v', $cell)?->item(0)?->textContent;
            if ($raw === null) {
                return '';
            }
            if ($type === 's') {
                if (!ctype_digit($raw) || !array_key_exists((int) $raw, $sharedStrings)) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX shared string index is invalid');
                }
                $value = $sharedStrings[(int) $raw];
            } elseif ($type === 'b') {
                if ($raw !== '0' && $raw !== '1') {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX boolean cell is invalid');
                }
                $value = $raw === '1' ? 'TRUE' : 'FALSE';
            } elseif (in_array($type, ['', 'n', 'str', 'd'], true)) {
                $value = $raw;
            } else {
                throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'An XLSX cell type is unsupported');
            }
        }
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 4096) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX cell has invalid or excessive text');
        }
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function textNodes(DOMXPath $xpath, string $query, DOMNode $context): string
    {
        $text = '';
        foreach ($xpath->query($query, $context) ?: [] as $node) {
            $text .= $node->textContent;
        }
        return $text;
    }

    private function xml(ZipArchive $zip, string $path): DOMDocument
    {
        $stat = $zip->statName($path);
        if (!is_array($stat) || (int) ($stat['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX XML part is missing or too large');
        }
        $raw = $zip->getFromName($path);
        if (!is_string($raw) || $raw === '' || stripos($raw, '<!DOCTYPE') !== false) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX XML part is invalid');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'An XLSX XML part could not be parsed');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('main', self::MAIN_NS);
        return $xpath;
    }
}
