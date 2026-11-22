# Installation

## Requirements

- HumHub 1.19 or later.
- PHP 8.2 or later (the minimum of HumHub 1.19).
- PHP extensions: `dom`, `libxml`, `mbstring`, `json` and `zlib`, which are part of almost every PHP installation. Without `zlib`, PDF files with compressed metadata and some Office files are passed on unchanged (and logged as errors).
- No Composer packages and no external programs (exiftool, ImageMagick, ffmpeg...) at runtime. Those tools are used only by the test suite.

## Installing

Install the module from *Administration → Modules → Marketplace*, or copy it to `protected/modules/metadata-cleaner`, then enable it in *Administration → Modules*.

New uploads are cleaned as soon as the module is enabled. To clean files stored before, use the *Existing files* tab of the module settings (see [MANUAL.md](MANUAL.md#cleaning-existing-files)). Make a backup of the file storage first: cleaning rewrites the stored files and cannot be undone.
