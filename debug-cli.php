<?php

// Simple CLI script to test XDebug
echo "Testing XDebug CLI debugging...\n";

function testFunction() {
    $a = 10;
    $b = 20;
    $c = $a + $b; // Set breakpoint here
    return $c;
}

$result = testFunction();
echo "Result: $result\n";