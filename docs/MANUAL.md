# Manual

## Settings

*Administration → Modules → Metadata Cleaner → Configure* (system administrators only).

| Setting | Default | Description |
|---|---|---|
| File types to clean | all | Files of unticked types are stored as uploaded, with their metadata. |
| Log of removed metadata | Field names only | *Off*, *Field names only* or *Field names and values*. Values can contain personal data such as the GPS position. |
| Keep log entries for (days) | 30 | Older entries are deleted by the daily cron job. |
| Keep the photo orientation tag | yes | Keeps only the EXIF Orientation tag, so phone photos are not shown rotated. |
| When a file cannot be cleaned | Accept the file unchanged | Or *Reject the file*: the upload fails with a message to the user. |

## What is removed

| Type | Removed | Kept |
|---|---|---|
| JPEG | EXIF, XMP (also extended), IPTC/Photoshop, comments, other APPn segments, data after the end of the image (maker trailers, motion photos, Ultra HDR gain maps) | JFIF, ICC profile, Adobe colour transform, image data; optionally a minimal EXIF block with the Orientation tag |
| PNG | eXIf, tEXt, zTXt, iTXt (XMP), tIME and unknown chunks | image, transparency, colour and APNG animation chunks |
| WebP | EXIF and XMP chunks, unknown chunks | image, alpha, ICC profile, animation |
| GIF | comments, application extensions (XMP) | images, palettes, animation loop, ICC profile |
| HEIC/HEIF/AVIF | EXIF and XMP items (bytes zeroed, items hidden), the short video of motion photos (`mpvd` box) | all image items and properties, including rotation |
| MP4/MOV/3GP, M4A | udta and meta boxes of the movie and of each track (GPS, device, Apple keys), XMP | all tracks and media data |
| WebM/MKV | Tags, Attachments (cover pictures, files), segment title and date, track names; the muxing and writing application names are emptied | all tracks, clusters, cues and chapters |
| MP3, AAC (ADTS) | ID3v2, ID3v1, APEv2 and Lyrics3 tags (title, artist, comments, pictures, encoder, dates) | audio frames |
| FLAC | Vorbis comments, pictures and application blocks (turned into padding) | stream info, seek table, cue sheet, audio frames |
| Ogg (Vorbis, Opus, Theora, Speex, FLAC) | all comments of the comment header (the encoder vendor string is kept) | all packets; page checksums are recomputed |
| TIFF | all tags not needed to decode the image (EXIF, GPS, XMP, IPTC, device and author tags) | image data and the tags that describe it |
| RAW DNG | as TIFF, plus maker notes (DNGPrivateData), original raw file name and data, preview application, settings and date | raw image data and the DNG tags needed to develop it (CFA layout, colour matrices, calibration, UniqueCameraModel, opcodes) |

Files of any other type are not changed.

## Console commands

```
php yii metadata-cleaner/clean [--dry-run] [--batch=100] [--from-id=0]
```

Cleans the files already stored, for example those uploaded before the module was enabled. Previews are deleted and re-created by HumHub when needed. `--dry-run` only lists the files that would change. `--from-id` resumes an interrupted run after the given file id.

```
php yii metadata-cleaner/purge-log
```

Deletes log entries older than the retention period (this also runs every day with the HumHub cron).
