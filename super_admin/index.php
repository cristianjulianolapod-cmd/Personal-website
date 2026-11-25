<?php
declare(strict_types=1);

$dashboardPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dashboard.html';
$dashboardHtml = file_get_contents($dashboardPath);
if ($dashboardHtml === false) {
    http_response_code(500);
    exit('Dashboard tidak dapat dimuat.');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo str_replace('<head>', '<head>' . PHP_EOL . '    <base href="../" />', $dashboardHtml);
