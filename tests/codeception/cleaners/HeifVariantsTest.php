<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\cleaners;

use humhub\modules\metadataCleaner\cleaners\FormatDetector;
use humhub\modules\metadataCleaner\cleaners\IsoBmff;
use humhub\modules\metadataCleaner\cleaners\MetadataBlock;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;

/**
 * HEIF variants written by vendors other than Apple (Samsung, Xiaomi),
 * C2PA manifest boxes and mime-typed EXIF items.
 */
class HeifVariantsTest extends CleanerTestCase
{
    public function testVendorMajorBrandsAreDetectedAsHeif(): void
    {
        // Xiaomi writes mif1/miaf/MiHB brands; some files use a vendor major
        // brand instead of a classic heic one.
        $this->assertSame(
            FormatDetector::FORMAT_HEIF,
            FormatDetector::detectFromBytes("\x00\x00\x00\x18ftypmiaf\x00\x00\x00\x00MiHBmif1miaf"),
        );
        $this->assertSame(
            FormatDetector::FORMAT_HEIF,
            FormatDetector::detectFromBytes("\x00\x00\x00\x18ftypMiHB\x00\x00\x00\x00MiHBmif1"),
        );
        $this->assertSame(
            FormatDetector::FORMAT_HEIF,
            FormatDetector::detectFromBytes("\x00\x00\x00\x18ftypmif1\x00\x00\x00\x00mif1MiHEheic"),
        );
    }

    public function testMimeExifItemIsRemoved(): void
    {
        // Some encoders store EXIF in a "mime" item with content type
        // image/exif instead of a dedicated Exif item.
        $path = $this->copyFixture('meta.heic');
        $data = file_get_contents($path);
        $patched = str_replace('application/rdf+xml', 'image/exif' . str_repeat(' ', 9), $data);
        $this->assertNotSame($data, $patched);
        file_put_contents($path, $patched);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertSame(FormatDetector::FORMAT_HEIF, $result->format);
        $this->assertTrue($result->changed);
        $locations = array_map(static fn(MetadataBlock $b) => $b->location, $result->removed);
        $this->assertContains('item:3:mime', $locations);
        $this->assertContains('item:2:Exif', $locations);
    }

    public function testC2paUuidBoxIsRemoved(): void
    {
        $path = $this->copyFixture('meta.heic');
        $payload = 'JUMBF-C2PA-MANIFEST-' . str_repeat('x', 100);
        $uuid = IsoBmff::C2PA_UUID;
        $box = pack('N', 8 + 16 + strlen($payload)) . 'uuid' . $uuid . $payload;
        $offset = filesize($path);
        file_put_contents($path, $box, FILE_APPEND);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $this->assertTrue($result->changed);
        $locations = array_map(static fn(MetadataBlock $b) => $b->location, $result->removed);
        $this->assertContains('uuid:C2PA', $locations);

        $after = file_get_contents($path);
        $this->assertSame(
            str_repeat("\x00", strlen($payload)),
            substr($after, $offset + 8 + 16, strlen($payload)),
        );
    }

    public function testJumbBoxIsRemoved(): void
    {
        $path = $this->copyFixture('meta.heic');
        $payload = str_repeat('J', 64);
        $offset = filesize($path);
        file_put_contents($path, pack('N', 8 + strlen($payload)) . 'jumb' . $payload, FILE_APPEND);

        $result = (new MetadataCleanerService())->cleanPath($path);

        $locations = array_map(static fn(MetadataBlock $b) => $b->location, $result->removed);
        $this->assertContains('jumb:JUMBF', $locations);

        $after = file_get_contents($path);
        $this->assertSame(str_repeat("\x00", strlen($payload)), substr($after, $offset + 8, strlen($payload)));
    }
}
