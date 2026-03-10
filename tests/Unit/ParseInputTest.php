<?php

namespace Tests\Unit;

use App\Util\ParseInput;
use PHPUnit\Framework\TestCase;

class ParseInputTest extends TestCase
{
    public function test_ids_from_native_array()
    {
        $this->assertSame([1, 2, 3], ParseInput::ids([1, 2, 3]));
    }

    public function test_ids_from_string_array()
    {
        $this->assertSame([1, 2, 3], ParseInput::ids(['1', '2', '3']));
    }

    public function test_ids_from_json_string()
    {
        $this->assertSame([3], ParseInput::ids('[3]'));
    }

    public function test_ids_from_json_string_multiple()
    {
        $this->assertSame([1, 2, 3], ParseInput::ids('[1,2,3]'));
    }

    public function test_ids_from_comma_separated_string()
    {
        $this->assertSame([1, 2, 3], ParseInput::ids('1,2,3'));
    }

    public function test_ids_from_single_value_string()
    {
        $this->assertSame([5], ParseInput::ids('5'));
    }

    public function test_ids_from_empty_input()
    {
        $this->assertSame([], ParseInput::ids(null));
        $this->assertSame([], ParseInput::ids(''));
        $this->assertSame([], ParseInput::ids([]));
    }
}
