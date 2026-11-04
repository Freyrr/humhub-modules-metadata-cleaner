<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\cleaners\MetadataBlock;
use humhub\modules\metadataCleaner\cleaners\TiffHeader;
use humhub\modules\metadataCleaner\readers\FieldExtractor;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;

class ImageCleanersTest extends CleanerTestCase
{
    public static function metadataFiles(): array
    {
        return [
            'jpeg baseline' => ['meta-plain.jpg', FormatDetector::FORMAT_JPEG],
            'jpeg progressive' => ['meta-progressive.jpg', FormatDetector::FORMAT_JPEG],
            'jpeg with trailer' => ['trailer.jpg', FormatDetector::FORMAT_JPEG],
            'png' => ['meta.png', FormatDetector::FORMAT_PNG],
            'webp' => ['meta.webp', FormatDetector::FORMAT_WEBP],
            'gif' => ['meta.gif', FormatDetector::FORMAT_GIF],
            'heic' => ['meta.heic', FormatDetector::FORMAT_HEIF],
        ];
    }

    public static function brokenFiles(): array
    {
        return [
            'jpeg' => ['broken.jpg'],
            'png' => ['broken.png'],
            'webp' => ['broken.webp'],
            'gif' => ['broken.gif'],
            'heic' => ['broken.heic'],
        ];
    }

    /**
     * @dataProvider metadataFiles
     */
    public function testRemovesAllMetadata(string $name, string $format): void
    {
        $this->requireExiftool();
        $path = $this->copyFixture($name);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame($format, $result->format);
        $this->assertTrue($result->changed);
        $this->assertTrue($result->hadMetadata());
        $this->assertNoMetadata($path);
    }

    /**
     * @dataProvider metadataFiles
     */
    public function testDoesNotReencode(string $name): void
    {
        if (str_ends_with($name, '.heic')) {
            // GD cannot decode HEIC: the bytes of the image item are compared instead.
            $path = $this->copyFixture($name);
            $before = file_get_contents($path);
            (new MetadataCleanerService())->cleanPath($path);
            $after = file_get_contents($path);
            $this->assertSame(strlen($before), strlen($after));
            $this->assertSame(substr($before, -10000), substr($after, -10000));
            return;
        }
        $path = $this->copyFixture($name);
        $before = $this->pixels($path);

        (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame($before, $this->pixels($path));
    }

    /**
     * @dataProvider metadataFiles
     */
    public function testSecondRunChangesNothing(string $name): void
    {
        $path = $this->copyFixture($name);
        $service = new MetadataCleanerService();
        $service->cleanPath($path);
        $once = file_get_contents($path);

        $again = $service->cleanPath($path);

        $this->assertFalse($again->changed);
        $this->assertFalse($again->hadMetadata());
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
            $this->fail('A broken file must not be accepted.');
        } catch (CleanerException) {
        }

        $this->assertSame($before, file_get_contents($path));
    }

    public function testJpegKeepsOnlyOrientation(): void
    {
        $this->requireExiftool();
        $path = $this->copyFixture('meta-plain.jpg');

        (new MetadataCleanerService(new CleanOptions(keepOrientation: true)))->cleanPath($path);

        $tags = $this->exiftoolTags($path);
        $this->assertSame('Rotate 90 CW', $tags['IFD0:Orientation'] ?? null);
        $this->assertSame(['IFD0:Orientation'], array_values(array_filter(
            array_keys($tags),
            static fn($k) => str_starts_with($k, 'IFD') || str_starts_with($k, 'Exif'),
        )));
    }

    public function testJpegCanDropOrientationToo(): void
    {
        $this->requireExiftool();
        $path = $this->copyFixture('meta-plain.jpg');

        (new MetadataCleanerService(new CleanOptions(keepOrientation: false)))->cleanPath($path);

        $this->assertNoMetadata($path, false);
    }

    public function testPngKeepsOnlyOrientation(): void
    {
        $this->requireExiftool();
        $path = $this->copyFixture('meta.png');

        (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame('Rotate 90 CW', $this->exiftoolTags($path)['IFD0:Orientation'] ?? null);
    }

    public function testJpegTrailerIsRemoved(): void
    {
        $path = $this->copyFixture('trailer.jpg');

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertStringNotContainsString('MotionPhoto', file_get_contents($path));
        $this->assertStringEndsWith("\xFF\xD9", file_get_contents($path));
        $this->assertContains('trailer', array_map(static fn(MetadataBlock $b) => $b->location, $result->removed));
    }

    public function testDisabledFormatIsNotTouched(): void
    {
        $path = $this->copyFixture('meta-plain.jpg');
        $before = file_get_contents($path);

        $result = (new MetadataCleanerService(new CleanOptions(), false, [FormatDetector::FORMAT_PNG]))->cleanPath($path);

        $this->assertNull($result->format);
        $this->assertSame($before, file_get_contents($path));
    }

    public function testUnknownFormatIsNotTouched(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mdct');
        file_put_contents($path, "%PDF-1.4\nnot an image");

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertNull($result->format);
        $this->assertFalse($result->changed);
        unlink($path);
    }

    public function testTiffIsRejectedByDefault(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mdct');
        file_put_contents($path, "II*\x00" . str_repeat("\x00", 20));

        $this->expectException(CleanerException::class);
        try {
            (new MetadataCleanerService())->cleanPath($path);
        } finally {
            unlink($path);
        }
    }

    public function testFormatDetection(): void
    {
        $this->assertSame(FormatDetector::FORMAT_JPEG, FormatDetector::detect($this->fixture('plain.jpg')));
        $this->assertSame(FormatDetector::FORMAT_PNG, FormatDetector::detect($this->fixture('plain.png')));
        $this->assertSame(FormatDetector::FORMAT_WEBP, FormatDetector::detect($this->fixture('plain.webp')));
        $this->assertSame(FormatDetector::FORMAT_GIF, FormatDetector::detect($this->fixture('plain.gif')));
        $this->assertSame(FormatDetector::FORMAT_HEIF, FormatDetector::detectFromBytes("\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic"));
        $this->assertSame(FormatDetector::FORMAT_HEIF, FormatDetector::detectFromBytes("\x00\x00\x00\x18ftypmif1\x00\x00\x00\x00mif1heic"));
        $this->assertSame(FormatDetector::FORMAT_VIDEO, FormatDetector::detectFromBytes("\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  "));
        $this->assertSame(FormatDetector::FORMAT_VIDEO, FormatDetector::detectFromBytes("\x00\x00\x00\x18ftyp3gp4\x00\x00\x00\x00isom3gp4"));
        $this->assertNull(FormatDetector::detectFromBytes('PK' . "\x03\x04"));
    }

    public function testFieldsAreReadForTheLog(): void
    {
        $path = $this->copyFixture('meta-plain.jpg');

        $result = (new MetadataCleanerService(new CleanOptions(collectPayloads: true)))->cleanPath($path);
        $fields = (new FieldExtractor())->extract($result->removed);

        $this->assertSame('45 27 51.12', $fields['EXIF:GPS:GPSLatitude'] ?? null);
        $this->assertSame('TestPhone', $fields['EXIF:IFD0:Make'] ?? null);
        $this->assertSame('SN12345', $fields['EXIF:ExifIFD:SerialNumber'] ?? null);
        $this->assertSame('Tester', $fields['XMP:xmp:CreatorTool'] ?? null);
        $this->assertSame('Mario Rossi', $fields['IPTC:By-line'] ?? null);
        $this->assertSame('secret comment', $fields['COMMENT:COM'] ?? null);
    }

    public function testOrientationOnlyBlockIsRecognised(): void
    {
        $tiff = TiffHeader::buildOrientationOnly(6);

        $this->assertTrue(TiffHeader::isOrientationOnly($tiff));
        $this->assertSame(6, TiffHeader::orientation($tiff));
    }
}
