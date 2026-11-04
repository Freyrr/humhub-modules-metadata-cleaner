<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\readers;

/**
 * Reads tag names and values from a TIFF/EXIF block, in plain PHP.
 * Walks IFD0, IFD1, the Exif, GPS and Interoperability sub-IFDs.
 */
class ExifReader
{
    private const SUB_IFDS = [0x8769 => 'ExifIFD', 0x8825 => 'GPS', 0xA005 => 'InteropIFD'];

    private const TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8];

    private const TAGS = [
        'IFD' => [
            0x010E => 'ImageDescription', 0x010F => 'Make', 0x0110 => 'Model', 0x0112 => 'Orientation',
            0x011A => 'XResolution', 0x011B => 'YResolution', 0x0128 => 'ResolutionUnit', 0x0131 => 'Software',
            0x0132 => 'ModifyDate', 0x013B => 'Artist', 0x013C => 'HostComputer', 0x0213 => 'YCbCrPositioning',
            0x8298 => 'Copyright', 0x0201 => 'ThumbnailOffset', 0x0202 => 'ThumbnailLength', 0x0103 => 'Compression',
            0x02BC => 'XMLPacket', 0x83BB => 'IPTC-NAA', 0x8649 => 'PhotoshopSettings', 0x8773 => 'ICC_Profile',
            0x0100 => 'ImageWidth', 0x0101 => 'ImageHeight', 0x0102 => 'BitsPerSample', 0x0106 => 'PhotometricInterpretation',
            0x0111 => 'StripOffsets', 0x0115 => 'SamplesPerPixel', 0x0116 => 'RowsPerStrip', 0x0117 => 'StripByteCounts',
            0x011C => 'PlanarConfiguration', 0x0153 => 'SampleFormat', 0x010D => 'DocumentName', 0x011D => 'PageName',
            0x9C9B => 'XPTitle', 0x9C9C => 'XPComment', 0x9C9D => 'XPAuthor', 0x9C9E => 'XPKeywords', 0x9C9F => 'XPSubject',
        ],
        'ExifIFD' => [
            0x829A => 'ExposureTime', 0x829D => 'FNumber', 0x8822 => 'ExposureProgram', 0x8827 => 'ISO',
            0x9000 => 'ExifVersion', 0x9003 => 'DateTimeOriginal', 0x9004 => 'CreateDate', 0x9010 => 'OffsetTime',
            0x9011 => 'OffsetTimeOriginal', 0x9012 => 'OffsetTimeDigitized', 0x9201 => 'ShutterSpeedValue',
            0x9202 => 'ApertureValue', 0x9204 => 'ExposureCompensation', 0x9207 => 'MeteringMode', 0x9209 => 'Flash',
            0x920A => 'FocalLength', 0x927C => 'MakerNote', 0x9286 => 'UserComment', 0x9290 => 'SubSecTime',
            0x9291 => 'SubSecTimeOriginal', 0x9292 => 'SubSecTimeDigitized', 0xA001 => 'ColorSpace',
            0xA002 => 'ExifImageWidth', 0xA003 => 'ExifImageHeight', 0xA420 => 'ImageUniqueID',
            0xA430 => 'OwnerName', 0xA431 => 'SerialNumber', 0xA432 => 'LensInfo', 0xA433 => 'LensMake',
            0xA434 => 'LensModel', 0xA435 => 'LensSerialNumber', 0xA402 => 'ExposureMode', 0xA403 => 'WhiteBalance',
            0xA405 => 'FocalLengthIn35mmFormat', 0xA406 => 'SceneCaptureType',
        ],
        'GPS' => [
            0x00 => 'GPSVersionID', 0x01 => 'GPSLatitudeRef', 0x02 => 'GPSLatitude', 0x03 => 'GPSLongitudeRef',
            0x04 => 'GPSLongitude', 0x05 => 'GPSAltitudeRef', 0x06 => 'GPSAltitude', 0x07 => 'GPSTimeStamp',
            0x0C => 'GPSSpeedRef', 0x0D => 'GPSSpeed', 0x10 => 'GPSImgDirectionRef', 0x11 => 'GPSImgDirection',
            0x12 => 'GPSMapDatum', 0x17 => 'GPSDestBearingRef', 0x18 => 'GPSDestBearing', 0x1B => 'GPSProcessingMethod',
            0x1D => 'GPSDateStamp', 0x1F => 'GPSHPositioningError',
        ],
        'InteropIFD' => [0x0001 => 'InteropIndex', 0x0002 => 'InteropVersion'],
    ];

    /** Tags whose value is binary or huge and is never logged. */
    private const NO_VALUE = [0x927C, 0x0201, 0x0202, 0x02BC, 0x83BB, 0x8649, 0x8773, 0x0111, 0x0117, 0x0144, 0x0145];

    public static function tagName(string $dictionary, int $tag): string
    {
        return self::TAGS[$dictionary][$tag] ?? sprintf('Tag0x%04X', $tag);
    }

    private string $data = '';
    private string $u16 = 'n';
    private string $u32 = 'N';
    /** @var array<int, true> */
    private array $visited = [];

    /**
     * @return array<string, string>
     */
    public function read(string $tiff): array
    {
        $this->data = $tiff;
        $this->visited = [];
        if (strlen($tiff) < 8) {
            return [];
        }
        $order = substr($tiff, 0, 2);
        if ($order === 'II') {
            [$this->u16, $this->u32] = ['v', 'V'];
        } elseif ($order === 'MM') {
            [$this->u16, $this->u32] = ['n', 'N'];
        } else {
            return [];
        }

        $fields = [];
        $offset = $this->u32At(4);
        $index = 0;
        while ($offset !== null && $offset !== 0 && $index < 4) {
            $offset = $this->readIfd($offset, 'IFD', $index === 0 ? 'IFD0' : 'IFD' . $index, $fields);
            $index++;
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     * @return int|null offset of the next IFD
     */
    private function readIfd(int $offset, string $dictionary, string $group, array &$fields): ?int
    {
        if (isset($this->visited[$offset]) || $offset + 2 > strlen($this->data)) {
            return null;
        }
        $this->visited[$offset] = true;
        $count = $this->u16At($offset);
        if ($count === null || $offset + 2 + $count * 12 + 4 > strlen($this->data)) {
            return null;
        }

        for ($i = 0; $i < $count; $i++) {
            $p = $offset + 2 + $i * 12;
            $tag = $this->u16At($p);
            $type = $this->u16At($p + 2);
            $n = $this->u32At($p + 4);

            if ($dictionary === 'IFD' && isset(self::SUB_IFDS[$tag])) {
                $sub = $this->u32At($p + 8);
                $name = self::SUB_IFDS[$tag];
                if ($sub !== null) {
                    $this->readIfd($sub, $name, $name, $fields);
                }
                continue;
            }
            if ($dictionary === 'ExifIFD' && $tag === 0xA005) {
                $sub = $this->u32At($p + 8);
                if ($sub !== null) {
                    $this->readIfd($sub, 'InteropIFD', 'InteropIFD', $fields);
                }
                continue;
            }

            $name = self::TAGS[$dictionary][$tag] ?? sprintf('Tag0x%04X', $tag);
            $fields['EXIF:' . $group . ':' . $name] = in_array($tag, self::NO_VALUE, true)
                ? ''
                : $this->value($type, $n, $p + 8);
        }

        return $this->u32At($offset + 2 + $count * 12);
    }

    private function value(?int $type, ?int $count, int $valueField): string
    {
        $size = self::TYPE_SIZES[$type] ?? null;
        if ($size === null || $count === null || $count === 0 || $count > 65535) {
            return '';
        }
        $total = $size * $count;
        $start = $total <= 4 ? $valueField : $this->u32At($valueField);
        if ($start === null || $start + $total > strlen($this->data)) {
            return '';
        }
        $raw = substr($this->data, $start, min($total, 1024));

        switch ($type) {
            case 2: // ASCII
                return rtrim($raw, "\x00");
            case 3:
                return implode(' ', array_values(unpack($this->u16 . '*', $raw)));
            case 4:
                return implode(' ', array_values(unpack($this->u32 . '*', $raw)));
            case 5:
            case 10:
                $parts = [];
                for ($i = 0; $i + 8 <= strlen($raw); $i += 8) {
                    $num = unpack($this->u32, $raw, $i)[1];
                    $den = unpack($this->u32, $raw, $i + 4)[1];
                    if ($type === 10) {
                        $num = $num >= 0x80000000 ? $num - 0x100000000 : $num;
                        $den = $den >= 0x80000000 ? $den - 0x100000000 : $den;
                    }
                    $parts[] = $den === 0 ? (string)$num : rtrim(rtrim(sprintf('%.6F', $num / $den), '0'), '.');
                }
                return implode(' ', $parts);
            case 1:
                return implode('.', array_values(unpack('C*', $raw)));
            case 7: // UNDEFINED: show as text when printable
                $text = rtrim($raw, "\x00");
                if (str_starts_with($text, "ASCII\x00\x00\x00") || str_starts_with($text, "UNICODE\x00")) {
                    $text = substr($text, 8);
                }
                return preg_match('/^[\x20-\x7E]*$/', $text) ? $text : '';
            default:
                return '';
        }
    }

    private function u16At(int $p): ?int
    {
        return $p + 2 <= strlen($this->data) ? unpack($this->u16, $this->data, $p)[1] : null;
    }

    private function u32At(int $p): ?int
    {
        return $p + 4 <= strlen($this->data) ? unpack($this->u32, $this->data, $p)[1] : null;
    }
}
