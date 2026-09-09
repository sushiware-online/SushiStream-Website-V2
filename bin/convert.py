#!/usr/bin/env python3
import sys
import os
import subprocess
import traceback
from pymongo import MongoClient
from bson.objectid import ObjectId # <--- CRITICAL FIX for MongoDB IDs
from dotenv import load_dotenv

print("Starting Python Conversion Script...")

# 1. Load Environment Variables
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
load_dotenv(os.path.join(BASE_DIR, '.env'))

video_id = sys.argv[1]
input_path = sys.argv[2]
print(f"Processing Video ID: {video_id}")
print(f"Input Path: {input_path}")

# 2. Setup Database Connection
mongo_host = os.getenv("MONGO_HOST", "127.0.0.1")
db_name = os.getenv("MONGO_DB_NAME", "sushistream_v2")
client = MongoClient(f"mongodb://{mongo_host}:27017/")
db = client[db_name]

# Safely convert string ID to MongoDB ObjectId
try:
    if len(video_id) == 24:
        query_id = ObjectId(video_id)
    else:
        query_id = video_id
except:
    query_id = video_id

# 3. Define Output Paths
output_dir = os.path.dirname(input_path)
filename_base = os.path.splitext(os.path.basename(input_path))[0]
mp4_path = os.path.join(output_dir, f"{filename_base}.mp4")
mpg_path = os.path.join(output_dir, f"{filename_base}.mpg")
thumb_path = os.path.join(output_dir, f"{filename_base}.jpg")

# If the uploaded source is already an .mp4, input_path and mp4_path are the
# same file. ffmpeg can't use the same file as both input and output, so in
# that case we encode to a temp file and swap it into place afterward.
mp4_output_target = mp4_path
mp4_is_same_as_input = os.path.abspath(input_path) == os.path.abspath(mp4_path)
if mp4_is_same_as_input:
    mp4_output_target = os.path.join(output_dir, f"{filename_base}.tmp.mp4")

def run_ffmpeg(command, step_name):
    print(f"Running FFmpeg for: {step_name}...")
    try:
        result = subprocess.run(command, stderr=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        if result.returncode != 0:
            print(f"FFmpeg Error on {step_name}:\n{result.stderr}")
            raise Exception(result.stderr)
        print(f"Success: {step_name}")
        return True
    except Exception as e:
        error_tail = str(e)[-1000:]
        db.videos.update_one({"_id": query_id}, {"$set": {"status": "failed", "error_log": error_tail}})
        return False

try:
    # 4. Generate MP4 (H.264 + AAC)
    mp4_cmd = ["ffmpeg", "-y", "-i", input_path, "-c:v", "libx264", "-preset", "fast", "-vf", "scale=240:136:flags=lanczos,setsar=1", "-c:a", "aac", mp4_output_target]
    if not run_ffmpeg(mp4_cmd, "MP4"): sys.exit(1)

    # If we encoded to a temp file (source was already .mp4), swap it into place.
    if mp4_is_same_as_input:
        os.replace(mp4_output_target, mp4_path)

    # 5. Generate true MPEG-1 Program Stream (.mpg) for native <video> playback.
    mpg_cmd = [
        "ffmpeg", "-y", "-i", input_path,
        "-f", "mpeg",
        "-codec:v", "mpeg1video",
        "-b:v", "224k",
        "-bf", "0",
        "-r", "30",
        "-vf", "scale=240:136:flags=lanczos,setsar=1",
        "-codec:a", "mp2",
        "-ar", "44100",
        "-ac", "1",
        "-b:a", "64k",
        mpg_path
    ]
    if not run_ffmpeg(mpg_cmd, "MPEG-1 Program Stream"): sys.exit(1)

    # 6. Generate Thumbnail
    thumb_cmd = ["ffmpeg", "-y", "-i", input_path, "-ss", "00:00:01", "-vframes", "1", thumb_path]
    run_ffmpeg(thumb_cmd, "Thumbnail")

    # 6b. Probe the encoded MP4/MPG for real metadata (duration, resolution, codecs, size)
    def probe_metadata(path):
        try:
            probe_cmd = [
                "ffprobe", "-v", "error",
                "-show_entries", "format=duration,size,bit_rate",
                "-show_entries", "stream=codec_type,codec_name,width,height,avg_frame_rate",
                "-of", "json",
                path
            ]
            result = subprocess.run(probe_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            if result.returncode != 0:
                print(f"ffprobe error: {result.stderr}")
                return {}
            import json as _json
            data = _json.loads(result.stdout)
            fmt = data.get("format", {})
            streams = data.get("streams", [])

            video_stream = next((s for s in streams if s.get("codec_type") == "video"), {})
            audio_stream = next((s for s in streams if s.get("codec_type") == "audio"), {})

            fps = None
            raw_fps = video_stream.get("avg_frame_rate")
            if raw_fps and raw_fps != "0/0":
                try:
                    num, den = raw_fps.split("/")
                    fps = round(int(num) / int(den), 2) if int(den) != 0 else None
                except Exception:
                    fps = None

            return {
                "duration_seconds": float(fmt.get("duration", 0)) if fmt.get("duration") else None,
                "size_bytes": int(fmt.get("size", 0)) if fmt.get("size") else None,
                "bitrate": int(fmt.get("bit_rate", 0)) if fmt.get("bit_rate") else None,
                "width": video_stream.get("width"),
                "height": video_stream.get("height"),
                "fps": fps,
                "video_codec": video_stream.get("codec_name"),
                "audio_codec": audio_stream.get("codec_name"),
            }
        except Exception as e:
            print(f"Metadata probe failed for {path}: {e}")
            return {}

    print("Probing metadata...")
    mp4_metadata = probe_metadata(mp4_path)
    mpg_metadata = probe_metadata(mpg_path)
    print(f"MP4 metadata: {mp4_metadata}")
    print(f"MPG metadata: {mpg_metadata}")

    # 7. Update Database on Success
    print("Updating database to READY...")
    result = db.videos.update_one(
        {"_id": query_id},
        {"$set": {
            "status": "ready",
            "files": {
                "mp4": f"{filename_base}.mp4",
                "mpg": f"{filename_base}.mpg",
                "thumbnail": f"{filename_base}.jpg",
                "thumb": f"{filename_base}.jpg"
            },
            "metadata": {
                "mp4": mp4_metadata,
                "mpg": mpg_metadata
            }
        }}
    )
    print(f"Database update acknowledged: {result.modified_count} docs changed.")
    print("Conversion completely finished!")

except Exception as e:
    error_trace = traceback.format_exc()
    print(f"Fatal Python Error:\n{error_trace}")
    db.videos.update_one({"_id": query_id}, {"$set": {"status": "failed", "error_log": error_trace[-1000:]}})
