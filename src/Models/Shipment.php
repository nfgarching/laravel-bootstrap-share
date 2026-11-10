<?php

namespace Nfgarching\Share\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lokale Spiegelung einer Sendung im Portal.
 *
 * status:
 *   pending   lokal angelegt, Übergabe ans Portal läuft
 *   released  im Portal freigegeben, Links gültig
 *   revoked   widerrufen, Dateien im Portal gelöscht
 *   expired   abgelaufen, Dateien im Portal gelöscht
 *   failed    Übergabe gescheitert (error enthält die Meldung)
 */
class Shipment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RELEASED = 'released';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $table = 'share_shipments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'encrypted:array',
            'subject' => 'encrypted',
            'message' => 'encrypted',
            'max_downloads' => 'integer',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class, 'share_shipment_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'share_shipment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PortalEvent::class, 'share_shipment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model'), 'user_id');
    }

    /** Sendungen zu einem Kontext, z. B. Shipment::forContext(['akte' => 123]) */
    public function scopeForContext(Builder $query, array $context): Builder
    {
        return $query->where('context_key', self::contextKey($context));
    }

    /**
     * Gleiche Regel wie im Mailer (SentMail::contextKey): erster Eintrag als "schlüssel:wert".
     */
    public static function contextKey(array $context): ?string
    {
        if (! $context) {
            return null;
        }

        $key = array_key_first($context);

        return is_scalar($context[$key]) ? $key.':'.$context[$key] : null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_RELEASED && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->status === self::STATUS_RELEASED && $this->expires_at?->isPast() => 'abgelaufen',
            default => match ($this->status) {
                self::STATUS_PENDING => 'in Übergabe',
                self::STATUS_RELEASED => 'abrufbar',
                self::STATUS_REVOKED => 'widerrufen',
                self::STATUS_EXPIRED => 'abgelaufen',
                self::STATUS_FAILED => 'fehlgeschlagen',
                default => $this->status,
            },
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->isActive() => 'success',
            $this->status === self::STATUS_FAILED => 'danger',
            $this->status === self::STATUS_PENDING => 'warning',
            default => 'secondary',
        };
    }
}
