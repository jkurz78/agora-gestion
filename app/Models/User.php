<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Enums\RoleSysteme;
use App\Enums\TwoFactorMethod;
use App\Tenant\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;

final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nom',
        'email',
        'password',
        'dernier_espace',
        'peut_voir_donnees_sensibles',
        'two_factor_method',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'two_factor_recovery_codes',
        'two_factor_trusted_token',
        'derniere_association_id',
        'role_systeme',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_trusted_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'dernier_espace' => Espace::class,
            'peut_voir_donnees_sensibles' => 'boolean',
            'role_systeme' => RoleSysteme::class,
            'two_factor_method' => TwoFactorMethod::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function associations(): BelongsToMany
    {
        return $this->belongsToMany(Association::class, 'association_user')
            ->withPivot('role', 'invited_at', 'joined_at', 'revoked_at')
            ->withTimestamps();
    }

    public function derniereAssociation(): BelongsTo
    {
        return $this->belongsTo(Association::class, 'derniere_association_id');
    }

    public function currentAssociation(): ?Association
    {
        return TenantContext::current();
    }

    public function currentRole(): ?string
    {
        $assoId = TenantContext::currentId();
        if ($assoId === null) {
            return null;
        }

        $pivot = $this->associations()->where('association_id', $assoId)->first();

        return $pivot?->pivot?->role;
    }

    public function currentRoleEnum(): ?RoleAssociation
    {
        return RoleAssociation::tryFrom($this->currentRole() ?? '');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role_systeme === RoleSysteme::SuperAdmin;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'saisi_par');
    }

    public function hasTwoFactorEnabled(): bool
    {
        if ($this->two_factor_method === null) {
            return false;
        }

        if ($this->two_factor_method === TwoFactorMethod::Totp) {
            return $this->two_factor_confirmed_at !== null;
        }

        return true;
    }

    /**
     * Cached check : does at least one super-admin user exist ?
     *
     * Used by the install flow to detect whether the instance has been
     * bootstrapped (a super-admin exists) or is still in fresh-install
     * state (no super-admin yet → expose the /setup page).
     *
     * Cache key 'app.installed' is invalidated by UserRoleObserver on any
     * role_systeme change.
     */
    public static function superAdminExists(): bool
    {
        return Cache::remember(
            'app.installed',
            3600,
            fn (): bool => self::query()
                ->where('role_systeme', RoleSysteme::SuperAdmin)
                ->exists(),
        );
    }
}
