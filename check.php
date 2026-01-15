<?php
header('Content-Type: text/plain; charset=utf-8');
echo "DIR: ".__DIR__."\n\n";
$it = scandir(__DIR__);
foreach ($it as $f) {
  if ($f === '.' || $f === '..') continue;
  $p = __DIR__.'/'.$f;
  printf("%-30s  %s  %s\n", $f, is_dir($p)?'[DIR]':'[FILE]', is_readable($p)?'readable':'NOT_READABLE');
}
