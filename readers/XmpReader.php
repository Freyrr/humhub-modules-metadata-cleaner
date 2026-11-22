<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\readers;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Reads property names and values from an XMP packet.
 */
class XmpReader
{
    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /**
     * @return array<string, string>
     */
    public function read(string $payload): array
    {
        $start = strpos($payload, '<');
        if ($start === false) {
            return [];
        }
        $xml = substr($payload, $start);
        $end = strrpos($xml, '>');
        $xml = $end === false ? $xml : substr($xml, 0, $end + 1);

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // No LIBXML_NOENT / DTD loading: external entities are never resolved.
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$ok) {
            return $this->readNamesOnly($xml);
        }

        $fields = [];
        foreach ($doc->getElementsByTagNameNS(self::RDF_NS, 'Description') as $description) {
            /** @var DOMElement $description */
            foreach ($description->attributes as $attr) {
                if ($attr->namespaceURI === self::RDF_NS || $attr->prefix === 'xmlns' || $attr->prefix === '') {
                    continue;
                }
                $fields['XMP:' . $attr->nodeName] = (string)$attr->value;
            }
            foreach ($description->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $fields['XMP:' . $child->nodeName] = $this->text($child);
                }
            }
        }

        return $fields;
    }

    private function text(DOMNode $node): string
    {
        $parts = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $value = $this->text($child);
                foreach ($child->attributes as $attr) {
                    if ($attr->namespaceURI !== self::RDF_NS && $attr->prefix !== 'xmlns') {
                        $value .= ($value === '' ? '' : ' ') . $attr->localName . '=' . $attr->value;
                    }
                }
                if ($value !== '') {
                    $parts[] = $value;
                }
            } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $text = trim($child->nodeValue ?? '');
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Fallback for broken XML: property names only.
     *
     * @return array<string, string>
     */
    private function readNamesOnly(string $xml): array
    {
        $fields = [];
        if (preg_match_all('/<([A-Za-z][\w.-]*:[A-Za-z][\w.-]*)[\s>\/]/', $xml, $m)) {
            foreach (array_unique($m[1]) as $name) {
                if (!str_starts_with($name, 'rdf:') && !str_starts_with($name, 'x:')) {
                    $fields['XMP:' . $name] = '';
                }
            }
        }

        return $fields;
    }
}
