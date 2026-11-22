<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from JPEG files by dropping marker segments.
 *
 * Kept: JFIF/JFXX (APP0), ICC profile (APP2), Adobe colour transform (APP14)
 * and every segment needed to decode the image. Everything else before the
 * image data is dropped, as is anything after the end-of-image marker
 * (maker trailers, motion photo videos, Ultra HDR gain maps).
 * The entropy-coded image data is copied byte for byte.
 */
final class JpegCleaner implements CleanerInterface
{
    private const EXIF_ID = "Exif\x00\x00";
    private const XMP_ID = "http://ns.adobe.com/xap/1.0/\x00";
    private const XMP_EXT_ID = "http://ns.adobe.com/xmp/extension/\x00";
    private const ICC_ID = "ICC_PROFILE\x00";
    private const PHOTOSHOP_ID = "Photoshop 3.0\x00";

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $data = FileIo::read($path);
        [$clean, $removed] = $this->cleanBytes($data, $options);

        if ($clean === $data) {
            return new CleanResult(FormatDetector::FORMAT_JPEG, false, $removed);
        }
        FileIo::replace($path, $clean);

        return new CleanResult(FormatDetector::FORMAT_JPEG, true, $removed);
    }

    /**
     * @return array{0: string, 1: MetadataBlock[]}
     */
    public function cleanBytes(string $data, CleanOptions $options): array
    {
        $len = strlen($data);
        if ($len < 4 || !str_starts_with($data, "\xFF\xD8")) {
            throw new CleanerException('Not a JPEG file.');
        }

        $out = ["\xFF\xD8"];
        $removed = [];
        $orientation = null;
        $keptOwnExif = false;
        $insertAt = 1; // index in $out after which the orientation block goes
        $pos = 2;

        while (true) {
            // Find the next marker, skipping fill bytes.
            if ($pos >= $len || $data[$pos] !== "\xFF") {
                throw new CleanerException(sprintf('Invalid JPEG marker at offset %d.', $pos));
            }
            while ($pos < $len && $data[$pos] === "\xFF") {
                $pos++;
            }
            if ($pos >= $len) {
                throw new CleanerException('Truncated JPEG file.');
            }
            $marker = ord($data[$pos]);
            $pos++;

            if ($marker === 0xD9) {
                throw new CleanerException('JPEG file has no image data.');
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out[] = "\xFF" . chr($marker);
                continue;
            }
            if ($pos + 2 > $len) {
                throw new CleanerException('Truncated JPEG segment.');
            }
            $segLen = unpack('n', $data, $pos)[1];
            if ($segLen < 2 || $pos + $segLen > $len) {
                throw new CleanerException(sprintf('Invalid JPEG segment length at offset %d.', $pos));
            }
            $payload = substr($data, $pos + 2, $segLen - 2);
            $segment = "\xFF" . chr($marker) . substr($data, $pos, $segLen);
            $pos += $segLen;

            if ($marker === 0xDA) {
                // Start of scan: copy the header and the entropy-coded data
                // up to the next real marker, then continue with the segments
                // between scans (progressive JPEGs have several scans).
                $out[] = $segment;
                $end = $this->findScanEnd($data, $pos, $len);
                $out[] = substr($data, $pos, $end - $pos);
                $pos = $end;
                if ($pos >= $len) {
                    // Some cameras omit the end-of-image marker: add it.
                    $out[] = "\xFF\xD9";
                    break;
                }
                if (substr($data, $pos, 2) === "\xFF\xD9") {
                    $out[] = "\xFF\xD9";
                    $pos += 2;
                    break;
                }
                continue;
            }

            $decision = $this->classify($marker, $payload);
            if ($decision === null) {
                $out[] = $segment;
                if ($marker === 0xE0 && $insertAt === count($out) - 1) {
                    // Keep the orientation block right after JFIF.
                    $insertAt = count($out);
                }
                continue;
            }

            if ($decision === MetadataBlock::TYPE_EXIF) {
                $tiff = substr($payload, strlen(self::EXIF_ID));
                if ($options->keepOrientation && !$keptOwnExif && TiffHeader::isOrientationOnly($tiff)) {
                    // Already clean (written by this module): keep it as is.
                    $out[] = $segment;
                    $keptOwnExif = true;
                    continue;
                }
                $orientation ??= TiffHeader::orientation($tiff);
            }
            $removed[] = new MetadataBlock(
                $decision,
                $marker === 0xFE ? 'COM' : sprintf('APP%d', $marker - 0xE0),
                strlen($segment),
                $options->collectPayloads && strlen($payload) <= $options->maxPayloadBytes ? $payload : null,
            );
        }

        if ($pos < $len) {
            $trailer = substr($data, $pos);
            if (trim($trailer, "\x00") !== '') {
                $removed[] = new MetadataBlock(
                    MetadataBlock::TYPE_OTHER,
                    'trailer',
                    strlen($trailer),
                );
            }
        }

        if ($options->keepOrientation && !$keptOwnExif && $orientation !== null && $orientation !== 1) {
            $tiff = TiffHeader::buildOrientationOnly($orientation);
            $block = "\xFF\xE1" . pack('n', 2 + strlen(self::EXIF_ID) + strlen($tiff)) . self::EXIF_ID . $tiff;
            array_splice($out, $insertAt, 0, [$block]);
        }

        if ($removed === []) {
            // Nothing to remove: hand back the original bytes untouched.
            return [$data, []];
        }

        return [implode('', $out), $removed];
    }

    /**
     * Returns null to keep a segment, or the metadata type to drop it.
     */
    private function classify(int $marker, string $payload): ?string
    {
        return match (true) {
            $marker === 0xE0 => str_starts_with($payload, "JFIF\x00") || str_starts_with($payload, "JFXX\x00")
                ? null : MetadataBlock::TYPE_OTHER,
            $marker === 0xE1 => match (true) {
                str_starts_with($payload, self::EXIF_ID) => MetadataBlock::TYPE_EXIF,
                str_starts_with($payload, self::XMP_ID), str_starts_with($payload, self::XMP_EXT_ID) => MetadataBlock::TYPE_XMP,
                default => MetadataBlock::TYPE_OTHER,
            },
            $marker === 0xE2 => str_starts_with($payload, self::ICC_ID) ? null : MetadataBlock::TYPE_OTHER,
            $marker === 0xED => str_starts_with($payload, self::PHOTOSHOP_ID) ? MetadataBlock::TYPE_IPTC : MetadataBlock::TYPE_OTHER,
            $marker === 0xEE => str_starts_with($payload, 'Adobe') ? null : MetadataBlock::TYPE_OTHER,
            $marker >= 0xE3 && $marker <= 0xEF => MetadataBlock::TYPE_OTHER,
            $marker === 0xFE => MetadataBlock::TYPE_COMMENT,
            default => null,
        };
    }

    /**
     * Position of the first marker after entropy-coded data that is not a
     * stuffed byte (FF00) or a restart marker (FFD0-FFD7), or the end of the
     * data when the file has no end-of-image marker.
     */
    private function findScanEnd(string $data, int $pos, int $len): int
    {
        while (true) {
            $ff = strpos($data, "\xFF", $pos);
            if ($ff === false || $ff + 1 >= $len) {
                return $len;
            }
            $next = ord($data[$ff + 1]);
            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7) || $next === 0xFF) {
                $pos = $ff + 1;
                continue;
            }
            return $ff;
        }
    }
}
