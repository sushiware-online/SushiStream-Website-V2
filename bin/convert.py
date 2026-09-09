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

# If the uploaded source file is already an .mp4, its path is identical to
# mp4_path above. ffmpeg refuses to use the same file as both input and
# output ("Output file is empty / same file" error), so in that case we
# encode to a temp file first and then atomically swap it into place.
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
    mp4_cmd = ["ffmpeg", "-y", "-i", input_path, "-c:v", "libx264", "-preset", "fast", "-s", "240x135", "-c:a", "aac", mp4_output_target]
    if not run_ffmpeg(mp4_cmd, "MP4"): sys.exit(1)

    # If we encoded to a temp file (because the source was already .mp4),
    # swap it into place now that encoding succeeded.
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
        "-s", "240x135",
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
            }
        }}
    )
    print(f"Database update acknowledged: {result.modified_count} docs changed.")
    print("Conversion completely finished!")

except Exception as e:
    error_trace = traceback.format_exc()
    print(f"Fatal Python Error:\n{error_trace}")
    db.videos.update_one({"_id": query_id}, {"$set": {"status": "failed", "error_log": error_trace[-1000:]}})
