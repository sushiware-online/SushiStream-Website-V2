<?php
// Include the autoloader based on the existing API structure
require __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\Client;

// Set headers to output as a downloadable CSV file
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="videos.csv"');
header('Access-Control-Allow-Origin: *');

// Connect to MongoDB using the same logic found in your other scripts
$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$client = new Client("mongodb://$mongoHost:27017");
$db = $client->sushistream_v2;
$collection = $db->videos;

// Build the base URL for the video paths
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'];

// Open output stream directly to the browser
$output = fopen('php://output', 'w');

// Write the exact string with a # at the start, with MP4 first
fputs($output, "# Info,MPG_URL\n");

// Fetch all videos (you can add ['status' => 'ready'] to find() if you want to exclude processing/failed ones)
$cursor = $collection->find();

foreach ($cursor as $doc) {
    // 1. Format "{title} by {uploader}"
    $title = $doc['title'] ?? 'Untitled';
    $uploader = $doc['uploader'] ?? 'Unknown';
    $infoColumn = sprintf('%s by %s', $title, $uploader);

    // 2. Extract files array
    $files = $doc['files'] ?? null;

    // 3. Generate absolute URLs for the video streams
    $mpgUrl = !empty($files['mpg']) ? $baseUrl . '/user-content/videos/' . rawurlencode($files['mpg']) : '';
    //$mp4Url = !empty($files['mp4']) ? $baseUrl . '/user-content/videos/' . rawurlencode($files['mp4']) : '';

    // 4. Write row to CSV
    // Note: fputcsv automatically handles enclosing fields in quotes if they contain commas
    fputcsv($output, [$infoColumn, $mpgUrl]);
}

fclose($output);
exit;
