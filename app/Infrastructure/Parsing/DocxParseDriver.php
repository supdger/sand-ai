<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

final class DocxParseDriver implements ParseDriver
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const REL_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const MC_NAMESPACE = 'http://schemas.openxmlformats.org/markup-compatibility/2006';
    private const MAX_TABLE_COLUMNS = 16384;
    private const BLOCK_CONTAINERS = ['sdtContent', 'customXml', 'ins', 'moveTo', 'smartTag'];

    public function code(): string { return 'docx/zip-xml'; }
    public function version(): string { return '1.5'; }

    public function parse(ParseInput $input): ParseResult
    {
        $zip = new ZipArchive();
        if ($zip->open($input->path) !== true) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX archive could not be opened');
        }
        try {
            // Bound expanded XML as well as the uploaded package, without increasing the configured allowance.
            $remainingBytes = max(1, (int) config('sand_ai_file.max_bytes', 50 * 1024 * 1024));
            $documents = [];
            $document = $this->xml($zip, 'word/document.xml', $remainingBytes, $documents);
            $xpath = $this->xpath($document);
            $body = $xpath->query('./w:body', $this->root($document, 'document'))?->item(0);
            if (!$body instanceof DOMElement) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX document body was not found');
            $relationships = $this->relationships($zip, $remainingBytes, $documents);
            $styles = $this->styles($zip, $relationships, $remainingBytes, $documents);
            $this->visibleDocument($body, $xpath, $styles);
            $blocks = [];
            $paragraphIndex = 0; $tableIndex = 0; $textboxIndex = 0;
            $this->assertExtractable($body, $xpath);
            $this->appendBody($body, $xpath, $blocks, $paragraphIndex, $tableIndex, $textboxIndex, []);
            $noteReferences = $this->noteReferences($body, $xpath);
            $evenHeaders = false;
            $settings = $this->relationshipByType($relationships, 'settings', false);
            if ($settings !== null) {
                $settingsDocument = $this->xml($zip, $this->target($settings), $remainingBytes, $documents);
                $setting = $this->xpath($settingsDocument)->query('./w:evenAndOddHeaders', $this->root($settingsDocument, 'settings'))?->item(0);
                $evenHeaders = $setting instanceof DOMElement && $this->enabled($setting);
            }
            $seenParts = [];
            $partIndices = ['header' => 0, 'footer' => 0];
            $inheritedReferences = [];
            foreach ($xpath->query('.//w:sectPr', $body) ?: [] as $section) {
                if (!$section instanceof DOMElement) continue;
                foreach ($xpath->query('./w:headerReference | ./w:footerReference', $section) ?: [] as $reference) {
                    if (!$reference instanceof DOMElement) continue;
                    $type = $reference->getAttributeNS(self::WORD_NAMESPACE, 'type');
                    if (!in_array($type, ['default', 'first', 'even'], true)) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX header or footer reference type is invalid');
                    $inheritedReferences[$reference->localName . ':' . $type] = $reference;
                }
                // A later section can enable a first/even story inherited from an earlier section.
                foreach ($inheritedReferences as $reference) {
                if (!$reference instanceof DOMElement) continue;
                $kind = $reference->localName === 'headerReference' ? 'header' : 'footer';
                $type = $reference->getAttributeNS(self::WORD_NAMESPACE, 'type');
                if (!in_array($type, ['default', 'first', 'even'], true)) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX header or footer reference type is invalid');
                $firstPage = $xpath->query('./w:titlePg', $section)?->item(0);
                if (($type === 'even' && !$evenHeaders) || ($type === 'first' && (!($firstPage instanceof DOMElement) || !$this->enabled($firstPage)))) continue;
                $relationship = $relationships[$reference->getAttributeNS(self::REL_NAMESPACE, 'id')] ?? null;
                if (!is_array($relationship) || $relationship['kind'] !== $kind) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced header or footer is unavailable');
                $path = $this->target($relationship);
                if (isset($seenParts[$kind . ':' . $path])) continue;
                $seenParts[$kind . ':' . $path] = true;
                $partDocument = $this->xml($zip, $path, $remainingBytes, $documents);
                $part = $this->root($partDocument, $kind === 'header' ? 'hdr' : 'ftr');
                $partXpath = $this->xpath($partDocument);
                $this->visibleDocument($part, $partXpath, $styles);
                $this->assertExtractable($part, $partXpath);
                $paragraphIndex = 0; $tableIndex = 0; $textboxIndex = 0;
                $this->appendBody($part, $partXpath, $blocks, $paragraphIndex, $tableIndex, $textboxIndex, ['document_part' => $kind, 'part_index' => ++$partIndices[$kind]]);
                array_push($noteReferences, ...$this->noteReferences($part, $partXpath));
                }
            }
            // References are collected only from selected visible stories. Separators and unused notes are not sources.
            $seenNotes = [];
            for ($index = 0; $index < count($noteReferences); $index++) {
                $reference = $noteReferences[$index];
                $key = $reference['kind'] . ':' . $reference['id'];
                if (isset($seenNotes[$key])) continue;
                $seenNotes[$key] = true;
                $relationship = $this->relationshipByType($relationships, $reference['kind'] . 's', true);
                if ($relationship === null) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX note relationship is unavailable');
                $noteDocument = $this->xml($zip, $this->target($relationship), $remainingBytes, $documents);
                $noteXpath = $this->xpath($noteDocument);
                $notes = $noteXpath->query('./w:' . $reference['kind'] . '[@w:id="' . $reference['id'] . '"]', $this->root($noteDocument, $reference['kind'] . 's'));
                if ($notes === false || $notes->length !== 1 || !($notes->item(0) instanceof DOMElement)) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced note is unavailable or duplicated');
                $note = $notes->item(0);
                $noteType = $note->getAttributeNS(self::WORD_NAMESPACE, 'type');
                if ($noteType !== '' && $noteType !== 'normal') throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX visible reference does not identify a normal note');
                $this->visibleDocument($note, $noteXpath, $styles);
                $this->assertExtractable($note, $noteXpath);
                $paragraphIndex = 0; $tableIndex = 0; $textboxIndex = 0;
                $this->appendBody($note, $noteXpath, $blocks, $paragraphIndex, $tableIndex, $textboxIndex, ['document_part' => $reference['kind'], 'reference_id' => $reference['id']]);
                array_push($noteReferences, ...$this->noteReferences($note, $noteXpath));
            }
            if ($blocks === []) throw new ParseException('SAND_AI_PARSE_EMPTY', 'The DOCX file contains no extractable visible text');
            return new ParseResult($this->code(), $this->version(), $blocks);
        } finally {
            $zip->close();
        }
    }

    /** @param array<string,DOMDocument> $documents */
    private function xml(ZipArchive $zip, string $path, int &$remainingBytes, array &$documents): DOMDocument
    {
        if (isset($documents[$path])) return $documents[$path];
        $stat = $zip->statName($path);
        $size = is_array($stat) ? ($stat['size'] ?? null) : null;
        if (!is_int($size) || $size < 1 || $size > $remainingBytes) throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'DOCX referenced XML is missing or exceeds the configured file text limit');
        $xml = $zip->getFromName($path);
        if (!is_string($xml) || strlen($xml) !== $size || stripos($xml, '<!DOCTYPE') !== false) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced XML is invalid');
        $remainingBytes -= $size;
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced XML could not be parsed');
            if ($document->doctype !== null) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced XML must not declare a document type');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $documents[$path] = $document;
        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NAMESPACE);
        $xpath->registerNamespace('mc', self::MC_NAMESPACE);
        return $xpath;
    }

    private function root(DOMDocument $document, string $name): DOMElement
    {
        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->namespaceURI !== self::WORD_NAMESPACE || $root->localName !== $name) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced part has an invalid document type');
        return $root;
    }

    /** @param array<string,DOMDocument> $documents @return array<string,array{kind:string,target:string,external:bool}> */
    private function relationships(ZipArchive $zip, int &$remainingBytes, array &$documents): array
    {
        $path = 'word/_rels/document.xml.rels';
        if ($zip->locateName($path) === false) return [];
        $root = $this->xml($zip, $path, $remainingBytes, $documents)->documentElement;
        if (!$root instanceof DOMElement || $root->namespaceURI !== self::PACKAGE_NAMESPACE || $root->localName !== 'Relationships') throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX document relationships are invalid');
        $relationships = [];
        foreach ($root->childNodes as $element) {
            if (!$element instanceof DOMElement || $element->namespaceURI !== self::PACKAGE_NAMESPACE || $element->localName !== 'Relationship') continue;
            $id = $element->getAttribute('Id');
            if ($id === '' || isset($relationships[$id])) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX relationship identity is missing or duplicated');
            $type = $element->getAttribute('Type');
            $mode = $element->getAttribute('TargetMode');
            $relationships[$id] = ['kind' => str_starts_with($type, self::REL_NAMESPACE . '/') ? substr($type, strlen(self::REL_NAMESPACE) + 1) : '', 'target' => $element->getAttribute('Target'), 'external' => $mode !== '' && $mode !== 'Internal'];
        }
        return $relationships;
    }

    /** @param array<string,array{kind:string,target:string,external:bool}> $relationships @return array{kind:string,target:string,external:bool}|null */
    private function relationshipByType(array $relationships, string $kind, bool $required): ?array
    {
        $found = null;
        foreach ($relationships as $relationship) {
            if ($relationship['kind'] !== $kind) continue;
            if ($found !== null) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced part relationship is duplicated');
            $found = $relationship;
        }
        if ($required && $found === null) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced part relationship was not found');
        return $found;
    }

    /** @param array{kind:string,target:string,external:bool} $relationship */
    private function target(array $relationship): string
    {
        $target = rawurldecode($relationship['target']);
        if ($relationship['external'] || $target === '' || str_contains($target, '\\') || preg_match('/[\x00-\x20\x7f?#:]/', $target) === 1) throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'External or invalid DOCX referenced parts are not supported');
        $segments = str_starts_with($target, '/') ? [] : ['word'];
        foreach (explode('/', ltrim($target, '/')) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') {
                if ($segments === []) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced part leaves the private package');
                array_pop($segments);
            } else $segments[] = $segment;
        }
        $path = implode('/', $segments);
        if (preg_match('~^word/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.xml$~D', $path) !== 1) throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'DOCX referenced part path is unsupported');
        return $path;
    }

    /** Select one rendering branch; deleted and hidden content never becomes evidence. */
    /** @param array{defaults:array<string,bool>,paragraph_default:string,character_default:string,entries:array<string,array{based_on:string,properties:array<string,bool>}>} $styles */
    private function visibleDocument(DOMElement $root, DOMXPath $xpath, array $styles): void
    {
        // Remove non-visible branches first: their unsupported markup cannot block a visible story.
        foreach ($xpath->query('.//w:del | .//w:moveFrom | .//w:sdtPr | .//w:sdtEndPr | .//w:customXmlPr | .//w:sectPrChange | .//w:pPrChange | .//w:rPrChange | .//w:tblPrChange | .//w:trPrChange | .//w:tcPrChange', $root) ?: [] as $removed) $removed->parentNode?->removeChild($removed);
        foreach ($xpath->query('.//w:r', $root) ?: [] as $run) {
            if (!$run instanceof DOMElement) continue;
            $paragraph = $run->parentNode;
            while ($paragraph !== null && !($paragraph instanceof DOMElement && $paragraph->namespaceURI === self::WORD_NAMESPACE && $paragraph->localName === 'p')) $paragraph = $paragraph->parentNode;
            $paragraphStyle = $paragraph instanceof DOMElement ? $xpath->query('./w:pPr/w:pStyle', $paragraph)?->item(0) : null;
            $runStyle = $xpath->query('./w:rPr/w:rStyle', $run)?->item(0);
            $visibility = $styles['defaults'];
            foreach ([
                $paragraphStyle instanceof DOMElement ? $paragraphStyle->getAttributeNS(self::WORD_NAMESPACE, 'val') : $styles['paragraph_default'],
                $runStyle instanceof DOMElement ? $runStyle->getAttributeNS(self::WORD_NAMESPACE, 'val') : $styles['character_default'],
            ] as $styleId) {
                foreach ($this->styleProperties($styles['entries'], $styleId) as $layer) {
                    // Word style vanish properties toggle inherited visibility; direct run values override it.
                    foreach ($layer as $field => $value) if ($value) $visibility[$field] = !($visibility[$field] ?? false);
                }
            }
            $visibility = array_replace($visibility, $this->visibilityProperties($run, $xpath, './w:rPr'));
            if (($visibility['vanish'] ?? false) || ($visibility['webHidden'] ?? false)) $run->parentNode?->removeChild($run);
        }
        foreach ($xpath->query('.//*[@style]', $root) ?: [] as $element) {
            if ($element instanceof DOMElement && preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:;|$)/i', $element->getAttribute('style')) === 1) $element->parentNode?->removeChild($element);
        }
        while (($alternates = $xpath->query('.//mc:AlternateContent', $root)) !== false && $alternates->length > 0) {
            $alternate = $alternates->item(0);
            if (!$alternate instanceof DOMElement || $alternate->parentNode === null) break;
            $chosen = null; $fallback = null;
            foreach ($alternate->childNodes as $branch) {
                if (!$branch instanceof DOMElement || $branch->namespaceURI !== self::MC_NAMESPACE) continue;
                if ($branch->localName === 'Fallback') $fallback = $branch;
                if ($branch->localName !== 'Choice' || $chosen !== null) continue;
                $requires = preg_split('/\s+/', trim($branch->getAttribute('Requires'))) ?: [];
                $supported = $requires !== [] && $requires !== [''];
                foreach ($requires as $prefix) {
                    if (!in_array($branch->lookupNamespaceURI($prefix), [self::WORD_NAMESPACE, 'urn:schemas-microsoft-com:vml', 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape', 'http://schemas.microsoft.com/office/word/2010/wordprocessingGroup'], true)) $supported = false;
                }
                if ($supported) $chosen = $branch;
            }
            $chosen ??= $fallback;
            if (!$chosen instanceof DOMElement) throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'DOCX visible alternate content cannot be selected');
            foreach ($chosen->childNodes as $child) $alternate->parentNode->insertBefore($child->cloneNode(true), $alternate);
            $alternate->parentNode->removeChild($alternate);
        }
    }

    private function enabled(DOMElement $element): bool
    {
        return !in_array(strtolower($element->getAttributeNS(self::WORD_NAMESPACE, 'val')), ['false', '0', 'off'], true);
    }

    /**
     * @param array<string,array{kind:string,target:string,external:bool}> $relationships
     * @param array<string,DOMDocument> $documents
     * @return array{defaults:array<string,bool>,paragraph_default:string,character_default:string,entries:array<string,array{based_on:string,properties:array<string,bool>}>}
     */
    private function styles(ZipArchive $zip, array $relationships, int &$remainingBytes, array &$documents): array
    {
        $result = ['defaults' => [], 'paragraph_default' => '', 'character_default' => '', 'entries' => []];
        $relationship = $this->relationshipByType($relationships, 'styles', false);
        if ($relationship === null) return $result;
        $document = $this->xml($zip, $this->target($relationship), $remainingBytes, $documents);
        $root = $this->root($document, 'styles');
        $xpath = $this->xpath($document);
        $result['defaults'] = $this->visibilityProperties($root, $xpath, './w:docDefaults/w:rPrDefault/w:rPr');
        foreach ($xpath->query('./w:style', $root) ?: [] as $style) {
            if (!$style instanceof DOMElement) continue;
            $id = $style->getAttributeNS(self::WORD_NAMESPACE, 'styleId');
            if ($id === '' || isset($result['entries'][$id])) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX style identity is invalid');
            $based = $xpath->query('./w:basedOn', $style)?->item(0);
            $result['entries'][$id] = [
                'based_on' => $based instanceof DOMElement ? $based->getAttributeNS(self::WORD_NAMESPACE, 'val') : '',
                'properties' => $this->visibilityProperties($style, $xpath, './w:rPr'),
            ];
            $type = $style->getAttributeNS(self::WORD_NAMESPACE, 'type');
            $default = strtolower($style->getAttributeNS(self::WORD_NAMESPACE, 'default'));
            if (in_array($type, ['paragraph', 'character'], true) && in_array($default, ['true', '1', 'on'], true)) {
                if ($result[$type . '_default'] !== '') throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX default style is duplicated');
                $result[$type . '_default'] = $id;
            }
        }
        return $result;
    }

    /** @return array<string,bool> */
    private function visibilityProperties(DOMElement $element, DOMXPath $xpath, string $path): array
    {
        $properties = [];
        foreach (['vanish', 'webHidden'] as $field) {
            $property = $xpath->query($path . '/w:' . $field, $element)?->item(0);
            if ($property instanceof DOMElement) $properties[$field] = $this->enabled($property);
        }
        return $properties;
    }

    /** @param array<string,array{based_on:string,properties:array<string,bool>}> $styles @return list<array<string,bool>> */
    private function styleProperties(array $styles, string $id): array
    {
        $chain = [];
        $seen = [];
        while ($id !== '') {
            if (isset($seen[$id])) throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'DOCX visible style inheritance is cyclic');
            if (!isset($styles[$id])) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX referenced text style is unavailable');
            $seen[$id] = true;
            $chain[] = $styles[$id]['properties'];
            $id = $styles[$id]['based_on'];
        }
        return array_reverse($chain);
    }

    private function assertExtractable(DOMElement $root, DOMXPath $xpath): void
    {
        if ($xpath->query('.//w:altChunk | .//w:subDoc', $root)?->length > 0) throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'DOCX external or embedded text requires a verified document export');
        foreach ($xpath->query('.//w:drawing | .//w:pict | .//w:object | .//w:sym | .//*[namespace-uri()="http://schemas.openxmlformats.org/officeDocument/2006/math"]', $root) ?: [] as $element) {
            if (!$element instanceof DOMElement) continue;
            $textboxes = $xpath->query('.//w:txbxContent', $element);
            $images = $xpath->query('.//*[local-name()="blip" or local-name()="imagedata" or local-name()="OLEObject"]', $element);
            $unsupportedShapes = $xpath->query('.//*[local-name()="graphicData"'
                . ' and not(@uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
                . ' or @uri="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup")]'
                . ' | .//*[local-name()="wsp" and not(.//w:txbxContent)]'
                . ' | .//*[namespace-uri()="urn:schemas-microsoft-com:vml"'
                . ' and (local-name()="shape" or local-name()="rect" or local-name()="oval"'
                . ' or local-name()="line" or local-name()="polyline" or local-name()="roundrect"'
                . ' or local-name()="arc" or local-name()="curve") and not(.//w:txbxContent)]', $element);
            if ($element->localName === 'object' || $element->localName === 'sym' || $element->namespaceURI !== self::WORD_NAMESPACE
                || $textboxes === false || $textboxes->length === 0 || ($images !== false && $images->length > 0)
                || ($unsupportedShapes !== false && $unsupportedShapes->length > 0)) {
                throw new ParseException('SAND_AI_OCR_REQUIRED', 'DOCX has visible images, symbols or objects beyond native text; export a private PDF retaining visual content and use the PDF/OCR route');
            }
        }
    }

    /** @return list<array{kind:string,id:int}> */
    private function noteReferences(DOMElement $root, DOMXPath $xpath): array
    {
        $references = [];
        foreach ($xpath->query('.//w:footnoteReference | .//w:endnoteReference', $root) ?: [] as $element) {
            if (!$element instanceof DOMElement) continue;
            $id = $element->getAttributeNS(self::WORD_NAMESPACE, 'id');
            if (preg_match('/\A[0-9]{1,9}\z/D', $id) !== 1) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX visible note reference is invalid');
            $references[] = ['kind' => $element->localName === 'footnoteReference' ? 'footnote' : 'endnote', 'id' => (int) $id];
        }
        return $references;
    }

    /** @param list<ParsedSourceBlock> $blocks @param array<string,scalar> $scope */
    private function appendBody(DOMElement $body, DOMXPath $xpath, array &$blocks, int &$paragraphIndex, int &$tableIndex, int &$textboxIndex, array $scope): void
    {
        foreach ($this->elements($body, ['p', 'tbl']) as $element) {
            if ($element->localName === 'tbl') { $this->appendTable($element, $xpath, $blocks, $tableIndex, $textboxIndex, $scope); continue; }
            $paragraphIndex++;
            $content = $this->text($xpath, $element);
            if ($content !== '') $blocks[] = new ParsedSourceBlock(count($blocks) + 1, 'paragraph', $scope + ['paragraph' => $paragraphIndex], $content);
            $this->appendTextboxes($element, $xpath, $blocks, $textboxIndex, array_replace($scope, ['anchor_paragraph' => $paragraphIndex]));
        }
    }

    /** @param list<ParsedSourceBlock> $blocks @param array<string,scalar> $scope */
    private function appendTable(DOMElement $table, DOMXPath $xpath, array &$blocks, int &$tableIndex, int &$textboxIndex, array $scope): void
    {
        $currentTableIndex = ++$tableIndex; $rowIndex = 0;
        foreach ($this->elements($table, ['tr']) as $row) {
            $rowIndex++; $cells = [];
            $columnIndex = $this->gridCount($xpath, $row, './w:trPr/w:gridBefore', 0, 0);
            foreach ($this->elements($row, ['tc']) as $cell) {
                $span = $this->gridCount($xpath, $cell, './w:tcPr/w:gridSpan', 1, 1);
                if ($columnIndex + $span > self::MAX_TABLE_COLUMNS) throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'DOCX table exceeds the supported grid width');
                $address = $this->column($columnIndex) . $rowIndex; $cellParagraph = 0;
                foreach ($this->elements($cell, ['p', 'tbl']) as $element) {
                    if ($element->localName === 'tbl') { $this->appendRow($blocks, $currentTableIndex, $rowIndex, $cells, $scope); $cells = []; $this->appendTable($element, $xpath, $blocks, $tableIndex, $textboxIndex, $scope); continue; }
                    $cellParagraph++;
                    $content = $this->text($xpath, $element);
                    if ($content !== '') $cells[$address] = isset($cells[$address]) ? $cells[$address] . "\n" . $content : $content;
                    if ($xpath->query('.//w:txbxContent', $element)?->length > 0) {
                        $this->appendRow($blocks, $currentTableIndex, $rowIndex, $cells, $scope); $cells = [];
                        $this->appendTextboxes($element, $xpath, $blocks, $textboxIndex, array_replace($scope, ['anchor_table' => $currentTableIndex, 'anchor_row' => $rowIndex, 'anchor_cell' => $address, 'anchor_paragraph' => $cellParagraph]));
                    }
                }
                $columnIndex += $span;
            }
            $this->appendRow($blocks, $currentTableIndex, $rowIndex, $cells, $scope);
        }
    }

    /** @param list<ParsedSourceBlock> $blocks @param array<string,string> $cells @param array<string,scalar> $scope */
    private function appendRow(array &$blocks, int $tableIndex, int $rowIndex, array $cells, array $scope): void
    {
        if ($cells !== []) $blocks[] = new ParsedSourceBlock(count($blocks) + 1, 'table_row', $scope + ['table' => $tableIndex, 'row' => $rowIndex], json_encode($cells, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param list<ParsedSourceBlock> $blocks @param array<string,scalar> $scope */
    private function appendTextboxes(DOMElement $paragraph, DOMXPath $xpath, array &$blocks, int &$textboxIndex, array $scope): void
    {
        foreach ($xpath->query('.//w:txbxContent', $paragraph) ?: [] as $box) {
            if (!$box instanceof DOMElement) continue;
            $parent = $box->parentNode;
            while ($parent !== null && !($parent instanceof DOMElement && $parent->namespaceURI === self::WORD_NAMESPACE && $parent->localName === 'p')) $parent = $parent->parentNode;
            if ($parent !== $paragraph) continue; // A nested box belongs to its own containing paragraph.
            $paragraphIndex = 0; $tableIndex = 0;
            $this->appendBody($box, $xpath, $blocks, $paragraphIndex, $tableIndex, $textboxIndex, array_replace($scope, ['textbox' => ++$textboxIndex]));
        }
    }

    /** @param list<string> $names @return iterable<DOMElement> */
    private function elements(DOMElement $parent, array $names): iterable
    {
        foreach ($parent->childNodes as $element) {
            if (!$element instanceof DOMElement || $element->namespaceURI !== self::WORD_NAMESPACE) continue;
            if (in_array($element->localName, $names, true)) yield $element;
            elseif ($element->localName === 'sdt') {
                foreach ($element->childNodes as $content) if ($content instanceof DOMElement && $content->namespaceURI === self::WORD_NAMESPACE && $content->localName === 'sdtContent') yield from $this->elements($content, $names);
            } elseif (in_array($element->localName, self::BLOCK_CONTAINERS, true)) yield from $this->elements($element, $names);
        }
    }

    private function gridCount(DOMXPath $xpath, DOMElement $element, string $path, int $default, int $minimum): int
    {
        $node = $xpath->query($path, $element)?->item(0);
        if ($node === null) return $default;
        if (!$node instanceof DOMElement) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX table grid is invalid');
        $value = $node->getAttributeNS(self::WORD_NAMESPACE, 'val');
        if (preg_match('/\A[0-9]{1,5}\z/D', $value) !== 1) throw new ParseException('SAND_AI_PARSE_FAILED', 'DOCX table grid is invalid');
        $count = (int) $value;
        if ($count < $minimum || $count > self::MAX_TABLE_COLUMNS) throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'DOCX table exceeds the supported grid width');
        return $count;
    }

    private function text(DOMXPath $xpath, DOMElement $node): string
    {
        $content = '';
        foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr | .//w:noBreakHyphen | .//w:softHyphen', $node) ?: [] as $part) {
            $parent = $part->parentNode; $insideBox = false;
            while ($parent !== null && $parent !== $node) {
                if ($parent instanceof DOMElement && $parent->namespaceURI === self::WORD_NAMESPACE && $parent->localName === 'txbxContent') { $insideBox = true; break; }
                $parent = $parent->parentNode;
            }
            if ($insideBox) continue;
            $content .= match ($part->localName) { 'tab' => "\t", 'br', 'cr' => "\n", 'noBreakHyphen' => "\u{2011}", 'softHyphen' => "\u{00AD}", default => $part->textContent };
        }
        return trim($content);
    }

    private function column(int $index): string
    {
        $label = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) $label = chr(65 + ($index - 1) % 26) . $label;
        return $label;
    }
}
