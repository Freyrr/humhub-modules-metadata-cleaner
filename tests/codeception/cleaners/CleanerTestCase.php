<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\cleaners;

use Codeception\Test\Unit;

/**
 * Helpers shared by the cleaner tests.
 */
abstract class CleanerTestCase extends Unit
{
    /** exiftool groups that must never survive a cleaning run. */
    protected const FORBIDDEN_GROUPS = ['GPS', 'ExifIFD', 'IFD1', 'InteropIFD', 'IPTC', 'Photoshop', 'MakerNotes', 'XMP'];

    /** @var string[] */
    private array $tmpFiles = [];

    protected function _after()
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    protected function fixture(string $name): string
    {
        return codecept_data_dir('fixtures/' . $name);
    }

    /**
     * A temporary copy of a fixture, deleted after the test.
     */
    protected function copyFixture(string $name): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mdct');
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $path = $tmp . '.' . $ext;
        rename($tmp, $path);
        copy($this->fixture($name), $path);
        $this->tmpFiles[] = $path;

        return $path;
    }

    protected function requireExiftool(): void
    {
        exec('exiftool -ver 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('exiftool is not installed.');
        }
    }

    /**
     * All tags exiftool finds, as "Group1:Tag" => value, without the tags
     * exiftool computes itself.
     *
     * @return array<string, mixed>
     */
    protected function exiftoolTags(string $path): array
    {
        exec('exiftool -j -a -G1 -s ' . escapeshellarg($path) . ' 2>/dev/null', $out);
        $data = json_decode(implode("\n", $out), true)[0] ?? [];

        return array_filter(
            $data,
            static fn(string $key) => !preg_match('/^(SourceFile|System:|ExifTool:|File:|Composite:)/', $key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Fails when exiftool still finds identifying metadata.
     */
    protected function assertNoMetadata(string $path, bool $orientationAllowed = true): void
    {
        foreach ($this->exiftoolTags($path) as $key => $value) {
            [$group, $tag] = explode(':', $key, 2) + [1 => ''];
            if ($orientationAllowed && $key === 'IFD0:Orientation') {
                continue;
            }
            foreach (self::FORBIDDEN_GROUPS as $forbidden) {
                $this->assertStringStartsNotWith($forbidden, $group, "Tag $key = " . json_encode($value) . ' still present');
            }
            $this->assertNotSame('IFD0', $group, "Tag $key still present");
            $this->assertNotContains($tag, ['Comment', 'Author', 'Make', 'Model', 'Software'], "Tag $key still present");
        }
    }

    /**
     * Decoded pixels of an image, to prove that nothing was re-encoded.
     */
    protected function pixels(string $path): string
    {
        $data = file_get_contents($path);
        $image = imagecreatefromstring($data);
        $this->assertNotFalse($image, 'Image cannot be decoded: ' . $path);
        ob_start();
        imagebmp($image, null, false);

        return md5(ob_get_clean());
    }
}
