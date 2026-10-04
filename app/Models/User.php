<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    private const string OWNER_EMAIL = 'owner@mytube.local';

    /**
     * Владелец библиотеки. Профилей и входа пока нет, а встроенные уведомления
     * Laravel привязаны к модели-получателю, — поэтому все уведомления получает
     * этот пользователь, заводится он сам при первом обращении. Когда появятся
     * профили, он станет первым из них, а уведомления — у каждого свои.
     */
    public static function owner(): self
    {
        return self::query()->firstOrCreate(
            ['email' => self::OWNER_EMAIL],
            ['name' => 'MyTube', 'password' => Str::random(64)],
        );
    }

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
}
