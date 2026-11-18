<?php

namespace Nfgarching\Share\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bitte um Unterlagen an eine Adresse (Spiegel der Anforderung im Portal).
 * status: open, closed, failed
 */
class UploadRequest extends Model
{
    protected $table = 'share_upload_requests';

    protected $guarded = [];

    protected $hidden = ['url', 'password'];

    protected function casts(): array
    {
        return [
            'context' => 'encrypted:array',
            'subject' => 'encrypted',
            'message' => 'encrypted',
            'email' => 'encrypted',
            'name' => 'encrypted',
            'url' => 'encrypted',
            'password' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class, 'share_upload_request_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'share_shipment_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
