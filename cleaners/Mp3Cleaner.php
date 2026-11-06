<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\readers\Id3Reader;

/**
 * Removes tags from MP3 and raw AAC (ADTS) files: ID3v2 tags at the start,
 * ID3v1 / ID3v1.1 / enhanced "TAG+", APEv2 and Lyrics3 tags at the end.
 * These tags hold title, artist, comments, cover pictures, the encoding
 * software and sometimes dates and places.
 *
 * The audio frames are copied unchanged: the tags are cut out and the file
 * is rewritten without them. A FLAC file with an ID3 tag in front is
 * cleaned the same way and then passed to the FLAC cleaner.
 */
final class Mp3Cleaner implements CleanerInterface
{
    /** At most this many tags are removed at each end. */
    private const MAX_TAGS = 8;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        $reader = new Id3Reader();
        $removed = [];
        try {
            $size = (int)fstat($fh)['size'];
            $start = 0;
            for ($i = 0; $i < self::MAX_TAGS && $size - $start >= 10 && FileIo::readAt($fh, $start, 3) === 'ID3'; $i++) {
                $header = FileIo::readAt($fh, $start, 10);
                if (ord($header[3]) < 2 || ord($header[3]) > 4 || (ord($header[6]) | ord($header[7]) | ord($header[8]) | ord($header[9])) & 0x80) {
                    throw new CleanerException('Invalid ID3v2 tag header.');
                }
                $length = 10 + Id3Reader::syncsafe(substr($header, 6, 4)) + ((ord($header[5]) & 0x10) ? 10 : 0);
                if ($start + $length > $size) {
                    throw new CleanerException('ID3v2 tag longer than the file.');
                }
                $removed[] = $this->block('ID3v2', $start, $length, $options, $fh, static fn(string $tag) => $reader->readV2($tag));
                $start += $length;
            }

            $head = FileIo::readAt($fh, $start, min(4, $size - $start));
            $isFlac = $head === 'fLaC';
            if (!$isFlac && !(strlen($head) >= 2 && $head[0] === "\xFF" && (ord($head[1]) & 0xE0) === 0xE0)) {
                throw new CleanerException('No MPEG audio frame after the ID3 tag.');
            }

            $end = $size;
            for ($i = 0; $i < self::MAX_TAGS; $i++) {
                $tag = $this->trailingTag($fh, $start, $end);
                if ($tag === null) {
                    break;
                }
                [$tagStart, $kind] = $tag;
                $removed[] = $this->block($kind, $tagStart, $end - $tagStart, $options, $fh, match ($kind) {
                    'ID3v1' => static fn(string $t) => $reader->readV1($t),
                    'APEv2' => static fn(string $t) => $reader->readApe($t),
                    default => null,
                });
                $end = $tagStart;
            }
        } finally {
            fclose($fh);
        }

        if ($removed !== []) {
            FileIo::keepRanges($path, [[$start, $end - $start]]);
        }
        if ($isFlac) {
            $flac = (new FlacCleaner())->clean($path, $options);

            return new CleanResult(FormatDetector::FORMAT_AUDIO, $removed !== [] || $flac->changed, array_merge($removed, $flac->removed));
        }

        return new CleanResult(FormatDetector::FORMAT_AUDIO, $removed !== [], $removed);
    }

    /**
     * Finds a tag that ends at $end.
     *
     * @param resource $fh
     * @return array{0: int, 1: string}|null start of the tag and its kind
     */
    private function trailingTag($fh, int $audioStart, int $end): ?array
    {
        $available = $end - $audioStart;
        if ($available >= 128 && FileIo::readAt($fh, $end - 128, 3) === 'TAG') {
            return [$end - 128, 'ID3v1'];
        }
        if ($available >= 227 && FileIo::readAt($fh, $end - 227, 4) === 'TAG+') {
            return [$end - 227, 'ID3v1+'];
        }
        if ($available >= 32 && FileIo::readAt($fh, $end - 32, 8) === 'APETAGEX') {
            $footer = FileIo::readAt($fh, $end - 32, 32);
            $length = unpack('V', $footer, 12)[1];
            $flags = unpack('V', $footer, 20)[1];
            $start = $end - $length - (($flags & 0x80000000) ? 32 : 0);
            if ($length < 32 || $start < $audioStart) {
                throw new CleanerException('Invalid APE tag size.');
            }

            return [$start, 'APEv2'];
        }
        if ($available >= 15 && FileIo::readAt($fh, $end - 9, 9) === 'LYRICS200') {
            $digits = FileIo::readAt($fh, $end - 15, 6);
            $start = $end - 15 - (int)$digits;
            if (!ctype_digit($digits) || $start < $audioStart || FileIo::readAt($fh, $start, 11) !== 'LYRICSBEGIN') {
                throw new CleanerException('Invalid Lyrics3 tag.');
            }

            return [$start, 'Lyrics3'];
        }
        if ($available >= 20 && FileIo::readAt($fh, $end - 9, 9) === 'LYRICSEND') {
            $window = min($available, 5100 + 9);
            $pos = strrpos(FileIo::readAt($fh, $end - $window, $window), 'LYRICSBEGIN');
            if ($pos === false) {
                throw new CleanerException('Invalid Lyrics3 tag.');
            }

            return [$end - $window + $pos, 'Lyrics3'];
        }

        return null;
    }

    /**
     * @param resource $fh
     */
    private function block(string $kind, int $start, int $length, CleanOptions $options, $fh, ?callable $read): MetadataBlock
    {
        $fields = [];
        if ($options->collectPayloads && $read !== null && $length <= $options->maxPayloadBytes) {
            $fields = $read(FileIo::readAt($fh, $start, $length));
        }

        return new MetadataBlock(MetadataBlock::TYPE_OTHER, 'Tag:' . $kind, $length, null, $fields);
    }
}
