<?php
$dir = __DIR__ . '/storage/zelle_receipts';

echo "<h2>Testando permissão de escrita em: $dir</h2>";

if (!is_dir($dir)) {
    echo "❌ Diretório não existe.";
    exit;
}

if (is_writable($dir)) {
    echo "✅ O PHP tem permissão para gravar nesta pasta.";
    // tenta criar e apagar um arquivo de teste
    $file = $dir . '/teste.txt';
    if (file_put_contents($file, "ok") !== false) {
        echo "<br>✅ Arquivo de teste criado.";
        unlink($file);
        echo "<br>✅ Arquivo de teste removido.";
    } else {
        echo "<br>⚠️ Não conseguiu criar o arquivo.";
    }
} else {
    echo "❌ O PHP NÃO tem permissão de escrita nesta pasta.";
}
