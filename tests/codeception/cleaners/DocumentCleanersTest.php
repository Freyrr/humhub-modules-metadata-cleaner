<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\cleaners;

use DOMDocument;
use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\cleaners\PdfCleaner;
use humhub\modules\metadataCleaner\readers\FieldExtractor;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;
use ZipArchive;

/**
 * PDF, Office / OpenDocument and SVG files.
 */
class DocumentCleanersTest extends CleanerTestCase
{
    private const SECRETS = ['Mario', 'Secret', 'secret', 'ACME'];

    public static function documents(): array
    {
        return [
            'pdf with incremental update' => ['meta.pdf', FormatDetector::FORMAT_PDF],
            'pdf with object streams' => ['meta-objstm.pdf', FormatDetector::FORMAT_PDF],
            'docx' => ['meta.docx', FormatDetector::FORMAT_OFFICE],
            'xlsx' => ['meta.xlsx', FormatDetector::FORMAT_OFFICE],
            'odt' => ['meta.odt', FormatDetector::FORMAT_OFFICE],
            'svg' => ['meta.svg', FormatDetector::FORMAT_SVG],
        ];
    }

    public static function brokenFiles(): array
    {
        return [
            'pdf' => ['broken.pdf'],
            'docx' => ['broken.docx'],
            'svg' => ['broken.svg'],
        ];
    }

    /**
     * @dataProvider documents
     */
    public function testSecondRunChangesNothing(string $name, string $format): void
    {
        $path = $this->copyFixture($name);
        $first = (new MetadataCleanerService())->cleanPath($path);
        $this->assertSame($format, $first->format);
        $this->assertTrue($first->changed);
        $once = file_get_contents($path);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertFalse($result->changed);
        $this->assertSame($once, file_get_contents($path));
    }

    /**
     * @dataProvider brokenFiles
     */
    public function testBrokenFileIsRejectedAndLeftUntouched(string $name): void
    {
        $path = $this->copyFixture($name);
        $before = file_get_contents($path);

        try {
            (new MetadataCleanerService())->cleanPath($path);
            $this->fail('No exception for a broken file.');
        } catch (CleanerException) {
        }

        $this->assertSame($before, file_get_contents($path));
    }

    // PDF

    public function testPdfInformationAndXmpAreRemovedInEveryRevision(): void
    {
        foreach (['meta.pdf', 'meta-objstm.pdf'] as $name) {
            $path = $this->copyFixture($name);
            $size = filesize($path);

            (new MetadataCleanerService())->cleanPath($path);

            $this->assertSame($size, filesize($path), "$name size");
            $text = $this->pdfText($path);
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, $text, "$name: $secret");
            }
        }
    }

    public function testPdfPagesAreUnchanged(): void
    {
        // Xpdf-derived tools exit with 99 when asked for their version.
        exec('pdftotext -v 2>&1', $out, $code);
        if ($code !== 0 && $code !== 99) {
            $this->markTestSkipped('pdftotext is not installed.');
        }
        foreach (['meta.pdf', 'meta-objstm.pdf'] as $name) {
            $path = $this->copyFixture($name);
            $before = shell_exec('pdftotext ' . escapeshellarg($path) . ' - 2>&1');

            (new MetadataCleanerService())->cleanPath($path);

            $this->assertSame($before, shell_exec('pdftotext ' . escapeshellarg($path) . ' - 2>&1'), $name);
            exec('pdfinfo ' . escapeshellarg($path) . ' 2>&1', $info, $code);
            $this->assertSame(0, $code, implode("\n", $info));
            $this->assertStringNotContainsString('Syntax Error', implode("\n", $info));
        }
    }

    public function testPdfFieldsAreReadForTheLog(): void
    {
        $path = $this->copyFixture('meta-objstm.pdf');

        $result = (new MetadataCleanerService(new CleanOptions(collectPayloads: true)))->cleanPath($path);
        $fields = (new FieldExtractor())->extract($result->removed);

        $this->assertSame('Mario Rossi', $fields['PDF:Author'] ?? null);
        $this->assertSame('Secret title', $fields['PDF:Title'] ?? null);
        $this->assertSame('Mario Rossi', $fields['XMP:dc:creator'] ?? null);
    }

    public function testEncryptedPdfIsRejected(): void
    {
        $path = $this->copyFixture('meta.pdf');
        file_put_contents($path, "\ntrailer\n<< /Size 3 /Root 1 0 R /Encrypt 2 0 R >>\n", FILE_APPEND);
        $before = file_get_contents($path);

        $this->expectException(CleanerException::class);
        try {
            (new MetadataCleanerService())->cleanPath($path);
        } finally {
            $this->assertSame($before, file_get_contents($path));
        }
    }

    public function testZlibStreamsHaveTheRequestedLength(): void
    {
        foreach ([20, 120, 70000, 140000] as $length) {
            $stored = PdfCleaner::storedToLength('<x/>', $length);
            $this->assertSame($length, strlen($stored));
            $this->assertStringStartsWith('<x/>', gzuncompress($stored));
        }
        $data = str_repeat('1 0 2 40 << /A (b) >> ', 500);
        $deflated = PdfCleaner::deflateToLength($data, 400);
        $this->assertSame(400, strlen($deflated));
        $this->assertStringStartsWith($data, gzuncompress($deflated));
    }

    // Office and OpenDocument

    public function testOfficePropertiesAreEmptiedAndContentKept(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The zip extension is not installed.');
        }
        $properties = ['docProps/core.xml', 'docProps/app.xml', 'docProps/custom.xml', 'meta.xml'];
        foreach (['meta.docx', 'meta.xlsx', 'meta.odt'] as $name) {
            $path = $this->copyFixture($name);
            $before = $this->zipEntries($path);

            (new MetadataCleanerService())->cleanPath($path);

            $after = $this->zipEntries($path);
            $this->assertSame(array_keys($before), array_keys($after), "$name: entries and order");
            foreach ($before as $entry => $content) {
                if (in_array($entry, $properties, true)) {
                    foreach (self::SECRETS as $secret) {
                        $this->assertStringNotContainsString($secret, $after[$entry], "$name $entry");
                    }
                    $doc = new DOMDocument();
                    $this->assertTrue($doc->loadXML($after[$entry]), "$name $entry is valid XML");
                } else {
                    $this->assertSame($content, $after[$entry], "$name $entry unchanged");
                }
            }
        }
    }

    public function testOpenDocumentMimetypeStaysFirstAndStored(): void
    {
        $path = $this->copyFixture('meta.odt');

        (new MetadataCleanerService())->cleanPath($path);

        $data = file_get_contents($path);
        $this->assertSame("PK\x03\x04", substr($data, 0, 4));
        $this->assertSame(0, unpack('v', $data, 8)[1], 'mimetype is stored');
        $this->assertSame('mimetype', substr($data, 30, 8));
        $this->assertStringStartsWith('application/vnd.oasis.opendocument.text', substr($data, 38));
    }

    public function testOfficeFieldsAreReadForTheLog(): void
    {
        $path = $this->copyFixture('meta.docx');

        $result = (new MetadataCleanerService(new CleanOptions(collectPayloads: true)))->cleanPath($path);
        $fields = (new FieldExtractor())->extract($result->removed);

        $this->assertSame('Mario Rossi', $fields['Office:creator'] ?? null);
        $this->assertSame('Mario Rossi', $fields['Office:lastModifiedBy'] ?? null);
        $this->assertSame('ACME Secret', $fields['Office:Company'] ?? null);
    }

    public function testOtherZipFilesAreNotHandled(): void
    {
        $path = $this->copyFixture('plain.zip');
        $before = file_get_contents($path);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertNull($result->format);
        $this->assertSame($before, file_get_contents($path));
    }

    // SVG

    public function testSvgMetadataAndScriptsAreRemoved(): void
    {
        $path = $this->copyFixture('meta.svg');

        $result = (new MetadataCleanerService(new CleanOptions(collectPayloads: true)))->cleanPath($path);

        $svg = file_get_contents($path);
        foreach (['<script', 'onload', 'onclick', 'javascript:', '<metadata', 'foreignObject', 'inkscape:', 'sodipodi:docname', '<!--', 'Mario', 'secret', '<set'] as $removed) {
            $this->assertStringNotContainsString($removed, $svg);
        }
        foreach (['<rect', '<circle', '<text', 'href="https://example.org/"', 'style="stroke:#000"', 'viewBox="0 0 100 80"'] as $kept) {
            $this->assertStringContainsString($kept, $svg);
        }
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $fields = (new FieldExtractor())->extract($result->removed);
        $this->assertSame('/home/mario.rossi/Documents/secret.png', $fields['SVG:inkscape:export-filename'] ?? null);
        $this->assertSame('2026-11-04', $fields['SVG:dc:date'] ?? null);
    }

    public function testSvgWithEntitiesIsRejected(): void
    {
        $path = $this->copyFixture('meta.svg');
        file_put_contents($path, '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "<script>alert(1)</script>">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>');

        $this->expectException(CleanerException::class);
        (new MetadataCleanerService())->cleanPath($path);
    }

    public function testSvgDetection(): void
    {
        $this->assertSame(FormatDetector::FORMAT_SVG, FormatDetector::detectType($this->fixture('meta.svg')));
        $this->assertSame(FormatDetector::FORMAT_SVG, FormatDetector::svgType("\xEF\xBB\xBF<!-- c --><!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"x.dtd\">\n<svg:svg xmlns:svg=\"http://www.w3.org/2000/svg\"/>"));
        $this->assertNull(FormatDetector::svgType('<html><body><svg></svg></body></html>'));
        $this->assertNull(FormatDetector::svgType('<svgfoo/>'));
    }

    public function testDisabledDocumentTypesAreNotTouched(): void
    {
        foreach (['meta.pdf', 'meta.docx', 'meta.svg'] as $name) {
            $path = $this->copyFixture($name);
            $before = file_get_contents($path);

            $result = (new MetadataCleanerService(new CleanOptions(), [FormatDetector::FORMAT_JPEG]))->cleanPath($path);

            $this->assertNull($result->format);
            $this->assertSame($before, file_get_contents($path));
        }
    }

    /**
     * Info and XMP of a PDF as shown by pdfinfo, or the raw file without it.
     */
    private function pdfText(string $path): string
    {
        exec('pdfinfo ' . escapeshellarg($path) . ' 2>' . self::nullDevice(), $info, $code);
        if ($code !== 0) {
            return file_get_contents($path);
        }
        exec('pdfinfo -meta ' . escapeshellarg($path) . ' 2>' . self::nullDevice(), $meta);

        return implode("\n", $info) . "\n" . implode("\n", $meta) . "\n" . file_get_contents($path);
    }

    /**
     * @return array<string, string> entry name => content, in archive order
     */
    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, "Cannot open $path");
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }
}
