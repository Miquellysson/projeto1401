<?php
// test-report.php — opcional: coleta de logs do navegador
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo "POST only"; exit; }
$raw = file_get_contents('php://input');
file_put_contents(__DIR__.'/hotlink-report-'.date('Ymd-His').'.log', $raw."\n", FILE_APPEND);
echo "ok";
