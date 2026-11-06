<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata chunks from PNG (and APNG) files.
 *
 * Only chunks needed to display the image are kept; text chunks (where XMP
 * also lives), eXIf, tIME and every unknown chunk are dropped. Image data
 * chunks are copied byte for byte.
 */
final class PngCleaner implements CleanerInterface
{
    private const SIGNATURE = "\x89PNG\r\n\x1A\n";

    private const KEEP = [
        'IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'pHYs', 'bKGD', 'hIST',
        'sPLT', 'cICP', 'mDCV', 'mDCv', 'cLLI', 'cLLi', 'acTL', 'fcTL', 'fdAT',
    ];

    private const MAX_TEXT_BYTES = 65536;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $data = FileIo::read($path);
        [$clean, $removed] = $this->cleanBytes($data, $options);
        if ($clean === $data) {
            return new CleanResult(FormatDetector::FORMAT_PNG, false, $removed);
        }
        FileIo::replace($path, $clean);

        return new CleanResult(FormatDetector::FORMAT_PNG, true, $removed);
    }

    /**
     * @return array{0: string, 1: MetadataBlock[]}
     */
    public function cleanBytes(string $data, CleanOptions $options): array
    {
        if (!str_starts_with($data, self::SIGNATURE)) {
            throw new CleanerException('Not a PNG file.');
        }
        $len = strlen($data);
        $pos = 8;
        $out = [self::SIGNATURE];
        $removed = [];
        $orientation = null;
        $keptOwnExif = false;
        $afterHeader = null;
        $ended = false;

        while ($pos + 12 <= $len) {
            $size = unpack('N', $data, $pos)[1];
            $type = substr($data, $pos + 4, 4);
            if ($size > 0x7FFFFFFF || $pos + 12 + $size > $len || !preg_match('/^[A-Za-z]{4}$/', $type)) {
                throw new CleanerException(sprintf('Invalid PNG chunk at offset %d.', $pos));
            }
            $chunk = substr($data, $pos, 12 + $size);
            $body = substr($data, $pos + 8, $size);
            $pos += 12 + $size;

            if ($type === 'eXIf' && $options->keepOrientation && !$keptOwnExif && TiffHeader::isOrientationOnly($body)) {
                $out[] = $chunk;
                $keptOwnExif = true;
                continue;
            }
            if (in_array($type, self::KEEP, true)) {
                $out[] = $chunk;
                if ($type === 'IHDR' && $afterHeader === null) {
                    $afterHeader = count($out);
                }
                if ($type === 'IEND') {
                    $ended = true;
                    break;
                }
                continue;
            }

            if ($type === 'eXIf') {
                $orientation ??= TiffHeader::orientation($body);
            }
            [$blockType, $location, $payload] = $this->describe($type, $body);
            $removed[] = new MetadataBlock(
                $blockType,
                $location,
                strlen($chunk),
                $options->collectPayloads && strlen($payload) <= $options->maxPayloadBytes ? $payload : null,
            );
        }

        if (!$ended || $afterHeader === null) {
            throw new CleanerException('Truncated PNG file.');
        }
        if ($pos < $len && trim(substr($data, $pos), "\x00") !== '') {
            $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'trailer', $len - $pos);
        }
        if ($removed === []) {
            return [$data, []];
        }
        if ($options->keepOrientation && !$keptOwnExif && $orientation !== null && $orientation !== 1) {
            array_splice($out, $afterHeader, 0, [self::chunk('eXIf', TiffHeader::buildOrientationOnly($orientation))]);
        }

        return [implode('', $out), $removed];
    }

    public static function chunk(string $type, string $body): string
    {
        return pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
    }

    /**
     * @return array{0: string, 1: string, 2: string} block type, location, payload for the log
     */
    private function describe(string $type, string $body): array
    {
        switch ($type) {
            case 'eXIf':
                return [MetadataBlock::TYPE_EXIF, 'eXIf', $body];
            case 'tEXt':
                [$keyword, $text] = array_pad(explode("\x00", $body, 2), 2, '');
                return [MetadataBlock::TYPE_TEXT, 'tEXt:' . $keyword, $text];
            case 'zTXt':
                [$keyword, $rest] = array_pad(explode("\x00", $body, 2), 2, '');
                $text = strlen($rest) > 1 ? @zlib_decode(substr($rest, 1), self::MAX_TEXT_BYTES) : '';
                return [MetadataBlock::TYPE_TEXT, 'zTXt:' . $keyword, $text === false ? '' : $text];
            case 'iTXt':
                [$keyword, $rest] = array_pad(explode("\x00", $body, 2), 2, '');
                $compressed = ($rest[0] ?? "\x00") === "\x01";
                $parts = explode("\x00", substr($rest, 2), 3);
                $text = $parts[2] ?? '';
                if ($compressed) {
                    $text = @zlib_decode($text, self::MAX_TEXT_BYTES);
                    $text = $text === false ? '' : $text;
                }
                if ($keyword === 'XML:com.adobe.xmp') {
                    return [MetadataBlock::TYPE_XMP, 'iTXt:' . $keyword, $text];
                }
                return [MetadataBlock::TYPE_TEXT, 'iTXt:' . $keyword, $text];
            default:
                return [MetadataBlock::TYPE_OTHER, $type, ''];
        }
    }
}
