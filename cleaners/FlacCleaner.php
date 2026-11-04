<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\readers\VorbisCommentReader;

/**
 * Removes metadata from FLAC files.
 *
 * FLAC metadata blocks that can hold personal data (Vorbis comments,
 * pictures, application blocks) are turned into PADDING blocks of the same
 * size and filled with zeros. Padding is part of the FLAC standard, nothing
 * moves, and the audio frames are not touched. Stream info, seek table and
 * cue sheet are kept.
 */
final class FlacCleaner implements CleanerInterface
{
    public const BLOCK_PADDING = 1;

    /** Block types turned into padding. */
    private const REMOVE = [2 => 'APPLICATION', 4 => 'VORBIS_COMMENT', 6 => 'PICTURE'];

    private const MAX_BLOCKS = 1000;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new CleanerException('Cannot read file.');
        }
        try {
            $size = (int)fstat($fh)['size'];
            [$patches, $removed] = self::plan($fh, 0, $size, $options);
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
     * Plans the changes for the metadata blocks that follow "fLaC" at $start.
     *
     * @param resource $fh
     * @return array{0: array<int, string|int>, 1: MetadataBlock[]}
     */
    public static function plan($fh, int $start, int $size, CleanOptions $options): array
    {
        if (FileIo::readAt($fh, $start, 4) !== 'fLaC') {
            throw new CleanerException('Not a FLAC file.');
        }
        $patches = [];
        $removed = [];
        $p = $start + 4;
        for ($i = 0; $i < self::MAX_BLOCKS; $i++) {
            $header = FileIo::readAt($fh, $p, 4);
            $last = (ord($header[0]) & 0x80) !== 0;
            $type = ord($header[0]) & 0x7F;
            $length = unpack('N', "\x00" . substr($header, 1, 3))[1];
            if ($type === 127 || ($i === 0 && $type !== 0) || $p + 4 + $length > $size) {
                throw new CleanerException('Invalid FLAC metadata block.');
            }
            if (isset(self::REMOVE[$type])) {
                $fields = [];
                if ($type === 4 && $options->collectPayloads && $length <= $options->maxPayloadBytes) {
                    $fields = (new VorbisCommentReader())->read(FileIo::readAt($fh, $p + 4, $length), 0);
                }
                $patches[$p] = chr(($last ? 0x80 : 0) | self::BLOCK_PADDING);
                if ($length > 0) {
                    $patches[$p + 4] = $length;
                }
                $removed[] = new MetadataBlock(MetadataBlock::TYPE_OTHER, 'FLAC:' . self::REMOVE[$type], $length, null, $fields);
            }
            $p += 4 + $length;
            if ($last) {
                return [$patches, $removed];
            }
        }

        throw new CleanerException('Too many FLAC metadata blocks.');
    }
}
