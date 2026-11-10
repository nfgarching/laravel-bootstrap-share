<?php

namespace Nfgarching\Share\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine bereitgestellte Datei. disk/path verweisen auf das Original in der
 * internen Anwendung; das Portal hält nur eine verschlüsselte Kopie auf Zeit.
 */
class File extends Model
{
    protected $table = 'share_files';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'share_shipment_id');
    }
}
