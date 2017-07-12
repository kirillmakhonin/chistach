<?php

function test14(){

}

function test13(){
    test14();
}

function test12(){
    test13();
}

$x = 'function test13';

function test11 /* help */ (bool $a) /* another */ : string {
    for ($i = 0; $i < 100; $i++)
        $x = 20;

    for ($i = 0; $i < 100; $i++) {
        test12();
    }

    return '213';
}



function test10(){
    test12();
}

test10();