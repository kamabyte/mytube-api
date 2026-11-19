#!/bin/sh
set -eu

# Development only: install ImageMagick, then run sh scripts/generate-icons.sh.
cd "$(dirname "$0")/.."
master=resources/images/app-icon.png
mkdir -p public/icons

magick "$master" -resize 180x180 -strip public/apple-touch-icon.png
magick "$master" -resize 32x32 -strip public/favicon-32x32.png
magick "$master" -resize 48x48 -strip -define icon:auto-resize=48,32,16 public/favicon.ico
magick "$master" -resize 192x192 -strip public/icons/icon-192.png
magick "$master" -resize 512x512 -strip public/icons/icon-512.png
