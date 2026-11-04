#!/usr/bin/env bash
# Regenerates the synthetic test files in tests/codeception/_data/fixtures.
# Needs PHP with GD and exiftool. Only used for development, never at runtime.
set -euo pipefail
BIN="$(cd "$(dirname "$0")" && pwd)"
cd "$BIN/../codeception/_data"
mkdir -p fixtures && cd fixtures
rm -f ./*

php -r '
$im = imagecreatetruecolor(160, 100);
for ($x = 0; $x < 160; $x++) {
    for ($y = 0; $y < 100; $y++) {
        imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 3) % 256, ($y * 5) % 256, ($x * $y) % 256));
    }
}
imagejpeg($im, "plain.jpg", 90);
imageinterlace($im, true);
imagejpeg($im, "progressive.jpg", 85);
imageinterlace($im, false);
imagepng($im, "plain.png");
imagewebp($im, "plain.webp", 90);
$p = imagecreate(160, 100);
for ($i = 0; $i < 16; $i++) {
    imagefilledrectangle($p, $i * 10, 0, $i * 10 + 9, 99, imagecolorallocate($p, $i * 16, 255 - $i * 16, 128));
}
imagegif($p, "plain.gif");
'

TAGS=(-GPSLatitude=45.4642 -GPSLatitudeRef=N -GPSLongitude=9.19 -GPSLongitudeRef=E -GPSAltitude=120
      -Make=TestPhone -Model=X1 -Software=TestOS -SerialNumber=SN12345 -DateTimeOriginal="2026:11:04 08:00:00"
      -XMP:CreatorTool=Tester -XMP:Creator="Mario Rossi" -XMP-exif:GPSLatitude=45.4642)

for f in plain.jpg progressive.jpg; do
    out="meta-${f}"
    cp "$f" "$out"
    exiftool -q -overwrite_original "${TAGS[@]}" -IPTC:By-line="Mario Rossi" -IPTC:City=Milano \
        -Comment="secret comment" -Orientation#=6 -ThumbnailImage'<='plain.jpg "$out"
done

cp plain.png meta.png
exiftool -q -overwrite_original "${TAGS[@]}" -PNG:Author="Mario Rossi" -PNG:Comment="secret" -Orientation#=6 meta.png

cp plain.webp meta.webp
exiftool -q -overwrite_original "${TAGS[@]}" meta.webp

cp plain.gif meta.gif
exiftool -q -overwrite_original -XMP:CreatorTool=Tester -XMP:Creator="Mario Rossi" -Comment="secret comment" meta.gif

# Broken files: must be rejected, never half-cleaned.
head -c 600 meta-plain.jpg > broken.jpg
head -c 200 meta.png > broken.png
head -c 100 meta.webp > broken.webp
head -c 100 meta.gif > broken.gif

# JPEG with data after the end-of-image marker (like phone trailers).
cp meta-plain.jpg trailer.jpg
printf 'SEFHMotionPhoto_Data secret trailer' >> trailer.jpg

ls -l

# Videos (needs ffmpeg). Metadata in moov/udta, moov/meta (Apple keys) and XMP.
ffmpeg -loglevel error -y -f lavfi -i testsrc=size=160x120:rate=10 -f lavfi -i sine=frequency=440 -t 1 \
    -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest plain.mp4
ffmpeg -loglevel error -y -i plain.mp4 -c copy -metadata location="+45.4642+009.1900/" -metadata make=TestPhone \
    -metadata model=X1 -metadata title=Secret -movflags use_metadata_tags meta.mp4
ffmpeg -loglevel error -y -i plain.mp4 -c copy -f mov -metadata location="+45.4642+009.1900/" \
    -metadata "com.apple.quicktime.make=Apple" -metadata "com.apple.quicktime.model=iPhone" -movflags use_metadata_tags meta.mov
exiftool -q -overwrite_original -XMP:CreatorTool=Tester -UserData:Make=TestPhone meta.mp4
ffmpeg -loglevel error -y -i meta.mp4 -c copy -map_metadata 0 -movflags +faststart+use_metadata_tags meta-faststart.mp4
exiftool -q -overwrite_original -XMP:CreatorTool=Tester meta-faststart.mp4
head -c 5000 meta.mp4 > broken.mp4

# HEIC (needs Python with pillow-heif: pip install pillow-heif).
python3 - <<'PY'
import pillow_heif, PIL.Image as Image
pillow_heif.register_heif_opener()
im = Image.new('RGB', (160, 100))
px = im.load()
for x in range(160):
    for y in range(100):
        px[x, y] = ((x * 3) % 256, (y * 5) % 256, (x * y) % 256)
im.save('plain.heic', quality=90)
PY
cp plain.heic meta.heic
exiftool -q -overwrite_original -GPSLatitude=45.4642 -GPSLatitudeRef=N -GPSLongitude=9.19 -GPSLongitudeRef=E \
    -Make=TestPhone -Model=X1 -SerialNumber=SN1 -XMP:CreatorTool=Tester meta.heic
head -c 300 meta.heic > broken.heic

# TIFF (needs ImageMagick): single page and multi-page.
convert plain.png -compress none plain.tif
convert plain.png plain.png -compress lzw multipage.tif
for f in plain.tif multipage.tif; do
    cp "$f" "meta-$f"
    exiftool -q -overwrite_original "${TAGS[@]}" -Artist="Mario Rossi" -IPTC:By-line="Mario Rossi" -Orientation#=6 "meta-$f"
done
head -c 400 meta-plain.tif > broken.tif

# Audio, WebM/MKV and motion photos (needs ffmpeg with libmp3lame, libvorbis, libopus, libvpx).
M=(-metadata title="Secret title" -metadata artist="Mario Rossi" -metadata comment="secret comment" -metadata date=2026-11-04)
ffmpeg -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 -c:a aac -b:a 64k plain.m4a
ffmpeg -loglevel error -y -i plain.m4a -c copy "${M[@]}" -metadata location="+45.4642+009.1900/" -movflags use_metadata_tags meta.m4a
ffmpeg -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 -c:a libmp3lame -b:a 64k -write_xing 0 -id3v2_version 0 plain.mp3
ffmpeg -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 -c:a libmp3lame -b:a 64k -id3v2_version 4 -write_id3v1 1 "${M[@]}" meta.mp3
# meta-ape.mp3: the same file with an APEv2 tag (before the ID3v1 tag), written by hand.
python3 - <<'PY'
import struct
item=b'Mario Rossi'; key=b'Artist'
items=struct.pack('<II',len(item),0)+key+b'\x00'+item
size=len(items)+32
footer=b'APETAGEX'+struct.pack('<IIII',2000,size,1,0)+b'\x00'*8
data=open('meta.mp3','rb').read()
v1=data[-128:]; assert v1[:3]==b'TAG'
open('meta-ape.mp3','wb').write(data[:-128]+items+footer+v1)
PY
ffmpeg -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 -c:a aac -b:a 64k -f adts -write_id3v2 1 "${M[@]}" meta.aac
ffmpeg -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 -c:a flac plain.flac
ffmpeg -loglevel error -y -i plain.flac -i meta-plain.jpg -map 0 -map 1 -c:a copy -c:v copy -disposition:v attached_pic "${M[@]}" meta.flac
ffmpeg -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 -c:a libvorbis "${M[@]}" meta.ogg
ffmpeg -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 -c:a libopus "${M[@]}" meta.opus
ffmpeg -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 -c:a flac -f ogg "${M[@]}" meta-flac.oga
ffmpeg -loglevel error -y -f lavfi -i testsrc=size=160x120:rate=10 -f lavfi -i sine=frequency=440 -t 1 -c:v libvpx-vp9 -b:v 100k -c:a libopus -shortest "${M[@]}" meta.webm
ffmpeg -loglevel error -y -f lavfi -i testsrc=size=160x120:rate=10 -f lavfi -i sine=frequency=440 -t 1 -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest \
   "${M[@]}" -metadata:s:a:0 title="Mario mic" -attach meta-plain.jpg -metadata:s:t mimetype=image/jpeg meta.mkv
# Motion photos: a video appended to a JPEG (Google/Samsung) and an "mpvd" box after a HEIC.
cp meta-plain.jpg motion.jpg
exiftool -q -overwrite_original -XMP-GCamera:MicroVideo=1 -XMP-GCamera:MicroVideoOffset=$(stat -c%s meta.mp4) motion.jpg
cat meta.mp4 >> motion.jpg
python3 - <<'PY'
import struct
v=open('meta.mp4','rb').read()
open('motion.heic','wb').write(open('meta.heic','rb').read()+struct.pack('>I',8+len(v))+b'mpvd'+v)
PY
head -c 40 meta.mp3 > broken.mp3
for f in meta.flac meta.ogg meta.opus meta.webm meta.mkv meta.m4a; do head -c 700 "$f" > "broken.${f#meta.}"; done

# DNG raw image (written by tests/bin/make-dng.py), with metadata added by exiftool.
python3 "$BIN/make-dng.py"
cp plain.dng meta.dng
exiftool -q -overwrite_original -GPSLatitude=45.4642 -GPSLatitudeRef=N -GPSLongitude=9.19 -GPSLongitudeRef=E \
    -Make=TestPhone -Model=X1 -Artist="Mario Rossi" -SerialNumber=SN12345 -DateTimeOriginal="2026:11:04 08:00:00" \
    -OriginalRawFileName=IMG_secret.CR2 -XMP:Creator="Mario Rossi" meta.dng
head -c 300 meta.dng > broken.dng
