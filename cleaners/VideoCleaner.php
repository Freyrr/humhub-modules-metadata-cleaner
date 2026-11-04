<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from MP4, MOV and 3GP files.
 *
 * Removed: the "udta" and "meta" boxes of the movie and of every track
 * (GPS ©xyz and loci, make, model, software, Apple keys/ilst...), XMP "uuid"
 * boxes, top-level metadata boxes and unknown top-level boxes that hold no
 * media data.
 *
 * Each removed box is replaced by a "free" box of the same size filled with
 * zeros, so no offset in the file changes and the sample tables (stco/co64)
 * stay valid without being rewritten. The audio and video data are never
 * touched. The work is done on a copy that replaces the original only when
 * complete.
 */
final class VideoCleaner implements CleanerInterface
{
    /** Largest "moov" box loaded in memory. */
    public const MAX_MOOV_BYTES = 268435456;

    /** Top-level boxes that are kept as they are. */
    private const KEEP_TOP = ['ftyp', 'moov', 'mdat', 'wide', 'moof', 'mfra', 'sidx', 'ssix', 'styp', 'emsg', 'prft', 'pdin', 'mdia'];

    /** Free-space boxes: kept, but their content is zeroed (old metadata often hides there). */
    private const FREE_TYPES = ['free', 'skip'];

    /** Containers walked to find nested metadata and the chunk offset tables. */
    private const CONTAINERS = ['trak', 'mdia', 'minf', 'stbl', 'edts', 'dinf', 'mvex'];

    private const METADATA_BOXES = ['udta', 'meta'];

    private const MAX_FIELD_BYTES = 4096;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        try {
            $fileSize = (int)fstat($fh)['size'];
            [$patches, $removed] = $this->plan($fh, $fileSize, $options);
        } finally {
            fclose($fh);
        }

        if ($patches === []) {
            return new CleanResult(FormatDetector::FORMAT_VIDEO, false, $removed);
        }
        $this->applyPatches($path, $patches);

        return new CleanResult(FormatDetector::FORMAT_VIDEO, true, $removed);
    }

    /**
     * Works out which byte ranges become free boxes.
     *
     * @param resource $fh
     * @return array{0: array<int, array{size: int, large: bool}>, 1: MetadataBlock[]}
     */
    private function plan($fh, int $fileSize, CleanOptions $options): array
    {
        $top = IsoBmff::topLevelBoxes($fh, $fileSize);
        $moovs = array_values(array_filter($top, static fn($b) => $b['type'] === 'moov'));
        if (count($moovs) !== 1) {
            throw new CleanerException(sprintf('Expected one "moov" box, found %d.', count($moovs)));
        }
        if (!in_array('mdat', array_column($top, 'type'), true) && !in_array('moof', array_column($top, 'type'), true)) {
            throw new CleanerException('No media data found.');
        }
        $moovBox = $moovs[0];
        if ($moovBox['size'] > self::MAX_MOOV_BYTES) {
            throw new CleanerException('The "moov" box is too large.');
        }
        $moov = IsoBmff::readAt($fh, $moovBox['start'], $moovBox['size']);
        if (str_contains(substr($moov, 8, 16), 'cmov')) {
            throw new CleanerException('Compressed QuickTime headers are not supported.');
        }

        $patches = [];
        $removed = [];
        $chunkOffsets = [];
        $this->walk($moov, $moovBox['header'], $moovBox['size'], 'moov', $moovBox['start'], $patches, $removed, $chunkOffsets, $options);

        $fragmented = in_array('moof', array_column($top, 'type'), true);
        foreach ($top as $box) {
            $type = $box['type'];
            if (in_array($type, self::KEEP_TOP, true)) {
                continue;
            }
            if ($type === 'uuid' && $box['uuid'] !== IsoBmff::XMP_UUID) {
                continue;
            }
            // Never touch a box that holds media data the sample tables point to.
            if ($fragmented || $this->holdsChunk($box, $chunkOffsets)) {
                continue;
            }
            if (in_array($type, self::FREE_TYPES, true)) {
                if ($box['size'] - $box['header'] > 0 && $this->hasNonZero($fh, $box)) {
                    $patches[$box['start']] = ['size' => $box['size'], 'large' => $box['header'] === 16];
                }
                continue;
            }
            $isXmp = $type === 'uuid';
            $payload = null;
            if ($options->collectPayloads && $isXmp && $box['size'] - $box['header'] <= $options->maxPayloadBytes) {
                $payload = IsoBmff::readAt($fh, $box['start'] + $box['header'], $box['size'] - $box['header']);
            }
            $patches[$box['start']] = ['size' => $box['size'], 'large' => $box['header'] >= 16 && $type !== 'uuid'];
            $removed[] = new MetadataBlock(
                $isXmp ? MetadataBlock::TYPE_XMP : MetadataBlock::TYPE_QUICKTIME,
                $isXmp ? 'uuid:XMP' : IsoBmff::printable($type),
                $box['size'],
                $payload,
            );
        }

        return [$patches, $removed];
    }

    /**
     * @param array<int, array{size: int, large: bool}> $patches
     * @param MetadataBlock[] $removed
     * @param int[] $chunkOffsets
     */
    private function walk(string $data, int $start, int $end, string $path, int $base, array &$patches, array &$removed, array &$chunkOffsets, CleanOptions $options): void
    {
        foreach (IsoBmff::boxes($data, $start, $end) as $box) {
            $type = $box['type'];
            $location = $path . '/' . IsoBmff::printable($type);
            $isXmp = $type === 'uuid' && substr($data, $box['start'] + $box['header'] - 16, 16) === IsoBmff::XMP_UUID;

            if (in_array($type, self::METADATA_BOXES, true) || $isXmp) {
                $patches[$base + $box['start']] = ['size' => $box['size'], 'large' => $box['header'] === 16];
                $body = substr($data, $box['start'] + $box['header'], $box['size'] - $box['header']);
                $removed[] = new MetadataBlock(
                    $isXmp ? MetadataBlock::TYPE_XMP : MetadataBlock::TYPE_QUICKTIME,
                    $isXmp ? $path . '/uuid:XMP' : $location,
                    $box['size'],
                    $options->collectPayloads && $isXmp && strlen($body) <= $options->maxPayloadBytes ? $body : null,
                    $options->collectPayloads && !$isXmp ? $this->describe($type, $body, $location) : [],
                );
                continue;
            }
            if (in_array($type, self::FREE_TYPES, true)) {
                $body = substr($data, $box['start'] + $box['header'], $box['size'] - $box['header']);
                if (trim($body, "\x00") !== '') {
                    $patches[$base + $box['start']] = ['size' => $box['size'], 'large' => $box['header'] === 16];
                }
                continue;
            }
            if ($type === 'stco' || $type === 'co64') {
                $this->readChunkOffsets($data, $box, $type === 'co64', $chunkOffsets);
                continue;
            }
            if (in_array($type, self::CONTAINERS, true)) {
                $this->walk($data, $box['start'] + $box['header'], $box['start'] + $box['size'], $location, $base, $patches, $removed, $chunkOffsets, $options);
            }
        }
    }

    /**
     * @param int[] $offsets
     */
    private function readChunkOffsets(string $data, array $box, bool $is64, array &$offsets): void
    {
        $p = $box['start'] + $box['header'] + 4;
        $end = $box['start'] + $box['size'];
        if ($p + 4 > $end) {
            return;
        }
        $count = unpack('N', $data, $p)[1];
        $p += 4;
        $step = $is64 ? 8 : 4;
        $count = min($count, intdiv($end - $p, $step));
        // Only the smallest and largest offsets per table are needed to know
        // which byte ranges hold media data.
        $min = PHP_INT_MAX;
        $max = -1;
        for ($i = 0; $i < $count; $i++, $p += $step) {
            $offset = $is64 ? unpack('J', $data, $p)[1] : unpack('N', $data, $p)[1];
            $min = min($min, $offset);
            $max = max($max, $offset);
        }
        if ($max >= 0) {
            $offsets[] = $min;
            $offsets[] = $max;
        }
    }

    /**
     * @param int[] $chunkOffsets min/max pairs
     */
    private function holdsChunk(array $box, array $chunkOffsets): bool
    {
        $boxEnd = $box['start'] + $box['size'];
        for ($i = 0; $i + 1 < count($chunkOffsets); $i += 2) {
            // The media of one track spans [min, max]: any overlap counts.
            if ($chunkOffsets[$i] < $boxEnd && $chunkOffsets[$i + 1] >= $box['start']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param resource $fh
     */
    private function hasNonZero($fh, array $box): bool
    {
        $pos = $box['start'] + $box['header'];
        $end = $box['start'] + $box['size'];
        while ($pos < $end) {
            $len = min(1048576, $end - $pos);
            if (trim(IsoBmff::readAt($fh, $pos, $len), "\x00") !== '') {
                return true;
            }
            $pos += $len;
        }

        return false;
    }

    /**
     * Copies the file, writes the free boxes into the copy, then replaces
     * the original with it.
     *
     * @param array<int, array{size: int, large: bool}> $patches
     */
    private function applyPatches(string $path, array $patches): void
    {
        $tmp = @tempnam(dirname($path), '.mdc');
        if ($tmp === false) {
            throw new CleanerException('Cannot create temporary file.');
        }
        try {
            if (!@copy($path, $tmp)) {
                throw new CleanerException('Cannot copy file.');
            }
            $fh = @fopen($tmp, 'r+b');
            if ($fh === false) {
                throw new CleanerException('Cannot open temporary file.');
            }
            try {
                foreach ($patches as $offset => $patch) {
                    $this->writeFreeBox($fh, $offset, $patch['size'], $patch['large']);
                }
                fflush($fh);
            } finally {
                fclose($fh);
            }
            $perms = @fileperms($path);
            if ($perms !== false) {
                @chmod($tmp, $perms & 0777);
            }
            if (!@rename($tmp, $path)) {
                throw new CleanerException('Cannot replace file.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        clearstatcache(true, $path);
    }

    /**
     * @param resource $fh
     */
    private function writeFreeBox($fh, int $offset, int $size, bool $large): void
    {
        $header = $large ? pack('N', 1) . 'free' . pack('J', $size) : pack('N', $size) . 'free';
        if (fseek($fh, $offset) !== 0 || fwrite($fh, $header) !== strlen($header)) {
            throw new CleanerException('Cannot write file.');
        }
        $left = $size - strlen($header);
        $zeros = str_repeat("\x00", 65536);
        while ($left > 0) {
            $n = min($left, 65536);
            if (fwrite($fh, $n === 65536 ? $zeros : substr($zeros, 0, $n)) !== $n) {
                throw new CleanerException('Cannot write file.');
            }
            $left -= $n;
        }
    }

    /**
     * Decodes the fields of a removed udta or meta box for the log.
     *
     * @return array<string, string>
     */
    private function describe(string $type, string $body, string $location): array
    {
        try {
            return $type === 'meta' ? $this->describeMeta($body, $location) : $this->describeUdta($body, $location);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function describeUdta(string $body, string $location): array
    {
        $fields = [];
        foreach (IsoBmff::boxes($body, 0, strlen($body)) as $child) {
            $name = 'QuickTime:' . self::atomName($child['type']);
            $value = substr($body, $child['start'] + $child['header'], $child['size'] - $child['header']);
            if (($child['type'][0] ?? '') === "\xA9") {
                // QuickTime international text: 16-bit length, 16-bit language, text.
                $value = strlen($value) >= 4 ? substr($value, 4, unpack('n', $value)[1]) : '';
            } elseif ($child['type'] === 'meta') {
                $fields += $this->describeMeta($value, $location . '/meta');
                continue;
            } elseif (strlen($value) > self::MAX_FIELD_BYTES || !preg_match('/^[\x20-\x7E\x{80}-\x{10FFFF}]*$/u', $value)) {
                $value = '';
            }
            $fields[$name] = $value;
        }

        return $fields;
    }

    /**
     * Apple "mdta" metadata: a keys box with the names and an ilst box with
     * the values, or iTunes-style ilst items.
     *
     * @return array<string, string>
     */
    private function describeMeta(string $body, string $location): array
    {
        // QuickTime meta starts with its children; ISO meta is a full box
        // with 4 bytes of version and flags first.
        $children = IsoBmff::boxes($body, substr($body, 4, 4) === 'hdlr' ? 0 : 4, strlen($body));
        $keys = [];
        $ilst = null;
        foreach ($children as $child) {
            if ($child['type'] === 'keys') {
                $p = $child['start'] + $child['header'] + 8;
                $end = $child['start'] + $child['size'];
                $index = 1;
                while ($p + 8 <= $end) {
                    $size = unpack('N', $body, $p)[1];
                    if ($size < 8 || $p + $size > $end) {
                        break;
                    }
                    $keys[$index++] = substr($body, $p + 8, $size - 8);
                    $p += $size;
                }
            } elseif ($child['type'] === 'ilst') {
                $ilst = $child;
            }
        }
        $fields = [];
        if ($ilst !== null) {
            foreach (IsoBmff::boxes($body, $ilst['start'] + $ilst['header'], $ilst['start'] + $ilst['size']) as $item) {
                $index = unpack('N', $item['type'])[1];
                $name = $keys[$index] ?? self::atomName($item['type']);
                $value = '';
                foreach (IsoBmff::boxes($body, $item['start'] + $item['header'], $item['start'] + $item['size']) as $data) {
                    if ($data['type'] === 'data' && $data['size'] >= 16) {
                        $dataType = unpack('N', $body, $data['start'] + 8)[1] & 0xFFFFFF;
                        $raw = substr($body, $data['start'] + 16, min($data['size'] - 16, self::MAX_FIELD_BYTES));
                        $value = $dataType === 1 ? $raw : '';
                    }
                }
                $fields['QuickTime:' . $name] = $value;
            }
        }

        return $fields;
    }

    private static function atomName(string $type): string
    {
        return str_starts_with($type, "\xA9") ? '©' . IsoBmff::printable(substr($type, 1)) : IsoBmff::printable($type);
    }
}
