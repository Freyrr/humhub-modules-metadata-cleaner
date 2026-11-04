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
        $this->requireTool(self::magick() . ' -version', 'ImageMagick');
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

    public function testDngKeepsTheRawTagsAndStillDevelops(): void
    {
        $this->requireExiftool();
        $path = $this->copyFixture('meta.dng');
        $before = $this->developed($path);

        $result = (new MetadataCleanerService(new CleanOptions(collectPayloads: true)))->cleanPath($path);

        $this->assertSame(FormatDetector::FORMAT_RAW, $result->format);
        $this->assertTrue($result->changed);
        $tags = $this->exiftoolTags($path);
        foreach (['IFD0:DNGVersion', 'IFD0:UniqueCameraModel', 'IFD0:ColorMatrix1', 'IFD0:CFAPattern2', 'IFD0:AsShotNeutral', 'IFD0:WhiteLevel'] as $kept) {
            $this->assertArrayHasKey($kept, $tags);
        }
        foreach (['IFD0:Make', 'IFD0:Artist', 'IFD0:OriginalRawFileName', 'GPS:GPSLatitude', 'ExifIFD:SerialNumber', 'XMP-dc:Creator'] as $removed) {
            $this->assertArrayNotHasKey($removed, $tags);
        }
        $fields = (new FieldExtractor())->extract($result->removed);
        $this->assertSame('IMG_secret.CR2', $fields['EXIF:IFD0:OriginalRawFileName'] ?? null);
        if ($before !== '') {
            $this->assertSame($before, $this->developed($path));
        }
    }

    public function testBrokenDngIsRejectedAndLeftUntouched(): void
    {
        $path = $this->copyFixture('broken.dng');
        $before = file_get_contents($path);
        try {
            (new MetadataCleanerService())->cleanPath($path);
            $this->fail('No exception for a broken file.');
        } catch (CleanerException) {
        }
        $this->assertSame($before, file_get_contents($path));
    }

    public function testDngAndCameraRawDetection(): void
    {
        $this->assertSame(FormatDetector::TYPE_DNG, FormatDetector::detectType($this->fixture('meta.dng')));
        $this->assertSame(FormatDetector::FORMAT_TIFF, FormatDetector::detectType($this->fixture('meta-plain.tif')));

        // A Canon CR2 header, and a TIFF-like raw with SubIFDs and Make (NEF, ARW...): not handled.
        $cr2 = $this->copyFixture('plain.tif');
        file_put_contents($cr2, "II*\x00\x10\x00\x00\x00CR\x02\x00" . str_repeat("\x00", 100));
        $this->assertNull(FormatDetector::detectType($cr2));
        $nef = $this->copyFixture('plain.tif');
        file_put_contents($nef, "II*\x00\x08\x00\x00\x00\x02\x00" . pack('vvVV', 0x010F, 2, 4, 0x4E494B4E) . pack('vvVV', 0x014A, 4, 1, 100) . "\x00\x00\x00\x00");
        $this->assertNull(FormatDetector::detectType($nef));
    }

    public function testDisabledRawIsNotTouched(): void
    {
        $path = $this->copyFixture('meta.dng');
        $before = file_get_contents($path);

        $result = (new MetadataCleanerService(new CleanOptions(), [FormatDetector::FORMAT_TIFF]))->cleanPath($path);

        $this->assertNull($result->format);
        $this->assertSame($before, file_get_contents($path));
    }

    /**
     * The raw image developed by ImageMagick, or '' when it cannot read DNG.
     */
    private function developed(string $path): string
    {
        exec('convert ' . escapeshellarg('dng:' . $path) . ' -format "%#" info: 2>/dev/null', $out, $code);

        return $code === 0 ? implode('', $out) : '';
    }

    /**
     * Every page decoded to raw RGB by ImageMagick.
     */
    private function decoded(string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tiffrgb');
        try {
            exec(self::magick() . ' ' . escapeshellarg($path) . ' -depth 8 rgb:' . escapeshellarg($tmp) . ' 2>' . self::nullDevice());

            return (string)md5_file($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The ImageMagick command: "magick" on Windows (where "convert" is a
     * system command), "convert" elsewhere.
     */
    private static function magick(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'magick' : 'convert';
    }
}
