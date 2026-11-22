<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace metadataCleaner\unit;

use humhub\modules\file\models\File;
use humhub\modules\file\models\FileUpload;
use humhub\modules\metadataCleaner\Events;
use humhub\modules\metadataCleaner\models\MetadataLog;
use humhub\modules\metadataCleaner\models\MetadataStats;
use humhub\modules\metadataCleaner\Module;
use humhub\modules\metadataCleaner\services\MetadataCleanerService;
use humhub\modules\post\models\Post;
use tests\codeception\_support\HumHubDbTestCase;
use Yii;
use yii\web\UploadedFile;

/**
 * The HumHub integration: the upload validation event and the stored-file
 * event.
 */
class EventsTest extends HumHubDbTestCase
{
    /** @var string[] */
    private array $tmpFiles = [];

    protected function _before()
    {
        parent::_before();
        // Each test is one request: forget uploads handled by earlier tests.
        (new \ReflectionProperty(Events::class, 'handledUploads'))->setValue(null, []);
    }

    protected function _after()
    {
        foreach ($this->tmpFiles as $path) {
            @unlink($path);
        }
        $this->tmpFiles = [];
        $this->module()->settings->delete('onError');
        parent::_after();
    }

    public function testUploadIsCleanedOnValidation(): void
    {
        $upload = $this->upload('meta-plain.jpg', 'image/jpeg');
        $before = file_get_contents($upload->uploadedFile->tempName);

        $this->assertTrue($upload->validate());

        $after = file_get_contents($upload->uploadedFile->tempName);
        $this->assertNotSame($before, $after);
        $this->assertFalse((new MetadataCleanerService())->cleanPath($upload->uploadedFile->tempName)->hadMetadata());
        $this->assertSame(strlen($after), (int)$upload->size);
    }

    public function testUploadThatCannotBeCleanedIsAcceptedByDefault(): void
    {
        $upload = $this->upload('broken.jpg', 'image/jpeg');
        $before = file_get_contents($upload->uploadedFile->tempName);

        $this->assertTrue($upload->validate());
        $this->assertSame($before, file_get_contents($upload->uploadedFile->tempName));
    }

    public function testUploadThatCannotBeCleanedIsRejectedInRejectMode(): void
    {
        $this->module()->settings->set('onError', Module::ON_ERROR_REJECT);
        $upload = $this->upload('broken.jpg', 'image/jpeg');

        $this->assertFalse($upload->validate());
        $this->assertTrue($upload->hasErrors('uploadedFile'));
    }

    public function testSignedDocumentIsNeverRejected(): void
    {
        $this->module()->settings->set('onError', Module::ON_ERROR_REJECT);
        $upload = $this->upload('meta.pdf', 'application/pdf');
        file_put_contents($upload->uploadedFile->tempName, "\n99 0 obj\n<< /Type /Sig /ByteRange [0 10 20 30] /Contents <00> >>\nendobj\n", FILE_APPEND);
        $before = file_get_contents($upload->uploadedFile->tempName);

        $this->assertTrue($upload->validate());
        $this->assertSame($before, file_get_contents($upload->uploadedFile->tempName));
        $this->assertTrue(MetadataLog::find()->where(['status' => MetadataLog::STATUS_SIGNED])->exists(), 'logged as signed');
        $this->assertSame(1, (int)MetadataStats::find()->where(['status' => MetadataStats::STATUS_SIGNED])->sum('total'), 'counted as signed');
        $this->assertSame(1, MetadataStats::summary()['byFormat']['pdf']['signed'] ?? null);
    }

    public function testNewStoredContentIsCleaned(): void
    {
        $file = $this->prepareFile();

        $file->setStoredFileContent(file_get_contents($this->fixture('meta-plain.jpg')));

        $content = $file->getStore()->getContent();
        $this->assertNotSame(file_get_contents($this->fixture('meta-plain.jpg')), $content);
        $this->assertSame(sha1($content), $file->hash_sha1);
        $this->assertSame(strlen($content), (int)$file->size);
    }

    /**
     * MG-CORE-09: replacing the content of an existing file (a new version,
     * an online editor, the REST API) must never delete the file, even when
     * the new content cannot be cleaned and rejecting is configured.
     */
    public function testStoredFileIsNeverDeletedInRejectMode(): void
    {
        $this->module()->settings->set('onError', Module::ON_ERROR_REJECT);
        $file = $this->prepareFile();
        $file->getPolymorphicRelation()->fileManagerEnableHistory = true;
        $file->setStoredFileContent('V1');
        $broken = file_get_contents($this->fixture('broken.jpg'));

        $file->setStoredFileContent($broken);

        $stored = File::findOne(['id' => $file->id]);
        $this->assertNotNull($stored, 'the file still exists');
        $this->assertSame($broken, $stored->getStore()->getContent());
        $this->assertCount(1, $stored->historyFiles, 'the previous version is kept');
        $this->assertTrue(MetadataLog::find()->where([
            'file_guid' => $file->guid,
            'status' => MetadataLog::STATUS_PASSED,
        ])->exists(), 'the failure is logged and the file kept');
    }

    private function module(): Module
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('metadata-cleaner');

        return $module;
    }

    private function fixture(string $name): string
    {
        return codecept_data_dir('fixtures/' . $name);
    }

    private function upload(string $fixture, string $type): FileUpload
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mdcu');
        copy($this->fixture($fixture), $tmp);
        $this->tmpFiles[] = $tmp;

        $upload = new FileUpload();
        $upload->setUploadedFile(new UploadedFile([
            'name' => $fixture,
            'tempName' => $tmp,
            'type' => $type,
            'size' => filesize($tmp),
            'error' => UPLOAD_ERR_OK,
        ]));

        return $upload;
    }

    private function prepareFile(): File
    {
        // A file can only be attached by its own creator.
        self::becomeUser('Admin');

        $file = new File();
        $file->file_name = 'photo.jpg';
        $file->save();
        Post::findOne(['id' => 1])->fileManager->attach($file);

        return $file;
    }
}
