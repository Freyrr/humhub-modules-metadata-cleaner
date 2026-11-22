<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

use DOMAttr;
use DOMComment;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;

/**
 * Removes metadata and scripts from SVG images.
 *
 * Metadata removed: <metadata> elements (RDF/Dublin Core: author, title,
 * licence...), comments, and the private elements and attributes of
 * editors (Inkscape, Sodipodi, Adobe Illustrator, Sketch...), which hold
 * file names and paths of the author's computer, layer names and settings.
 *
 * Scripts removed: <script> and <handler> elements, <foreignObject> (HTML
 * content), embedded <iframe>, <embed> and <object>, every on* event
 * attribute, links and animations to "javascript:" or "data:" HTML URLs,
 * and styles with script expressions. Documents with entity declarations
 * are refused, so nothing hidden in entities can come back.
 */
final class SvgCleaner implements CleanerInterface
{
    private const MAX_FILE_BYTES = 33554432;

    private const SVG_NS = 'http://www.w3.org/2000/svg';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** Namespaces of editor data and metadata. */
    private const PRIVATE_NS = [
        'http://www.inkscape.org/namespaces/inkscape',
        'http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd',
        'http://inkscape.sourceforge.net/DTD/sodipodi-0.dtd',
        'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'http://purl.org/dc/elements/1.1/',
        'http://creativecommons.org/ns#',
        'http://web.resource.org/cc/',
        'http://www.bohemiancoding.com/sketch/ns',
        'http://www.serif.com/',
        'adobe:ns:meta/',
    ];

    /** Adobe namespaces start with this (Illustrator, graphs, variables, SFW, PGF...). */
    private const ADOBE_NS_PREFIX = 'http://ns.adobe.com/';

    /** Elements removed wherever they are. */
    private const REMOVE_ELEMENTS = ['metadata', 'script', 'handler', 'listener', 'foreignObject', 'iframe', 'embed', 'object'];

    /** Elements that can set an attribute: removed when they target a link or an event. */
    private const ANIMATIONS = ['set', 'animate', 'animateTransform', 'animateMotion'];

    /** @var array<string, string> */
    private array $fields = [];
    private int $removedCount = 0;

    public function clean(string $path, CleanOptions $options): CleanResult
    {
        if ((int)@filesize($path) > self::MAX_FILE_BYTES) {
            throw new CleanerException('SVG file too large.');
        }
        $data = FileIo::read($path);
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            $data = substr($data, 3);
        }
        if (stripos($data, '<!ENTITY') !== false) {
            throw new CleanerException('SVG files with entity declarations are not supported.');
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($data, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $doc->documentElement;
        if (!$loaded || !$root instanceof DOMElement) {
            throw new CleanerException('Invalid SVG (XML) file.');
        }
        if ($root->localName !== 'svg' || !in_array($root->namespaceURI, [self::SVG_NS, null, ''], true)) {
            return CleanResult::notHandled();
        }

        $this->fields = [];
        $this->removedCount = 0;
        foreach (iterator_to_array($doc->childNodes) as $child) {
            if ($child instanceof DOMDocumentType || $child instanceof DOMComment
                || ($child instanceof DOMProcessingInstruction && $child->target !== 'xml-stylesheet')) {
                $this->remove($child, 'SVG:' . ($child instanceof DOMDocumentType ? 'DOCTYPE' : 'comment'), $child instanceof DOMComment ? $child->data : '');
            }
        }
        $this->walk($root);

        if ($this->removedCount === 0) {
            return new CleanResult(FormatDetector::FORMAT_SVG, false);
        }
        $out = $doc->saveXML();
        if ($out === false) {
            throw new CleanerException('Cannot write SVG.');
        }
        FileIo::replace($path, $out);

        return new CleanResult(FormatDetector::FORMAT_SVG, true, [
            new MetadataBlock(MetadataBlock::TYPE_OTHER, 'SVG:document', strlen($data), null, $options->collectPayloads ? $this->fields : ['SVG:metadata' => '']),
        ]);
    }

    private function walk(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            $reason = $this->attributeReason($attribute);
            if ($reason !== null) {
                $this->note('SVG:' . $attribute->nodeName, $reason === 'private' ? $attribute->value : '');
                $element->removeAttributeNode($attribute);
                $this->removedCount++;
            }
        }
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $this->remove($child, 'SVG:comment', $child->data);
            } elseif ($child instanceof DOMProcessingInstruction) {
                $this->remove($child, 'SVG:' . $child->target, '');
            } elseif ($child instanceof DOMElement) {
                if ($this->isRemovedElement($child)) {
                    if ($child->localName === 'metadata') {
                        $this->metadataFields($child);
                    }
                    $this->remove($child, 'SVG:' . $child->nodeName, '');
                } else {
                    $this->walk($child);
                }
            }
        }
    }

    private function isRemovedElement(DOMElement $element): bool
    {
        $ns = (string)$element->namespaceURI;
        if ($this->isPrivateNamespace($ns)) {
            return true;
        }
        $name = $element->localName;
        if (in_array($name, self::REMOVE_ELEMENTS, true)) {
            return true;
        }
        if ($name === 'style' && preg_match('/javascript:|expression\s*\(|@import|behavior\s*:|-moz-binding/i', $element->textContent)) {
            return true;
        }
        if (in_array($name, self::ANIMATIONS, true)) {
            $target = strtolower(trim($element->getAttribute('attributeName')));
            if ($target === 'href' || str_ends_with($target, ':href') || str_starts_with($target, 'on')) {
                return true;
            }
            foreach (['to', 'from', 'values', 'by'] as $attribute) {
                if (self::isDangerousUrl($element->getAttribute($attribute))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Why an attribute is removed: 'private', 'script' or null to keep it.
     */
    private function attributeReason(DOMAttr $attribute): ?string
    {
        $ns = (string)$attribute->namespaceURI;
        if ($ns !== '' && $this->isPrivateNamespace($ns)) {
            return 'private';
        }
        $name = strtolower($attribute->localName);
        if (str_starts_with($name, 'on')) {
            return 'script';
        }
        if (($name === 'href' || $name === 'src' || $name === 'action' || $name === 'formaction') && self::isDangerousUrl($attribute->value)) {
            return 'script';
        }
        if ($name === 'style' && preg_match('/javascript:|expression\s*\(|behavior\s*:|-moz-binding/i', $attribute->value)) {
            return 'script';
        }

        return null;
    }

    private function isPrivateNamespace(string $ns): bool
    {
        return in_array($ns, self::PRIVATE_NS, true) || str_starts_with($ns, self::ADOBE_NS_PREFIX);
    }

    public static function isDangerousUrl(string $value): bool
    {
        $value = strtolower(preg_replace('/[\x00-\x20]+/', '', $value) ?? '');

        return str_starts_with($value, 'javascript:') || str_starts_with($value, 'vbscript:')
            || (str_starts_with($value, 'data:') && !preg_match('#^data:image/(png|jpe?g|gif|webp|avif|bmp)[;,]#', $value));
    }

    private function metadataFields(DOMElement $metadata): void
    {
        foreach ($metadata->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement && $node->childElementCount === 0) {
                $value = trim($node->textContent);
                if ($value !== '' || $node->hasAttribute('rdf:resource')) {
                    $this->note('SVG:' . $node->nodeName, $value !== '' ? $value : $node->getAttribute('rdf:resource'));
                }
            }
        }
    }

    private function remove(DOMNode $node, string $field, string $value): void
    {
        $this->note($field, $value);
        $node->parentNode?->removeChild($node);
        $this->removedCount++;
    }

    private function note(string $field, string $value): void
    {
        if (count($this->fields) >= 200 && !isset($this->fields[$field])) {
            return;
        }
        $value = trim($value);
        $this->fields[$field] = isset($this->fields[$field]) && $value !== '' && $this->fields[$field] !== '' ? $this->fields[$field] . '; ' . $value : ($value !== '' ? $value : ($this->fields[$field] ?? ''));
    }
}
