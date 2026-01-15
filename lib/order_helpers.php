<?php

declare(strict_types=1);

if (!function_exists('order_helper_parse_money')) {
    function order_helper_parse_money($value): float
    {
        if (function_exists('orders_parse_money')) {
            return orders_parse_money($value);
        }
        $value = preg_replace('/[^\d,\.\-]/', '', (string)$value);
        if ($value === '' || $value === null) {
            return 0.0;
        }
        $commaCount = substr_count($value, ',');
        $dotCount = substr_count($value, '.');
        if ($commaCount > 0 && $dotCount > 0) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($commaCount > 0 && $dotCount === 0) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
        return (float)$value;
    }
}

if (!function_exists('order_helper_normalize_items')) {
    function order_helper_normalize_items(array $items, string $currency): array
    {
        $normalized = [];
        foreach ($items as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty = (int)($row['qty'] ?? 1);
            if ($qty < 1) {
                $qty = 1;
            }
            $priceValue = order_helper_parse_money($row['price'] ?? '');
            if ($priceValue <= 0) {
                continue;
            }
            $total = round($priceValue * $qty, 2);
            $normalized[] = [
                'name'        => $name,
                'sku'         => trim((string)($row['sku'] ?? '')),
                'qty'         => $qty,
                'price'       => $priceValue,
                'currency'    => $currency,
                'line_total'  => $total,
            ];
        }
        return $normalized;
    }
}

if (!function_exists('order_helper_calculate_totals')) {
    function order_helper_calculate_totals(array $items, string $shippingCost, string $currency): array
    {
        $normalized = order_helper_normalize_items($items, $currency);
        $errors = [];
        if (empty($normalized)) {
            $errors[] = 'Adicione pelo menos um item com nome e valor.';
        }
        $subtotal = array_sum(array_map(static fn($item) => $item['line_total'], $normalized));
        $shipping = max(0, round(order_helper_parse_money($shippingCost), 2));
        $total = round($subtotal + $shipping, 2);
        $itemsOutput = array_map(static function ($item) {
            return [
                'name'     => $item['name'],
                'sku'      => $item['sku'],
                'qty'      => $item['qty'],
                'price'    => $item['price'],
                'currency' => $item['currency'],
            ];
        }, $normalized);

        return [
            'errors'   => $errors,
            'items'    => $itemsOutput,
            'subtotal' => round($subtotal, 2),
            'shipping' => $shipping,
            'total'    => $total,
        ];
    }
}
