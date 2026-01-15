<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../lib/order_helpers.php';

final class OrderCalculationTest extends TestCase
{
    public function testTotalsAreCalculated(): void
    {
        $items = [
            ['name' => 'Produto A', 'sku' => 'A1', 'qty' => 2, 'price' => '10,00'],
            ['name' => 'Produto B', 'sku' => 'B2', 'qty' => 1, 'price' => '5.50'],
        ];
        $calc = order_helper_calculate_totals($items, '4,50', 'USD');
        $this->assertEquals([], $calc['errors']);
        $this->assertEquals(25.5, $calc['subtotal']);
        $this->assertEquals(4.5, $calc['shipping']);
        $this->assertEquals(30.0, $calc['total']);
        $this->assertEquals(2, count($calc['items']));
    }

    public function testInvalidItemsAreDiscarded(): void
    {
        $items = [
            ['name' => '', 'sku' => 'A1', 'qty' => 2, 'price' => '10'],
            ['name' => 'Produto válido', 'sku' => '', 'qty' => 0, 'price' => '0.00'],
            ['name' => 'Produto B', 'sku' => '', 'qty' => 1, 'price' => '12'],
        ];
        $calc = order_helper_calculate_totals($items, '0', 'USD');
        $this->assertEquals([], $calc['errors'], 'Deve sobrar ao menos um item válido.');
        $this->assertEquals(12.0, $calc['subtotal']);
        $this->assertEquals(12.0, $calc['total']);
        $this->assertEquals(1, count($calc['items']));
    }

    public function testRequiresAtLeastOneValidItem(): void
    {
        $items = [
            ['name' => '', 'sku' => '', 'qty' => 1, 'price' => '0'],
        ];
        $calc = order_helper_calculate_totals($items, '0', 'USD');
        $this->assertTrue(!empty($calc['errors']), 'Deve retornar erro sem itens válidos.');
    }
}
