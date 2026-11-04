# Metadata Cleaner

Removes EXIF, XMP and IPTC metadata — GPS position included — from every image and video uploaded to HumHub, **without re-encoding** the file: only the metadata blocks of the container are removed, the picture and the video stream stay byte-for-byte identical.

## Features

- Cleans JPEG, PNG/APNG, WebP, GIF, HEIC/HEIF and MP4/MOV/3GP files (TIFF optional).
- Format detected from the file content, never from the file name.
- Keeps what is needed to show the file correctly: colour profiles and, optionally, the EXIF Orientation tag.
- Works on every upload path: the web uploader, the REST API, imports and other modules.
- Robust: every error is caught. By default a file that cannot be cleaned is passed on unchanged; it can be configured to reject it instead.
- Optional log of removed metadata (off / field names / names and values) with automatic deletion after a configurable number of days. Visible to administrators only.
- Console command to clean files uploaded before the module was installed.
- Pure PHP: no external program or library is needed at runtime.

## Requirements

- HumHub 1.19 or later.

See [MANUAL.md](MANUAL.md) for configuration and the console commands.
