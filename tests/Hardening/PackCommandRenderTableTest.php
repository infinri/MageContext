<?php

declare(strict_types=1);

namespace MageContext\Tests\Hardening;

use MageContext\Command\PackCommand;
use PHPUnit\Framework\TestCase;

/**
 * Regression: renderTable must not emit "Array to string conversion" warnings
 * when cell values contain nested arrays.
 *
 * @see https://github.com/…/issues/XX — PHP Warning on line 305
 */
class PackCommandRenderTableTest extends TestCase
{
    /**
     * Nested array values in a table row must be stringified without PHP warnings.
     */
    public function testRenderTableHandlesNestedArrayValues(): void
    {
        $command = new PackCommand();
        $method = new \ReflectionMethod($command, 'renderTable');
        $method->setAccessible(true);

        $rows = [
            [
                'name' => 'SomePlugin',
                'methods' => ['beforeSave', ['nested', 'array']],
                'scope' => 'global',
            ],
        ];
        $keys = ['name', 'methods', 'scope'];

        // Convert warnings to exceptions so the test fails on "Array to string conversion"
        set_error_handler(static function (int $errno, string $errstr): never {
            throw new \RuntimeException($errstr, $errno);
        }, E_WARNING);

        try {
            $result = $method->invoke($command, $rows, $keys);
        } finally {
            restore_error_handler();
        }

        $this->assertIsString($result);
        $this->assertStringContainsString('SomePlugin', $result);
        $this->assertStringContainsString('beforeSave', $result);
        $this->assertStringContainsString('global', $result);
    }

    /**
     * Flat array values (no nesting) continue to work as before.
     */
    public function testRenderTableHandlesFlatArrayValues(): void
    {
        $command = new PackCommand();
        $method = new \ReflectionMethod($command, 'renderTable');
        $method->setAccessible(true);

        $rows = [
            [
                'name' => 'MyObserver',
                'events' => ['checkout_submit_all_after', 'sales_order_save_after'],
            ],
        ];
        $keys = ['name', 'events'];

        $result = $method->invoke($command, $rows, $keys);

        $this->assertStringContainsString('checkout_submit_all_after', $result);
        $this->assertStringContainsString('sales_order_save_after', $result);
    }
}
