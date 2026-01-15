<?php
error_reporting(E_ALL); ini_set('display_errors',1);
require __DIR__.'/lib/db.php';
try {
  $pdo = db();
  echo "OK: Connected to MySQL<br>";
  $ver = $pdo->query("select version() v")->fetch()['v'];
  echo "MySQL version: $ver";
} catch (Throwable $e) {
  echo "ERROR: ".$e->getMessage();
}