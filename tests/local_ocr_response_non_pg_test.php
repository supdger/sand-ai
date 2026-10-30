<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach (['ParseException', 'ParsedSourceBlock', 'ParseResult', 'LocalPaddleOcrResponse'] as $name) {
    $type = 'app\\Infrastructure\\Parsing\\' . $name;
    if (!class_exists($type, false)) {
        require $root . '/app/Infrastructure/Parsing/' . $name . '.php';
    }
}

use app\Infrastructure\Parsing\LocalPaddleOcrResponse;
use app\Infrastructure\Parsing\ParseException;

$mapper = new LocalPaddleOcrResponse();
$valid = ['driver_version' => 'local-1', 'blocks' => [
    ['page' => 2, 'x' => 10, 'y' => 20, 'width' => 30, 'height' => 40, 'confidence' => 0.8, 'text' => '第二页正文'],
    ['page' => 3, 'text' => '第三页正文'],
]];
$result = $mapper->normalize($valid, 'local_paddle_ocr', 'fallback');
if ($result->driverVersion !== 'local-1' || count($result->blocks) !== 2
    || $result->blocks[0]->locator !== ['page' => 2, 'x' => 10, 'y' => 20, 'width' => 30, 'height' => 40, 'confidence' => 0.8]
    || $result->blocks[1]->locator['page'] !== 3 || $result->blocks[1]->content !== '第三页正文') {
    throw new RuntimeException('Valid OCR pages or coordinates were not preserved');
}

$invalid = [
    ['blocks' => [['text' => '缺页码']]],
    ['blocks' => [['page' => 0, 'text' => '非法页码']]],
    ['blocks' => [['page' => '2', 'text' => '伪造页码']]],
    ['blocks' => [['page' => 2.5, 'text' => '非整数页码']]],
    ['blocks' => [['page' => 2, 'x' => -1, 'text' => '非法坐标']]],
    ['blocks' => [['page' => 2, 'confidence' => 1.2, 'text' => '非法置信度']]],
    ['blocks' => [['page' => 2, 'text' => ['非法文本']]]],
    ['blocks' => [['page' => 2, 'text' => '正文'], '非法块']],
    ['blocks' => ['key' => ['page' => 2, 'text' => '非列表']]],
    ['driver_version' => [], 'blocks' => []],
];
foreach ($invalid as $payload) {
    try {
        $mapper->normalize($payload, 'local_paddle_ocr', 'fallback');
        throw new RuntimeException('Invalid OCR provenance was accepted');
    } catch (ParseException $exception) {
        if ($exception->errorCode !== 'SAND_AI_PARSE_FAILED') {
            throw $exception;
        }
    }
}

echo "local-ocr-response 11/11\n";
