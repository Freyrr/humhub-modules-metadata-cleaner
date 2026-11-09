<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Removes metadata from Matroska and WebM files (.mkv, .mka, .webm).
 *
 * Removed: Tags (title, artist, dates, location, encoder settings...),
 * Attachments (cover pictures, other files), the segment title and date,
 * track names, and the seek entries that point to removed elements. The
 * muxing and writing application names, which are mandatory, are emptied.
 *
 * Each removed element is replaced by a Void element of the same size, as
 * the Matroska standard allows, so no position in the file changes and the
 * clusters with the audio and video are never touched.
 */
final class MatroskaCleaner implements CleanerInterface
{
    private const ID_EBML = 0x1A45DFA3;
    private const ID_DOCTYPE = 0x4282;
    private const ID_SEGMENT = 0x18538067;
    private const ID_SEEKHEAD = 0x114D9B74;
    private const ID_SEEK = 0x4DBB;
    private const ID_SEEKID = 0x53AB;
    private const ID_INFO = 0x1549A966;
    private const ID_TRACKS = 0x1654AE6B;
    private const ID_TRACKENTRY = 0xAE;
    private const ID_CLUSTER = 0x1F43B675;
    private const ID_CUES = 0x1C53BB6B;
    private const ID_ATTACHMENTS = 0x1941A469;
    private const ID_CHAPTERS = 0x1043A770;
    private const ID_TAGS = 0x1254C367;
    private const ID_VOID = 0xEC;
    private const ID_CRC32 = 0xBF;

    /** Info children that are removed. */
    private const INFO_REMOVE = [0x7BA9 => 'Title', 0x4461 => 'DateUTC'];

    /** Info children that are mandatory: their text is emptied. */
    private const INFO_EMPTY = [0x4D80 => 'MuxingApp', 0x5741 => 'WritingApp'];

    private const TRACK_NAME = 0x536E;

    /** Elements that can follow a cluster at segment level. */
    private const SEGMENT_CHILDREN = [self::ID_SEEKHEAD, self::ID_INFO, self::ID_TRACKS, self::ID_CLUSTER, self::ID_CUES, self::ID_ATTACHMENTS, self::ID_CHAPTERS, self::ID_TAGS, self::ID_VOID, self::ID_CRC32];

    /** Largest master element (Info, Tracks, SeekHead) loaded in memory. */
    private const MAX_MASTER_BYTES = 16777216;

    private const MAX_ELEMENTS = 10000000;

    private const UNKNOWN = -1;

    /** @var resource */
    private $fh;
    private int $size = 0;
    private int $elements = 0;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        $this->fh = $fh;
        $this->elements = 0;
        try {
            $this->size = (int)fstat($fh)['size'];
            [$patches, $removed] = $this->plan($options);
        } finally {
            fclose($fh);
        }
        if ($patches === []) {
            return new CleanResult(FormatDetector::FORMAT_VIDEO, false, $removed);
        }
        FilePatcher::apply($path, $patches);

        return new CleanResult(FormatDetector::FORMAT_VIDEO, true, $removed);
    }

    /**
     * @return array{0: array<int, string>, 1: MetadataBlock[]}
     */
    private function plan(CleanOptions $options): array
    {
        $ebml = $this->element(0);
        if ($ebml['id'] !== self::ID_EBML || $ebml['size'] === self::UNKNOWN) {
            throw new CleanerException('Missing EBML header.');
        }
        $docType = '';
        foreach ($this->children($this->load($ebml), $ebml['dataStart']) as $child) {
            if ($child['id'] === self::ID_DOCTYPE) {
                $docType = rtrim($child['data'], "\x00");
            }
        }
        if (!in_array($docType, ['matroska', 'webm'], true)) {
            throw new CleanerException('Unsupported EBML document type.');
        }

        $patches = [];
        $removed = [];
        $pos = $ebml['end'];
        $segments = 0;
        while ($pos < $this->size) {
            $element = $this->element($pos);
            if ($element['id'] === self::ID_VOID) {
                $pos = $element['end'];
                continue;
            }
            if ($element['id'] !== self::ID_SEGMENT) {
                throw new CleanerException('Unexpected top-level element.');
            }
            $segments++;
            $pos = $this->segment($element, $patches, $removed, $options);
        }
        if ($segments === 0) {
            throw new CleanerException('No Matroska segment.');
        }

        return [$patches, $removed];
    }

    /**
     * @return int end of the segment
     */
    private function segment(array $segment, array &$patches, array &$removed, CleanOptions $options): int
    {
        $end = $segment['end'];
        $pos = $segment['dataStart'];
        while ($pos < $end) {
            if ($end - $pos < 2) {
                break;
            }
            $element = $this->element($pos, $segment['size'] === self::UNKNOWN ? null : $end);
            if ($segment['size'] === self::UNKNOWN && !in_array($element['id'], self::SEGMENT_CHILDREN, true)) {
                // An unknown-size segment ends where something else starts.
                return $pos;
            }
            if ($element['size'] === self::UNKNOWN) {
                if ($element['id'] !== self::ID_CLUSTER) {
                    throw new CleanerException('Unknown size is only supported for clusters.');
                }
                $element['end'] = $this->clusterEnd($element, $end);
            }
            switch ($element['id']) {
                case self::ID_TAGS:
                case self::ID_ATTACHMENTS:
                    $fields = $options->collectPayloads && $element['end'] - $element['dataStart'] <= $options->maxPayloadBytes
                        ? $this->describe($this->load($element), $element['dataStart'], $element['id'] === self::ID_TAGS)
                        : [];
                    $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, $element['id'] === self::ID_TAGS ? 'Matroska:Tags' : 'Matroska:Attachments', $element['end'] - $pos, null, $fields);
                    self::addVoid($patches, $pos, $element['end'] - $pos);
                    break;
                case self::ID_INFO:
                    $this->info($element, $patches, $removed);
                    break;
                case self::ID_TRACKS:
                    $this->tracks($element, $patches, $removed);
                    break;
                case self::ID_SEEKHEAD:
                    $this->seekHead($element, $patches);
                    break;
            }
            $pos = $element['end'];
        }

        return $end;
    }

    private function info(array $info, array &$patches, array &$removed): void
    {
        $data = $this->load($info);
        $fields = [];
        $changed = false;
        foreach ($this->children($data, $info['dataStart']) as $child) {
            $id = $child['id'];
            if (isset(self::INFO_REMOVE[$id])) {
                $fields['Matroska:' . self::INFO_REMOVE[$id]] = $id === 0x4461 ? $this->date($child['data']) : $child['data'];
                self::addVoid($patches, $child['start'], $child['end'] - $child['start']);
                $changed = true;
            } elseif (isset(self::INFO_EMPTY[$id]) && trim($child['data'], "\x00") !== '') {
                $fields['Matroska:' . self::INFO_EMPTY[$id]] = $child['data'];
                $patches[$child['dataStart']] = str_repeat("\x00", $child['end'] - $child['dataStart']);
                $changed = true;
            }
        }
        if ($changed) {
            $this->voidCrc($data, $info, $patches);
            $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'Matroska:Info', $info['end'] - $info['start'], null, $fields);
        }
    }

    private function tracks(array $tracks, array &$patches, array &$removed): void
    {
        $data = $this->load($tracks);
        $fields = [];
        foreach ($this->children($data, $tracks['dataStart']) as $entry) {
            if ($entry['id'] !== self::ID_TRACKENTRY) {
                continue;
            }
            $entryData = substr($data, $entry['dataStart'] - $tracks['dataStart'], $entry['end'] - $entry['dataStart']);
            foreach ($this->children($entryData, $entry['dataStart']) as $child) {
                if ($child['id'] === self::TRACK_NAME) {
                    $fields['Matroska:TrackName'] = isset($fields['Matroska:TrackName']) ? $fields['Matroska:TrackName'] . '; ' . $child['data'] : $child['data'];
                    self::addVoid($patches, $child['start'], $child['end'] - $child['start']);
                    $this->voidCrc($entryData, $entry, $patches);
                }
            }
        }
        if ($fields !== []) {
            $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'Matroska:Tracks', $tracks['end'] - $tracks['start'], null, $fields);
        }
    }

    private function seekHead(array $seekHead, array &$patches): void
    {
        $data = $this->load($seekHead);
        $changed = false;
        foreach ($this->children($data, $seekHead['dataStart']) as $seek) {
            if ($seek['id'] !== self::ID_SEEK) {
                continue;
            }
            foreach ($this->children($seek['data'], $seek['dataStart']) as $child) {
                if ($child['id'] === self::ID_SEEKID && in_array(self::bytesToInt($child['data']), [self::ID_TAGS, self::ID_ATTACHMENTS], true)) {
                    self::addVoid($patches, $seek['start'], $seek['end'] - $seek['start']);
                    $changed = true;
                }
            }
        }
        if ($changed) {
            $this->voidCrc($data, $seekHead, $patches);
        }
    }

    /**
     * A master element whose children change no longer matches its CRC-32.
     */
    private function voidCrc(string $data, array $parent, array &$patches): void
    {
        foreach ($this->children($data, $parent['dataStart']) as $child) {
            if ($child['id'] === self::ID_CRC32) {
                self::addVoid($patches, $child['start'], $child['end'] - $child['start']);
            }
        }
    }

    /**
     * Finds where a cluster of unknown size ends: at the first element that
     * cannot be inside a cluster.
     */
    private function clusterEnd(array $cluster, int $limit): int
    {
        $pos = $cluster['dataStart'];
        while ($pos < $limit && $limit - $pos >= 2) {
            $element = $this->element($pos);
            if (in_array($element['id'], self::SEGMENT_CHILDREN, true) || $element['id'] === self::ID_SEGMENT) {
                return $pos;
            }
            if ($element['size'] === self::UNKNOWN) {
                throw new CleanerException('Unknown size inside a cluster.');
            }
            $pos = $element['end'];
        }

        return min($pos, $limit);
    }

    /**
     * Lists names and values of Tags (SimpleTag name/value) or Attachments (file names).
     *
     * @return array<string, string>
     */
    private function describe(string $data, int $base, bool $tags, int $depth = 0): array
    {
        $fields = [];
        if ($depth > 8) {
            return $fields;
        }
        $name = null;
        foreach ($this->children($data, $base, false) as $child) {
            $id = $child['id'];
            if ($tags && $id === 0x45A3) {
                $name = $child['data'];
            } elseif ($tags && $id === 0x4487 && $name !== null) {
                $fields['Matroska:' . $name] = $child['data'];
            } elseif (!$tags && $id === 0x466E) {
                $fields['Matroska:AttachedFile'] = isset($fields['Matroska:AttachedFile']) ? $fields['Matroska:AttachedFile'] . '; ' . $child['data'] : $child['data'];
            } elseif (in_array($id, [0x7373, 0x67C8, 0x61A7], true)) {
                // Tag, SimpleTag, AttachedFile: walk into them.
                $fields += $this->describe($child['data'], $child['dataStart'], $tags, $depth + 1);
            }
        }

        return $fields;
    }

    private function date(string $data): string
    {
        if (strlen($data) !== 8) {
            return '';
        }
        $ns = unpack('J', $data)[1];
        $seconds = intdiv($ns, 1000000000) + 978307200;

        return gmdate('Y-m-d H:i:s', $seconds) . ' UTC';
    }

    /**
     * Reads the element header at $pos.
     *
     * @return array{id: int, start: int, dataStart: int, size: int, end: int}
     */
    private function element(int $pos, ?int $limit = null): array
    {
        if (++$this->elements > self::MAX_ELEMENTS) {
            throw new CleanerException('Too many Matroska elements.');
        }
        $head = FileIo::readAt($this->fh, $pos, min(12, $this->size - $pos));
        [$id, $idLength] = self::vint($head, 0, true);
        [$size, $sizeLength] = self::vint($head, $idLength, false);
        $dataStart = $pos + $idLength + $sizeLength;
        $end = $size === self::UNKNOWN ? ($limit ?? $this->size) : $dataStart + $size;
        if ($end > ($limit ?? $this->size)) {
            throw new CleanerException(sprintf('Matroska element at offset %d is larger than its parent.', $pos));
        }

        return ['id' => $id, 'start' => $pos, 'dataStart' => $dataStart, 'size' => $size, 'end' => $end];
    }

    private function load(array $element): string
    {
        $length = $element['end'] - $element['dataStart'];
        if ($length > self::MAX_MASTER_BYTES) {
            throw new CleanerException('Matroska header element too large.');
        }

        return FileIo::readAt($this->fh, $element['dataStart'], $length);
    }

    /**
     * Parses the children of a master element already in memory.
     *
     * @param int $base file offset of $data
     * @return list<array{id: int, start: int, dataStart: int, end: int, data: string}>
     */
    private function children(string $data, int $base, bool $strict = true): array
    {
        $children = [];
        $p = 0;
        $length = strlen($data);
        while ($p < $length) {
            if ($length - $p < 2) {
                if ($strict && trim(substr($data, $p), "\x00") !== '') {
                    throw new CleanerException('Truncated Matroska element.');
                }
                break;
            }
            try {
                [$id, $idLength] = self::vint($data, $p, true);
                [$size, $sizeLength] = self::vint($data, $p + $idLength, false);
            } catch (CleanerException $e) {
                if ($strict) {
                    throw $e;
                }
                break;
            }
            $dataStart = $p + $idLength + $sizeLength;
            if ($size === self::UNKNOWN || $dataStart + $size > $length) {
                if ($strict) {
                    throw new CleanerException('Invalid Matroska child element size.');
                }
                break;
            }
            $children[] = [
                'id' => $id,
                'start' => $base + $p,
                'dataStart' => $base + $dataStart,
                'end' => $base + $dataStart + $size,
                'data' => substr($data, $dataStart, $size),
            ];
            $p = $dataStart + $size;
        }

        return $children;
    }

    /**
     * Reads an EBML variable-length integer.
     *
     * @return array{0: int, 1: int} value (UNKNOWN for an all-ones size) and length in bytes
     */
    private static function vint(string $data, int $p, bool $isId): array
    {
        if ($p >= strlen($data)) {
            throw new CleanerException('Truncated Matroska element header.');
        }
        $first = ord($data[$p]);
        $length = 1;
        while ($length <= 8 && !($first & (0x80 >> ($length - 1)))) {
            $length++;
        }
        if ($length > ($isId ? 4 : 8) || $p + $length > strlen($data)) {
            throw new CleanerException('Invalid Matroska variable-length integer.');
        }
        $value = $isId ? $first : $first & (0xFF >> $length);
        $allOnes = $value === (0xFF >> $length);
        for ($i = 1; $i < $length; $i++) {
            $byte = ord($data[$p + $i]);
            $allOnes = $allOnes && $byte === 0xFF;
            $value = ($value << 8) | $byte;
        }
        if (!$isId && $allOnes) {
            return [self::UNKNOWN, $length];
        }

        return [$value, $length];
    }

    private static function bytesToInt(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }

    /**
     * Plans a Void element of exactly $total bytes (at least 2) at $pos.
     */
    public static function addVoid(array &$patches, int $pos, int $total): void
    {
        for ($n = 1; $n <= 8; $n++) {
            $data = $total - 1 - $n;
            if ($data >= 0 && $data < (1 << (7 * $n)) - 1) {
                $size = (1 << (7 * $n)) | $data;
                $patches[$pos] = "\xEC" . substr(pack('J', $size), 8 - $n);
                if ($data > 0) {
                    $patches[$pos + 1 + $n] = $data;
                }

                return;
            }
        }

        throw new CleanerException('Cannot build a Void element of this size.');
    }
}
