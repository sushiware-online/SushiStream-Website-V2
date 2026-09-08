<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client as MongoClient;
use MongoDB\BSON\ObjectId;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php convert.php <videoId> <originalFilePath>\n");
    exit(1);
}

$videoId = $argv[1];
$originalPath = $argv[2];

if (!file_exists($originalPath)) {
    fwrite(STDERR, "Original file not found: $originalPath\n");
    exit(1);
}

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushi_stream");

$dir = dirname($originalPath);
$baseName = pathinfo($originalPath, PATHINFO_FILENAME);

$mp4Filename   = $baseName . '.mp4';
$tsFilename    = $baseName . '.ts';
$thumbFilename = $baseName . '.jpg';

$mp4Path   = $dir . DIRECTORY_SEPARATOR . $mp4Filename;
$tsPath    = $dir . DIRECTORY_SEPARATOR . $tsFilename;
$thumbPath = $dir . DIRECTORY_SEPARATOR . $thumbFilename;

// Single-pass FFmpeg generation
$cmd = sprintf(
    'ffmpeg -y -i %s ' .
    // Stream 1: MP4
    '-vf "scale=240:136" -c:v libx264 -preset fast -pix_fmt yuv420p -c:a aac -b:a 64k %s ' .
    // Stream 2: MPEG-1 (Added -bf 0 and -ac 1 for JSMpeg compatibility)
    '-f mpegts -codec:v mpeg1video -s 240x136 -b:v 224k -r 30 -bf 0 -codec:a mp2 -b:a 64k -ar 44100 -ac 1 %s ' .
    // Stream 3: Thumbnail
    '-ss 00:00:01 -vframes 1 -q:v 2 %s 2>&1',
    escapeshellarg($originalPath),
    escapeshellarg($mp4Path),
    escapeshellarg($tsPath),
    escapeshellarg($thumbPath)
);

exec($cmd, $output, $returnVar);

if ($returnVar === 0 && file_exists($mp4Path) && file_exists($tsPath)) {
    $db->videos->updateOne(
        ['_id' => new ObjectId($videoId)],
        ['$set' => [
            'status'     => 'ready',
            'files'      => [
                'mp4'   => $mp4Filename,
                'ts'    => $tsFilename,
                'thumb' => $thumbFilename
            ],
            'metadata'   => [
                'resolution' => '240x136',
                'video_bitrate' => '224k',
                'audio_bitrate' => '64k'
            ],
            'converted_at' => new \MongoDB\BSON\UTCDateTime()
        ]]
    );
    @unlink($originalPath);
} else {
    error_log("FFmpeg error on video $videoId: " . implode("\n", $output));
    $db->videos->updateOne(
        ['_id' => new ObjectId($videoId)],
        ['$set' => [
            'status'     => 'failed',
            'error_log'  => implode("\n", array_slice($output, -10))
        ]]
    );
}
