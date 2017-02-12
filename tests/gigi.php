<?php
$a = [0, 1, 2, 3, 4];

// print_r($a);
// unset($a[1]);
// print_r($a);
//echo($a[1] . PHP_EOL);
foreach ($a as &$i)
{
    //echo($i . PHP_EOL);
    unset($a[$i]);
    print_r($a);
    echo("-------------------------------------------". PHP_EOL);
}