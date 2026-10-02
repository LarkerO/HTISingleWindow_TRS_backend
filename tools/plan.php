<?php
require dirname(__DIR__).'/vendor/autoload.php';
$data=(new app\mtr\WorldReader())->read($argv[1]);
$report=(new app\mtr\Timetable())->build($data);
echo json_encode(['counts'=>$report['counts'],'trips'=>count($report['trips']),'warnings'=>$report['warnings']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
