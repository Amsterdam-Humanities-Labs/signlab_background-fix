#!/usr/bin/env bash
# Generates a tiny 1440x1252 1-second test clip with audio, matching production ratio.
set -e
DIR="$(dirname "$0")/fixtures"
mkdir -p "$DIR"
ffmpeg -hide_banner -loglevel error -y \
  -f lavfi -i "testsrc=size=1440x1252:rate=25:duration=1" \
  -f lavfi -i "sine=frequency=440:duration=1" \
  -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest \
  "$DIR/R00000000_1.mp4"
echo "wrote $DIR/R00000000_1.mp4"
