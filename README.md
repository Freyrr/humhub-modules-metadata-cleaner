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

Every format can be enabled or disabled individually. New uploads that cannot
be cleaned are either passed through unchanged or rejected, depending on the
configuration. Files that already exist are never rejected or deleted, and
digitally signed documents are left unchanged so that their signature stays
valid.

## Features

- Cleaning runs on upload and on already-stored files (bulk cleanup from the
  *Existing files* tab of the settings or the console command)
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

- [Overview](docs/README.md)
- [Installation](docs/INSTALLATION.md)
- [Technical manual](docs/MANUAL.md)
- [Changelog](docs/CHANGELOG.md)

## Installation

Install from the HumHub Marketplace (*Administration → Modules*), or copy
this directory to `protected/modules/metadata-cleaner` (or a custom module
path) and enable it.

Database migrations run automatically on module enable. To clean existing
files: `php yii metadata-cleaner/clean` (make a backup of the file storage
first).

## Tests

- Cleaner tests, no HumHub needed:
  `cd tests && codecept run cleaners -c codeception.cleaners.yml`
- HumHub integration tests (unit and functional), from the module's `tests`
  folder of a HumHub test installation:
  `HUMHUB_PATH=/path/to/humhub codecept run unit,functional`

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
