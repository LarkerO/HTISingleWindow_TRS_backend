<?php
require dirname(__DIR__).'/vendor/autoload.php';
$data=(new app\mtr\WorldReader())->read($argv[1]);
foreach($data as $kind=>$items) { echo "$kind: ".count($items).PHP_EOL; if($items) echo json_encode(array_values($items)[0],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL; }
