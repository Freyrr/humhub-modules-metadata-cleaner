<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes EXIF, XMP and unknown chunks from WebP files and clears the
 * matching flags in the VP8X header. Image chunks are copied byte for byte.
 */
final class WebpCleaner implements CleanerInterface
{
    private const KEEP = ['VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ICCP', 'ANIM', 'ANMF'];

    private const FLAG_EXIF = 0x08;
    private const FLAG_XMP = 0x04;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $data = FileIo::read($path);
        [$clean, $removed] = $this->cleanBytes($data, $options);
        if ($clean === $data) {
            return new CleanResult(FormatDetector::FORMAT_WEBP, false, $removed);
        }
        FileIo::replace($path, $clean);

        return new CleanResult(FormatDetector::FORMAT_WEBP, true, $removed);
    }

    /**
     * @return array{0: string, 1: MetadataBlock[]}
     */
    public function cleanBytes(string $data, CleanOptions $options): array
    {
        $len = strlen($data);
        if ($len < 20 || !str_starts_with($data, 'RIFF') || substr($data, 8, 4) !== 'WEBP') {
            throw new CleanerException('Not a WebP file.');
        }
        $riffEnd = 8 + unpack('V', $data, 4)[1];
        if ($riffEnd > $len) {
            throw new CleanerException('Truncated WebP file.');
        }

        $pos = 12;
        $chunks = [];
        $removed = [];
        $hasImage = false;
        while ($pos + 8 <= $riffEnd) {
            $fourcc = substr($data, $pos, 4);
            $size = unpack('V', $data, $pos + 4)[1];
            $padded = $size + ($size & 1);
            if ($pos + 8 + $size > $riffEnd) {
                throw new CleanerException(sprintf('Invalid WebP chunk at offset %d.', $pos));
            }
            $chunk = substr($data, $pos, 8 + $padded);
            $body = substr($data, $pos + 8, $size);
            $pos += 8 + $padded;

            if (in_array($fourcc, self::KEEP, true)) {
                $chunks[] = [$fourcc, $chunk];
                $hasImage = $hasImage || in_array($fourcc, ['VP8 ', 'VP8L', 'ANMF'], true);
                continue;
            }
            $type = match ($fourcc) {
                'EXIF' => MetadataBlock::TYPE_EXIF,
                'XMP ' => MetadataBlock::TYPE_XMP,
                default => MetadataBlock::TYPE_OTHER,
            };
            $removed[] = new MetadataBlock(
                $type,
                trim($fourcc),
                strlen($chunk),
                $options->collectPayloads && $type !== MetadataBlock::TYPE_OTHER && $size <= $options->maxPayloadBytes ? $body : null,
            );
        }
        if (!$hasImage) {
            throw new CleanerException('WebP file has no image data.');
        }
        if ($riffEnd < $len && trim(substr($data, $riffEnd), "\x00") !== '') {
            $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'trailer', $len - $riffEnd);
        }

        if ($removed === []) {
            return [$data, []];
        }

        $body = '';
        foreach ($chunks as [$fourcc, $chunk]) {
            if ($fourcc === 'VP8X' && strlen($chunk) >= 9) {
                $chunk[8] = chr(ord($chunk[8]) & ~(self::FLAG_EXIF | self::FLAG_XMP) & 0xFF);
            }
            $body .= $chunk;
        }

        return ['RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body, $removed];
    }
}
