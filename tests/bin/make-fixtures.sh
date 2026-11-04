#!/usr/bin/env bash
# Regenerates the synthetic test files in tests/codeception/_data/fixtures.
# Needs PHP with GD and exiftool. Only used for development, never at runtime.
set -euo pipefail
cd "$(dirname "$0")/../codeception/_data"
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
