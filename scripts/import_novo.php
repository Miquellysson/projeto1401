<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/utils.php';

$sqlFile = __DIR__ . '/../novo.sql';
if (!file_exists($sqlFile)) {
    fwrite(fopen('php://stderr', 'w'), "Arquivo novo.sql não encontrado.\n");
    exit(1);
}

$sqlDump = file_get_contents($sqlFile);
if ($sqlDump === false || trim($sqlDump) === '') {
    fwrite(fopen('php://stderr', 'w'), "Não foi possível ler novo.sql ou o arquivo está vazio.\n");
    exit(1);
}

function parseInsertData(string $sqlDump, string $table): array
{
    $pattern = '/INSERT INTO\s+`' . preg_quote($table, '/') . '`'
        . '\s*\(([^)]+)\)\s*VALUES\s*(.+?);/is';
    if (!preg_match($pattern, $sqlDump, $matches)) {
        return [];
    }

    $columnsRaw = $matches[1];
    $valuesRaw = $matches[2];
    $columns = array_map(function ($col) {
        return trim(str_replace('`', '', $col));
    }, explode(',', $columnsRaw));

    $rows = [];
    if (preg_match_all('/\((?>[^()]+|(?R))*\)/', $valuesRaw, $tupleMatches)) {
        foreach ($tupleMatches[0] as $tuple) {
            $values = parseSqlTuple($tuple);
            if (count($values) !== count($columns)) {
                continue;
            }
            $assoc = [];
            foreach ($columns as $idx => $column) {
                $assoc[$column] = $values[$idx];
            }
            $rows[] = $assoc;
        }
    }
    return $rows;
}

function parseSqlTuple(string $tuple): array
{
    $tuple = trim($tuple);
    if ($tuple === '') {
        return [];
    }
    if ($tuple[0] === '(') {
        $tuple = substr($tuple, 1);
    }
    if (substr($tuple, -1) === ')') {
        $tuple = substr($tuple, 0, -1);
    }

    $values = [];
    $current = '';
    $inString = false;
    $wasString = false;
    $length = strlen($tuple);

    for ($i = 0; $i < $length; $i++) {
        $char = $tuple[$i];
        if ($inString) {
            if ($char === "'") {
                if ($i + 1 < $length && $tuple[$i + 1] === "'") {
                    $current .= "'"; // escaped quote
                    $i++;
                } else {
                    $inString = false;
                }
            } else {
                $current .= $char;
            }
        } else {
            if ($char === "'") {
                $inString = true;
                $wasString = true;
            } elseif ($char === ',') {
                $values[] = normalizeSqlValue($current, $wasString);
                $current = '';
                $wasString = false;
            } else {
                $current .= $char;
            }
        }
    }
    $values[] = normalizeSqlValue($current, $wasString);

    return $values;
}

function normalizeSqlValue(string $value, bool $wasString)
{
    if ($wasString) {
        return $value;
    }
    $value = trim($value);
    if ($value === '' || strcasecmp($value, 'NULL') === 0) {
        return null;
    }
    if (preg_match('/^-?\d+$/', $value)) {
        return (int)$value;
    }
    if (preg_match('/^-?\d+\.\d+$/', $value)) {
        return (float)$value;
    }
    return $value;
}

function splitName(string $fullName): array
{
    $fullName = trim($fullName);
    if ($fullName === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/', $fullName);
    if (!$parts) {
        return [$fullName, ''];
    }
    $first = array_shift($parts);
    $last = implode(' ', $parts);
    return [$first, trim($last)];
}

function ensureProductSlug(PDO $pdo, string $baseSlug, ?int $ignoreId = null): string
{
    $slug = slugify($baseSlug);
    if ($slug === '') {
        $slug = bin2hex(random_bytes(4));
    }
    $check = $pdo->prepare($ignoreId
        ? 'SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1'
        : 'SELECT id FROM products WHERE slug = ? LIMIT 1');
    $suffix = 2;
    $candidate = $slug;
    while (true) {
        $params = $ignoreId ? [$candidate, $ignoreId] : [$candidate];
        $check->execute($params);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
        $candidate = $slug . '-' . $suffix;
        $suffix++;
    }
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$report = [
    'categories' => 0,
    'customers' => 0,
    'products' => 0,
    'orders' => 0,
    'order_items' => 0,
    'payment_methods' => 0,
    'settings' => 0,
    'users' => 0,
    'notifications' => 0,
];

$categories = parseInsertData($sqlDump, 'categories');
$customers = parseInsertData($sqlDump, 'customers');
$products  = parseInsertData($sqlDump, 'products');
$orders    = parseInsertData($sqlDump, 'orders');
$payments  = parseInsertData($sqlDump, 'payment_methods');
$settings  = parseInsertData($sqlDump, 'settings');
$usersData = parseInsertData($sqlDump, 'users');
$notifications = parseInsertData($sqlDump, 'notifications');

$pdo->beginTransaction();

try {
    if ($categories) {
        $stmt = $pdo->prepare(
            'INSERT INTO categories (id, name, slug, description, image_path, active, sort_order, created_at)
             VALUES (:id, :name, :slug, :description, :image_path, :active, :sort_order, :created_at)
             ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), image_path=VALUES(image_path),
             active=VALUES(active), sort_order=VALUES(sort_order)'
        );
        foreach ($categories as $row) {
            $stmt->execute([
                ':id' => $row['id'] ?? null,
                ':name' => $row['name'] ?? null,
                ':slug' => $row['slug'] ?? slugify((string)($row['name'] ?? 'categoria')),
                ':description' => $row['description'] ?? null,
                ':image_path' => $row['image_path'] ?? null,
                ':active' => (int)($row['active'] ?? 1),
                ':sort_order' => (int)($row['sort_order'] ?? 0),
                ':created_at' => $row['created_at'] ?? null,
            ]);
            $report['categories']++;
        }
    }

    if ($customers) {
        $stmt = $pdo->prepare(
            'INSERT INTO customers (id, first_name, last_name, name, email, phone, address, address2, city, state, zipcode, country, created_at)
             VALUES (:id, :first_name, :last_name, :name, :email, :phone, :address, :address2, :city, :state, :zipcode, :country, :created_at)
             ON DUPLICATE KEY UPDATE first_name=VALUES(first_name), last_name=VALUES(last_name), name=VALUES(name),
             email=VALUES(email), phone=VALUES(phone), address=VALUES(address), address2=VALUES(address2),
             city=VALUES(city), state=VALUES(state), zipcode=VALUES(zipcode), country=VALUES(country)'
        );
        foreach ($customers as $row) {
            $name = (string)($row['name'] ?? '');
            [$first, $last] = splitName($name);
            $stmt->execute([
                ':id' => $row['id'] ?? null,
                ':first_name' => $first,
                ':last_name' => $last,
                ':name' => $name,
                ':email' => $row['email'] ?? null,
                ':phone' => $row['phone'] ?? null,
                ':address' => $row['address'] ?? null,
                ':address2' => $row['address2'] ?? null,
                ':city' => $row['city'] ?? null,
                ':state' => $row['state'] ?? null,
                ':zipcode' => $row['zipcode'] ?? null,
                ':country' => $row['country'] ?? 'US',
                ':created_at' => $row['created_at'] ?? null,
            ]);
            $report['customers']++;
        }
    }

    if ($products) {
        $productStmt = $pdo->prepare(
            'INSERT INTO products (id, category_id, sku, slug, name, description, price, price_compare, currency, shipping_cost,
                                   stock, image_path, square_payment_link, square_credit_link, square_debit_link, square_afterpay_link,
                                   stripe_payment_link, active, featured, meta_title, meta_description, created_at, updated_at)
             VALUES (:id, :category_id, :sku, :slug, :name, :description, :price, :price_compare, :currency, :shipping_cost,
                     :stock, :image_path, :square_payment_link, :square_credit_link, :square_debit_link, :square_afterpay_link,
                     :stripe_payment_link, :active, :featured, :meta_title, :meta_description, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description),
             price=VALUES(price), price_compare=VALUES(price_compare), currency=VALUES(currency), shipping_cost=VALUES(shipping_cost),
             stock=VALUES(stock), image_path=VALUES(image_path), square_payment_link=VALUES(square_payment_link),
             square_credit_link=VALUES(square_credit_link), square_debit_link=VALUES(square_debit_link),
             square_afterpay_link=VALUES(square_afterpay_link), stripe_payment_link=VALUES(stripe_payment_link),
             active=VALUES(active), featured=VALUES(featured), meta_title=VALUES(meta_title), meta_description=VALUES(meta_description),
             updated_at=VALUES(updated_at)'
        );

        $detailsStmt = $pdo->prepare(
            'INSERT INTO product_details (product_id, short_description, detailed_description, specs_json, additional_info,
                                          payment_conditions, delivery_info, media_gallery, video_url)
             VALUES (:product_id, :short_description, :detailed_description, :specs_json, :additional_info,
                     :payment_conditions, :delivery_info, :media_gallery, :video_url)
             ON DUPLICATE KEY UPDATE short_description=VALUES(short_description), detailed_description=VALUES(detailed_description),
             specs_json=VALUES(specs_json), additional_info=VALUES(additional_info), payment_conditions=VALUES(payment_conditions),
             delivery_info=VALUES(delivery_info), media_gallery=VALUES(media_gallery), video_url=VALUES(video_url)'
        );

        foreach ($products as $row) {
            $productId = $row['id'] ?? null;
            $name = (string)($row['name'] ?? '');
            $slugBase = $row['slug'] ?? ($row['sku'] ?? $name);
            $slug = ensureProductSlug($pdo, (string)$slugBase, is_int($productId) ? $productId : null);
            $image = $row['image_path'] ?? null;
            $productStmt->execute([
                ':id' => $productId,
                ':category_id' => $row['category_id'] ?? null,
                ':sku' => $row['sku'] ?? null,
                ':slug' => $slug,
                ':name' => $name,
                ':description' => $row['description'] ?? null,
                ':price' => $row['price'] ?? 0,
                ':price_compare' => $row['price_compare'] ?? null,
                ':currency' => $row['currency'] ?? 'USD',
                ':shipping_cost' => $row['shipping_cost'] ?? 0,
                ':stock' => $row['stock'] ?? 0,
                ':image_path' => $image,
                ':square_payment_link' => $row['square_payment_link'] ?? null,
                ':square_credit_link' => $row['square_credit_link'] ?? null,
                ':square_debit_link' => $row['square_debit_link'] ?? null,
                ':square_afterpay_link' => $row['square_afterpay_link'] ?? null,
                ':stripe_payment_link' => $row['stripe_payment_link'] ?? null,
                ':active' => (int)($row['active'] ?? 1),
                ':featured' => (int)($row['featured'] ?? 0),
                ':meta_title' => $row['meta_title'] ?? null,
                ':meta_description' => $row['meta_description'] ?? null,
                ':created_at' => $row['created_at'] ?? null,
                ':updated_at' => $row['updated_at'] ?? null,
            ]);

            $gallery = [];
            if ($image) {
                $gallery[] = ['path' => $image, 'alt' => $name];
            }
            $detailsStmt->execute([
                ':product_id' => $productId,
                ':short_description' => $row['description'] ?? null,
                ':detailed_description' => $row['description'] ?? null,
                ':specs_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                ':additional_info' => null,
                ':payment_conditions' => null,
                ':delivery_info' => null,
                ':media_gallery' => json_encode($gallery, JSON_UNESCAPED_UNICODE),
                ':video_url' => null,
            ]);
            $report['products']++;
        }
    }

    if ($orders) {
        $orderStmt = $pdo->prepare(
            'INSERT INTO orders (id, customer_id, items_json, subtotal, shipping_cost, total, currency, payment_method,
                                 delivery_method_code, delivery_method_label, delivery_method_details, payment_ref,
                                 payment_status, status, track_token, zelle_receipt, notes, admin_viewed, created_at, updated_at)
             VALUES (:id, :customer_id, :items_json, :subtotal, :shipping_cost, :total, :currency, :payment_method,
                     :delivery_method_code, :delivery_method_label, :delivery_method_details, :payment_ref,
                     :payment_status, :status, :track_token, :zelle_receipt, :notes, :admin_viewed, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id), items_json=VALUES(items_json), subtotal=VALUES(subtotal),
             shipping_cost=VALUES(shipping_cost), total=VALUES(total), currency=VALUES(currency),
             payment_method=VALUES(payment_method), delivery_method_code=VALUES(delivery_method_code),
             delivery_method_label=VALUES(delivery_method_label), delivery_method_details=VALUES(delivery_method_details),
             payment_ref=VALUES(payment_ref), payment_status=VALUES(payment_status), status=VALUES(status),
             track_token=VALUES(track_token), zelle_receipt=VALUES(zelle_receipt), notes=VALUES(notes),
             admin_viewed=VALUES(admin_viewed), created_at=VALUES(created_at), updated_at=VALUES(updated_at)'
        );
        $deleteItems = $pdo->prepare('DELETE FROM order_items WHERE order_id = ?');
        $insertItem = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, name, sku, price, quantity)
             VALUES (:order_id, :product_id, :name, :sku, :price, :quantity)'
        );

        foreach ($orders as $row) {
            $shippingData = [
                'shipping_name' => $row['shipping_name'] ?? null,
                'shipping_company' => $row['shipping_company'] ?? null,
                'shipping_email' => $row['shipping_email'] ?? null,
                'shipping_phone' => $row['shipping_phone'] ?? null,
                'shipping_address1' => $row['shipping_address1'] ?? null,
                'shipping_address2' => $row['shipping_address2'] ?? null,
                'shipping_city' => $row['shipping_city'] ?? null,
                'shipping_state' => $row['shipping_state'] ?? null,
                'shipping_zipcode' => $row['shipping_zipcode'] ?? null,
                'shipping_country' => $row['shipping_country'] ?? null,
            ];
            $shippingFiltered = array_filter($shippingData, function ($value) {
                return $value !== null && $value !== '';
            });

            $notes = $row['notes'] ?? null;
            if ($shippingFiltered) {
                $shippingJson = json_encode($shippingFiltered, JSON_UNESCAPED_UNICODE);
                if ($notes === null || $notes === '') {
                    $notes = '[import] shipping_info=' . $shippingJson;
                } else {
                    $notes = trim((string)$notes) . "\n[import shipping] " . $shippingJson;
                }
            }

            $orderStmt->execute([
                ':id' => $row['id'] ?? null,
                ':customer_id' => $row['customer_id'] ?? null,
                ':items_json' => $row['items_json'] ?? '[]',
                ':subtotal' => $row['subtotal'] ?? 0,
                ':shipping_cost' => $row['shipping_cost'] ?? 0,
                ':total' => $row['total'] ?? 0,
                ':currency' => $row['currency'] ?? 'USD',
                ':payment_method' => $row['payment_method'] ?? 'unknown',
                ':delivery_method_code' => null,
                ':delivery_method_label' => null,
                ':delivery_method_details' => null,
                ':payment_ref' => $row['payment_ref'] ?? null,
                ':payment_status' => $row['payment_status'] ?? 'pending',
                ':status' => $row['status'] ?? 'pending',
                ':track_token' => $row['track_token'] ?? null,
                ':zelle_receipt' => $row['zelle_receipt'] ?? null,
                ':notes' => $notes,
                ':admin_viewed' => (int)($row['admin_viewed'] ?? 0),
                ':created_at' => $row['created_at'] ?? null,
                ':updated_at' => $row['updated_at'] ?? null,
            ]);
            $report['orders']++;

            if (isset($row['id'])) {
                $orderId = (int)$row['id'];
                $deleteItems->execute([$orderId]);
                $itemsJson = $row['items_json'] ?? '[]';
                $items = json_decode((string)$itemsJson, true);
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $insertItem->execute([
                            ':order_id' => $orderId,
                            ':product_id' => isset($item['id']) ? (int)$item['id'] : null,
                            ':name' => $item['name'] ?? '',
                            ':sku' => $item['sku'] ?? null,
                            ':price' => $item['price'] ?? 0,
                            ':quantity' => $item['qty'] ?? 1,
                        ]);
                        $report['order_items']++;
                    }
                }
            }
        }
    }

    if ($payments) {
        $stmt = $pdo->prepare(
            'INSERT INTO payment_methods (id, code, name, description, instructions, settings, icon_path, is_active,
                                          require_receipt, sort_order, created_at, updated_at)
             VALUES (:id, :code, :name, :description, :instructions, :settings, :icon_path, :is_active,
                     :require_receipt, :sort_order, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), instructions=VALUES(instructions),
             settings=VALUES(settings), icon_path=VALUES(icon_path), is_active=VALUES(is_active),
             require_receipt=VALUES(require_receipt), sort_order=VALUES(sort_order), updated_at=VALUES(updated_at)'
        );
        foreach ($payments as $row) {
            $settingsValue = $row['settings'] ?? null;
            if (is_string($settingsValue) && $settingsValue !== '') {
                $decoded = json_decode($settingsValue, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $settingsValue = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                } else {
                    $settingsValue = null;
                }
            }
            $stmt->execute([
                ':id' => $row['id'] ?? null,
                ':code' => $row['code'] ?? null,
                ':name' => $row['name'] ?? null,
                ':description' => $row['description'] ?? null,
                ':instructions' => $row['instructions'] ?? null,
                ':settings' => $settingsValue,
                ':icon_path' => $row['icon_path'] ?? null,
                ':is_active' => (int)($row['is_active'] ?? 1),
                ':require_receipt' => (int)($row['require_receipt'] ?? 0),
                ':sort_order' => (int)($row['sort_order'] ?? 0),
                ':created_at' => $row['created_at'] ?? null,
                ':updated_at' => $row['updated_at'] ?? null,
            ]);
            $report['payment_methods']++;
        }
    }

    if ($settings) {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (skey, svalue, updated_at)
             VALUES (:skey, :svalue, :updated_at)
             ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=VALUES(updated_at)'
        );
        foreach ($settings as $row) {
            $stmt->execute([
                ':skey' => $row['skey'] ?? null,
                ':svalue' => $row['svalue'] ?? null,
                ':updated_at' => $row['updated_at'] ?? null,
            ]);
            $report['settings']++;
        }
    }

    if ($usersData) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, name, email, pass, role, active, created_at)
             VALUES (:id, :name, :email, :pass, :role, :active, :created_at)
             ON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email), pass=VALUES(pass),
             role=VALUES(role), active=VALUES(active)'
        );
        foreach ($usersData as $row) {
            $stmt->execute([
                ':id' => $row['id'] ?? null,
                ':name' => $row['name'] ?? null,
                ':email' => $row['email'] ?? null,
                ':pass' => $row['pass'] ?? null,
                ':role' => $row['role'] ?? 'admin',
                ':active' => (int)($row['active'] ?? 1),
                ':created_at' => $row['created_at'] ?? null,
            ]);
            $report['users']++;
        }
    }

    if ($notifications) {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (id, type, title, message, data, is_read, created_at)
             VALUES (:id, :type, :title, :message, :data, :is_read, :created_at)
             ON DUPLICATE KEY UPDATE type=VALUES(type), title=VALUES(title), message=VALUES(message),
             data=VALUES(data), is_read=VALUES(is_read), created_at=VALUES(created_at)'
        );
        foreach ($notifications as $row) {
            $stmt->execute([
                ':id' => $row['id'] ?? null,
                ':type' => $row['type'] ?? null,
                ':title' => $row['title'] ?? null,
                ':message' => $row['message'] ?? null,
                ':data' => $row['data'] ?? null,
                ':is_read' => (int)($row['is_read'] ?? 0),
                ':created_at' => $row['created_at'] ?? null,
            ]);
            $report['notifications']++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(fopen('php://stderr', 'w'), "Falha ao importar dados: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Importação concluída.\n";
foreach ($report as $entity => $count) {
    echo sprintf(" - %s: %d registros processados\n", $entity, $count);
}
