<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\readers;

/**
 * Reads IPTC IIM datasets from a Photoshop image resource block (JPEG APP13)
 * or from raw IIM data.
 */
class IptcReader
{
    private const DATASETS = [
        5 => 'ObjectName', 7 => 'EditStatus', 10 => 'Urgency', 15 => 'Category', 20 => 'SupplementalCategories',
        25 => 'Keywords', 40 => 'SpecialInstructions', 55 => 'DateCreated', 60 => 'TimeCreated',
        62 => 'DigitalCreationDate', 63 => 'DigitalCreationTime', 65 => 'OriginatingProgram', 70 => 'ProgramVersion',
        80 => 'By-line', 85 => 'By-lineTitle', 90 => 'City', 92 => 'Sub-location', 95 => 'Province-State',
        100 => 'Country-PrimaryLocationCode', 101 => 'Country-PrimaryLocationName', 103 => 'OriginalTransmissionReference',
        105 => 'Headline', 110 => 'Credit', 115 => 'Source', 116 => 'CopyrightNotice', 118 => 'Contact',
        120 => 'Caption-Abstract', 122 => 'Writer-Editor',
    ];

    /**
     * @return array<string, string>
     */
    public function read(string $payload): array
    {
        if (str_starts_with($payload, "Photoshop 3.0\x00")) {
            $iim = $this->findIimResource(substr($payload, 14));
            if ($iim === null) {
                return [];
            }
            $payload = $iim;
        }

        return $this->readIim($payload);
    }

    private function findIimResource(string $irb): ?string
    {
        $len = strlen($irb);
        $p = 0;
        while ($p + 12 <= $len && substr($irb, $p, 4) === '8BIM') {
            $id = unpack('n', $irb, $p + 4)[1];
            $nameLen = ord($irb[$p + 6]);
            $p += 6 + 1 + $nameLen;
            if (($nameLen + 1) % 2 === 1) {
                $p++;
            }
            if ($p + 4 > $len) {
                return null;
            }
            $size = unpack('N', $irb, $p)[1];
            $p += 4;
            if ($p + $size > $len) {
                return null;
            }
            if ($id === 0x0404) {
                return substr($irb, $p, $size);
            }
            $p += $size + ($size % 2);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function readIim(string $iim): array
    {
        $fields = [];
        $len = strlen($iim);
        $p = 0;
        while ($p + 5 <= $len && $iim[$p] === "\x1C") {
            $record = ord($iim[$p + 1]);
            $dataset = ord($iim[$p + 2]);
            $size = unpack('n', $iim, $p + 3)[1];
            $p += 5;
            if ($size & 0x8000) {
                return $fields; // extended datasets are not used for text fields
            }
            if ($p + $size > $len) {
                break;
            }
            if ($record === 2 && $dataset !== 0) {
                $name = 'IPTC:' . (self::DATASETS[$dataset] ?? 'Dataset2:' . $dataset);
                $value = substr($iim, $p, $size);
                $fields[$name] = isset($fields[$name]) ? $fields[$name] . ', ' . $value : $value;
            }
            $p += $size;
        }

        return $fields;
    }
}
