# Metadata Cleaner

Removes EXIF, XMP and IPTC metadata — GPS position included — from every image and video uploaded to HumHub, **without re-encoding** the file. Only the metadata blocks of the file are removed: the picture and the audio/video streams stay byte-for-byte identical, so there is no loss of quality.

## Why this module

Photos and videos taken with a phone or a camera carry hidden information that most users do not know about:

- the **exact GPS position** where the picture was taken — often the user's home, school or workplace;
- date and time, down to the second;
- make, model and **serial number** of the device, owner and author names;
- software used, editing history, embedded thumbnails (which may still show a part of the picture that was cropped out).

When such a file is shared in a social network, every member who can download it can read this information. Metadata Cleaner removes it at upload time, before the file is stored, so that it never reaches the platform.

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

## Features

- Cleans **JPEG, PNG/APNG, WebP, GIF, HEIC/HEIF/AVIF, MP4/MOV/3GP and TIFF** files. Each file type can be switched on or off; all are on by default.
- The file type is detected from the file content, never from its name, so a renamed file is still cleaned.
- Keeps what is needed to show the file correctly: colour profiles and, optionally, the EXIF Orientation tag (without it some phone photos are shown rotated).
- Works on every upload path: the web uploader, the REST API, imports and other modules.
- Robust: every error is caught. By default a file that cannot be cleaned is accepted unchanged and the error is logged; it can be configured to reject the file instead.
- Optional log (off / field names only / names and values) with the result of each run, the uploading user, file name, type and size, and automatic deletion after a configurable number of days. Visible to system administrators only.
- Console command to clean files uploaded before the module was enabled.
- Pure PHP: no external program or library is needed at runtime.

## Requirements

- HumHub 1.19 or later.
- PHP 8.2 or later (the minimum of HumHub 1.19).
- PHP extensions: `dom`, `libxml`, `mbstring` and `json`. HumHub already requires all of them.
- No Composer packages and no external programs (exiftool, ImageMagick, ffmpeg...) at runtime. Those tools are used only by the test suite.

See [MANUAL.md](MANUAL.md) for the settings and the console commands.
