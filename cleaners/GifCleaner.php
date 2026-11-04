<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes comment extensions and application extensions (XMP lives there)
 * from GIF files. Loop control (NETSCAPE2.0, ANIMEXTS1.0) and colour profiles
 * (ICCRGBG1) are kept; image data is copied byte for byte.
 */
final class GifCleaner implements CleanerInterface
{
    private const KEEP_APPLICATIONS = ['NETSCAPE2.0', 'ANIMEXTS1.0', 'ICCRGBG1012'];

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $data = FileIo::read($path);
        [$clean, $removed] = $this->cleanBytes($data, $options);
        if ($clean === $data) {
            return new CleanResult(FormatDetector::FORMAT_GIF, false, $removed);
        }
        FileIo::replace($path, $clean);

        return new CleanResult(FormatDetector::FORMAT_GIF, true, $removed);
    }

    /**
     * @return array{0: string, 1: MetadataBlock[]}
     */
    public function cleanBytes(string $data, CleanOptions $options): array
    {
        $len = strlen($data);
        if ($len < 13 || (!str_starts_with($data, 'GIF87a') && !str_starts_with($data, 'GIF89a'))) {
            throw new CleanerException('Not a GIF file.');
        }
        $flags = ord($data[10]);
        $pos = 13 + (($flags & 0x80) ? 3 * (2 << ($flags & 0x07)) : 0);
        if ($pos > $len) {
            throw new CleanerException('Truncated GIF file.');
        }
        $out = [substr($data, 0, $pos)];
        $removed = [];
        $ended = false;

        while ($pos < $len) {
            $introducer = $data[$pos];
            if ($introducer === "\x3B") {
                $out[] = "\x3B";
                $pos++;
                $ended = true;
                break;
            }
            if ($introducer === "\x2C") {
                if ($pos + 10 > $len) {
                    throw new CleanerException('Truncated GIF image descriptor.');
                }
                $imgFlags = ord($data[$pos + 9]);
                $headerEnd = $pos + 10 + (($imgFlags & 0x80) ? 3 * (2 << ($imgFlags & 0x07)) : 0) + 1;
                $end = $this->skipSubBlocks($data, $headerEnd, $len);
                $out[] = substr($data, $pos, $end - $pos);
                $pos = $end;
                continue;
            }
            if ($introducer !== "\x21" || $pos + 2 > $len) {
                throw new CleanerException(sprintf('Invalid GIF block at offset %d.', $pos));
            }
            $label = ord($data[$pos + 1]);
            $end = $this->skipSubBlocks($data, $pos + 2, $len);
            $block = substr($data, $pos, $end - $pos);
            $start = $pos;
            $pos = $end;

            if ($label === 0xF9 || $label === 0x01) {
                $out[] = $block;
                continue;
            }
            if ($label === 0xFF) {
                $appId = substr($data, $start + 3, 11);
                if (ord($data[$start + 2]) === 11 && in_array($appId, self::KEEP_APPLICATIONS, true)) {
                    $out[] = $block;
                    continue;
                }
                $isXmp = $appId === 'XMP DataXMP';
                $removed[] = new MetadataBlock(
                    $isXmp ? MetadataBlock::TYPE_XMP : MetadataBlock::TYPE_OTHER,
                    'Application:' . rtrim($appId, "\x00"),
                    strlen($block),
                    // XMP in GIF is stored raw after the app id, ending with a "magic trailer".
                    $isXmp && $options->collectPayloads && strlen($block) <= $options->maxPayloadBytes
                        ? substr($block, 14, max(0, strlen($block) - 14 - 258))
                        : null,
                );
                continue;
            }
            $removed[] = new MetadataBlock(
                $label === 0xFE ? MetadataBlock::TYPE_COMMENT : MetadataBlock::TYPE_OTHER,
                $label === 0xFE ? 'Comment' : sprintf('Extension:0x%02X', $label),
                strlen($block),
                $label === 0xFE && $options->collectPayloads ? $this->joinSubBlocks($data, $start + 2, $end) : null,
            );
        }

        if (!$ended) {
            throw new CleanerException('Truncated GIF file.');
        }
        if ($pos < $len && trim(substr($data, $pos), "\x00") !== '') {
            $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'trailer', $len - $pos);
        }
        if ($removed === []) {
            return [$data, []];
        }

        return [implode('', $out), $removed];
    }

    /**
     * Position right after the terminating zero-length sub-block.
     */
    private function skipSubBlocks(string $data, int $pos, int $len): int
    {
        while (true) {
            if ($pos >= $len) {
                throw new CleanerException('Truncated GIF data.');
            }
            $size = ord($data[$pos]);
            $pos++;
            if ($size === 0) {
                return $pos;
            }
            $pos += $size;
        }
    }

    private function joinSubBlocks(string $data, int $pos, int $end): string
    {
        $text = '';
        while ($pos < $end) {
            $size = ord($data[$pos]);
            if ($size === 0) {
                break;
            }
            $text .= substr($data, $pos + 1, $size);
            $pos += 1 + $size;
        }

        return $text;
    }
}
