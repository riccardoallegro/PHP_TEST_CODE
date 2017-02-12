<?php
declare(strict_types=1);
//namespace stats\tests;

use PHPUnit\Framework\TestCase;
use \App\Baseball;

class BaseballTest extends TestCase
{
    public function testContaPalle()     
    {
        $b = new Baseball();

        $a = 389;
        $h = 129;
        $r = $b->calc_avg($a, $h);

        $expct = number_format($h / $a, 3);

        $this->assertEquals($r, $expct);
    }

    public function testInutile()
    {
        $this->assertTrue(true);
    }

    public function testSemprePiuInutile()
    {
        $this->assertFalse(false);
    }
}
