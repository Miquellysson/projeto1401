<?php
require __DIR__.'/lib/db.php';
$pdo = db();
$st = $pdo->query("SELECT id, sku, image_path FROM products");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$up = $pdo->prepare("UPDATE products SET image_path=? WHERE id=?");
$cnt=0;
foreach ($rows as $r) {
  if (empty($r['image_path'])) {
    $seed = $r['sku'] ?: ('prod'.$r['id']);
    $img = "https://picsum.photos/seed/".urlencode($seed)."/600/600";
    $up->execute([$img, $r['id']]);
    $cnt++;
  }
}
echo "Atualizadas imagens para {$cnt} produtos.";
