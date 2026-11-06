# Metadata Cleaner for HumHub

HumHub module that removes privacy-sensitive metadata (EXIF, XMP, IPTC, GPS,
author and device data) from files uploaded by users — images, videos, audio,
camera RAW, PDF and Office/OpenDocument files — **without re-encoding them**.

- Requires HumHub 1.19+
- PHP 8.2+
- License: AGPL-3.0-or-later

## What it cleans

| Family | Formats |
|---|---|
| Images | JPEG, PNG/APNG, WebP, GIF, HEIC/HEIF/AVIF, TIFF, camera RAW (DNG, CR2, NEF, ARW, ORF, RW2), SVG (metadata and scripts), JPEG 2000, JPEG XL |
| Video | MP4/MOV/3GP, WebM/MKV, AVI, WMV |
| Audio | M4A, MP3, AAC, FLAC, Ogg/Opus, WAV, AIFF |
| Documents | PDF, Office (DOCX/XLSX/PPTX), OpenDocument (ODT/ODS/ODP) |

Every format can be enabled or disabled individually. Files that cannot be
cleaned are either passed through unchanged or rejected, depending on the
configuration.

## Features

- Cleaning runs on upload and on already-stored files (background cleanup
  started from the configuration page, with progress, stop/resume and a
  report; also available as a console command)
- Optional audit log of removed fields with configurable retention
- Optional preservation of the EXIF orientation tag
- Works with any HumHub storage driver (local, S3, ...)

## Data stored

The module stores one row per cleaning run in its own `metadata_cleaner_log`
table: file name, MIME type, file size before/after, detected format, outcome
and the names of the removed metadata fields (values only if the admin enables
it). Entries are kept for a configurable number of days (default 30) and then
deleted automatically. If the user who uploaded the file is deleted, the log
row is anonymised (`created_by` is set to NULL).

## Support

Bug reports and feature requests: [GitHub Issues](https://github.com/Freyrr/humhub-modules-metadata-cleaner/issues).
Security issues: please report privately via GitHub's private vulnerability
reporting or contact the maintainer through the repository.

## Documentation

- [User guide](docs/README.md)
- [Technical manual](docs/MANUAL.md)
- [Changelog](docs/CHANGELOG.md)

## Installation

Install from the HumHub Marketplace (*Administration → Modules*), or copy
this directory to `protected/modules/metadata-cleaner` (or a custom module
path) and enable it.

Database migrations run automatically on module enable. To clean existing
files, open the module configuration page and use *Clean existing files*
(it runs through the HumHub queue, so the cron jobs must be configured), or
run `php yii metadata-cleaner/clean` from the console.

## Development

```bash
cd tests
codecept run cleaners -c codeception.cleaners.yml
```

Requires PHP 8.2+ with `gd`, `fileinfo`, `zip` extensions. Optional external
tools for full test coverage: exiftool, ffmpeg, ImageMagick, Poppler.
CI runs the suite on PHP 8.2–8.4 (`.github/workflows/`).

A Docker-based HumHub instance for integration testing is provided in
`tests/docker-compose.yml`.
