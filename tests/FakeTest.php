<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Baseball;

/**
 * Aggiungo un commento.
 * Questa modifica dovrebbe essere visibile solo nel branch "windows"
 */
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

    public function testFalse()
    {
        $this->assertFalse(true);
    }

    public function testFalse2()
    {
        $this->assertFalse(true);
    }
>>>>>>> windows
}
