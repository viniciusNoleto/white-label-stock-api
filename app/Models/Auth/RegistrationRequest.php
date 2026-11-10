<?php

namespace App\Models\Auth;

use App\Models\Concerns\FindsOrFailsByKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pedido de cadastro aguardando um admin: aprovado vira `User` (com a mesma senha), recusado é excluído.
 */
class RegistrationRequest extends Model {

    use FindsOrFailsByKey, HasFactory;

    protected $table = 'registration_requests';

    protected $fillable = [
        'name',
        'email',
        'password',
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
            fn(Builder $query) => $query->whereLike('name', '%' . escape_like($search) . '%')->orWhereLike('email', '%' . escape_like($search) . '%')
        ));
    }
}
