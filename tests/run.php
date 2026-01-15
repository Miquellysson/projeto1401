<?php

declare(strict_types=1);

require_once __DIR__.'/LoginTest.php';
require_once __DIR__.'/OrderCalculationTest.php';
require_once __DIR__.'/EmailTest.php';

$suites = [
    new LoginTest(),
    new OrderCalculationTest(),
    new EmailTest(),
];

$failures = 0;
foreach ($suites as $suite) {
    foreach ($suite->run() as $result) {
        if ($result['status'] === 'ok') {
            echo "✔ {$result['test']}\n";
        } else {
            $failures++;
            $message = $result['message'] ?? 'falha desconhecida';
            echo "✖ {$result['test']} :: {$message}\n";
        }
    }
}

if ($failures > 0) {
    exit(1);
}
echo "Todos os testes passaram!\n";
