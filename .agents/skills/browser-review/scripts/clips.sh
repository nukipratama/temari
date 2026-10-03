#!/bin/sh
# Encodes record.mjs recordings into mp4/gif clips for `gh pr edit --attach`. Needs ffmpeg with
# libx264; run it on the host or in the Sail container, from anywhere in the repo.
#
#   clips.sh render <recording> [slow-factor]   frames -> storage/app/clips/<recording>.mp4
#                                               (+ <recording>-slow.mp4 slowed by the factor); deletes the frames
#   clips.sh sbs <feature-ground-viewport[-reduced]> [slow]
#                                               <base>-before.mp4 | <base>-after.mp4 -> <base>-sbs.mp4 (before left)
#   clips.sh gif <clip.mp4>                     <clip>.gif, 12 fps, 480px wide
#
# Env: CRF (28), CLIP_W/CLIP_H (1280x900 bound per clip), SBS_W/SBS_H (960x900 bound per side).
set -eu
cd "$(dirname "$0")/../../../.."
OUT=storage/app/clips
LIMIT=10485760

report() {
    size=$(wc -c <"$1")
    echo "$1  $((size / 1024)) KB"
    if [ "$size" -gt "$LIMIT" ]; then
        echo "WARNING: $1 is over 10 MB; GitHub may refuse the attachment. Raise CRF or shorten the scenario." >&2
    fi
}

encode() {
    ffmpeg -v error -y -f concat -i "$1" \
        -vf "setpts=$3*PTS,fps=30,scale=w=${CLIP_W:-1280}:h=${CLIP_H:-900}:force_original_aspect_ratio=decrease:force_divisible_by=2,format=yuv420p" \
        -c:v libx264 -crf "${CRF:-28}" -preset slow -movflags +faststart -an "$2"
    report "$2"
}

case "${1:-}" in
render)
    dir=$OUT/.frames/$2
    encode "$dir/frames.ffconcat" "$OUT/$2.mp4" 1
    if [ -n "${3:-}" ]; then
        encode "$dir/frames.ffconcat" "$OUT/$2-slow.mp4" "$3"
    fi
    rm -rf "$dir"
    ;;
sbs)
    suffix=""
    if [ "${3:-}" = "slow" ]; then suffix="-slow"; fi
    fit="scale=w=${SBS_W:-960}:h=${SBS_H:-900}:force_original_aspect_ratio=decrease:force_divisible_by=2"
    ffmpeg -v error -y -i "$OUT/$2-before$suffix.mp4" -i "$OUT/$2-after$suffix.mp4" \
        -filter_complex "[0:v]$fit,pad=iw+8:ih:0:0:color=gray[l];[1:v]$fit[r];[l][r]hstack=inputs=2,format=yuv420p" \
        -c:v libx264 -crf "${CRF:-28}" -preset slow -movflags +faststart -an "$OUT/$2-sbs$suffix.mp4"
    report "$OUT/$2-sbs$suffix.mp4"
    ;;
gif)
    ffmpeg -v error -y -i "$2" \
        -vf "fps=12,scale=480:-2:flags=lanczos,split[a][b];[a]palettegen=max_colors=96[p];[b][p]paletteuse=dither=bayer:bayer_scale=4" \
        "${2%.mp4}.gif"
    report "${2%.mp4}.gif"
    ;;
*)
    sed -n '2,12p' "$0"
    exit 2
    ;;
esac
