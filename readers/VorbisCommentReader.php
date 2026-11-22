<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\readers;

/**
 * Reads a Vorbis comment block (used by FLAC, Ogg Vorbis, Opus, Theora,
 * Speex): a vendor string and a list of "NAME=value" comments, all with
 * little-endian 32-bit lengths.
 */
class VorbisCommentReader
{
    private const MAX_COMMENTS = 500;

    /**
     * @param string $data the block
     * @param int $offset where the vendor length starts
     * @param string $prefix field name prefix
     * @return array<string, string>
     */
    public function read(string $data, int $offset, string $prefix = 'Vorbis'): array
    {
        $fields = [];
        $vendor = $this->string($data, $offset);
        if ($vendor === null) {
            return [];
        }
        $fields[$prefix . ':Vendor'] = $vendor;
        if ($offset + 4 > strlen($data)) {
            return $fields;
        }
        $count = unpack('V', $data, $offset)[1];
        $offset += 4;
        for ($i = 0; $i < min($count, self::MAX_COMMENTS); $i++) {
            $comment = $this->string($data, $offset);
            if ($comment === null) {
                break;
            }
            [$name, $value] = explode('=', $comment, 2) + [1 => ''];
            $name = strtoupper($name);
            if ($name === 'METADATA_BLOCK_PICTURE' || $name === 'COVERART') {
                $value = '';
            }
            $key = $prefix . ':' . $name;
            $fields[$key] = isset($fields[$key]) ? $fields[$key] . '; ' . $value : $value;
        }

        return $fields;
    }

    private function string(string $data, int &$offset): ?string
    {
        if ($offset + 4 > strlen($data)) {
            return null;
        }
        $length = unpack('V', $data, $offset)[1];
        if ($offset + 4 + $length > strlen($data)) {
            return null;
        }
        $value = substr($data, $offset + 4, min($length, 4096));
        $offset += 4 + $length;

        return $value;
    }
}
