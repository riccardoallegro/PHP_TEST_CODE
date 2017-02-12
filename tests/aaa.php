<?php
printf("%s", __DIR__ . DIRECTORY_SEPARATOR . "../vendor/autoload.php" . PHP_EOL);

require_once(__DIR__ . DIRECTORY_SEPARATOR . "../vendor/autoload.php");

$b = new \App\Baseball;

echo getcwd() . "\n";

echo $b->calc_avg(12, 1010);
?>