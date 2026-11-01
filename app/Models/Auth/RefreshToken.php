<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model {

    public const UPDATED_AT = null;

    protected $table = 'refresh_tokens';

    protected $fillable = [
        'user_id',
        'token_hash',
        'family_id',
        'revoked',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'revoked' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function byToken(Builder $query, string $token): void {
        $query->where('token_hash', hash_token($token));
    }

    public function isExpired(): bool {
        return $this->expires_at->isPast();
    }
}
