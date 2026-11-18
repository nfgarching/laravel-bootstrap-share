<?php

namespace Nfgarching\Share\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine aus dem Portal abgeholte Datei.
 *
 * status: stored (auf disk/path abgelegt), filed (als Datei in nfgarching/files,
 *         file_id gesetzt), quarantined (Virenfund, in der Quarantäne)
 */
class Upload extends Model
{
    public const STATUS_STORED = 'stored';

    public const STATUS_FILED = 'filed';

    public const STATUS_QUARANTINED = 'quarantined';

    protected $table = 'share_uploads';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'encrypted:array',
            'sender_email' => 'encrypted',
            'sender_name' => 'encrypted',
            'subject' => 'encrypted',
            'message' => 'encrypted',
            'size' => 'integer',
            'received_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function uploadRequest(): BelongsTo
    {
        return $this->belongsTo(UploadRequest::class, 'share_upload_request_id');
    }

    public function senderLabel(): string
    {
        return $this->sender_name ? "{$this->sender_name} <{$this->sender_email}>" : (string) $this->sender_email;
    }
}
