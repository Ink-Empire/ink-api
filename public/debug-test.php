<?php

// This is a test file to debug XDebug connectivity
function testFunction()
{
    $x = 1;
    $y = 2;
    $z = $x + $y; // Set a breakpoint on this line
    return $z;
}

echo "Testing XDebug... Result: " . testFunction();