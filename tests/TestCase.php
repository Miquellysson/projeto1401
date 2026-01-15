<?php

declare(strict_types=1);

abstract class TestCase
{
    private array $results = [];

    protected function setUp(): void
    {
        // override as needed
    }

    protected function tearDown(): void
    {
        // override as needed
    }

    public function run(): array
    {
        $class = static::class;
        $methods = array_filter(get_class_methods($this), static fn($method) => str_starts_with($method, 'test'));
        foreach ($methods as $method) {
            $this->setUp();
            $error = null;
            try {
                $this->$method();
                $this->results[] = ['test' => "{$class}::{$method}", 'status' => 'ok'];
            } catch (AssertionError $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
            if ($error !== null) {
                $this->results[] = ['test' => "{$class}::{$method}", 'status' => 'fail', 'message' => $error];
            }
            $this->tearDown();
        }
        return $this->results;
    }

    protected function assertTrue($condition, string $message = 'Failed asserting that condition is true'): void
    {
        if (!$condition) {
            throw new AssertionError($message);
        }
    }

    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            $msg = $message !== '' ? $message : sprintf("Failed asserting that %s matches expected %s", var_export($actual, true), var_export($expected, true));
            throw new AssertionError($msg);
        }
    }

    protected function assertSame($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $msg = $message !== '' ? $message : sprintf("Failed asserting that %s is identical to expected %s", var_export($actual, true), var_export($expected, true));
            throw new AssertionError($msg);
        }
    }
}
