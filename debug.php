<?php
echo "<h2>Debugging Paths</h2>";
echo "<b>Current File Path:</b> " . __DIR__ . "<br><br>";

$envFile = __DIR__ . '/.env';
echo "<b>Looking for .env at:</b> " . $envFile . "<br>";

if (file_exists($envFile)) {
    echo "<span style='color:green;font-weight:bold;'>✅ .env file FOUND!</span><br>";
    $contents = file_get_contents($envFile);
    if (strpos($contents, 'DOC_ENC_KEY') !== false) {
        echo "<span style='color:green;'>✅ DOC_ENC_KEY string is inside the file.</span><br>";
    } else {
        echo "<span style='color:red;'>❌ DOC_ENC_KEY string is missing from the file contents.</span><br>";
    }
} else {
    echo "<span style='color:red;font-weight:bold;'>❌ .env file MISSING! (Check your file extensions, it might be .env.txt)</span><br>";
}
?>