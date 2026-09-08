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
$db = $mongo->selectDatabase("sushistream_v2");

$dir = dirname($originalPath);
$baseName = pathinfo($originalPath, PATHINFO_FILENAME);

$mp4Filename   = $baseName . '.mp4';
$tsFilename    = $baseName . '.ts';
$thumbFilename = $baseName . '.jpg';

$mp4Path   = $dir . DIRECTORY_SEPARATOR . $mp4Filename;
$tsPath    = $dir . DIRECTORY_SEPARATOR . $tsFilename;
$thumbPath = $dir . DIRECTORY_SEPARATOR . $thumbFilename;

$phpBinary = PHP_BINARY; 
$convertScript = realpath(__DIR__ . '/../../bin/convert.php');
$logPath = __DIR__ . '/../../public/user-content/videos/ffmpeg_debug.log';

// Build the command and log it to PHP-FPM error logs just in case
$cmd = sprintf(
    '%s %s %s %s > %s 2>&1 &',
    escapeshellarg($phpBinary ?: 'php'), 
    escapeshellarg($convertScript),
    escapeshellarg($videoId),
    escapeshellarg($originalPath),
    escapeshellarg($logPath)
);

error_log("SushiStream Executing: " . $cmd);
exec($cmd);

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
