<?php
/**
 * Created by PhpStorm.
 * User: Youi
 * Date: 2015-09-24
 * Time: 00:56
 */

/*
$date_one = new DateTime('2015-9-20');
$date_two = new DateTime('2015-9-5');

$diff = $date_one->diff($date_two);

echo $diff->format('%y %m %d %a');
*/
//$proc = proc_open("aria2c",
//    array(
//        array("pipe","r"),
//        array("pipe","w"),
//        array("pipe","w")
//    ),
//    $pipes);
//print stream_get_contents($pipes[2]);

var_dump(`aria2c 2>&1`);