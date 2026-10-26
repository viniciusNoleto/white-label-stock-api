<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessToken extends Model {

    public const UPDATED_AT = null;

    protected $table = 'access_tokens';

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function scopeByToken($query, string $token) {
        return $query->where('token_hash', hash_token($token));
    }

    public function scopeNotExpired($query) {
        return $query->where('expires_at', '>', now());
    }
}
