<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'taller_id', 'sucursal_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    public const ROL_SUPERADMIN = 'Superadmin';
    public const ROL_ADMIN = 'Administrador';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class);
    }

    /** Sucursal fija del usuario (null = puede cambiar entre todas las de su taller). */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class)->withoutGlobalScopes();
    }

    /** Dueño del sistema: ve todos los talleres y no le aplica el vencimiento de la suscripción. */
    public function esSuperadmin(): bool
    {
        return $this->hasRole(self::ROL_SUPERADMIN);
    }
}
