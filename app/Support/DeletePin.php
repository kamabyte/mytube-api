<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * PIN на удаление каналов и видео — от случайных нажатий
 * (дети у телевизора), а не от злоумышленника: авторизации в приложении нет.
 *
 * Хеш PIN лежит в таблице settings, а не в .env: .env перегенерируется
 * при provision. Задаётся командой mytube:delete-pin. PIN нужен для
 * удаления и выгрузки.
 *
 * После верного PIN сессия остаётся «разблокированной» UNLOCK_MINUTES —
 * чтобы не вводить его на каждое удаление подряд.
 */
class DeletePin
{
    public const int UNLOCK_MINUTES = 10;

    private const string SETTING = 'delete_pin_hash';

    private const string SESSION_KEY = 'delete_pin_unlocked_until';

    /** Попыток на адрес за DECAY_SECONDS: 4 цифры так не перебрать. */
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 600;

    public function isConfigured(): bool
    {
        return $this->hash() !== null;
    }

    public function set(string $pin): void
    {
        DB::table('settings')->upsert(
            [['key' => self::SETTING, 'value' => Hash::make($pin), 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    public function clear(): void
    {
        DB::table('settings')->where('key', self::SETTING)->delete();
    }

    public static function isValidFormat(string $pin): bool
    {
        return (bool) preg_match('/^\d{4,8}$/', $pin);
    }

    public function isUnlocked(Request $request): bool
    {
        return $request->hasSession()
            && $request->session()->get(self::SESSION_KEY, 0) > now()->getTimestamp();
    }

    /**
     * Пропускает запрос на удаление или бросает ошибку поля pin.
     *
     * @throws ValidationException
     */
    public function authorize(Request $request): void
    {
        $hash = $this->hash();

        if ($hash === null) {
            throw ValidationException::withMessages([
                'pin' => 'PIN для удаления не задан. На сервере: php artisan mytube:delete-pin',
            ]);
        }

        if ($this->isUnlocked($request)) {
            return;
        }

        $limiterKey = 'delete-pin:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);

            throw ValidationException::withMessages([
                'pin' => "Слишком много неверных попыток. Попробуйте через {$minutes} мин.",
            ]);
        }

        $pin = $request->input('pin', '');

        if (is_int($pin)) {
            $pin = (string) $pin;
        }

        if (! is_string($pin) || ! self::isValidFormat($pin) || ! Hash::check($pin, $hash)) {
            RateLimiter::hit($limiterKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'pin' => $pin === '' ? 'Введите PIN.' : 'Неверный PIN.',
            ]);
        }

        RateLimiter::clear($limiterKey);

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, now()->addMinutes(self::UNLOCK_MINUTES)->getTimestamp());
        }
    }

    private function hash(): ?string
    {
        return DB::table('settings')->where('key', self::SETTING)->value('value');
    }
}
