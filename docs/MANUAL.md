# Manual

## Settings

*Administration → Modules → Metadata Cleaner → Configure* (system administrators only).

| Setting | Default | Description |
|---|---|---|
| File types to clean | all | Files of unticked types are stored as uploaded, with their metadata. |
| Log of removed metadata | Field names only | *Off*, *Field names only* or *Field names and values*. Values can contain personal data such as the GPS position. |
| Keep log entries for (days) | 30 | Older entries are deleted by the daily cron job. |
| Keep statistics for (months) | 3 | Monthly statistics buckets older than this are deleted by the daily cron job. The two retention settings are grouped under *Data retention rules* in the form. |
| Keep the photo orientation tag | yes | Keeps only the EXIF Orientation tag, so phone photos are not shown rotated. |
| When a file cannot be cleaned | Accept the file unchanged | Or *Reject the file*: the upload fails with a message to the user. |

## What is removed

| Type | Removed | Kept |
|---|---|---|
| JPEG | EXIF, XMP (also extended), IPTC/Photoshop, comments, other APPn segments, data after the end of the image (maker trailers, motion photos, Ultra HDR gain maps) | JFIF, ICC profile, Adobe colour transform, image data; optionally a minimal EXIF block with the Orientation tag |
| PNG | eXIf, tEXt, zTXt, iTXt (XMP), tIME and unknown chunks | image, transparency, colour and APNG animation chunks |
| WebP | EXIF and XMP chunks, unknown chunks | image, alpha, ICC profile, animation |
| GIF | comments, application extensions (XMP) | images, palettes, animation loop, ICC profile |
| HEIC/HEIF/AVIF | EXIF and XMP items (bytes zeroed, items hidden), EXIF stored in `mime` items, C2PA/JUMBF content-credential manifests, the short video of motion photos (`mpvd` box) | all image items and properties, including rotation |
| MP4/MOV/3GP, M4A | udta and meta boxes of the movie and of each track (GPS, device, Apple keys), XMP | all tracks and media data |
| WebM/MKV | Tags, Attachments (cover pictures, files), segment title and date, track names; the muxing and writing application names are emptied | all tracks, clusters, cues and chapters |
| MP3, AAC (ADTS) | ID3v2, ID3v1, APEv2 and Lyrics3 tags (title, artist, comments, pictures, encoder, dates) | audio frames |
| FLAC | Vorbis comments, pictures and application blocks (turned into padding) | stream info, seek table, cue sheet, audio frames |
| Ogg (Vorbis, Opus, Theora, Speex, FLAC) | all comments of the comment header (the encoder vendor string is kept) | all packets; page checksums are recomputed |
| TIFF | all tags not needed to decode the image (EXIF, GPS, XMP, IPTC, device and author tags) | image data and the tags that describe it |
| RAW (DNG, CR2, NEF, ARW, ORF, RW2) | as TIFF, plus maker notes (DNGPrivateData), original raw file name and data, preview application, settings and date | raw image data and the DNG tags needed to develop it (CFA layout, colour matrices, calibration, UniqueCameraModel, opcodes) |
| JPEG 2000 (JP2, JPX) | uuid (EXIF, XMP, vendor), xml, asoc and lbl boxes, replaced by free boxes of the same size | header and codestream |
| JPEG XL (container) | Exif, xml (XMP) and jumb (JUMBF, C2PA) boxes, also brotli-compressed, replaced by free boxes of the same size | codestream |
| WAV, RF64, AVI | LIST INFO (title, artist, comment, software), bext, iXML, axml, XMP, exif and ID3 chunks | format, sound and video data, AVI index |
| AIFF, AIFC | NAME, AUTH, (c), ANNO, COMT, ID3, iXML, XMP and APPL chunks | COMM, SSND and the other chunks needed to play the file |
| WMV, WMA (ASF) | content description, extended content description, script commands, markers, branding, metadata and metadata library objects (payload zeroed in place) | file layout and media data |
| PDF | document information dictionaries (author, title, subject, keywords, creator, producer, dates, custom entries) of every revision, also inside compressed object streams; every XMP metadata stream | pages, fonts, images and the file structure: everything is overwritten in place with data of the same size |
| Office (DOCX, XLSX, PPTX...) | docProps/core.xml, app.xml and custom.xml (author, last modified by, company, manager, template, editing time, custom properties) | every other part of the document, copied byte for byte |
| OpenDocument (ODT, ODS, ODP...) | meta.xml (creator, dates, editing time, generator, user-defined fields) | every other part of the document, copied byte for byte |
| SVG | metadata, comments, editor data (Inkscape, Sodipodi, Illustrator, Sketch...), scripts, event attributes, javascript: links, foreignObject, embedded frames | the drawing |

Files of any other type are not changed. See the README for what the module does not cover.

> **Note — very large files:** the cleaners of JPEG, PNG, WebP, GIF, TIFF/RAW, WAV, AVI, AIFF, PDF and SVG work on a copy of the file in memory, so these types cannot be cleaned above a size limit of **64 MB or the "Maximum upload file size" configured in HumHub file settings, whichever is smaller**. Larger files are then passed on unchanged (or rejected, depending on the *When a file cannot be cleaned* setting) and the error is logged. Streaming formats (MP4/MOV, WebM/MKV, ASF/WMV, MP3, M4A, FLAC, Ogg, HEIC/HEIF) have no size limit.

## Statistics

The *Statistics* panel at the bottom of the configuration page shows monthly counters: every file the module processes is counted once — *Metadata removed*, *Processed, no metadata* or an error — per format and per month, plus the total space saved. The figures cover only the configured retention period (*Keep statistics for (months)*, default 3) and are independent of the log: monthly totals older than the retention are deleted automatically every day.

> **Note — WMV/ASF:** the cleaner expects the standard ASF header layout. Files with unusual structures (DRM-protected streams, non-standard index objects) may fail cleaning: they are then passed on unchanged, or rejected, depending on the *When a file cannot be cleaned* setting, and the error is logged.
>
> **Note — Photoshop (PSD/PSB):** detected but not cleaned yet: removing image resources is not yet safe enough for production files, so they are stored unchanged.

## Cleaning existing files

New uploads are cleaned automatically. Files stored before (for example before the module was enabled, or before a format was ticked) are cleaned from the *Clean existing files* panel of the configuration page:

- **Formats:** only the formats ticked in the settings, as saved when the cleanup starts, are cleaned. Files of other formats are not changed and are counted as *skipped*. For example, with *Office / OpenDocument* unticked, Office files are left as they are.
- **Background batches:** the cleanup runs as HumHub queue jobs. Each job cleans up to 100 files or works for about 20 seconds, saves the last file processed and queues the next batch, so no web request or job runs for long. The HumHub cron jobs (queue) must be configured.
- **No blocking on errors:** a file that cannot be cleaned is kept unchanged (whatever the *When a file cannot be cleaned* setting: stored files are never deleted), counted as an error and logged; the cleanup goes on with the next file.
- **Stop and resume:** *Stop* ends the cleanup after the current file. *Resume* continues from the last file processed. A cleanup without progress for 10 minutes is shown as stalled (queue not running, job killed), with a *Resume* button.
- **Report:** while it runs, the panel refreshes every 5 seconds and shows the progress bar, files processed out of the total, and the counters *Metadata removed*, *Already without metadata*, *Skipped*, *Errors* and space saved, with the last error. Below, *Last completed cleanup* shows the dates, the formats, the same counters and whether all files were processed without errors.
- The cleanup covers the files stored when it starts; files uploaded meanwhile are cleaned on upload. Cleaned files are logged with source `bulk` and counted in the statistics.

## Console command

```
php yii metadata-cleaner/purge-log
```

Deletes log entries older than the retention period (this also runs every day with the HumHub cron). `php yii metadata-cleaner/purge-stats` does the same for the statistics buckets.

`php yii metadata-cleaner/clean` cleans all stored files in the foreground, as an alternative to the panel (options `--dryRun`/`-n`, `--batch`, `--fromId` to resume). It is not listed by `php yii help`.

> **Note — disabling the module:** like every HumHub module, *Disable* wipes the module state: the log, statistics and cleanup tables are dropped by the uninstall migration and the settings are reset. Re-enabling starts with a clean log and default settings.
