<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;

class LegacyFormatsTest extends CleanerTestCase
{
    public static function metas(): array
    {
        return [
            'wav' => ['meta.wav', 'audio'],
            'avi' => ['meta.avi', 'video'],
            'wmv' => ['meta.wmv', 'video'],
            'aiff' => ['meta.aiff', 'audio'],
            'psd' => ['meta.psd', 'psd'],
            'jp2' => ['meta.jp2', 'jpeg'],
            'jxl' => ['meta.jxl', 'jpeg'],
            'nef (raw)' => ['meta.nef', 'raw'],
        ];
    }

    /**
     * @dataProvider metas
     */
    public function testRemovesMetadata(string $name, string $group): void
    {
        $path = $this->copyFixture($name);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame($group, $result->format);
        $this->assertTrue($result->changed, "$name was not cleaned");
        $this->assertNotEmpty($result->removed);
        $bytes = file_get_contents($path);
        $this->assertStringNotContainsString('Mario', $bytes, "$name: author still present");
        $this->assertStringNotContainsString('secret', strtolower($bytes), "$name: secret still present");
    }

    /**
     * @dataProvider metas
     */
    public function testSecondRunChangesNothing(string $name, string $group): void
    {
        $path = $this->copyFixture($name);
        $service = new MetadataCleanerService();
        $service->cleanPath($path);
        $once = file_get_contents($path);

        $this->assertFalse($service->cleanPath($path)->changed);
        $this->assertSame($once, file_get_contents($path));
    }

    public static function brokens(): array
    {
        return [
            'wav' => ['broken.wav'],
            'avi' => ['broken.avi'],
            'wmv' => ['broken.wmv'],
            'aiff' => ['broken.aiff'],
            'psd' => ['broken.psd'],
            'jp2' => ['broken.jp2'],
            'jxl' => ['broken.jxl'],
            'nef' => ['broken.nef'],
        ];
    }

    /**
     * @dataProvider brokens
     */
    public function testBrokenIsRejectedAndLeftUntouched(string $name): void
    {
        $path = $this->copyFixture($name);
        $before = file_get_contents($path);

        try {
            (new MetadataCleanerService())->cleanPath($path);
            $this->fail("A broken file must not be accepted: $name");
        } catch (CleanerException) {
        }

        $this->assertSame($before, file_get_contents($path));
    }

    public static function plains(): array
    {
        return [
            'wav' => ['plain.wav'],
            'wmv' => ['plain.wmv'],
            'aiff' => ['plain.aiff'],
            'psd' => ['plain.psd'],
            'jp2' => ['plain.jp2'],
            'jxl' => ['plain.jxl'],
            'nef' => ['plain.nef'],
        ];
    }

    /**
     * @dataProvider plains
     */
    public function testCleanFileIsNotTouched(string $name): void
    {
        $path = $this->copyFixture($name);
        $before = file_get_contents($path);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertFalse($result->changed, "$name was changed");
        $this->assertSame($before, file_get_contents($path));
    }

    public function testDetection(): void
    {
        $this->assertSame(FormatDetector::FORMAT_AUDIO, FormatDetector::detect($this->fixture('meta.wav')));
        $this->assertSame(FormatDetector::FORMAT_AUDIO, FormatDetector::detect($this->fixture('meta.aiff')));
        $this->assertSame(FormatDetector::FORMAT_VIDEO, FormatDetector::detect($this->fixture('meta.avi')));
        $this->assertSame(FormatDetector::FORMAT_VIDEO, FormatDetector::detect($this->fixture('meta.wmv')));
        $this->assertSame(FormatDetector::FORMAT_PSD, FormatDetector::detect($this->fixture('meta.psd')));
        $this->assertSame(FormatDetector::FORMAT_JPEG, FormatDetector::detect($this->fixture('meta.jp2')));
        $this->assertSame(FormatDetector::FORMAT_JPEG, FormatDetector::detect($this->fixture('meta.jxl')));
        $this->assertSame(FormatDetector::FORMAT_RAW, FormatDetector::detect($this->fixture('meta.nef')));
    }

    public function testWavKeepsSoundData(): void
    {
        $path = $this->copyFixture('meta.wav');
        (new MetadataCleanerService())->cleanPath($path);
        $bytes = file_get_contents($path);
        $this->assertStringContainsString('data', $bytes);
        $this->assertStringContainsString("\x80", $bytes);
    }

    public function testWmvKeepsLayoutAndSize(): void
    {
        $path = $this->copyFixture('meta.wmv');
        $before = filesize($path);
        (new MetadataCleanerService())->cleanPath($path);
        $this->assertSame($before, filesize($path), 'WMV must keep its size (objects are zeroed in place)');
    }

    public function testJp2KeepsSizeAndCodestream(): void
    {
        $path = $this->copyFixture('meta.jp2');
        $before = filesize($path);
        (new MetadataCleanerService())->cleanPath($path);
        $this->assertSame($before, filesize($path));
        $this->assertStringContainsString('jp2c', file_get_contents($path));
    }
}
