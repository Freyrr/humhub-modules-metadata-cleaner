<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Removes the document properties from Office Open XML files (.docx,
 * .xlsx, .pptx and their macro/template variants) and OpenDocument files
 * (.odt, .ods, .odp, .odg).
 *
 * Only the files that hold the document properties are replaced:
 * - Office: docProps/core.xml (author, last modified by, title, subject,
 *   keywords, dates...), docProps/app.xml (company, manager, template,
 *   editing time, application) and docProps/custom.xml (custom properties);
 * - OpenDocument: meta.xml (creator, dates, editing time and cycles,
 *   generator, user-defined fields...).
 * Each one becomes an empty, valid properties file. The other entries of
 * the ZIP archive (text, sheets, slides, embedded pictures...) are copied
 * byte for byte, in the same order, so the "mimetype" entry of OpenDocument
 * stays first and uncompressed. Comments, tracked changes and the metadata
 * of embedded pictures are content of the document and are not changed.
 *
 * ZIP archives that are not Office or OpenDocument files are not handled.
 */
final class OfficeCleaner implements CleanerInterface
{
    private const MAX_FILE_BYTES = 536870912;
    private const MAX_ENTRIES = 65535;
    private const MAX_PROPERTIES_BYTES = 16777216;

    private const REL_CORE = '/metadata/core-properties';
    private const REL_APP = '/extended-properties';
    private const REL_CUSTOM = '/custom-properties';

    private const EMPTY_CORE = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"></cp:coreProperties>';
    private const EMPTY_APP = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"></Properties>';
    private const EMPTY_CUSTOM = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"></Properties>';

    /** @var resource */
    private $fh;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        if ((int)@filesize($path) > self::MAX_FILE_BYTES) {
            throw new CleanerException('Office file too large.');
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        $this->fh = $fh;
        try {
            $size = (int)fstat($fh)['size'];
            [$entries, $comment] = $this->readCentralDirectory($size);
            $byName = [];
            foreach ($entries as $i => $entry) {
                $byName[$entry['name']] = $i;
            }

            $replacements = $this->replacements($entries, $byName);
            if ($replacements === null) {
                return CleanResult::notHandled();
            }

            $removed = [];
            foreach ($replacements as $name => $content) {
                $entry = $entries[$byName[$name]];
                $old = $this->content($entry);
                if ($old === $content) {
                    unset($replacements[$name]);
                    continue;
                }
                $removed[] = new MetadataBlock(
                    MetadataBlock::TYPE_OTHER,
                    'Office:' . $name,
                    $entry['usize'],
                    null,
                    $options->collectPayloads && $old !== null ? self::fields($old) : [],
                );
            }
            if ($replacements === [] && $comment === '') {
                return new CleanResult(FormatDetector::FORMAT_OFFICE, false, $removed);
            }
            $out = $this->rebuild($entries, $replacements);
        } finally {
            fclose($fh);
        }
        FileIo::replace($path, $out);

        return new CleanResult(FormatDetector::FORMAT_OFFICE, true, $removed);
    }

    /**
     * New content of each properties entry, or null when the archive is not
     * an Office or OpenDocument file.
     *
     * @return array<string, string>|null
     */
    private function replacements(array $entries, array $byName): ?array
    {
        if (isset($byName['mimetype'])) {
            $mime = trim((string)$this->content($entries[$byName['mimetype']]));
            if (!str_starts_with($mime, 'application/vnd.oasis.opendocument.')) {
                return null;
            }
            if (!isset($byName['meta.xml'])) {
                return [];
            }
            $version = '1.2';
            $meta = $this->content($entries[$byName['meta.xml']]);
            if ($meta !== null && preg_match('/office:version="([0-9.]+)"/', $meta, $m)) {
                $version = $m[1];
            }

            return ['meta.xml' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
                . ' office:version="' . $version . '"><office:meta/></office:document-meta>'];
        }

        if (!isset($byName['[Content_Types].xml'])) {
            return null;
        }
        $targets = [];
        $rels = isset($byName['_rels/.rels']) ? $this->content($entries[$byName['_rels/.rels']]) : null;
        if ($rels !== null && preg_match_all('/<Relationship\b[^>]*>/i', $rels, $matches)) {
            foreach ($matches[0] as $relationship) {
                if (!preg_match('/\bType="([^"]+)"/', $relationship, $type) || !preg_match('/\bTarget="([^"]+)"/', $relationship, $target)) {
                    continue;
                }
                $name = ltrim(html_entity_decode($target[1], ENT_QUOTES | ENT_XML1), '/');
                foreach ([self::REL_CORE => self::EMPTY_CORE, self::REL_APP => self::EMPTY_APP, self::REL_CUSTOM => self::EMPTY_CUSTOM] as $suffix => $empty) {
                    if (str_ends_with($type[1], $suffix) && isset($byName[$name])) {
                        $targets[$name] = $empty;
                    }
                }
            }
        }
        foreach (['docProps/core.xml' => self::EMPTY_CORE, 'docProps/app.xml' => self::EMPTY_APP, 'docProps/custom.xml' => self::EMPTY_CUSTOM] as $name => $empty) {
            if (isset($byName[$name]) && !isset($targets[$name])) {
                $targets[$name] = $empty;
            }
        }

        return $targets;
    }

    /**
     * @return array{0: list<array>, 1: string} entries and archive comment
     */
    private function readCentralDirectory(int $size): array
    {
        if ($size < 22) {
            throw new CleanerException('ZIP file too short.');
        }
        $tailLength = min($size, 65557);
        $tail = FileIo::readAt($this->fh, $size - $tailLength, $tailLength);
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || $eocd + 22 > strlen($tail)) {
            throw new CleanerException('ZIP end of central directory not found.');
        }
        $record = unpack('vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset/vcommentLength', $tail, $eocd + 4);
        if ($record['disk'] !== 0 || $record['cdDisk'] !== 0 || $record['diskEntries'] !== $record['entries']) {
            throw new CleanerException('Multi-volume ZIP files are not supported.');
        }
        if ($record['entries'] === 0xFFFF || $record['cdSize'] === 0xFFFFFFFF || $record['cdOffset'] === 0xFFFFFFFF) {
            throw new CleanerException('ZIP64 files are not supported.');
        }
        if ($record['entries'] > self::MAX_ENTRIES || $record['cdOffset'] + $record['cdSize'] > $size) {
            throw new CleanerException('Invalid ZIP central directory.');
        }
        $comment = (string)substr($tail, $eocd + 22, $record['commentLength']);
        $cd = FileIo::readAt($this->fh, $record['cdOffset'], $record['cdSize']);

        $entries = [];
        $p = 0;
        for ($i = 0; $i < $record['entries']; $i++) {
            if (substr($cd, $p, 4) !== "PK\x01\x02" || $p + 46 > strlen($cd)) {
                throw new CleanerException('Invalid ZIP central directory entry.');
            }
            $e = unpack('vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset', $cd, $p + 4);
            $e['name'] = (string)substr($cd, $p + 46, $e['nameLength']);
            $e['extra'] = (string)substr($cd, $p + 46 + $e['nameLength'], $e['extraLength']);
            $e['comment'] = (string)substr($cd, $p + 46 + $e['nameLength'] + $e['extraLength'], $e['commentLength']);
            if ($e['csize'] === 0xFFFFFFFF || $e['usize'] === 0xFFFFFFFF || $e['offset'] === 0xFFFFFFFF) {
                throw new CleanerException('ZIP64 entries are not supported.');
            }
            $p += 46 + $e['nameLength'] + $e['extraLength'] + $e['commentLength'];
            $e['range'] = $this->localRange($e, $record['cdOffset']);
            $entries[] = $e;
        }

        return [$entries, $comment];
    }

    /**
     * Byte range of an entry in the archive: local header, data and data descriptor.
     *
     * @return array{0: int, 1: int, 2: int} start, data start, end
     */
    private function localRange(array $entry, int $limit): array
    {
        $local = FileIo::readAt($this->fh, $entry['offset'], 30);
        if (!str_starts_with($local, "PK\x03\x04")) {
            throw new CleanerException('Invalid ZIP local header.');
        }
        $lengths = unpack('vname/vextra', $local, 26);
        $dataStart = $entry['offset'] + 30 + $lengths['name'] + $lengths['extra'];
        $end = $dataStart + $entry['csize'];
        if ($entry['flags'] & 0x08) {
            $descriptor = FileIo::readAt($this->fh, $end, min(16, $limit - $end));
            $end += str_starts_with($descriptor, "PK\x07\x08") ? 16 : 12;
        }
        if ($end > $limit) {
            throw new CleanerException('ZIP entry outside of the archive.');
        }

        return [$entry['offset'], $dataStart, $end];
    }

    /**
     * Uncompressed content of an entry, or null when it cannot be read.
     */
    private function content(array $entry): ?string
    {
        if ($entry['usize'] > self::MAX_PROPERTIES_BYTES || ($entry['flags'] & 0x01)) {
            return null;
        }
        $raw = FileIo::readAt($this->fh, $entry['range'][1], $entry['csize']);
        if ($entry['method'] === 0) {
            return $raw;
        }
        if ($entry['method'] === 8 && function_exists('gzinflate')) {
            $data = @gzinflate($raw, self::MAX_PROPERTIES_BYTES);

            return $data === false ? null : $data;
        }

        return null;
    }

    /**
     * Writes the archive again with the replaced entries stored uncompressed.
     *
     * @param array<string, string> $replacements
     */
    private function rebuild(array $entries, array $replacements): string
    {
        $out = '';
        $central = '';
        foreach ($entries as $entry) {
            $offset = strlen($out);
            if (isset($replacements[$entry['name']])) {
                if ($entry['flags'] & 0x01) {
                    throw new CleanerException('Encrypted properties entry.');
                }
                $content = $replacements[$entry['name']];
                $flags = $entry['flags'] & 0x0800;
                $crc = crc32($content);
                $out .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, $flags, 0, $entry['time'], $entry['date'], $crc, strlen($content), strlen($content), strlen($entry['name']), 0)
                    . $entry['name'] . $content;
                $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', $entry['madeBy'], 20, $flags, 0, $entry['time'], $entry['date'], $crc, strlen($content), strlen($content), strlen($entry['name']), 0, 0, 0, $entry['internal'], $entry['external'], $offset)
                    . $entry['name'];
                continue;
            }
            [$start, , $end] = $entry['range'];
            $out .= FileIo::readAt($this->fh, $start, $end - $start);
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', $entry['madeBy'], $entry['needed'], $entry['flags'], $entry['method'], $entry['time'], $entry['date'], $entry['crc'], $entry['csize'], $entry['usize'], strlen($entry['name']), strlen($entry['extra']), 0, 0, $entry['internal'], $entry['external'], $offset)
                . $entry['name'] . $entry['extra'];
        }
        $cdOffset = strlen($out);

        return $out . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($entries), count($entries), strlen($central), $cdOffset, 0);
    }

    /**
     * Text values of a properties file, for the log.
     *
     * @return array<string, string>
     */
    public static function fields(string $xml): array
    {
        if (stripos($xml, '<!ENTITY') !== false) {
            return [];
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($xml, LIBXML_NONET)) {
                return [];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $fields = [];
        self::collect($doc->documentElement, $fields, 0);

        return $fields;
    }

    private static function collect(?DOMNode $node, array &$fields, int $depth): void
    {
        if (!$node instanceof DOMElement || $depth > 10 || count($fields) >= 200) {
            return;
        }
        $hasElements = false;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $hasElements = true;
                self::collect($child, $fields, $depth + 1);
            }
        }
        if ($depth === 0 || $hasElements) {
            return;
        }
        $name = $node->localName;
        if ($node->hasAttribute('meta:name')) {
            $name = $node->getAttribute('meta:name');
        } elseif ($node->parentNode instanceof DOMElement && $node->parentNode->hasAttribute('name')) {
            $name = $node->parentNode->getAttribute('name');
        }
        $key = 'Office:' . $name;
        $value = trim($node->textContent);
        $fields[$key] = isset($fields[$key]) && $value !== '' ? $fields[$key] . '; ' . $value : $value;
    }
}
