Changelog
=========

1.0.0 (November 4, 2026)
------------------------
- Enh: Initial release: cleans metadata (EXIF, XMP, IPTC, GPS, author and device data) from uploaded files without re-encoding.
- Enh: Cleaners for JPEG, PNG, WebP, GIF, MP4/MOV/3GP, WebM/MKV, AVI, WMV, M4A, MP3, AAC, FLAC, Ogg, Opus, WAV, AIFF, TIFF/DNG and TIFF-based RAW images, PDF, Office and OpenDocument files, SVG, JPEG 2000 (.jp2) and JPEG XL (.jxl).
- Enh: Videos embedded in JPEG and HEIC motion photos are removed.
- Enh: PSD/PSB files are detected but stored unchanged for now.
- Enh: Configurable formats, error policy (pass unchanged or reject) and metadata logging with retention; admin log view in the module configuration page.
- Enh: HEIC/HEIF cleaning also covers Samsung and Xiaomi brand variants, EXIF stored in mime items and C2PA content-credential manifests.
- Enh: Statistics panel in the module configuration page: monthly counters per status, per format and per month, plus space saved — counts every processed file, including ones without metadata. Retention is configurable (*Keep statistics for (months)*, default 3) and older monthly totals are purged daily.
- Enh: Translations: English, Italian, Spanish, French, German, Portuguese and Simplified Chinese.
- Enh: *Clean existing files* in the module configuration page: removes the metadata from files stored before, as background queue jobs in batches. Shows progress, can be stopped and resumed from the last file processed, and reports the last completed cleanup (removed, already clean, skipped, errors, space saved). A file that cannot be cleaned is kept unchanged and counted as an error, and the cleanup goes on. Only the formats selected in the settings are cleaned.
- Enh: Requires HumHub 1.19.
- Fix: Files larger than 64 MB of memory-bound formats (TIFF/RAW, WAV, AVI, AIFF, PDF, SVG and still images) are no longer cleaned in memory: they are passed unchanged or rejected instead of risking a PHP memory exhaustion. The limit is additionally capped by the "Maximum upload file size" set in the HumHub file module settings.
