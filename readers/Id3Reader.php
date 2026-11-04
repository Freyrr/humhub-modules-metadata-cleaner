<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\readers;

/**
 * Reads the frames of ID3v2 tags and the fields of ID3v1 and APEv2 tags,
 * for the log only.
 */
class Id3Reader
{
    private const MAX_FRAMES = 300;

    /** Frames holding binary data: listed without value. */
    private const BINARY_FRAMES = ['APIC', 'PIC', 'GEOB', 'GEO', 'PRIV', 'MCDI', 'MCI', 'SYLT', 'SLT', 'AENC', 'CRA', 'ENCR', 'GRID', 'SIGN', 'RVA2', 'EQU2', 'RBUF', 'POSS', 'SEEK', 'ASPI'];

    /**
     * @param string $tag a whole ID3v2 tag, header included
     * @return array<string, string>
     */
    public function readV2(string $tag): array
    {
        if (strlen($tag) < 10 || !str_starts_with($tag, 'ID3')) {
            return [];
        }
        $major = ord($tag[3]);
        $flags = ord($tag[5]);
        $body = substr($tag, 10);
        if ($major < 4 && ($flags & 0x80)) {
            $body = str_replace("\xFF\x00", "\xFF", $body);
        }
        $p = 0;
        if ($flags & 0x40) {
            // Extended header.
            if (strlen($body) < 4) {
                return [];
            }
            $p = $major === 4 ? self::syncsafe(substr($body, 0, 4)) : 4 + unpack('N', $body)[1];
        }

        $fields = [];
        $idLength = $major === 2 ? 3 : 4;
        $headerLength = $major === 2 ? 6 : 10;
        $count = 0;
        while ($p + $headerLength <= strlen($body) && $count++ < self::MAX_FRAMES) {
            $id = substr($body, $p, $idLength);
            if (!preg_match('/^[A-Z0-9]+$/', $id)) {
                break;
            }
            if ($major === 2) {
                $size = unpack('N', "\x00" . substr($body, $p + 3, 3))[1];
                $formatFlags = 0;
            } else {
                $size = $major === 4 ? self::syncsafe(substr($body, $p + 4, 4)) : unpack('N', $body, $p + 4)[1];
                $formatFlags = ord($body[$p + 9]);
            }
            $data = (string)substr($body, $p + $headerLength, $size);
            $p += $headerLength + $size;

            $name = 'ID3:' . $id;
            if ($major === 4 && ($formatFlags & 0x02)) {
                $data = str_replace("\xFF\x00", "\xFF", $data);
            }
            $packed = $major === 4 ? ($formatFlags & 0x0C) : ($formatFlags & 0xC0);
            $value = ($packed || in_array($id, self::BINARY_FRAMES, true)) ? '' : $this->frameValue($id, $data);
            $fields[$name] = isset($fields[$name]) && $value !== '' ? $fields[$name] . '; ' . $value : $value;
        }

        return $fields;
    }

    /**
     * @param string $tag the 128 bytes of an ID3v1 tag
     * @return array<string, string>
     */
    public function readV1(string $tag): array
    {
        if (strlen($tag) !== 128 || !str_starts_with($tag, 'TAG')) {
            return [];
        }
        $text = static fn(int $offset, int $length) => rtrim(substr($tag, $offset, $length), "\x00 ");

        return array_filter([
            'ID3v1:Title' => $text(3, 30),
            'ID3v1:Artist' => $text(33, 30),
            'ID3v1:Album' => $text(63, 30),
            'ID3v1:Year' => $text(93, 4),
            'ID3v1:Comment' => $text(97, 30),
        ], static fn($v) => $v !== '') ?: ['ID3v1:Tag' => ''];
    }

    /**
     * @param string $tag an APEv2 tag (items, then the 32-byte footer)
     * @return array<string, string>
     */
    public function readApe(string $tag): array
    {
        $fields = [];
        $p = str_starts_with($tag, 'APETAGEX') ? 32 : 0;
        $end = strlen($tag) - 32;
        $count = 0;
        while ($p + 8 < $end && $count++ < self::MAX_FRAMES) {
            $length = unpack('V', $tag, $p)[1];
            $flags = unpack('V', $tag, $p + 4)[1];
            $keyEnd = strpos($tag, "\x00", $p + 8);
            if ($keyEnd === false || $keyEnd >= $end) {
                break;
            }
            $key = substr($tag, $p + 8, $keyEnd - $p - 8);
            $value = (($flags >> 1) & 3) === 0 ? (string)substr($tag, $keyEnd + 1, min($length, 4096)) : '';
            $fields['APE:' . $key] = $value;
            $p = $keyEnd + 1 + $length;
        }

        return $fields ?: ['APE:Tag' => ''];
    }

    public static function syncsafe(string $bytes): int
    {
        $b = array_values(unpack('C4', str_pad($bytes, 4, "\x00")));

        return ($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3];
    }

    private function frameValue(string $id, string $data): string
    {
        if ($data === '') {
            return '';
        }
        if ($id[0] === 'T' && $id !== 'TXXX' && $id !== 'TXX') {
            return $this->decode(ord($data[0]), substr($data, 1));
        }
        if ($id === 'TXXX' || $id === 'TXX' || $id === 'WXXX' || $id === 'WXX') {
            return $this->decode(ord($data[0]), substr($data, 1));
        }
        if ($id === 'COMM' || $id === 'COM' || $id === 'USLT' || $id === 'ULT') {
            // Encoding, language, short description, text.
            return $this->decode(ord($data[0]), substr($data, 4));
        }
        if ($id[0] === 'W') {
            return rtrim($data, "\x00");
        }

        return '';
    }

    private function decode(int $encoding, string $text): string
    {
        $text = match ($encoding) {
            1, 2 => (string)@mb_convert_encoding($text, 'UTF-8', $encoding === 1 ? 'UTF-16' : 'UTF-16BE'),
            3 => $text,
            default => (string)@mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1'),
        };

        return trim(str_replace("\x00", ' / ', rtrim($text, "\x00")));
    }
}
