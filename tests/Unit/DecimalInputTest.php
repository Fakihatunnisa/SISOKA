<?php

namespace Tests\Unit;

use App\Support\DecimalInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalInputTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            ['0,123', 0.123], ['0.123', 0.123], ['1,2345', 1.2345],
            ['13.172', 13172.0], ['13,172', 13172.0], ['1.2340', 1.234], [1.234, 1.234],
            ['1.234,56', 1234.56], ['1,234.56', 1234.56],
            ['1.234.567', 1234567.0], ['-12,34', -12.34],
            ['Rp 1.234,50', 1234.5], ['12,34%', 12.34], ['0', 0.0],
            ['', null], [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_parses_numbers_without_losing_decimal_precision($input, $expected): void
    {
        $this->assertSame($expected, DecimalInput::parse($input));
    }

    public static function invalidNumbers(): array
    {
        return [['abc'], ['-'], ['1a2'], ['1.23.456'], ['1,23.4'], [[]], [true]];
    }

    #[DataProvider('invalidNumbers')]
    public function test_rejects_invalid_input_instead_of_saving_zero($input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DecimalInput::parse($input);
    }
}
