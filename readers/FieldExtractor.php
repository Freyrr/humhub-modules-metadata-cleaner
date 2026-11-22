<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\readers;

use humhub\modules\metadataCleaner\cleaners\MetadataBlock;

/**
 * Turns removed metadata blocks into "group:name => value" pairs for the log.
 */
class FieldExtractor
{
    public const MAX_VALUE_LENGTH = 200;
    public const MAX_FIELDS = 200;

    /**
     * @param MetadataBlock[] $blocks
     * @return array<string, string> field name => value (may be empty when unknown)
     */
    public function extract(array $blocks): array
    {
        $fields = [];
        foreach ($blocks as $block) {
            $found = $block->fields !== [] ? $block->fields : match ($block->type) {
                MetadataBlock::TYPE_EXIF => $block->payload !== null ? (new ExifReader())->read($this->stripExifId($block->payload)) : [],
                MetadataBlock::TYPE_XMP => $block->payload !== null ? (new XmpReader())->read($block->payload) : [],
                MetadataBlock::TYPE_IPTC => $block->payload !== null ? (new IptcReader())->read($block->payload) : [],
                MetadataBlock::TYPE_COMMENT, MetadataBlock::TYPE_TEXT => [$this->name($block) => (string)$block->payload],
                default => [],
            };
            if ($found === []) {
                $found = [$this->name($block) => ''];
            }
            foreach ($found as $name => $value) {
                if (count($fields) >= self::MAX_FIELDS) {
                    break 2;
                }
                $fields[$name] = self::shorten($value);
            }
        }

        return $fields;
    }

    public static function shorten(string $value): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $value) ?? '');
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return mb_strlen($value) > self::MAX_VALUE_LENGTH ? mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…' : $value;
    }

    private function name(MetadataBlock $block): string
    {
        if ($block->type === MetadataBlock::TYPE_OTHER && str_contains($block->location, ':')) {
            return $block->location;
        }

        return strtoupper($block->type) . ':' . $block->location;
    }

    private function stripExifId(string $payload): string
    {
        return str_starts_with($payload, "Exif\x00\x00") ? substr($payload, 6) : $payload;
    }
}
