<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\cleaners\IsoBmff;
use humhub\modules\metadataCleaner\readers\FieldExtractor;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;

class VideoCleanerTest extends CleanerTestCase
{
    /** exiftool groups holding QuickTime/MP4 metadata. */
    private const VIDEO_GROUPS = ['Keys', 'UserData', 'ItemList', 'XMP', 'GPS', '3GP'];

    public static function videos(): array
    {
        return [
            'mp4, moov at the end' => ['meta.mp4'],
            'mp4, moov at the start' => ['meta-faststart.mp4'],
            'mov with Apple keys' => ['meta.mov'],
        ];
    }

    /**
     * @dataProvider videos
     */
    public function testRemovesAllMetadata(string $name): void
    {
        $this->requireExiftool();
        $path = $this->copyFixture($name);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame(FormatDetector::FORMAT_VIDEO, $result->format);
        $this->assertTrue($result->changed);
        foreach (array_keys($this->exiftoolTags($path)) as $key) {
            [$group] = explode(':', $key, 2);
            foreach (self::VIDEO_GROUPS as $forbidden) {
                $this->assertStringStartsNotWith($forbidden, $group, "Tag $key still present");
            }
        }
    }

    /**
     * @dataProvider videos
     */
    public function testMediaDataAndOffsetsAreUnchanged(string $name): void
    {
        $path = $this->copyFixture($name);
        $before = file_get_contents($path);

        (new MetadataCleanerService())->cleanPath($path);
        $after = file_get_contents($path);

        $this->assertSame(strlen($before), strlen($after), 'File size must not change.');
        $this->assertSame($this->mdat($before), $this->mdat($after));
    }

    /**
     * @dataProvider videos
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

    public function testCleanedVideoStillDecodes(): void
    {
        $this->requireTool('ffmpeg -version', 'ffmpeg');
        foreach (array_keys(self::videos()) as $label) {
            $path = $this->copyFixture(self::videos()[$label][0]);
            (new MetadataCleanerService())->cleanPath($path);
            exec('ffmpeg -v error -i ' . escapeshellarg($path) . ' -f null - 2>&1', $errors, $code);
            $this->assertSame(0, $code, $label . ': ' . implode("\n", $errors));
            $this->assertSame([], $errors, $label);
        }
    }

    public function testBrokenVideoIsRejectedAndLeftUntouched(): void
    {
        $path = $this->copyFixture('broken.mp4');
        $before = file_get_contents($path);

        try {
            (new MetadataCleanerService())->cleanPath($path);
            $this->fail('A broken file must not be accepted.');
        } catch (CleanerException) {
        }

        $this->assertSame($before, file_get_contents($path));
    }

    public function testFieldsAreReadForTheLog(): void
    {
        $path = $this->copyFixture('meta.mov');

        $result = (new MetadataCleanerService(new CleanOptions(collectPayloads: true)))->cleanPath($path);
        $fields = (new FieldExtractor())->extract($result->removed);

        $this->assertSame('+45.4642+009.1900/', $fields['QuickTime:location'] ?? null);
        $this->assertSame('iPhone', $fields['QuickTime:com.apple.quicktime.model'] ?? null);
    }

    private function mdat(string $data): string
    {
        foreach (IsoBmff::boxes($data, 0, strlen($data)) as $box) {
            if ($box['type'] === 'mdat') {
                return md5(substr($data, $box['start'], $box['size']));
            }
        }
        $this->fail('No mdat box.');
    }
}
