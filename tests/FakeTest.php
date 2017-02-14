<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Baseball;

class FakeTest extends TestCase
{
    //#IFDEF(##WINDOWS)
    //#BEGIN
    public function testWindowsTrue()
    {
        $this->assertTrue(true);
    }

    public function testWindowsFalse()
    {
        $this->assertFalse(true);
    }
    //#END
}
