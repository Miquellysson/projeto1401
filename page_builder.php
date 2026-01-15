<?php
require __DIR__.'/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Location: settings.php?tab=builder');
exit;
