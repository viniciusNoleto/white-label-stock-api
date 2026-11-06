<?php

namespace App\Models;

use App\Models\Concerns\FindsOrFailsByKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable {

    use FindsOrFailsByKey, HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Busca por nome ou e-mail.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $search): void {
        $query->when($search, fn(Builder $query) => $query->where(
            fn(Builder $query) => $query->whereLike('name', '%' . $search . '%')->orWhereLike('email', '%' . $search . '%')
        ));
    }
}
