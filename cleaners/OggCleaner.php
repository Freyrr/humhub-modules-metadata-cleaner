<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\readers\VorbisCommentReader;

/**
 * Removes metadata from Ogg files: Vorbis, Opus, Theora, Speex and FLAC
 * streams (.ogg, .oga, .ogv, .opus, .spx).
 *
 * The metadata of these codecs is in a comment header packet: a vendor
 * string and "NAME=value" comments (title, artist, date, location, encoder,
 * embedded pictures...). The packet is rewritten in place with the vendor
 * string, no comments and zeros up to its original length, which every
 * decoder accepts. For FLAC streams the comment and picture blocks become
 * padding. Nothing changes size, so all pages keep their layout: only the
 * checksums of the changed pages are recomputed.
 */
final class OggCleaner implements CleanerInterface
{
    /** Largest header packet loaded in memory. */
    private const MAX_PACKET_BYTES = 16777216;

    private const MAX_PAGES = 10000000;

    /** First bytes of the first packet => codec. */
    private const CODECS = [
        "\x01vorbis" => 'vorbis',
        'OpusHead' => 'opus',
        "\x80theora" => 'theora',
        "\x7FFLAC" => 'flac',
        'Speex   ' => 'speex',
    ];

    /** First bytes of the comment packet of each codec. */
    private const COMMENT_MAGIC = ['vorbis' => "\x03vorbis", 'opus' => 'OpusTags', 'theora' => "\x81theora", 'speex' => ''];

    private static ?array $crcTable = null;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        try {
            $size = (int)fstat($fh)['size'];
            [$patches, $removed] = $this->plan($fh, $size, $options);
        } finally {
            fclose($fh);
        }
        if ($patches === []) {
            return new CleanResult(FormatDetector::FORMAT_AUDIO, false, $removed);
        }
        FilePatcher::apply($path, $patches);

        return new CleanResult(FormatDetector::FORMAT_AUDIO, true, $removed);
    }

    /**
     * @param resource $fh
     * @return array{0: array<int, string>, 1: MetadataBlock[]} whole pages to write, removed blocks
     */
    private function plan($fh, int $size, CleanOptions $options): array
    {
        $streams = [];
        $changes = [];
        $pages = [];
        $removed = [];
        $pos = 0;
        $count = 0;
        while ($pos < $size) {
            if (++$count > self::MAX_PAGES) {
                throw new CleanerException('Too many Ogg pages.');
            }
            $header = FileIo::readAt($fh, $pos, min(27, $size - $pos));
            if (strlen($header) < 27 || !str_starts_with($header, 'OggS') || $header[4] !== "\x00") {
                throw new CleanerException(sprintf('Invalid Ogg page at offset %d.', $pos));
            }
            $type = ord($header[5]);
            $serial = unpack('V', $header, 14)[1];
            $segments = ord($header[26]);
            $lacing = FileIo::readAt($fh, $pos + 27, $segments);
            $dataStart = $pos + 27 + $segments;
            $pageLength = 27 + $segments + array_sum(unpack('C*', $lacing) ?: []);
            if ($pos + $pageLength > $size) {
                throw new CleanerException('Truncated Ogg page.');
            }
            if ($type & 0x02) {
                $streams[$serial] = ['codec' => null, 'index' => 0, 'parts' => [], 'bytes' => 0, 'done' => false];
            }
            if (isset($streams[$serial]) && !$streams[$serial]['done']) {
                $offset = $dataStart;
                for ($i = 0; $i < $segments; $i++) {
                    $length = ord($lacing[$i]);
                    $streams[$serial]['parts'][] = [$offset, $length, $pos];
                    $streams[$serial]['bytes'] += $length;
                    if ($streams[$serial]['bytes'] > self::MAX_PACKET_BYTES) {
                        throw new CleanerException('Ogg header packet too large.');
                    }
                    $offset += $length;
                    if ($length < 255) {
                        $this->packet($fh, $streams[$serial], $changes, $pages, $removed, $options);
                        $streams[$serial]['parts'] = [];
                        $streams[$serial]['bytes'] = 0;
                        if ($streams[$serial]['done']) {
                            break;
                        }
                    }
                }
            }
            $pos += $pageLength;
        }

        foreach ($streams as $stream) {
            if (!$stream['done'] && $stream['codec'] !== null) {
                throw new CleanerException('Ogg stream without a complete comment header.');
            }
        }

        $patches = [];
        foreach ($pages as $pageStart => $true) {
            $patches[$pageStart] = $this->rewritePage($fh, $pageStart, $changes);
        }

        return [$patches, $removed];
    }

    /**
     * Handles a complete packet of a stream.
     *
     * @param resource $fh
     * @param array<int, string> $changes new bytes by file offset
     * @param array<int, true> $pages pages to rewrite
     * @param MetadataBlock[] $removed
     */
    private function packet($fh, array &$stream, array &$changes, array &$pages, array &$removed, CleanOptions $options): void
    {
        $data = '';
        foreach ($stream['parts'] as [$offset, $length]) {
            $data .= FileIo::readAt($fh, $offset, $length);
        }
        $index = $stream['index']++;

        if ($index === 0) {
            foreach (self::CODECS as $magic => $codec) {
                if (str_starts_with($data, $magic)) {
                    $stream['codec'] = $codec;
                    // FLAC: the first packet holds STREAMINFO, which may be the last block.
                    if ($codec === 'flac' && strlen($data) > 13 && (ord($data[13]) & 0x80)) {
                        $stream['done'] = true;
                    }
                    return;
                }
            }
            // Unknown codec (skeleton, video...): nothing to clean.
            $stream['done'] = true;
            return;
        }

        if ($stream['codec'] === 'flac') {
            $this->flacBlock($data, $stream, $changes, $pages, $removed, $options);
            return;
        }

        $magic = self::COMMENT_MAGIC[$stream['codec']];
        if (!str_starts_with($data, $magic)) {
            throw new CleanerException('Missing Ogg comment header.');
        }
        $stream['done'] = true;
        $vendorAt = strlen($magic);
        if ($vendorAt + 4 > strlen($data)) {
            throw new CleanerException('Truncated Ogg comment header.');
        }
        $vendorLength = unpack('V', $data, $vendorAt)[1];
        if ($vendorAt + 4 + $vendorLength + 4 > strlen($data)) {
            throw new CleanerException('Invalid Ogg comment header.');
        }
        $clean = substr($data, 0, $vendorAt + 4 + $vendorLength) . "\x00\x00\x00\x00" . ($stream['codec'] === 'vorbis' ? "\x01" : '');
        if (strlen($clean) > strlen($data)) {
            throw new CleanerException('Invalid Ogg comment header.');
        }
        $clean = str_pad($clean, strlen($data), "\x00");
        if ($clean === $data) {
            return;
        }
        $fields = [];
        if ($options->collectPayloads) {
            $fields = (new VorbisCommentReader())->read($data, $vendorAt, ucfirst($stream['codec']));
            unset($fields[ucfirst($stream['codec']) . ':Vendor']);
        }
        $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, ucfirst($stream['codec']) . ':comments', strlen($data), null, $fields);
        $this->spread($stream['parts'], $clean, $changes, $pages);
    }

    private function flacBlock(string $data, array &$stream, array &$changes, array &$pages, array &$removed, CleanOptions $options): void
    {
        if ($data === '') {
            throw new CleanerException('Empty FLAC metadata packet.');
        }
        $last = (ord($data[0]) & 0x80) !== 0;
        $type = ord($data[0]) & 0x7F;
        if ($last) {
            $stream['done'] = true;
        }
        if (!in_array($type, [2, 4, 6], true)) {
            return;
        }
        $fields = [];
        if ($type === 4 && $options->collectPayloads) {
            $fields = (new VorbisCommentReader())->read($data, 4);
        }
        $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'FLAC:' . [2 => 'APPLICATION', 4 => 'VORBIS_COMMENT', 6 => 'PICTURE'][$type], strlen($data) - 4, null, $fields);
        $clean = chr(($last ? 0x80 : 0) | FlacCleaner::BLOCK_PADDING) . substr($data, 1, 3) . str_repeat("\x00", max(0, strlen($data) - 4));
        $this->spread($stream['parts'], $clean, $changes, $pages);
    }

    /**
     * Writes new packet bytes back over the segments the packet came from.
     */
    private function spread(array $parts, string $clean, array &$changes, array &$pages): void
    {
        $p = 0;
        foreach ($parts as [$offset, $length, $pageStart]) {
            if ($length > 0) {
                $changes[$offset] = substr($clean, $p, $length);
                $pages[$pageStart] = true;
            }
            $p += $length;
        }
    }

    /**
     * @param resource $fh
     * @param array<int, string> $changes
     */
    private function rewritePage($fh, int $start, array $changes): string
    {
        $segments = ord(FileIo::readAt($fh, $start + 26, 1));
        $lacing = FileIo::readAt($fh, $start + 27, $segments);
        $length = 27 + $segments + array_sum(unpack('C*', $lacing) ?: []);
        $page = FileIo::readAt($fh, $start, $length);
        foreach ($changes as $offset => $bytes) {
            if ($offset >= $start && $offset < $start + $length) {
                $page = substr_replace($page, $bytes, $offset - $start, strlen($bytes));
            }
        }
        $page = substr_replace($page, "\x00\x00\x00\x00", 22, 4);

        return substr_replace($page, pack('V', self::crc($page)), 22, 4);
    }

    /**
     * Ogg page checksum: CRC-32 with polynomial 0x04C11DB7, no reflection,
     * initial value 0 and no final XOR.
     */
    public static function crc(string $data): int
    {
        if (self::$crcTable === null) {
            self::$crcTable = [];
            for ($i = 0; $i < 256; $i++) {
                $r = $i << 24;
                for ($j = 0; $j < 8; $j++) {
                    $r = ($r & 0x80000000) ? (($r << 1) ^ 0x04C11DB7) : ($r << 1);
                    $r &= 0xFFFFFFFF;
                }
                self::$crcTable[$i] = $r;
            }
        }
        $table = self::$crcTable;
        $crc = 0;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc = (($crc << 8) & 0xFFFFFFFF) ^ $table[(($crc >> 24) ^ ord($data[$i])) & 0xFF];
        }

        return $crc;
    }
}
