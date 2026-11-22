<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from RIFF files: WAV and RF64 audio and AVI video.
 *
 * Removed: LIST chunks of type "INFO" (title, artist, comment, software...),
 * broadcast chunks (bext), XML chunks (iXML, axml, XMP, exif) and embedded
 * ID3 tags. "JUNK"/"PAD " padding is zeroed when it hides old metadata.
 * Chunks needed to play the file are copied byte for byte.
 * AVI "movi" lists and index chunks are never touched.
 */
final class RiffCleaner implements CleanerInterface
{
    /** Chunks that hold metadata, whatever the form type. */
    private const DROP_CHUNKS = ['bext', 'iXML', 'axml', 'exif', 'XMP ', 'ID3 ', 'id3 ', 'ID32', 'DISP'];

    /** LIST/RIFF types that are metadata. */
    private const DROP_LIST_TYPES = ['INFO'];

    /** LIST types that must be recursed into (container lists). */
    private const CONTAINER_LIST_TYPES = ['AVI ', 'movi', 'hdrl', 'strl', 'odml', 'LIST', 'WAVE'];

    private const FREE_TYPES = ['JUNK', 'PAD '];

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $data = FileIo::read($path);
        [$clean, $removed, $group] = $this->cleanBytes($data, $options);

        if ($clean === $data) {
            return new CleanResult($group, false, $removed);
        }
        FileIo::replace($path, $clean);

        return new CleanResult($group, true, $removed);
    }

    /**
     * @return array{0: string, 1: MetadataBlock[], 2: string|null}
     */
    public function cleanBytes(string $data, CleanOptions $options): array
    {
        $len = strlen($data);
        $sig = substr($data, 0, 4);
        if ($len < 12 || ($sig !== 'RIFF' && $sig !== 'RF64')) {
            throw new CleanerException('Not a RIFF file.');
        }
        $form = substr($data, 8, 4);
        // The declared size must fit inside the file, or the file is truncated.
        if (8 + unpack('V', $data, 4)[1] > $len) {
            throw new CleanerException('Truncated RIFF file.');
        }
        $group = match ($form) {
            'WAVE' => FormatDetector::FORMAT_AUDIO,
            'AVI ' => FormatDetector::FORMAT_VIDEO,
            default => throw new CleanerException('Unsupported RIFF form type.'),
        };

        $removed = [];
        $body = $this->cleanChunks($data, 12, $len, $form, $removed, $options);
        $out = $sig . pack('V', 4 + strlen($body)) . $form . $body;

        if ($removed === []) {
            return [$data, [], $group];
        }

        return [$out, $removed, $group];
    }

    /**
     * Cleans a sequence of chunks between $start and $end. LIST chunks whose
     * type is metadata are dropped; container lists are recursed into.
     *
     * @param MetadataBlock[] $removed
     */
    private function cleanChunks(string $data, int $start, int $end, string $formType, array &$removed, CleanOptions $options, string $path = ''): string
    {
        $out = '';
        $pos = $start;
        while ($pos + 8 <= $end) {
            $fourcc = substr($data, $pos, 4);
            $size = unpack('V', $data, $pos + 4)[1];
            $padded = $size + ($size & 1);
            if ($pos + 8 + $size > $end) {
                // A truncated tail of zeros is padding; anything else is broken.
                if (trim(substr($data, $pos), "\x00") === '') {
                    break;
                }
                throw new CleanerException(sprintf('Invalid RIFF chunk at offset %d.', $pos));
            }
            $body = substr($data, $pos + 8, $size);
            $location = ltrim($path . '/' . trim($fourcc), '/');
            $pos += 8 + $padded;

            if ($fourcc === 'LIST' || $fourcc === 'RIFF') {
                $listType = substr($body, 0, 4);
                if (in_array($listType, self::DROP_LIST_TYPES, true)) {
                    $removed[] = $this->block(MetadataBlock::TYPE_TEXT, $location . ':' . trim($listType), 12 + $padded, $body, $options);
                    continue;
                }
                if ($size >= 4 && in_array($listType, self::CONTAINER_LIST_TYPES, true)) {
                    $inner = $this->cleanChunks($body, 4, $size, $formType, $removed, $options, $location);
                    $out .= $fourcc . pack('V', 4 + strlen($inner)) . $listType . $inner . ($size & 1 && (4 + strlen($inner)) & 1 ? "\x00" : '');
                    // Repad to even.
                    if (strlen($out) % 2 === 1) {
                        $out .= "\x00";
                    }
                    continue;
                }
                // Unknown list: kept as is (may hold media data).
                $out .= $fourcc . pack('V', $size) . $body . str_repeat("\x00", $padded - $size);
                continue;
            }

            if (in_array($fourcc, self::DROP_CHUNKS, true)) {
                $removed[] = $this->block(MetadataBlock::TYPE_TEXT, $location, 8 + $padded, $body, $options);
                continue;
            }

            if (in_array($fourcc, self::FREE_TYPES, true)) {
                if (trim($body, "\x00") !== '') {
                    $removed[] = $this->block(MetadataBlock::TYPE_OTHER, $location, 8 + $padded, $body, $options);
                    $body = str_repeat("\x00", $size);
                }
                $out .= $fourcc . pack('V', $size) . $body . str_repeat("\x00", $padded - $size);
                continue;
            }

            $out .= $fourcc . pack('V', $size) . $body . str_repeat("\x00", $padded - $size);
        }

        return $out;
    }

    private function block(string $type, string $location, int $size, string $body, CleanOptions $options): MetadataBlock
    {
        return new MetadataBlock(
            $type,
            $location,
            $size,
            $options->collectPayloads && strlen($body) <= $options->maxPayloadBytes ? $body : null,
        );
    }
}
