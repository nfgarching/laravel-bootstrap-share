<?php

namespace Nfgarching\Share\Events;

use Nfgarching\Share\Models\Upload;

/**
 * Eine Datei aus dem Portal ist abgeholt, entschlüsselt, geprüft und abgelegt.
 * Kontext (z. B. die Akte) in $upload->context, Ablage in disk/path bzw. file_id.
 */
class UploadReceived
{
    public function __construct(public Upload $upload) {}
}
