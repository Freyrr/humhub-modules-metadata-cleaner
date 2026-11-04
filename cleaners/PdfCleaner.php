<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\readers\PdfReader;
use humhub\modules\metadataCleaner\readers\XmpReader;

/**
 * Removes the document metadata from PDF files.
 *
 * Removed:
 * - the document information dictionaries (Author, Title, Subject, Keywords,
 *   Creator, Producer, dates and custom entries) of every revision of the
 *   file, also when they are inside compressed object streams;
 * - every XMP metadata stream (document, pages, images, fonts...).
 *
 * Everything is overwritten in place with data of the same length: the
 * information dictionaries become empty dictionaries padded with spaces and
 * the XMP streams become an empty XMP packet, so the cross-reference tables
 * stay valid and the pages are not touched. Compressed streams are rebuilt
 * as zlib data of exactly the same length. Older revisions kept by
 * incremental updates are cleaned too, so the removed values cannot be
 * recovered from the file.
 *
 * Encrypted PDFs are not supported. Metadata inside embedded images and
 * attached files is not changed.
 */
final class PdfCleaner implements CleanerInterface
{
    private const MAX_FILE_BYTES = 268435456;

    /** Largest stream decompressed in memory. */
    private const MAX_STREAM_BYTES = 67108864;

    private const EMPTY_XMP = "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?><x:xmpmeta xmlns:x=\"adobe:ns:meta/\"/>";
    private const XMP_END = '<?xpacket end="w"?>';

    private string $data = '';

    /** @var array<int, array{num: int, gen: int, start: int, bodyStart: int}> objects by offset */
    private array $objects = [];

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        if ((int)@filesize($path) > self::MAX_FILE_BYTES) {
            throw new CleanerException('PDF file too large.');
        }
        $this->data = FileIo::read($path);
        if (!str_starts_with($this->data, '%PDF-')) {
            throw new CleanerException('Not a PDF file.');
        }
        $this->indexObjects();

        $patches = [];
        $removed = [];
        $infoRefs = $this->infoReferences();
        if ($this->hasEncryption()) {
            throw new CleanerException('Encrypted PDF files are not supported.');
        }

        foreach ($infoRefs as [$num, $gen]) {
            $this->cleanInfo($num, $gen, $patches, $removed, $options);
        }
        foreach ($this->objects as $object) {
            $this->cleanMetadataStream($object, $patches, $removed, $options);
        }

        if ($patches === []) {
            return new CleanResult(FormatDetector::FORMAT_PDF, false, $removed);
        }
        $out = $this->data;
        ksort($patches);
        foreach ($patches as $offset => $bytes) {
            $out = substr_replace($out, $bytes, $offset, strlen($bytes));
        }
        if (strlen($out) !== strlen($this->data)) {
            throw new CleanerException('PDF size changed while cleaning.');
        }
        FileIo::replace($path, $out);

        return new CleanResult(FormatDetector::FORMAT_PDF, true, $removed);
    }

    /**
     * Finds every "N G obj" in the file, in every revision.
     */
    private function indexObjects(): void
    {
        $this->objects = [];
        if (!preg_match_all('/(?<![0-9])([0-9]{1,10})[ \t\r\n\f\x00]+([0-9]{1,5})[ \t\r\n\f\x00]+obj\b/', $this->data, $matches, PREG_OFFSET_CAPTURE)) {
            throw new CleanerException('PDF file without objects.');
        }
        foreach ($matches[0] as $i => [$text, $offset]) {
            $this->objects[$offset] = [
                'num' => (int)$matches[1][$i][0],
                'gen' => (int)$matches[2][$i][0],
                'start' => $offset,
                'bodyStart' => $offset + strlen($text),
            ];
        }
    }

    /**
     * /Info references of every trailer and cross-reference stream.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function infoReferences(): array
    {
        $dicts = [];
        $offset = 0;
        while (($pos = strpos($this->data, 'trailer', $offset)) !== false) {
            $start = $this->skipSpace($pos + 7);
            if (substr($this->data, $start, 2) === '<<') {
                $dicts[] = substr($this->data, $start, $this->dictEnd($start) - $start);
            }
            $offset = $pos + 7;
        }
        foreach ($this->objects as $object) {
            $dict = $this->objectDict($object);
            if ($dict !== null && preg_match('#/Type\s*/XRef\b#', $dict[2])) {
                $dicts[] = $dict[2];
            }
        }
        $refs = [];
        foreach ($dicts as $dict) {
            if (preg_match('#/Info\s+([0-9]+)\s+([0-9]+)\s+R\b#', $dict, $m)) {
                $refs[$m[1] . ' ' . $m[2]] = [(int)$m[1], (int)$m[2]];
            }
            if (preg_match('#/Encrypt\b#', $dict)) {
                $this->encrypted = true;
            }
        }

        return array_values($refs);
    }

    private bool $encrypted = false;

    private function hasEncryption(): bool
    {
        return $this->encrypted;
    }

    /**
     * Empties every copy of an information dictionary.
     */
    private function cleanInfo(int $num, int $gen, array &$patches, array &$removed, CleanOptions $options): void
    {
        $found = false;
        foreach ($this->objects as $object) {
            if ($object['num'] !== $num || $object['gen'] !== $gen) {
                continue;
            }
            $dict = $this->objectDict($object);
            if ($dict === null) {
                continue;
            }
            $found = true;
            [$start, $end, $text] = $dict;
            if (trim(substr($text, 2, -2)) === '') {
                continue;
            }
            $patches[$start] = '<<' . str_repeat(' ', $end - $start - 4) . '>>';
            $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'PDF:Info', $end - $start, null, $options->collectPayloads ? (new PdfReader())->info($text) : []);
        }
        if (!$found) {
            $this->cleanInfoInObjectStreams($num, $patches, $removed, $options);
        }
    }

    /**
     * The information dictionary is inside a compressed object stream: the
     * stream is decompressed, the dictionary emptied, and the stream
     * compressed again into exactly the same number of bytes.
     */
    private function cleanInfoInObjectStreams(int $num, array &$patches, array &$removed, CleanOptions $options): void
    {
        foreach ($this->objects as $object) {
            $dict = $this->objectDict($object);
            if ($dict === null || !preg_match('#/Type\s*/ObjStm\b#', $dict[2])) {
                continue;
            }
            $stream = $this->stream($object, $dict);
            if ($stream === null) {
                continue;
            }
            $decoded = $this->decode($dict[2], substr($this->data, $stream[0], $stream[1]));
            if (!preg_match('#/First\s+([0-9]+)#', $dict[2], $first) || !preg_match('#/N\s+([0-9]+)#', $dict[2], $count)) {
                throw new CleanerException('Invalid object stream.');
            }
            $numbers = preg_split('/\s+/', trim(substr($decoded, 0, (int)$first[1])));
            for ($i = 0; $i + 1 < count($numbers) && $i < 2 * (int)$count[1]; $i += 2) {
                if ((int)$numbers[$i] !== $num) {
                    continue;
                }
                $at = $this->skipSpace((int)$first[1] + (int)$numbers[$i + 1], $decoded);
                if (substr($decoded, $at, 2) !== '<<') {
                    throw new CleanerException('Information dictionary is not a dictionary.');
                }
                $end = $this->dictEnd($at, $decoded);
                $text = substr($decoded, $at, $end - $at);
                if (trim(substr($text, 2, -2)) === '') {
                    return;
                }
                $decoded = substr_replace($decoded, '<<' . str_repeat(' ', $end - $at - 4) . '>>', $at, $end - $at);
                $patches[$stream[0]] = self::deflateToLength($decoded, $stream[1]);
                $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'PDF:Info', $end - $at, null, $options->collectPayloads ? (new PdfReader())->info($text) : []);

                return;
            }
        }
    }

    /**
     * Replaces the content of an XMP metadata stream with an empty packet.
     */
    private function cleanMetadataStream(array $object, array &$patches, array &$removed, CleanOptions $options): void
    {
        $dict = $this->objectDict($object);
        if ($dict === null || !preg_match('#/Type\s*/Metadata\b#', $dict[2])) {
            return;
        }
        $stream = $this->stream($object, $dict);
        if ($stream === null) {
            throw new CleanerException('Metadata stream without data.');
        }
        [$start, $length] = $stream;
        $raw = substr($this->data, $start, $length);
        $filtered = preg_match('#/Filter\b#', $dict[2]) === 1;
        $decoded = $this->decode($dict[2], $raw);
        if (trim($decoded) === '' || str_starts_with($decoded, self::EMPTY_XMP)) {
            return;
        }
        $empty = self::emptyXmp(strlen($decoded));
        $patches[$start] = $filtered ? self::storedToLength($empty, $length) : self::emptyXmp($length);
        $fields = [];
        if ($options->collectPayloads) {
            $fields = (new XmpReader())->read($decoded);
        }
        $removed[] = new MetadataBlock(MetadataBlock::TYPE_XMP, 'PDF:Metadata', $length, null, $fields ?: ['XMP:PDF' => '']);
    }

    /**
     * An empty XMP packet, padded with spaces to $length bytes (or only
     * spaces when it does not fit).
     */
    private static function emptyXmp(int $length): string
    {
        $packet = self::EMPTY_XMP . self::XMP_END;
        if ($length < strlen($packet)) {
            return str_repeat(' ', $length);
        }

        return self::EMPTY_XMP . str_repeat(' ', $length - strlen($packet)) . self::XMP_END;
    }

    /**
     * Dictionary of an object: [start, end, text] or null when the object is not a dictionary.
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function objectDict(array $object): ?array
    {
        $start = $this->skipSpace($object['bodyStart']);
        if (substr($this->data, $start, 2) !== '<<') {
            return null;
        }
        try {
            $end = $this->dictEnd($start);
        } catch (CleanerException) {
            return null;
        }

        return [$start, $end, substr($this->data, $start, $end - $start)];
    }

    /**
     * Data of the stream that follows a dictionary: [start, length] or null.
     *
     * @return array{0: int, 1: int}|null
     */
    private function stream(array $object, array $dict): ?array
    {
        $p = $this->skipSpace($dict[1]);
        if (substr($this->data, $p, 6) !== 'stream') {
            return null;
        }
        $p += 6;
        if (substr($this->data, $p, 2) === "\r\n") {
            $p += 2;
        } elseif (in_array(substr($this->data, $p, 1), ["\n", "\r"], true)) {
            $p += 1;
        } else {
            throw new CleanerException('Invalid stream keyword.');
        }
        $length = $this->streamLength($dict[2]);
        if ($length === null || $p + $length > strlen($this->data)) {
            // Fall back to the endstream keyword.
            $end = strpos($this->data, 'endstream', $p);
            if ($end === false) {
                throw new CleanerException('Stream without end.');
            }
            $length = $end - $p;
            if (substr($this->data, $end - 2, 2) === "\r\n") {
                $length -= 2;
            } elseif (in_array(substr($this->data, $end - 1, 1), ["\n", "\r"], true)) {
                $length -= 1;
            }
        } elseif (!preg_match('/\G[\s\x00]*endstream/', $this->data, $m, 0, $p + $length)) {
            throw new CleanerException('Stream length does not match its data.');
        }

        return [$p, $length];
    }

    private function streamLength(string $dict): ?int
    {
        if (preg_match('#/Length\s+([0-9]+)\s+([0-9]+)\s+R\b#', $dict, $m)) {
            foreach ($this->objects as $object) {
                if ($object['num'] === (int)$m[1] && $object['gen'] === (int)$m[2]
                    && preg_match('/\G\s*([0-9]+)\s+endobj/', $this->data, $value, 0, $object['bodyStart'])) {
                    return (int)$value[1];
                }
            }

            return null;
        }

        return preg_match('#/Length\s+([0-9]+)(?!\s+[0-9]+\s+R)#', $dict, $m) ? (int)$m[1] : null;
    }

    /**
     * Decodes stream data: none or FlateDecode without predictor.
     */
    private function decode(string $dict, string $raw): string
    {
        if (!preg_match('#/Filter\s*(\[\s*)?/([A-Za-z0-9]+)\s*(\])?#', $dict, $m)) {
            return $raw;
        }
        if ($m[2] !== 'FlateDecode' || preg_match('#/Filter\s*\[\s*/[A-Za-z0-9]+\s*/#', $dict) || preg_match('#/DecodeParms#', $dict)) {
            throw new CleanerException('Unsupported PDF stream filter.');
        }
        if (!function_exists('gzuncompress')) {
            throw new CleanerException('The PHP zlib extension is needed for compressed PDF streams.');
        }
        $decoded = @gzuncompress($raw, self::MAX_STREAM_BYTES);
        if ($decoded === false) {
            $decoded = @gzinflate($raw, self::MAX_STREAM_BYTES);
        }
        if ($decoded === false) {
            throw new CleanerException('Cannot decompress PDF stream.');
        }

        return $decoded;
    }

    /**
     * zlib data of exactly $length bytes that decompresses to $data followed
     * by spaces: the data is compressed and padded with a stored block.
     */
    public static function deflateToLength(string $data, int $length): string
    {
        if (!function_exists('deflate_init')) {
            throw new CleanerException('The PHP zlib extension is needed for compressed PDF streams.');
        }
        $context = deflate_init(ZLIB_ENCODING_RAW, ['level' => 9]);
        $compressed = deflate_add($context, $data, ZLIB_SYNC_FLUSH);
        $result = self::paddingBlocks($length - 2 - strlen($compressed) - 4);
        if ($result === null) {
            throw new CleanerException('The cleaned PDF stream does not fit in its original size.');
        }
        [$blocks, $spaces] = $result;

        return "\x78\xDA" . $compressed . $blocks . hash('adler32', $data . $spaces, true);
    }

    /**
     * zlib data of exactly $length bytes made only of stored blocks: $data
     * followed by spaces (only spaces when $data does not fit). Needs no
     * zlib extension.
     */
    public static function storedToLength(string $data, int $length): string
    {
        $budget = $length - 2 - 4;
        $result = self::paddingBlocks($budget, $data) ?? self::paddingBlocks($budget, '');
        if ($result === null) {
            throw new CleanerException('PDF stream too short to be rewritten.');
        }

        return "\x78\x01" . $result[0] . hash('adler32', $result[1], true);
    }

    /**
     * Stored deflate blocks (the last one final) of exactly $bytes bytes,
     * holding $data followed by spaces.
     *
     * @return array{0: string, 1: string}|null the blocks and the bytes they hold
     */
    private static function paddingBlocks(int $bytes, string $data = ''): ?array
    {
        for ($blocks = 1; $blocks * 5 <= $bytes; $blocks++) {
            $content = $bytes - 5 * $blocks;
            if ($content < strlen($data)) {
                return null;
            }
            if ($content > 65535 * $blocks) {
                continue;
            }
            $plain = $data . str_repeat(' ', $content - strlen($data));
            $size = (int)ceil($content / $blocks);
            $out = '';
            for ($i = 0; $i < $blocks; $i++) {
                $chunk = (string)substr($plain, $i * $size, $size);
                $out .= chr($i === $blocks - 1 ? 1 : 0) . pack('v', strlen($chunk)) . pack('v', ~strlen($chunk) & 0xFFFF) . $chunk;
            }

            return [$out, $plain];
        }

        return null;
    }

    private function skipSpace(int $p, ?string $data = null): int
    {
        $data ??= $this->data;
        $length = strlen($data);
        while ($p < $length) {
            $c = $data[$p];
            if ($c === '%') {
                while ($p < $length && $data[$p] !== "\n" && $data[$p] !== "\r") {
                    $p++;
                }
                continue;
            }
            if (!in_array($c, [' ', "\t", "\r", "\n", "\f", "\x00"], true)) {
                break;
            }
            $p++;
        }

        return $p;
    }

    /**
     * Position just after the ">>" that closes the dictionary starting at $start.
     */
    private function dictEnd(int $start, ?string $data = null): int
    {
        $data ??= $this->data;
        $length = strlen($data);
        $depth = 0;
        $p = $start;
        $limit = min($length, $start + 16777216);
        while ($p < $limit) {
            $c = $data[$p];
            if ($c === '<' && ($data[$p + 1] ?? '') === '<') {
                $depth++;
                $p += 2;
            } elseif ($c === '>' && ($data[$p + 1] ?? '') === '>') {
                $depth--;
                $p += 2;
                if ($depth === 0) {
                    return $p;
                }
            } elseif ($c === '(') {
                $p = $this->stringEnd($p, $data);
            } elseif ($c === '<') {
                $close = strpos($data, '>', $p);
                if ($close === false) {
                    break;
                }
                $p = $close + 1;
            } elseif ($c === '%') {
                while ($p < $length && $data[$p] !== "\n" && $data[$p] !== "\r") {
                    $p++;
                }
            } else {
                $p++;
            }
        }

        throw new CleanerException('Unterminated PDF dictionary.');
    }

    private function stringEnd(int $p, string $data): int
    {
        $depth = 0;
        $length = strlen($data);
        while ($p < $length) {
            $c = $data[$p];
            if ($c === '\\') {
                $p += 2;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $p + 1;
                }
            }
            $p++;
        }

        throw new CleanerException('Unterminated PDF string.');
    }
}
