<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from Photoshop PSD and PSB files.
 *
 * The image resources section holds all the metadata: only the blocks needed
 * to render the document are kept (ICC profile, resolution info, layer
 * settings). IPTC, EXIF, XMP, thumbnails, copyright flags, print settings and
 * every other block are dropped. Layer, mask and pixel data are never touched.
 */
final class PsdCleaner implements CleanerInterface
{
    /**
     * Image resource IDs that describe how the image looks (Adobe Photoshop
     * File Formats Specification). Everything else is removed: IPTC 0x0404,
     * EXIF 0x0422/0x0423, XMP 0x0424, thumbnails 0x0409/0x040C, copyright
     * 0x040A, URLs 0x040B/0x041B/0x041E, slices 0x041A, document IDs 0x0414,
     * version info 0x0421, caption digest 0x0425, print settings, auto-save
     * path 0x043E, plug-in, ImageReady and Lightroom data.
     */
    private const KEEP_IDS = [
        0x03ED, // Resolution info
        0x03EE, // Alpha channel names
        0x03F4, // Grayscale and multichannel halftoning
        0x03F5, // Colour halftoning
        0x03F6, // Duotone halftoning
        0x03F7, // Grayscale and multichannel transfer functions
        0x03F8, // Colour transfer functions
        0x03F9, // Duotone transfer functions
        0x03FA, // Duotone image information: needed to show duotone images
        0x03FB, // Effective black and white values
        0x0400, // Layer state
        0x0401, // Working path
        0x0402, // Layer groups
        0x040D, // Global light angle (layer effects)
        0x040F, // ICC profile: colours would be wrong without it
        0x0411, // ICC untagged flag
        0x0415, // Unicode alpha channel names
        0x0416, // Indexed colour table count
        0x0417, // Transparency index
        0x0419, // Global light altitude (layer effects)
        0x041D, // Alpha channel identifiers
        0x0428, // Pixel aspect ratio
        0x042A, // Alternate duotone colours
        0x042B, // Alternate spot colours
        0x042D, // Layer selection IDs
        0x0430, // Layer groups enabled
        0x0BB7, // Name of the clipping path
    ];

    /** Saved paths (0x07D0-0x0BB6) are part of the drawing and are kept too. */
    private const PATH_IDS = [0x07D0, 0x0BB6];

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $data = FileIo::read($path);
        [$clean, $removed] = $this->cleanBytes($data, $options);

        if ($clean === $data) {
            return new CleanResult(FormatDetector::FORMAT_PSD, false, $removed);
        }
        FileIo::replace($path, $clean);

        return new CleanResult(FormatDetector::FORMAT_PSD, true, $removed);
    }

    /**
     * @return array{0: string, 1: MetadataBlock[]}
     */
    public function cleanBytes(string $data, CleanOptions $options): array
    {
        $len = strlen($data);
        // 8BPS + version(2) + reserved(6) + channels(2) + height(4) + width(4) + depth(2) + mode(2) = 26
        if ($len < 30 || substr($data, 0, 4) !== '8BPS' || !in_array(unpack('n', $data, 4)[1], [1, 2], true)) {
            throw new CleanerException('Not a PSD file.');
        }
        $pos = 26;
        $colorLen = $this->section($data, $pos, $len);
        $pos += 4 + $colorLen;
        $resLen = $this->section($data, $pos, $len);
        $resStart = $pos + 4;
        $resEnd = $resStart + $resLen;

        $removed = [];
        $out = '';
        $p = $resStart;
        while ($p + 4 <= $resEnd) {
            $sig = substr($data, $p, 4);
            if ($sig !== '8BIM' && $sig !== '8B64') {
                throw new CleanerException(sprintf('Invalid image resource signature at offset %d.', $p));
            }
            $id = unpack('n', $data, $p + 4)[1];
            $nameLen = ord($data[$p + 6]);
            $nameField = 1 + $nameLen + ((1 + $nameLen) & 1 ? 1 : 0); // Pascal string padded to even
            $sizePos = $p + 6 + $nameField;
            if ($sizePos + 4 > $resEnd) {
                throw new CleanerException('Truncated image resource.');
            }
            $size = unpack('N', $data, $sizePos)[1];
            $blockEnd = $sizePos + 4 + $size + ($size & 1);
            if ($blockEnd > $resEnd) {
                throw new CleanerException('Truncated image resource data.');
            }
            $block = substr($data, $p, $blockEnd - $p);
            $body = substr($data, $sizePos + 4, $size);
            $p = $blockEnd;

            if (in_array($id, self::KEEP_IDS, true) || ($id >= self::PATH_IDS[0] && $id <= self::PATH_IDS[1])) {
                $out .= $block;
                continue;
            }
            $type = match ($id) {
                0x0422 => MetadataBlock::TYPE_EXIF,
                0x0424 => MetadataBlock::TYPE_XMP,
                0x0404 => MetadataBlock::TYPE_IPTC,
                default => MetadataBlock::TYPE_OTHER,
            };
            $removed[] = new MetadataBlock(
                $type,
                sprintf('8BIM:0x%04X', $id),
                strlen($block),
                $options->collectPayloads && $size <= $options->maxPayloadBytes ? $body : null,
            );
        }
        if ($p !== $resEnd) {
            throw new CleanerException('Malformed image resources.');
        }
        if ($removed === []) {
            return [$data, []];
        }

        return [substr($data, 0, $pos) . pack('N', strlen($out)) . $out . substr($data, $resEnd), $removed];
    }

    /**
     * Reads a 4-byte big-endian section length and checks it fits.
     */
    private function section(string $data, int $pos, int $len): int
    {
        if ($pos + 4 > $len) {
            throw new CleanerException('Truncated PSD file.');
        }
        $size = unpack('N', $data, $pos)[1];
        if ($pos + 4 + $size > $len) {
            throw new CleanerException('Truncated PSD section.');
        }

        return $size;
    }
}
