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
    public function testTrue()
    {
        $this->assertTrue(true);
    }

    public function testTrue2()
    {
        $this->assertTrue(true);
    }

    public function testFalse()
    {
        $this->assertFalse(true);
    }

    public function testFalse2()
    {
        $this->assertFalse(true);
    }
}
