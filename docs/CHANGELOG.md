Changelog
=========

1.0.0 (November 4, 2026)
------------------------
- Enh: Initial release: cleans metadata (EXIF, XMP, IPTC, GPS, author and device data) from uploaded files without re-encoding.
- Enh: Cleaners for JPEG, PNG, WebP, GIF, MP4/MOV/3GP, WebM/MKV, AVI, WMV, M4A, MP3, AAC, FLAC, Ogg, Opus, WAV, AIFF, TIFF/DNG and TIFF-based RAW images, PDF, Office and OpenDocument files, SVG, JPEG 2000 (.jp2) and JPEG XL (.jxl).
- Enh: Videos embedded in JPEG and HEIC motion photos are removed.
- Enh: PSD/PSB files are detected but stored unchanged for now.
- Enh: Configurable formats, error policy (pass unchanged or reject) and metadata logging with retention; admin log view in the module configuration page.
- Enh: Retroactive cleaning of files stored before the module was enabled: a new "Existing files" section in the admin settings runs the job in batches with start/pause/resume/stop controls, live progress and an optional dry run (console command `metadata-cleaner/clean` unchanged).
- Enh: HEIC/HEIF cleaning also covers Samsung and Xiaomi brand variants, EXIF stored in mime items and C2PA content-credential manifests.
- Enh: Statistics panel in the module configuration page: monthly counters per status, per format and per month, plus space saved — counts every processed file, including ones without metadata. Retention is configurable (*Keep statistics for (months)*, default 3) and older monthly totals are purged daily.
- Enh: Translations: English, Italian, Spanish, French, German, Portuguese and Simplified Chinese.
- Enh: Requires HumHub 1.19.
- Enh: The module settings page is split into three tabs (*Settings*, *Existing files*, *Activity*) instead of one long scrolling page.
- Fix: Files larger than 64 MB of memory-bound formats (TIFF/RAW, WAV, AVI, AIFF, PDF, SVG and still images) are no longer cleaned in memory: they are passed unchanged or rejected instead of risking a PHP memory exhaustion. The limit is additionally capped by the "Maximum upload file size" set in the HumHub file module settings.
- Fix: When the content of an existing file is replaced (new version, online editor, REST API) and cannot be cleaned, the file is no longer deleted in "Reject the file" mode: the content is kept and the error is logged. Rejecting applies to new uploads only.
- Enh: Digitally signed PDF, Office and OpenDocument files are left unchanged, so their signatures stay valid. The settings page says so, and the log and statistics count them as "Signed, not modified".
- Enh: Office and OpenDocument files are rebuilt in a temporary file instead of in memory.
- Enh: Cleaning existing files asks for confirmation and reminds to make a backup first, in the settings and on the console.
- Enh: The console command `metadata-cleaner/clean` is a regular, documented action.
- Chg: Requirements moved to `docs/INSTALLATION.md`; privacy laws and limits moved to `docs/MANUAL.md`.
- Chg: Settings page script moved to an asset bundle; icon and screenshots moved to `resources/`; unused PSD cleaner removed.
- Chg: Test bootstrap follows the current HumHub module template (`HUMHUB_PATH`); new integration tests for the upload and stored-file events and the admin access.
