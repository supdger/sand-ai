<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
require getenv('SANDPACKAGE_TEST_VENDOR') ?: '/Users/code/project/sand_ai/server/vendor/autoload.php';
foreach (['ParseException', 'ParseDriver', 'ParseInput', 'ParsedSourceBlock', 'ParseResult', 'PdfOcrCoveragePolicy', 'PdfParseDriver'] as $name) {
    $type = 'app\\Infrastructure\\Parsing\\' . $name;
    if (!class_exists($type, false) && !interface_exists($type, false)) {
        require $root . '/app/Infrastructure/Parsing/' . $name . '.php';
    }
}

use app\Infrastructure\Parsing\ParseException;
use app\Infrastructure\Parsing\ParseInput;
use app\Infrastructure\Parsing\ParseResult;
use app\Infrastructure\Parsing\ParsedSourceBlock;
use app\Infrastructure\Parsing\PdfOcrCoveragePolicy;
use app\Infrastructure\Parsing\PdfParseDriver;

/** @param list<string> $objects */
function pdfFixture(array $objects): string
{
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . count($offsets) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size " . count($offsets) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
}

$text = "BT /F1 12 Tf 72 720 Td (Readable material) Tj ET";
$image = "FF0000>";
$imagePlacement = 'q 100 0 0 100 72 600 cm /Im1 Do Q';
$objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 6 0 R >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im1 7 0 R >> >> /Contents 8 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Length ' . strlen($text) . " >>\nstream\n" . $text . "\nendstream",
    '<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /ASCIIHexDecode /Length ' . strlen($image) . " >>\nstream\n" . $image . "\nendstream",
    '<< /Length ' . strlen($imagePlacement) . " >>\nstream\n" . $imagePlacement . "\nendstream",
];
$path = tempnam(sys_get_temp_dir(), 'sandai_pdf_');
if ($path === false) {
    throw new RuntimeException('PDF fixture could not be created');
}

try {
    $driver = new PdfParseDriver();
    file_put_contents($path, pdfFixture($objects));
    try {
        $driver->parse(new ParseInput($path, 'pdf', 'application/pdf'));
        throw new RuntimeException('Mixed text and image PDF was accepted as complete');
    } catch (ParseException $exception) {
        if ($exception->errorCode !== 'SAND_AI_OCR_REQUIRED'
            || $exception->requiredSourcePages !== [1, 2]
            || $exception->documentPageCount !== 2) {
            throw $exception;
        }
        $requiredPages = $exception->requiredSourcePages;
        $pageCount = $exception->documentPageCount;
    }
    $coverage = new PdfOcrCoveragePolicy();
    $coverage->assert($requiredPages, $pageCount, new ParseResult('local_paddle_ocr', '1', [
        new ParsedSourceBlock(1, 'page_region', ['page' => 1], 'Readable material'),
        new ParsedSourceBlock(2, 'page_region', ['page' => 2], 'Scanned material'),
    ]));
    foreach ([
        [new ParsedSourceBlock(1, 'page_region', ['page' => 1], 'Only text page')],
        [new ParsedSourceBlock(1, 'page_region', ['page' => 2], 'Only scanned page')],
        [new ParsedSourceBlock(1, 'page_region', ['page' => 3], 'Outside document')],
    ] as $missing) {
        try {
            $coverage->assert($requiredPages, $pageCount, new ParseResult('local_paddle_ocr', '1', $missing));
            throw new RuntimeException('OCR omitted a required PDF page');
        } catch (ParseException $exception) {
            if ($exception->errorCode !== 'SAND_AI_PARSE_FAILED') {
                throw $exception;
            }
        }
    }

    $blank = $objects;
    $blank[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 8 0 R >>';
    $blank[7] = "<< /Length 0 >>\nstream\n\nendstream";
    file_put_contents($path, pdfFixture($blank));
    $result = $driver->parse(new ParseInput($path, 'pdf', 'application/pdf'));
    if (count($result->blocks) !== 1 || $result->blocks[0]->locator !== ['page' => 1]
        || $result->blocks[0]->content !== 'Readable material') {
        throw new RuntimeException('Blank page affected text provenance');
    }

    $allBlank = $blank;
    $allBlank[5] = "<< /Length 0 >>\nstream\n\nendstream";
    file_put_contents($path, pdfFixture($allBlank));
    try {
        $driver->parse(new ParseInput($path, 'pdf', 'application/pdf'));
        throw new RuntimeException('Empty PDF was sent to OCR');
    } catch (ParseException $exception) {
        if ($exception->errorCode !== 'SAND_AI_PARSE_EMPTY') {
            throw $exception;
        }
    }

    $imageOnly = $objects;
    $imageOnly[2] = $objects[3];
    $imageOnly[3] = $blank[3];
    file_put_contents($path, pdfFixture($imageOnly));
    try {
        $driver->parse(new ParseInput($path, 'pdf', 'application/pdf'));
        throw new RuntimeException('Image-only PDF was accepted');
    } catch (ParseException $exception) {
        if ($exception->errorCode !== 'SAND_AI_OCR_REQUIRED' || $exception->requiredSourcePages !== [1]) {
            throw $exception;
        }
    }

    $illustrated = $objects;
    $illustrated[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> /XObject << /Im1 7 0 R >> >> /Contents 8 0 R >>';
    $illustratedContent = $imagePlacement . "\nBT /F1 12 Tf 72 500 Td (Illustrated text) Tj ET";
    $illustrated[7] = '<< /Length ' . strlen($illustratedContent) . " >>\nstream\n" . $illustratedContent . "\nendstream";
    file_put_contents($path, pdfFixture($illustrated));
    $result = $driver->parse(new ParseInput($path, 'pdf', 'application/pdf'));
    if (count($result->blocks) !== 2 || $result->blocks[1]->content !== 'Illustrated text') {
        throw new RuntimeException('Text-bearing illustrated page was rejected');
    }
} finally {
    unlink($path);
}

echo "pdf-mixed-parse 9/9\n";
