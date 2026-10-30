<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach (['ParseException', 'ParseDriver', 'ParseInput', 'ParsedSourceBlock', 'ParseResult', 'CsvParseDriver', 'ParseDriverFactory'] as $name) {
    require $root . '/app/Infrastructure/Parsing/' . $name . '.php';
}
require $root . '/app/Api/Support/ApiProblem.php';
require $root . '/app/Infrastructure/Capability/CapabilityDriverRegistry.php';

use app\Infrastructure\Parsing\CsvParseDriver;
use app\Infrastructure\Parsing\ParseDriverFactory;
use app\Infrastructure\Parsing\ParseException;
use app\Infrastructure\Parsing\ParseInput;
use app\Infrastructure\Capability\CapabilityDriverRegistry;

if (!function_exists('env')) {
    function env(string $name, mixed $default = null): mixed
    {
        return $default;
    }
}
$allowed = (require $root . '/config/sand_ai_file.php')['allowed']['csv'] ?? [];
$mediaTypes = CapabilityDriverRegistry::parseDriver('document_parse', 'native_document_parse')['media_types'] ?? [];
if (!in_array('text/plain', $allowed, true) || !in_array('text/csv', $allowed, true)
    || !in_array('text/plain', $mediaTypes, true) || !in_array('text/csv', $mediaTypes, true)) {
    throw new RuntimeException('CSV upload or private parse capability is not registered');
}

$path = tempnam(sys_get_temp_dir(), 'sandai_csv_');
if ($path === false) {
    throw new RuntimeException('CSV fixture could not be created');
}

try {
    file_put_contents($path, "\xEF\xBB\xBF标题,说明\n合同,\"跨行,\n内容\"\n\n末行,完成\n");
    $driver = ParseDriverFactory::forExtension('CSV');
    if (!$driver instanceof CsvParseDriver) {
        throw new RuntimeException('CSV extension did not select the table parser');
    }
    $result = $driver->parse(new ParseInput($path, 'csv', 'text/plain'));
    if (count($result->blocks) !== 3
        || $result->blocks[0]->locator !== ['row' => 1]
        || $result->blocks[1]->locator !== ['row' => 2]
        || $result->blocks[2]->locator !== ['row' => 5]
        || json_decode($result->blocks[1]->content, true, 512, JSON_THROW_ON_ERROR) !== ['A2' => '合同', 'B2' => '跨行, 内容']
        || $result->blocks[1]->locatorType !== 'table_row') {
        throw new RuntimeException('CSV cells or physical source rows were not preserved');
    }

    file_put_contents($path, mb_convert_encoding("合同,金额\n", 'GB18030', 'UTF-8'));
    $encoded = $driver->parse(new ParseInput($path, 'csv', 'text/plain'));
    if (json_decode($encoded->blocks[0]->content, true, 512, JSON_THROW_ON_ERROR) !== ['A1' => '合同', 'B1' => '金额']) {
        throw new RuntimeException('Chinese CSV cells were not converted to UTF-8');
    }

    file_put_contents($path, "\n,\n");
    try {
        $driver->parse(new ParseInput($path, 'csv', 'text/plain'));
        throw new RuntimeException('Empty CSV table was accepted');
    } catch (ParseException $exception) {
        if ($exception->errorCode !== 'SAND_AI_PARSE_EMPTY') {
            throw $exception;
        }
    }

    file_put_contents($path, implode(',', array_fill(0, 257, 'x')));
    try {
        $driver->parse(new ParseInput($path, 'csv', 'text/plain'));
        throw new RuntimeException('Oversized CSV table was accepted');
    } catch (ParseException $exception) {
        if ($exception->errorCode !== 'SAND_AI_PARSE_FAILED') {
            throw $exception;
        }
    }

    echo "csv-parse 6/6\n";
} finally {
    unlink($path);
}
