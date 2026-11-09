<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\PsdCleaner;
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

    public function testPsdKeepsDrawingResourcesAndDropsPersonalOnes(): void
    {
        $block = static fn(int $id, string $body) => '8BIM' . pack('n', $id) . "\x00\x00" . pack('N', strlen($body)) . $body . (strlen($body) & 1 ? "\x00" : '');
        $keep = [0x03ED, 0x0402, 0x040F, 0x07D0, 0x0BB7];
        $drop = [0x040B, 0x041B, 0x041E, 0x0421, 0x043E, 0x0406, 0x0426, 0x043A, 0x2710];
        $res = '';
        foreach (array_merge($keep, $drop) as $id) {
            $res .= $block($id, sprintf('data %04X', $id));
        }
        $psd = '8BPS' . pack('n', 1) . str_repeat("\x00", 6) . pack('nNNnn', 3, 2, 2, 8, 3)
            . pack('N', 0) . pack('N', strlen($res)) . $res . pack('N', 0) . pack('n', 0) . str_repeat("\x77", 24);

        [$clean, $removed] = (new PsdCleaner())->cleanBytes($psd, new CleanOptions());

        foreach ($keep as $id) {
            $this->assertStringContainsString(sprintf('data %04X', $id), $clean);
        }
        foreach ($drop as $id) {
            $this->assertStringNotContainsString(sprintf('data %04X', $id), $clean);
        }
        $this->assertCount(count($drop), $removed);
        $this->assertStringEndsWith(str_repeat("\x77", 24), $clean);
    }
}
