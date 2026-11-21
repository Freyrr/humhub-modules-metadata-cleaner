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
| When a file cannot be cleaned | Accept the file unchanged | Or *Reject the file*: the upload fails with a message to the user. Applies to new uploads only: when the content of an existing file is replaced (a new version, a save from an online editor, the REST API...) or cleaned by the *Existing files* job, the file is never rejected or deleted; its content is kept unchanged and the error is logged. |

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

Files of any other type are not changed. See [What the module does not cover](#what-the-module-does-not-cover).

> **Note — very large files:** the cleaners of JPEG, PNG, WebP, GIF, TIFF/RAW, WAV, AVI, AIFF, PDF and SVG work on a copy of the file in memory, so these types cannot be cleaned above a size limit of **64 MB or the "Maximum upload file size" configured in HumHub file settings, whichever is smaller**. Larger files are then passed on unchanged (or rejected, depending on the *When a file cannot be cleaned* setting) and the error is logged. Streaming formats (MP4/MOV, WebM/MKV, ASF/WMV, MP3, M4A, FLAC, Ogg, HEIC/HEIF) have no size limit; Office and OpenDocument files are also rebuilt without loading them in memory, up to 512 MB.

## What the module does not cover

The module removes the metadata stored in the file formats listed above. It does not, and cannot, make every upload anonymous. In particular:

- **What the file shows or says.** Faces, number plates, street signs, screen contents, text in a document, voices in a recording: anything that is part of the picture, sound or text itself is not changed.
- **The file name.** HumHub keeps the name of the uploaded file (for example `IMG_20261104_Milano.jpg` or `CV Mario Rossi.pdf`), which can itself contain personal data.
- **Content of documents.** In Office and OpenDocument files only the document properties are removed: comments and their authors, tracked changes, hidden text, speaker notes, and the metadata of pictures embedded in the document stay as they are. In PDF files the metadata of embedded images and attached files is not changed.
- **Other file types.** Camera RAW formats that are not based on TIFF (CR3, RAF, X3F...), legacy Office files (DOC, XLS, PPT), RTF, EPUB, flat OpenDocument XML files, compressed SVG (SVGZ), Photoshop files (PSD, PSB: recognised, but not cleaned yet), JPEG XL files without a container (bare codestream: they hold no metadata), archives (ZIP, RAR, 7z) and their contents are stored unchanged.
- **Files that cannot be cleaned safely.** Encrypted PDF files, PDF streams with unusual compression, ZIP64 or encrypted Office files, SVG files with entity declarations and damaged files are passed on unchanged (or rejected, depending on the settings), and the error is logged.
- **Digitally signed documents.** Signed PDF files (including document timestamps) and signed Office and OpenDocument files are stored unchanged, because any change would invalidate the signature. They are logged as not cleaned and are never rejected.
- **Technical data that is kept on purpose,** because the file needs it: the EXIF orientation tag (optional), colour profiles, the camera model name that DNG files require (`UniqueCameraModel`), the encoder name inside Ogg and AAC audio streams, and the empty application fields that Matroska requires.
- **Images stored outside the file library**, such as profile and space images, are not processed.
- **Files uploaded before the module was enabled** are not cleaned automatically: run the *Existing files* job (see [Cleaning existing files](#cleaning-existing-files)) to clean them.

## Privacy laws it helps with

_Last reviewed: November 4, 2026. Laws change: check the current text of the laws that apply to you._

Removing data that is not needed is a basic principle of privacy laws around the world. Location data, in particular, is personal data almost everywhere, and in several jurisdictions it is a sensitive category. This module helps a HumHub operator apply these principles to uploaded files:

**European Union and Europe**

- **GDPR** (EU 2016/679): data minimisation (Art. 5(1)(c)), data protection by design and by default (Art. 25) and security of processing (Art. 32). Location data is explicitly named as personal data (Art. 4(1)).
- **UK GDPR** and the Data Protection Act 2018, and the Swiss **revised Federal Act on Data Protection (nFADP)**, which follow the same principles.

**United States**

- **CCPA as amended by the CPRA** (California): precise geolocation is "sensitive personal information", and collection must be limited to what is reasonably necessary.
- **COPPA**: geolocation information and photos of a child under 13 are personal information; removing the GPS position from uploads reduces what is collected from children.
- State privacy laws such as those of **Virginia (VCDPA)**, **Colorado (CPA)**, **Connecticut (CTDPA)** and others, which treat precise geolocation as sensitive data.

**Rest of the world**

- **Brazil — LGPD**: principle of necessity (Art. 6).
- **Canada — PIPEDA**: limiting collection (Principle 4.4).
- **Japan — APPI**, **Australia — Privacy Act 1988** (Australian Privacy Principles 3 and 11), **China — PIPL** (minimum necessary, Art. 6; location tracking is sensitive personal information, Art. 28), **India — DPDP Act 2023**, **South Africa — POPIA**, and other laws based on the same principles.

> **Not legal advice.** This list is for information only. The module removes metadata from uploaded files; it does not by itself make a platform compliant with any law. Compliance depends on how the whole platform is run. Ask a qualified lawyer or your data protection officer about your obligations.

Note that the optional log of the module can itself store personal data (for example a GPS position, if values are logged). By default it stores field names only and deletes entries after 30 days.

## Statistics

The settings page is split into three tabs: *Settings* (the form below), *Existing files* (the retroactive cleaning job) and *Activity* (log and statistics). The *Statistics* section of the *Activity* tab shows monthly counters: every file the module processes is counted once — *Metadata removed*, *Processed, no metadata* or an error — per format and per month, plus the total space saved. The figures cover only the configured retention period (*Keep statistics for (months)*, default 3) and are independent of the log: monthly totals older than the retention are deleted automatically every day.

> **Note — WMV/ASF:** the cleaner expects the standard ASF header layout. Files with unusual structures (DRM-protected streams, non-standard index objects) may fail cleaning: they are then passed on unchanged, or rejected, depending on the *When a file cannot be cleaned* setting, and the error is logged.
>
> **Note — Photoshop (PSD/PSB):** detected but not cleaned yet: removing image resources is not yet safe enough for production files, so they are stored unchanged.

## Cleaning existing files

Files uploaded **before** the module was enabled are not touched automatically. Two ways to clean them retroactively:

- **Admin UI** — the *Existing files* tab of the module settings page runs a job in small batches while the page is open, with **Start**, **Pause**, **Resume** and **Stop** buttons, live counters and an optional *Dry run* that only reports what would change. A real run asks for confirmation first. The job state is kept in the module settings, so closing the page pauses the work without losing progress; reopening the page continues while the job runs.
- **Console** — `php yii metadata-cleaner/clean` processes every stored file in one run (see below).

> **Make a backup first.** A real run rewrites the stored files of the enabled types and cannot be undone. Back up the file storage (`uploads/file`) before you start, and use *Dry run* to see what would change. Files that cannot be cleaned are left as they are and logged; they are never deleted.

Both paths share the same cleaners, statistics and log (source `admin` or `console`).

## Console commands

```
php yii metadata-cleaner/clean [--dryRun] [--batch=100] [--fromId=0]
```

Cleans every stored file. `--dryRun` (`-n`) only reports what would change, `--batch` sets the number of files loaded per query and `--fromId` resumes an interrupted run after the given file id. Without `--dryRun` the command asks for confirmation; add `--interactive=0` to skip it in scripts.

```
php yii metadata-cleaner/purge-log
```

Deletes log entries older than the retention period (this also runs every day with the HumHub cron). `php yii metadata-cleaner/purge-stats` does the same for the statistics buckets.

> **Note — disabling the module:** like every HumHub module, *Disable* wipes the module state: the log and statistics tables are dropped by the uninstall migration and the settings are reset. Re-enabling starts with a clean log and default settings.
