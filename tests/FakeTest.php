<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Baseball;

/**
* Commento inutile per il branch "ubuntu"
*/


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

    //#IFDEF(##UBUNTU _OR_ ##LINUX)
    //#BEGIN
    public function testFalse()
    {
        $this->assertFalse(true);
    }

    public function testFalse2()
    {
        $this->assertFalse(true);
    }
    //#END
}
