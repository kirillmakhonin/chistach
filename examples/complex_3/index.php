<?php

require_once('file1.php');
require_once(   "file2.php" /* test */  );


function test30(){

}

\asasf\test30();

test12();

function i1(){

}

function a(){
    b();
}

function b(){
    c();
}

function c(){
    d();
}

function d(){
    a();
}