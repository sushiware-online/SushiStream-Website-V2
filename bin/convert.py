#!/usr/bin/env python3
import sys
import os
import subprocess
import traceback
from pymongo import MongoClient
from dotenv import load_dotenv

# 1. Load Environment Variables & Paths
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
load_dotenv(os.path.join(BASE_DIR, '.env'))

if len(sys.argv) < 3:
    print("Usage: convert.py <video_id> <input_path>")
    sys.exit(1)

video_id = sys.argv[1]
input_path = sys.argv[2]

# 2. Setup Database Connection
mongo_host = os.getenv("MONGO_HOST", "127.0.0.1")
db_name = os.getenv("MONGO_DB_NAME", "sushistream_v2")

client = MongoClient(f"mongodb://{mongo_host}:27017/")
db = client[db_name]

# 3. Define Output Paths
output_dir = os.path.dirname(input_path)
filename_base = os.path.splitext(os.path.basename(input_path))[0]

mp4_path = os.path.join(output_dir, f"{filename_base}.mp4")
ts_path = os.path.join(output_dir, f"{filename_base}.ts")
thumb_path = os.path.join(output_dir, f"{filename_base}.jpg")

def run_ffmpeg(command):
    """Runs an FFmpeg command and returns True if successful, False otherwise."""
    try:
        # Capture stderr because FFmpeg writes its progress/errors there
        result = subprocess.run(command, stderr=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        if result.returncode != 0:
            raise Exception(result.stderr)
        return True
    except Exception as e:
        # If it crashes, log the error to the database
        error_tail = str(e)[-1000:] # Grab the last 1000 chars of the log
        db.videos.update_one(
            {"_id": video_id},
            {"$set": {"status": "failed", "error_log": error_tail}}
        )
        return False

try:
    # 4. Generate MP4
    mp4_cmd = ["ffmpeg", "-y", "-i", input_path, "-c:v", "libx264", "-preset", "fast", "-c:a", "aac", mp4_path]
    if not run_ffmpeg(mp4_cmd): sys.exit(1)

    # 5. Generate MPEG-TS (For WebAssembly / Microcontrollers)
    ts_cmd = ["ffmpeg", "-y", "-i", input_path, "-f", "mpegts", "-codec:v", "mpeg1video", "-codec:a", "mp2", ts_path]
    if not run_ffmpeg(ts_cmd): sys.exit(1)

    # 6. Generate Thumbnail
    thumb_cmd = ["ffmpeg", "-y", "-i", input_path, "-ss", "00:00:01", "-vframes", "1", thumb_path]
    run_ffmpeg(thumb_cmd) # Don't crash if thumbnail fails

    # 7. Update Database on Success
    db.videos.update_one(
        {"_id": video_id},
        {"$set": {
            "status": "ready",
            "formats": {
                "mp4": f"/user-content/videos/{filename_base}.mp4",
                "ts": f"/user-content/videos/{filename_base}.ts"
            },
            "thumbnail": f"/user-content/videos/{filename_base}.jpg"
        }}
    )

    # Optional: Delete the raw uploaded file to save space
    # os.remove(input_path)

except Exception as e:
    # Catch any Python-level crashes
    error_trace = traceback.format_exc()
    db.videos.update_one(
        {"_id": video_id},
        {"$set": {"status": "failed", "error_log": error_trace[-1000:]}}
    )
