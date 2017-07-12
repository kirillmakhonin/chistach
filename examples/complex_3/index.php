<?php

require_once 'file1.php';
require_once 'file2.php';


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