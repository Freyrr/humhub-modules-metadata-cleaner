<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from AIFF and AIFC files.
 *
 * Removed: NAME, AUTH, "(c) ", ANNO, COMT, ID3 , iXML, XMP  and APPL chunks
 * (application data, where sound-librarian tools hide their tags).
 * Chunks needed to play the file (COMM, SSND, INST...) are copied byte for byte.
 */
final class AiffCleaner implements CleanerInterface
{
    private const DROP_CHUNKS = ['NAME', 'AUTH', '(c) ', 'ANNO', 'COMT', 'ID3 ', 'ID32', 'iXML', 'XMP ', 'APPL'];

    private const PAD_ZERO = ['SKIP'];

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $data = FileIo::read($path);
        [$clean, $removed] = $this->cleanBytes($data, $options);

        if ($clean === $data) {
            return new CleanResult(FormatDetector::FORMAT_AUDIO, false, $removed);
        }
        FileIo::replace($path, $clean);

        return new CleanResult(FormatDetector::FORMAT_AUDIO, true, $removed);
    }

    /**
     * @return array{0: string, 1: MetadataBlock[]}
     */
    public function cleanBytes(string $data, CleanOptions $options): array
    {
        $len = strlen($data);
        if ($len < 12 || substr($data, 0, 4) !== 'FORM' || !in_array(substr($data, 8, 4), ['AIFF', 'AIFC'], true)) {
            throw new CleanerException('Not an AIFF file.');
        }

        $removed = [];
        $out = 'FORM' . substr($data, 8, 4);
        $pos = 12;
        while ($pos + 8 <= $len) {
            $fourcc = substr($data, $pos, 4);
            $size = unpack('N', $data, $pos + 4)[1];
            if ($pos + 8 + $size > $len) {
                if (trim(substr($data, $pos), "\x00") === '') {
                    break;
                }
                throw new CleanerException(sprintf('Invalid AIFF chunk at offset %d.', $pos));
            }
            $body = substr($data, $pos + 8, $size);
            $pos += 8 + $size + ($size & 1);

            if (in_array($fourcc, self::DROP_CHUNKS, true)) {
                $removed[] = new MetadataBlock(
                    MetadataBlock::TYPE_TEXT,
                    trim($fourcc),
                    8 + $size + ($size & 1),
                    $options->collectPayloads && $size <= $options->maxPayloadBytes ? $body : null,
                );
                continue;
            }
            if (in_array($fourcc, self::PAD_ZERO, true) && trim($body, "\x00") !== '') {
                $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, trim($fourcc), 8 + $size + ($size & 1));
                $body = str_repeat("\x00", $size);
            }
            $out .= $fourcc . pack('N', strlen($body)) . $body . ($size & 1 ? "\x00" : '');
        }
        if ($pos < $len && trim(substr($data, $pos), "\x00") !== '') {
            $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'trailer', $len - $pos);
        }
        if ($removed === []) {
            return [$data, []];
        }

        return [substr($out, 0, 4) . pack('N', strlen($out) - 8) . substr($out, 8), $removed];
    }
}
