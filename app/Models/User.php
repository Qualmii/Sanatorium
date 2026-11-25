<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Jobs\SendUserEmailVerification;
use App\Notifications\UserEmailVerificationNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property Carbon $createdAt
 * @property Carbon $updatedAt
 * @property string $first_name
 * @property null|string $second_name
 * @property string $last_name
 * @property-read string $fio
 * @property string $phone
 * @property string $email
 * @property bool $is_blocked
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'second_name',
        'last_name',
        'phone',
        'email',
        'password',
        'is_blocked',
        'email_verified_at',
        'unblocked_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'id',
        'createdAt',
        'updatedAt',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'unblocked_at' => 'datetime',
        'password' => 'hashed',
        'is_blocked' => 'boolean',
    ];

    public function getNameAttribute(): string
    {
        // если в БД есть поля first_name/last_name — собираем fio
        $first = $this->attributes['first_name'] ?? null;
        $last = $this->attributes['last_name'] ?? null;
        $second = $this->attributes['second_name'] ?? null;

        if ($first || $last || $second) {
            return trim(trim(($last ?? '').' '.($first ?? '')).' '.($second ?? ''));
        }

        // fallback — возвращаем обычное поле name (существует в миграции)
        return $this->attributes['name'] ?? '';
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class);
    }

    /**
     * Mark the user's email as verified.
     */
    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function getEmailForVerification(): string
    {
        return $this->email;
    }

    public function queueEmailVerification(bool $emailChanged = false): void
    {
        $notification = new UserEmailVerificationNotification($emailChanged);
        SendUserEmailVerification::dispatch(
            $this->getKey(),
            $this->email,
            $notification->toMail($this)->verificationUrl,
            $this->unblocked_at->toDateTimeString(),
            $emailChanged,
        );
    }

    /**
     * Determine if the user has verified their email address.
     */
    public function hasVerifiedEmail(): bool
    {
        return ! is_null($this->email_verified_at);
    }
}
