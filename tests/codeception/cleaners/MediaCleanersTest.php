<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\cleaners\CleanerException;
use humhub\modules\metadataCleaner\cleaners\CleanOptions;
use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\cleaners\MatroskaCleaner;
use humhub\modules\metadataCleaner\cleaners\OggCleaner;
use humhub\modules\metadataCleaner\readers\FieldExtractor;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;

/**
 * Audio files (M4A, MP3, AAC, FLAC, Ogg, Opus), Matroska/WebM videos and
 * the videos embedded in motion photos.
 */
class MediaCleanersTest extends CleanerTestCase
{
    /** Text that must not survive in any tag. */
    private const SECRETS = ['Mario', 'Secret', 'secret', '2026', '45.46'];

    public static function mediaFiles(): array
    {
        return [
            'm4a' => ['meta.m4a', FormatDetector::FORMAT_AUDIO],
            'mp3 with ID3v2 and ID3v1' => ['meta.mp3', FormatDetector::FORMAT_AUDIO],
            'aac with ID3v2' => ['meta.aac', FormatDetector::FORMAT_AUDIO],
            'flac with picture' => ['meta.flac', FormatDetector::FORMAT_AUDIO],
            'ogg vorbis' => ['meta.ogg', FormatDetector::FORMAT_AUDIO],
            'opus' => ['meta.opus', FormatDetector::FORMAT_AUDIO],
            'flac in ogg' => ['meta-flac.oga', FormatDetector::FORMAT_AUDIO],
            'webm' => ['meta.webm', FormatDetector::FORMAT_VIDEO],
            'mkv with attachment' => ['meta.mkv', FormatDetector::FORMAT_VIDEO],
        ];
    }

    public static function brokenFiles(): array
    {
        return [
            'mp3' => ['broken.mp3'],
            'flac' => ['broken.flac'],
            'ogg' => ['broken.ogg'],
            'opus' => ['broken.opus'],
            'webm' => ['broken.webm'],
            'mkv' => ['broken.mkv'],
            'm4a' => ['broken.m4a'],
        ];
    }

    public function testApeTagIsRemoved(): void
    {
        $path = $this->copyFixture('meta-ape.mp3');

        $result = (new MetadataCleanerService(new CleanOptions(collectPayloads: true)))->cleanPath($path);

        $this->assertTrue($result->changed);
        $data = file_get_contents($path);
        $this->assertStringNotContainsString('APETAGEX', $data);
        $this->assertStringNotContainsString('Mario', $data);
        $this->assertSame('Mario Rossi', (new FieldExtractor())->extract($result->removed)['APE:Artist'] ?? null);
    }

    /**
     * @dataProvider mediaFiles
     */
    public function testRemovesAllTags(string $name, string $format): void
    {
        $this->requireFfmpeg();
        $path = $this->copyFixture($name);
        $this->assertNotSame([], $this->secretTags($path), 'The fixture has no tags to remove.');

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame($format, $result->format);
        $this->assertTrue($result->changed);
        $this->assertSame([], $this->secretTags($path));
        $this->requireExiftool();
        foreach ($this->exiftoolTags($path) as $key => $value) {
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, is_scalar($value) ? (string)$value : json_encode($value), "Tag $key still present");
            }
        }
    }

    /**
     * @dataProvider mediaFiles
     */
    public function testAudioAndVideoAreUnchanged(string $name): void
    {
        $this->requireFfmpeg();
        $path = $this->copyFixture($name);
        $before = $this->packets($path);
        $errorsBefore = $this->decodeErrors($path);

        (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame($before, $this->packets($path));
        // Some ffmpeg versions complain about the test files themselves:
        // cleaning must not add any new decoder message.
        $this->assertSame($errorsBefore, $this->decodeErrors($path));
    }

    /**
     * @dataProvider mediaFiles
     */
    public function testSecondRunChangesNothing(string $name): void
    {
        $path = $this->copyFixture($name);
        (new MetadataCleanerService())->cleanPath($path);
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

    public function testOggChecksumsAreValid(): void
    {
        foreach (['meta.ogg', 'meta.opus', 'meta-flac.oga'] as $name) {
            $path = $this->copyFixture($name);
            (new MetadataCleanerService())->cleanPath($path);
            $data = file_get_contents($path);
            $pos = 0;
            while ($pos < strlen($data)) {
                $this->assertSame('OggS', substr($data, $pos, 4));
                $segments = ord($data[$pos + 26]);
                $length = 27 + $segments + array_sum(unpack('C*', substr($data, $pos + 27, $segments)) ?: []);
                $page = substr($data, $pos, $length);
                $crc = unpack('V', $page, 22)[1];
                $this->assertSame($crc, OggCleaner::crc(substr_replace($page, "\x00\x00\x00\x00", 22, 4)), "$name page at $pos");
                $pos += $length;
            }
        }
    }

    public function testMotionPhotoVideoIsRemovedFromJpeg(): void
    {
        $path = $this->copyFixture('motion.jpg');

        (new MetadataCleanerService())->cleanPath($path);

        $data = file_get_contents($path);
        $this->assertStringNotContainsString('ftyp', $data);
        $this->assertStringNotContainsString('MicroVideo', $data);
        $this->assertStringEndsWith("\xFF\xD9", $data);
        $this->assertSame($this->pixels($this->fixture('motion.jpg')), $this->pixels($path));
    }

    public function testMotionPhotoVideoIsRemovedFromHeic(): void
    {
        $path = $this->copyFixture('motion.heic');
        $sizeBefore = filesize($path);

        (new MetadataCleanerService())->cleanPath($path);

        $data = file_get_contents($path);
        $this->assertSame($sizeBefore, strlen($data));
        $this->assertStringNotContainsString('mpvd', $data);
        $this->assertStringNotContainsString('moov', $data);
        $this->assertStringNotContainsString('TestPhone', $data);
    }

    public function testFieldsAreReadForTheLog(): void
    {
        $expected = [
            'meta.mp3' => ['ID3:TPE1' => 'Mario Rossi', 'ID3v1:Title' => 'Secret title'],
            'meta.flac' => ['Vorbis:ARTIST' => 'Mario Rossi', 'FLAC:PICTURE' => ''],
            'meta.opus' => ['Opus:TITLE' => 'Secret title'],
            'meta.mkv' => ['Matroska:Title' => 'Secret title', 'Matroska:TrackName' => 'Mario mic', 'Matroska:AttachedFile' => 'meta-plain.jpg', 'Matroska:ARTIST' => 'Mario Rossi'],
            'meta.m4a' => ['QuickTime:artist' => 'Mario Rossi'],
        ];
        foreach ($expected as $name => $fields) {
            $path = $this->copyFixture($name);
            $result = (new MetadataCleanerService(new CleanOptions(collectPayloads: true)))->cleanPath($path);
            $found = (new FieldExtractor())->extract($result->removed);
            foreach ($fields as $field => $value) {
                $this->assertArrayHasKey($field, $found, $name);
                $this->assertSame($value, $found[$field], "$name $field");
            }
        }
    }

    public function testDetection(): void
    {
        $this->assertSame(FormatDetector::TYPE_MP3, FormatDetector::detectType($this->fixture('meta.mp3')));
        $this->assertSame(FormatDetector::TYPE_MP3, FormatDetector::detectType($this->fixture('plain.mp3')));
        $this->assertSame(FormatDetector::TYPE_MP3, FormatDetector::detectType($this->fixture('meta.aac')));
        $this->assertSame(FormatDetector::TYPE_FLAC, FormatDetector::detectType($this->fixture('meta.flac')));
        $this->assertSame(FormatDetector::TYPE_OGG, FormatDetector::detectType($this->fixture('meta.opus')));
        $this->assertSame(FormatDetector::TYPE_MATROSKA, FormatDetector::detectType($this->fixture('meta.webm')));
        $this->assertSame(FormatDetector::TYPE_M4A, FormatDetector::detectType($this->fixture('meta.m4a')));
        $this->assertSame(FormatDetector::FORMAT_AUDIO, FormatDetector::detect($this->fixture('meta.m4a')));
        $this->assertSame(FormatDetector::FORMAT_VIDEO, FormatDetector::detect($this->fixture('meta.mkv')));
        $this->assertSame(FormatDetector::FORMAT_JPEG, FormatDetector::detectFromBytes("\xFF\xD8\xFF\xE0"));
    }

    public function testDisabledAudioIsNotTouched(): void
    {
        $path = $this->copyFixture('meta.mp3');
        $before = file_get_contents($path);

        $result = (new MetadataCleanerService(new CleanOptions(), [FormatDetector::FORMAT_VIDEO]))->cleanPath($path);

        $this->assertNull($result->format);
        $this->assertSame($before, file_get_contents($path));
    }

    public function testVoidElementsHaveTheRequestedSize(): void
    {
        foreach ([2, 3, 9, 127, 128, 129, 16385, 70000] as $total) {
            $patches = [];
            MatroskaCleaner::addVoid($patches, 0, $total);
            $header = $patches[0];
            $zeros = $patches[strlen($header)] ?? 0;
            $this->assertSame($total, strlen($header) + $zeros, "Void of $total bytes");
            $this->assertSame("\xEC", $header[0]);
        }
    }

    private function requireFfmpeg(): void
    {
        exec('ffprobe -version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
    }

    /**
     * Tag values of the container and of the streams that contain a secret.
     *
     * @return string[]
     */
    private function secretTags(string $path): array
    {
        exec('ffprobe -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($path), $out);
        $info = json_decode(implode("\n", $out), true) ?? [];
        $tags = $info['format']['tags'] ?? [];
        foreach ($info['streams'] ?? [] as $stream) {
            $tags += $stream['tags'] ?? [];
        }
        $found = [];
        foreach ($tags as $key => $value) {
            foreach (self::SECRETS as $secret) {
                if (str_contains((string)$value, $secret)) {
                    $found[] = "$key=$value";
                }
            }
        }

        return $found;
    }

    /**
     * Checksum of the audio and video packets, which must never change.
     */
    private function packets(string $path): string
    {
        exec('ffmpeg -v error -i ' . escapeshellarg($path) . ' -map 0:a? -map 0:V? -c copy -f streamhash -hash md5 - 2>/dev/null', $out);
        // Codec setup data of the same streams (for example the Opus and Vorbis
        // headers); cover pictures are metadata and are left out.
        exec('ffprobe -v error -show_data_hash md5 -show_entries stream=codec_type,codec_name,extradata_hash:stream_disposition=attached_pic -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null', $streams);
        foreach ($streams as $stream) {
            if (preg_match('/,(audio|video),.*,0$/', $stream)) {
                $out[] = $stream;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Messages of a full decode, without the memory addresses that change on every run.
     *
     * @return string[]
     */
    private function decodeErrors(string $path): array
    {
        exec('ffmpeg -v error -i ' . escapeshellarg($path) . ' -f null - 2>&1', $errors, $code);
        $this->assertSame(0, $code, implode("\n", $errors));

        return array_map(static fn(string $line) => preg_replace('/ @ 0x[0-9a-f]+\]/', ']', $line), $errors);
    }
}
