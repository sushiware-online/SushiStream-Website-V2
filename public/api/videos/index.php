<?php
require __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';

try {
    $client = new Client("mongodb://$mongoHost:27017");
    $db = $client->sushistream_v2;
    $collection = $db->videos;

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $baseUrl = $protocol . $_SERVER['HTTP_HOST'];

    // Single video lookup (?id=...)
    if (!empty($_GET['id'])) {
        $id = $_GET['id'];
        if (!preg_match('/^[0-9a-fA-F]{24}$/', $id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid Video ID format']);
            exit;
        }

        $video = $collection->findOne(['_id' => new ObjectId($id)]);
        if (!$video) {
            http_response_code(404);
            echo json_encode(['error' => 'Video not found']);
            exit;
        }

        echo json_encode(formatVideoResponse($video, $baseUrl), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // List videos with filters & pagination
    $status = $_GET['status'] ?? 'ready';
    $filter = [];
    if ($status !== 'all') {
        $filter['status'] = $status;
    }

    $limit = isset($_GET['limit']) ? min(max((int)$_GET['limit'], 1), 100) : 20;
    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
    $skip = ($page - 1) * $limit;

    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'sort'  => ['uploaded_at' => -1],
        'skip'  => $skip,
        'limit' => $limit
    ]);

    $items = [];
    foreach ($cursor as $doc) {
        $items[] = formatVideoResponse($doc, $baseUrl);
    }

    echo json_encode([
        'version'     => '2.0.0',
        'page'        => $page,
        'limit'       => $limit,
        'total_items' => $total,
        'total_pages' => ceil($total / $limit),
        'videos'      => $items
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}

function formatVideoResponse($doc, string $baseUrl): array
{
    $files = $doc['files'] ?? null;
    return [
        'id'          => (string)$doc['_id'],
        'title'       => $doc['title'] ?? '',
        'uploader'    => $doc['uploader'] ?? '',
        'status'      => $doc['status'] ?? 'ready',
        'metadata'    => $doc['metadata'] ?? [
            'resolution' => '240x136',
            'video_bitrate' => '224k',
            'audio_bitrate' => '64k'
        ],
        'urls'        => [
            'watch'     => $baseUrl . '/watch/index.php?v=' . (string)$doc['_id'],
            'mp4'       => !empty($files['mp4']) ? $baseUrl . '/user-content/videos/' . rawurlencode($files['mp4']) : null,
            'mpeg1_ts'  => !empty($files['ts']) ? $baseUrl . '/user-content/videos/' . rawurlencode($files['ts']) : null,
	    'mpg'       => !empty($files['mpg']) ? $baseUrl . '/user-content/videos/' . rawurlencode($files['mpg']) : null,
            'thumbnail' => !empty($files['thumb']) ? $baseUrl . '/user-content/videos/' . rawurlencode($files['thumb']) : null,
        ],
        'uploaded_at' => isset($doc['uploaded_at']) ? $doc['uploaded_at']->toDateTime()->format(DATE_ATOM) : null
    ];
}
