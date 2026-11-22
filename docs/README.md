# Metadata Cleaner

Removes EXIF, XMP and IPTC metadata — GPS position included — from every image and video uploaded to HumHub, **without re-encoding** the file. Only the metadata blocks of the file are removed: the picture and the audio/video streams stay byte-for-byte identical, so there is no loss of quality.

## Why this module

Photos and videos taken with a phone or a camera carry hidden information that most users do not know about:

- the **exact GPS position** where the picture was taken — often the user's home, school or workplace;
- date and time, down to the second;
- make, model and **serial number** of the device, owner and author names;
- software used, editing history, embedded thumbnails (which may still show a part of the picture that was cropped out).

When such a file is shared in a social network, every member who can download it can read this information. Metadata Cleaner removes it at upload time, before the file is stored, so that it never reaches the platform. Files stored before the module was enabled can be cleaned afterwards from the module settings.

## Features

- Cleans **JPEG, PNG/APNG, WebP, GIF, HEIC/HEIF/AVIF, TIFF, DNG raw and SVG** images (including files from Samsung and Xiaomi devices and C2PA content-credential manifests), **MP4/MOV/3GP, WebM/MKV, AVI and WMV** videos, **M4A, MP3, AAC, FLAC, Ogg, Opus, WAV and AIFF** audio files, **JPEG 2000 and JPEG XL** images, and **PDF, Office (DOCX, XLSX, PPTX) and OpenDocument (ODT, ODS, ODP)** documents, including the short videos of motion photos. SVG images are also cleaned of scripts. Each file type can be switched on or off; all are on by default.
- The file type is detected from the file content, never from its name, so a renamed file is still cleaned.
- Keeps what is needed to show the file correctly: colour profiles and, optionally, the EXIF Orientation tag (without it some phone photos are shown rotated).
- Works on every upload path: the web uploader, the REST API, imports and other modules.
- Safe failure: by default a file that cannot be cleaned is accepted unchanged and the error is logged; new uploads can be rejected instead. Files that already exist are never deleted.
- Optional log (off / field names only / names and values) with the result of each run, the uploading user, file name, type and size, and automatic deletion after a configurable number of days. Visible to system administrators only.
- Statistics: monthly counters of every processed file per format and outcome (metadata removed, already clean, errors), kept independently of the log with their own configurable retention.
- Pure PHP: no external program or library is needed at runtime.
- Available in English, Italian, Spanish, French, German, Portuguese and Simplified Chinese.

See [INSTALLATION.md](INSTALLATION.md) for the requirements and [MANUAL.md](MANUAL.md) for the settings, the details of what is removed and the limits of the module.
