<?php

namespace Nfgarching\Share\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recipient extends Model
{
    protected $table = 'share_recipients';

    protected $guarded = [];

    protected $hidden = ['url', 'password'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'name' => 'encrypted',
            'url' => 'encrypted',
            'password' => 'encrypted',
            'password_protected' => 'boolean',
            'locked' => 'boolean',
            'downloads' => 'integer',
            'opened_at' => 'datetime',
            'first_downloaded_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'share_shipment_id');
    }

    public function label(): string
    {
        return $this->name ? "{$this->name} <{$this->email}>" : (string) $this->email;
    }
}
