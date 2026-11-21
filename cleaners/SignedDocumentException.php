<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\cleaners;

/**
 * Thrown when a document carries a digital signature that cleaning would
 * invalidate. The file is left unchanged and the case is logged as not
 * cleaned; it is never a reason to reject the file.
 */
class SignedDocumentException extends CleanerException
{
    public function __construct()
    {
        parent::__construct('Digitally signed document: left unchanged so that the signature stays valid.');
    }
}
