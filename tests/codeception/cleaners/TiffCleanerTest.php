<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\readers\FieldExtractor;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;

class TiffCleanerTest extends CleanerTestCase
{
    private const FORBIDDEN_TAGS = ['Make', 'Model', 'Artist', 'Software', 'ModifyDate', 'HostComputer', 'Copyright', 'ImageDescription'];

    public static function tiffs(): array
    {
        return [
            'single page' => ['meta-plain.tif'],
            'multi-page, LZW' => ['meta-multipage.tif'],
        ];
    }

    /**
     * @dataProvider tiffs
     */
    public function testRemovesAllMetadata(string $name): void
    {
        $this->requireExiftool();
        $path = $this->copyFixture($name);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame(FormatDetector::FORMAT_TIFF, $result->format);
        $this->assertTrue($result->changed);
        foreach ($this->exiftoolTags($path) as $key => $value) {
            [$group, $tag] = explode(':', $key, 2);
            foreach (['ExifIFD', 'GPS', 'XMP', 'IPTC', 'Photoshop', 'InteropIFD'] as $forbidden) {
                $this->assertStringStartsNotWith($forbidden, $group, "Tag $key still present");
            }
            $this->assertNotContains($tag, self::FORBIDDEN_TAGS, "Tag $key still present");
        }
        $this->assertStringNotContainsString('Mario Rossi', file_get_contents($path));
        $this->assertStringNotContainsString('TestPhone', file_get_contents($path));
    }

    /**
     * @dataProvider tiffs
     */
    public function testDoesNotReencode(string $name): void
    {
        exec('convert -version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ImageMagick is not installed.');
        }
        $path = $this->copyFixture($name);
        $before = $this->decoded($path);

        (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame($before, $this->decoded($path));
    }

    /**
     * @dataProvider tiffs
     */
    public function testSecondRunChangesNothing(string $name): void
    {
        $path = $this->copyFixture($name);
        $service = new MetadataCleanerService();
        $service->cleanPath($path);
        $once = file_get_contents($path);

        $this->assertFalse($service->cleanPath($path)->changed);
        $this->assertSame($once, file_get_contents($path));
    }

    public function testOrientationIsKept(): void
    {
        $this->requireExiftool();
        $path = $this->copyFixture('meta-plain.tif');

        (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame('Rotate 90 CW', $this->exiftoolTags($path)['IFD0:Orientation'] ?? null);
    }

    public function testBrokenTiffIsRejectedAndLeftUntouched(): void
    {
        $path = $this->copyFixture('broken.tif');
        $before = file_get_contents($path);

        try {
            (new MetadataCleanerService())->cleanPath($path);
            $this->fail('A broken file must not be accepted.');
        } catch (CleanerException) {
        }

        $this->assertSame($before, file_get_contents($path));
    }

    public function testFieldsAreListedForTheLog(): void
    {
        $path = $this->copyFixture('meta-plain.tif');

        $result = (new MetadataCleanerService(new CleanOptions(collectPayloads: true)))->cleanPath($path);
        $fields = (new FieldExtractor())->extract($result->removed);

        $this->assertArrayHasKey('EXIF:IFD0:Make', $fields);
        $this->assertArrayHasKey('EXIF:GPS:GPSLatitude', $fields);
        $this->assertArrayHasKey('EXIF:IFD0:XMLPacket', $fields);
        $this->assertSame('TestPhone', $fields['EXIF:IFD0:Make']);
        $this->assertStringStartsWith('45 27', $fields['EXIF:GPS:GPSLatitude']);
    }

    /**
     * Every page decoded to raw RGB by ImageMagick.
     */
    private function decoded(string $path): string
    {
        exec('convert ' . escapeshellarg($path) . ' -depth 8 rgb:- 2>/dev/null | md5sum', $out);

        return $out[0] ?? '';
    }
}
