<?php
$file = $_GET['file'] ?? null;
if (!$file) {
    http_response_code(400);
    exit('Missing file parameter');
}

$base = pathinfo($file, PATHINFO_FILENAME);
$jpgPath = __DIR__ . "/user-content/videos/" . basename($base) . ".jpg";

if (file_exists($jpgPath)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    readfile($jpgPath);
    exit;
}

http_response_code(404);
exit('Thumbnail not found');
