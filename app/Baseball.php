<?php
namespace App;

class Baseball
{
    protected $hits;

    /**
    * Metodo per inizializzare la classe.
    * (e invece non è vero)
    */    
    public function setup()
    {
        $this->hits = 0;
    }

    /**
    * Add two given values together and divide by 2
    */
    public function calc_avg($ab, $hits)
    {
        $res = "0.000";

        if ($ab !== 0) {
            $res = number_format($hits / $ab, 3);
        }

        return $res;
    }

    /**
    * On Base Percentage
    */
    public function calc_obp($ab, $bb, $hp, $sac, $hits)
    {
        if (!($total = $ab + $bb + $hp + $sac)) {
            $obp = "0.000";
        } else {
            $obp = number_format(($hits + $bb + $hp + $sac) / $total, 3);
        }

        return $obp;
    }

    /**
     * calculate slugging percentage
     */
    public function calc_slg($ab, $singles, $doubles, $triples, $hr)
    {
        if ($ab == 0) {
            $slg = "0.000";
        } else {
            $slg = number_format((($singles*1)+($doubles*2)+($triples*3)+($hr*4))/$ab, 3);
        }
        return $slg;
    }

    /**
     * abc
     */
    private function calc_ops($ab, $singles, $doubles, $triples, $hr)
    {
        $slg = number_format((($singles*1)+($doubles*2)+($triples*3)+($hr*4))/$ab, 3);
        $obp = number_format(($hits + $bb + $hp + $sac)/$ab, 3);
        $ops = $slg + $obp;
        return $ops;
    }
}
