<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/utils.php';

$sqlDumpPath = __DIR__ . '/../novo.sql';
if (!file_exists($sqlDumpPath)) {
    fwrite(fopen('php://stderr', 'w'), "novo.sql não encontrado.\n");
    exit(1);
}

$sqlDump = file_get_contents($sqlDumpPath);
if ($sqlDump === false || trim($sqlDump) === '') {
    fwrite(fopen('php://stderr', 'w'), "Não foi possível ler novo.sql.\n");
    exit(1);
}

function parseInsert(string $sqlDump, string $table): array
{
    $pattern = '/INSERT INTO\s+`' . preg_quote($table, '/') . '`'
        . '\s*\(([^)]+)\)\s*VALUES\s*(.+?);/is';
    if (!preg_match($pattern, $sqlDump, $matches)) {
        return [];
    }
    $columns = array_map(static function ($col) {
        return trim(str_replace('`', '', $col));
    }, explode(',', $matches[1]));

    $valuesBlock = $matches[2];
    $rows = [];
    if (preg_match_all('/\((?>[^()]+|(?R))*\)/', $valuesBlock, $tupleMatches)) {
        foreach ($tupleMatches[0] as $tuple) {
            $values = parseTuple($tuple);
            if (count($values) !== count($columns)) {
                continue;
            }
            $assoc = [];
            foreach ($columns as $i => $column) {
                $assoc[$column] = $values[$i];
            }
            $rows[] = $assoc;
        }
    }
    return $rows;
}

function parseTuple(string $tuple): array
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
    $len = strlen($tuple);
    for ($i = 0; $i < $len; $i++) {
        $char = $tuple[$i];
        if ($inString) {
            if ($char === "'") {
                if ($i + 1 < $len && $tuple[$i + 1] === "'") {
                    $current .= "'";
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
                $values[] = normalizeValue($current, $wasString);
                $current = '';
                $wasString = false;
            } else {
                $current .= $char;
            }
        }
    }
    $values[] = normalizeValue($current, $wasString);
    return $values;
}

function normalizeValue(string $value, bool $wasString)
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

function sqlValue($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    $value = trim((string)$value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = str_replace("\\", "\\\\", $value);
    $value = str_replace("'", "''", $value);
    $value = str_replace("\n", "\\n", $value);
    return "'" . $value . "'";
}

function splitName(?string $fullName): array
{
    $fullName = trim((string)$fullName);
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

function uniqueSlug(string $text, array &$used): string
{
    $slug = slugify(trim($text));
    if ($slug === '') {
        $slug = bin2hex(random_bytes(4));
    }
    $candidate = $slug;
    $suffix = 2;
    while (isset($used[$candidate])) {
        $candidate = $slug . '-' . $suffix;
        $suffix++;
    }
    $used[$candidate] = true;
    return $candidate;
}

$categories = parseInsert($sqlDump, 'categories');
$customers = parseInsert($sqlDump, 'customers');
$notifications = parseInsert($sqlDump, 'notifications');
$orders = parseInsert($sqlDump, 'orders');
$paymentMethods = parseInsert($sqlDump, 'payment_methods');
$products = parseInsert($sqlDump, 'products');
$settings = parseInsert($sqlDump, 'settings');
$users = parseInsert($sqlDump, 'users');

$sqlStatements = [];

if ($categories) {
    $values = [];
    foreach ($categories as $row) {
        $values[] = '('
            . sqlValue($row['id'] ?? null) . ','
            . sqlValue($row['name'] ?? null) . ','
            . sqlValue($row['slug'] ?? slugify((string)($row['name'] ?? 'categoria'))) . ','
            . sqlValue($row['description'] ?? null) . ','
            . sqlValue($row['image_path'] ?? null) . ','
            . sqlValue($row['active'] ?? 1) . ','
            . sqlValue($row['sort_order'] ?? 0) . ','
            . sqlValue($row['created_at'] ?? null)
            . ')';
    }
    $sqlStatements[] = "INSERT INTO categories (id, name, slug, description, image_path, active, sort_order, created_at)\nVALUES\n    "
        . implode(",\n    ", $values)
        . "\nON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), image_path=VALUES(image_path), active=VALUES(active), sort_order=VALUES(sort_order), created_at=VALUES(created_at);";
}

if ($customers) {
    $values = [];
    foreach ($customers as $row) {
        $name = (string)($row['name'] ?? '');
        [$first, $last] = splitName($name);
        $values[] = '('
            . sqlValue($row['id'] ?? null) . ','
            . sqlValue($first) . ','
            . sqlValue($last) . ','
            . sqlValue($name) . ','
            . sqlValue($row['email'] ?? null) . ','
            . sqlValue($row['phone'] ?? null) . ','
            . sqlValue($row['address'] ?? null) . ','
            . sqlValue($row['address2'] ?? null) . ','
            . sqlValue($row['city'] ?? null) . ','
            . sqlValue($row['state'] ?? null) . ','
            . sqlValue($row['zipcode'] ?? null) . ','
            . sqlValue($row['country'] ?? 'US') . ','
            . sqlValue($row['created_at'] ?? null)
            . ')';
    }
    $sqlStatements[] = "INSERT INTO customers (id, first_name, last_name, name, email, phone, address, address2, city, state, zipcode, country, created_at)\nVALUES\n    "
        . implode(",\n    ", $values)
        . "\nON DUPLICATE KEY UPDATE first_name=VALUES(first_name), last_name=VALUES(last_name), name=VALUES(name), email=VALUES(email), phone=VALUES(phone), address=VALUES(address), address2=VALUES(address2), city=VALUES(city), state=VALUES(state), zipcode=VALUES(zipcode), country=VALUES(country);";
}

$productSlugsUsed = [];
if ($products) {
    $productValues = [];
    $detailsValues = [];
    foreach ($products as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $slugBase = $row['slug'] ?? ($row['sku'] ?? $name);
        $slug = uniqueSlug((string)$slugBase, $productSlugsUsed);
        $image = $row['image_path'] ?? null;
        $image = $image !== null ? trim((string)$image) : null;
        if ($image === '') {
            $image = null;
        }
        $productValues[] = '('
            . sqlValue($row['id'] ?? null) . ','
            . sqlValue($row['category_id'] ?? null) . ','
            . sqlValue($row['sku'] ?? null) . ','
            . sqlValue($slug) . ','
            . sqlValue($name) . ','
            . sqlValue($row['description'] ?? null) . ','
            . sqlValue($row['price'] ?? 0) . ','
            . sqlValue($row['price_compare'] ?? null) . ','
            . sqlValue($row['currency'] ?? 'USD') . ','
            . sqlValue($row['shipping_cost'] ?? 0) . ','
            . sqlValue($row['stock'] ?? 0) . ','
            . sqlValue($image) . ','
            . sqlValue($row['square_payment_link'] ?? null) . ','
            . sqlValue($row['square_credit_link'] ?? null) . ','
            . sqlValue($row['square_debit_link'] ?? null) . ','
            . sqlValue($row['square_afterpay_link'] ?? null) . ','
            . sqlValue($row['stripe_payment_link'] ?? null) . ','
            . sqlValue($row['active'] ?? 1) . ','
            . sqlValue($row['featured'] ?? 0) . ','
            . sqlValue($row['meta_title'] ?? null) . ','
            . sqlValue($row['meta_description'] ?? null) . ','
            . sqlValue($row['created_at'] ?? null) . ','
            . sqlValue($row['updated_at'] ?? null)
            . ')';

        $gallery = [];
        if ($image) {
            $gallery[] = ['path' => $image, 'alt' => $name];
        }
        $detailsValues[] = 'SELECT '
            . sqlValue($row['id'] ?? null) . ' AS product_id,'
            . sqlValue($row['description'] ?? null) . ' AS short_description,'
            . sqlValue($row['description'] ?? null) . ' AS detailed_description,'
            . sqlValue(json_encode([], JSON_UNESCAPED_UNICODE)) . ' AS specs_json,'
            . sqlValue(null) . ' AS additional_info,'
            . sqlValue(null) . ' AS payment_conditions,'
            . sqlValue(null) . ' AS delivery_info,'
            . sqlValue(json_encode($gallery, JSON_UNESCAPED_UNICODE)) . ' AS media_gallery,'
            . sqlValue(null) . ' AS video_url';
    }
    $sqlStatements[] = "INSERT INTO products (id, category_id, sku, slug, name, description, price, price_compare, currency, shipping_cost, stock, image_path, square_payment_link, square_credit_link, square_debit_link, square_afterpay_link, stripe_payment_link, active, featured, meta_title, meta_description, created_at, updated_at)\nVALUES\n    "
        . implode(",\n    ", $productValues)
        . "\nON DUPLICATE KEY UPDATE category_id=VALUES(category_id), sku=VALUES(sku), slug=VALUES(slug), name=VALUES(name), description=VALUES(description), price=VALUES(price), price_compare=VALUES(price_compare), currency=VALUES(currency), shipping_cost=VALUES(shipping_cost), stock=VALUES(stock), image_path=VALUES(image_path), square_payment_link=VALUES(square_payment_link), square_credit_link=VALUES(square_credit_link), square_debit_link=VALUES(square_debit_link), square_afterpay_link=VALUES(square_afterpay_link), stripe_payment_link=VALUES(stripe_payment_link), active=VALUES(active), featured=VALUES(featured), meta_title=VALUES(meta_title), meta_description=VALUES(meta_description), created_at=VALUES(created_at), updated_at=VALUES(updated_at);";

    $detailsUnion = implode("\nUNION ALL\n    ", $detailsValues);
    $sqlStatements[] = "INSERT INTO product_details (product_id, short_description, detailed_description, specs_json, additional_info, payment_conditions, delivery_info, media_gallery, video_url)\nSELECT src.*\nFROM (\n    "
        . $detailsUnion
        . "\n) AS src\nWHERE EXISTS (SELECT 1 FROM products WHERE products.id = src.product_id)\nON DUPLICATE KEY UPDATE short_description=VALUES(short_description), detailed_description=VALUES(detailed_description), specs_json=VALUES(specs_json), additional_info=VALUES(additional_info), payment_conditions=VALUES(payment_conditions), delivery_info=VALUES(delivery_info), media_gallery=VALUES(media_gallery), video_url=VALUES(video_url);";
}

if ($paymentMethods) {
    $values = [];
    foreach ($paymentMethods as $row) {
        $settingsValue = $row['settings'] ?? null;
        if (is_string($settingsValue) && $settingsValue !== '') {
            $decoded = json_decode($settingsValue, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $settingsValue = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            } else {
                $settingsValue = null;
            }
        }
        $values[] = '('
            . sqlValue($row['id'] ?? null) . ','
            . sqlValue($row['code'] ?? null) . ','
            . sqlValue($row['name'] ?? null) . ','
            . sqlValue($row['description'] ?? null) . ','
            . sqlValue($row['instructions'] ?? null) . ','
            . sqlValue($settingsValue) . ','
            . sqlValue($row['icon_path'] ?? null) . ','
            . sqlValue($row['is_active'] ?? 1) . ','
            . sqlValue($row['require_receipt'] ?? 0) . ','
            . sqlValue($row['sort_order'] ?? 0) . ','
            . sqlValue($row['created_at'] ?? null) . ','
            . sqlValue($row['updated_at'] ?? null)
            . ')';
    }
    $sqlStatements[] = "INSERT INTO payment_methods (id, code, name, description, instructions, settings, icon_path, is_active, require_receipt, sort_order, created_at, updated_at)\nVALUES\n    "
        . implode(",\n    ", $values)
        . "\nON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), instructions=VALUES(instructions), settings=VALUES(settings), icon_path=VALUES(icon_path), is_active=VALUES(is_active), require_receipt=VALUES(require_receipt), sort_order=VALUES(sort_order), updated_at=VALUES(updated_at);";
}

if ($settings) {
    $values = [];
    foreach ($settings as $row) {
        $values[] = '('
            . sqlValue($row['skey'] ?? null) . ','
            . sqlValue($row['svalue'] ?? null) . ','
            . sqlValue($row['updated_at'] ?? null)
            . ')';
    }
    $sqlStatements[] = "INSERT INTO settings (skey, svalue, updated_at)\nVALUES\n    "
        . implode(",\n    ", $values)
        . "\nON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=VALUES(updated_at);";
}

if ($users) {
    $values = [];
    foreach ($users as $row) {
        $values[] = '('
            . sqlValue($row['id'] ?? null) . ','
            . sqlValue($row['name'] ?? null) . ','
            . sqlValue($row['email'] ?? null) . ','
            . sqlValue($row['pass'] ?? null) . ','
            . sqlValue($row['role'] ?? 'admin') . ','
            . sqlValue($row['active'] ?? 1) . ','
            . sqlValue($row['created_at'] ?? null)
            . ')';
    }
    $sqlStatements[] = "INSERT INTO users (id, name, email, pass, role, active, created_at)\nVALUES\n    "
        . implode(",\n    ", $values)
        . "\nON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email), pass=VALUES(pass), role=VALUES(role), active=VALUES(active);";
}

$orderItems = [];
if ($orders) {
    $orderValues = [];
    foreach ($orders as $row) {
        $itemsJson = (string)($row['items_json'] ?? '[]');
        $itemsJson = trim($itemsJson);
        if ($itemsJson === '') {
            $itemsJson = '[]';
        }
        $items = json_decode($itemsJson, true);
        if (!is_array($items)) {
            $items = [];
        }

        $orderValues[] = '('
            . sqlValue($row['id'] ?? null) . ','
            . sqlValue($row['customer_id'] ?? null) . ','
            . sqlValue($itemsJson) . ','
            . sqlValue($row['subtotal'] ?? 0) . ','
            . sqlValue($row['shipping_cost'] ?? 0) . ','
            . sqlValue($row['total'] ?? 0) . ','
            . sqlValue($row['currency'] ?? 'USD') . ','
            . sqlValue($row['payment_method'] ?? 'unknown') . ','
            . sqlValue(null) . ','
            . sqlValue(null) . ','
            . sqlValue(null) . ','
            . sqlValue($row['payment_ref'] ?? null) . ','
            . sqlValue($row['payment_status'] ?? 'pending') . ','
            . sqlValue($row['status'] ?? 'pending') . ','
            . sqlValue($row['track_token'] ?? null) . ','
            . sqlValue($row['zelle_receipt'] ?? null) . ','
            . sqlValue($row['notes'] ?? null) . ','
            . sqlValue($row['admin_viewed'] ?? 0) . ','
            . sqlValue($row['created_at'] ?? null) . ','
            . sqlValue($row['updated_at'] ?? null)
            . ')';

        $orderId = $row['id'] ?? null;
        if ($orderId !== null) {
            foreach ($items as $item) {
                $orderItems[] = '('
                    . sqlValue($orderId) . ','
                    . sqlValue(isset($item['id']) ? (int)$item['id'] : null) . ','
                    . sqlValue($item['name'] ?? '') . ','
                    . sqlValue($item['sku'] ?? null) . ','
                    . sqlValue($item['price'] ?? 0) . ','
                    . sqlValue($item['qty'] ?? 1)
                    . ')';
            }
        }
    }
    $sqlStatements[] = "INSERT INTO orders (id, customer_id, items_json, subtotal, shipping_cost, total, currency, payment_method, delivery_method_code, delivery_method_label, delivery_method_details, payment_ref, payment_status, status, track_token, zelle_receipt, notes, admin_viewed, created_at, updated_at)\nVALUES\n    "
        . implode(",\n    ", $orderValues)
        . "\nON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id), items_json=VALUES(items_json), subtotal=VALUES(subtotal), shipping_cost=VALUES(shipping_cost), total=VALUES(total), currency=VALUES(currency), payment_method=VALUES(payment_method), payment_ref=VALUES(payment_ref), payment_status=VALUES(payment_status), status=VALUES(status), track_token=VALUES(track_token), zelle_receipt=VALUES(zelle_receipt), notes=VALUES(notes), admin_viewed=VALUES(admin_viewed), created_at=VALUES(created_at), updated_at=VALUES(updated_at);";
}

if ($orderItems) {
    $orderIds = array_unique(array_map(static function ($value) {
        return trim($value, '()');
    }, array_map(static function ($row) {
        return explode(',', trim($row))[0];
    }, $orderItems)));
    $orderIdList = implode(',', $orderIds);
    $sqlStatements[] = "DELETE FROM order_items WHERE order_id IN (" . $orderIdList . ");";
    $sqlStatements[] = "INSERT INTO order_items (order_id, product_id, name, sku, price, quantity)\nVALUES\n    "
        . implode(",\n    ", $orderItems)
        . ";";
}

if ($notifications) {
    $values = [];
    foreach ($notifications as $row) {
        $dataValue = $row['data'] ?? null;
        if (is_string($dataValue) && $dataValue !== '') {
            $decoded = json_decode($dataValue, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = json_decode(stripslashes($dataValue), true);
            }
            if (json_last_error() === JSON_ERROR_NONE) {
                $dataValue = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            } else {
                $dataValue = '{}';
            }
        }
        $values[] = '('
            . sqlValue($row['id'] ?? null) . ','
            . sqlValue($row['type'] ?? null) . ','
            . sqlValue($row['title'] ?? null) . ','
            . sqlValue($row['message'] ?? null) . ','
            . sqlValue($dataValue) . ','
            . sqlValue($row['is_read'] ?? 0) . ','
            . sqlValue($row['created_at'] ?? null)
            . ')';
    }
    $sqlStatements[] = "INSERT INTO notifications (id, type, title, message, data, is_read, created_at)\nVALUES\n    "
        . implode(",\n    ", $values)
        . "\nON DUPLICATE KEY UPDATE type=VALUES(type), title=VALUES(title), message=VALUES(message), data=VALUES(data), is_read=VALUES(is_read), created_at=VALUES(created_at);";
}

$output = [];
$output[] = "START TRANSACTION;";
$output = array_merge($output, $sqlStatements);
$output[] = "COMMIT;";

$targetFile = __DIR__ . '/../import_novo_manual.sql';
file_put_contents($targetFile, implode("\n\n", $output) . "\n");

echo "Arquivo SQL gerado em: {$targetFile}\n";
