<?php
// This is a simple XDebug test script

// Enable debugging for this script
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if XDebug is enabled
if (!extension_loaded('xdebug')) {
    die('XDebug is not loaded!');
}

// Print out XDebug configuration
echo "<h1>XDebug Configuration</h1>";
echo "<pre>";
echo "XDebug version: " . phpversion('xdebug') . "\n";
echo "xdebug.client_host: " . ini_get('xdebug.client_host') . "\n";
echo "xdebug.client_port: " . ini_get('xdebug.client_port') . "\n";
echo "xdebug.mode: " . ini_get('xdebug.mode') . "\n";
echo "xdebug.start_with_request: " . ini_get('xdebug.start_with_request') . "\n";
echo "xdebug.idekey: " . ini_get('xdebug.idekey') . "\n";
echo "</pre>";

// A simple function to test breakpoints
function testDebug()
{
    $a = 1;
    $b = 2;
    $c = $a + $b; // Set a breakpoint on this line
    return $c;
}

echo "<h2>Testing breakpoint</h2>";
echo "Result: " . testDebug();

// Try to manually trigger XDebug connection
echo "<h2>Try these links with debug enabled:</h2>";
echo "<a href='xdebug-test.php?XDEBUG_SESSION_START=PHPSTORM'>Click to start debug session with PHPSTORM</a><br>";
echo "<a href='xdebug-test.php?XDEBUG_TRIGGER=PHPSTORM'>Click to trigger debug with PHPSTORM</a><br>";

// Output phpinfo focused on XDebug
echo "<h2>XDebug info from phpinfo()</h2>";
echo "<div style='height: 300px; overflow: auto;'>";
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_contents();
ob_end_clean();

// Extract xdebug section from phpinfo output
$startPos = strpos($info, 'xdebug');
if ($startPos !== false) {
    $endPos = strpos($info, '</table>', $startPos);
    if ($endPos !== false) {
        $xdebugInfo = substr($info, $startPos, $endPos - $startPos + 8);
        echo $xdebugInfo;
    }
}
echo "</div>";