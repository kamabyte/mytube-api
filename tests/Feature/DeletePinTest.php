<?php

use App\Models\Channel;
use App\Models\Video;
use App\Support\DeletePin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('public');
    $this->mediaRoot = useMediaDisk();
});

function pinVideo(): Video
{
    $channel = Channel::query()->firstOrCreate(['external_id' => 'UC-pin'], ['name' => 'Pin']);

    return Video::withoutGlobalScopes()->create([
        'external_id' => 'pin-'.Str::random(6),
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'name' => 'Pinned',
        'published_at' => now(),
    ]);
}

it('refuses to delete from the web until a PIN is set', function (): void {
    $video = pinVideo();

    $this->from("/watch/{$video->id}")
        ->delete("/videos/{$video->id}", ['pin' => '1234'])
        ->assertRedirect("/watch/{$video->id}")
        ->assertSessionHasErrors(['pin' => 'PIN для удаления не задан. На сервере: php artisan mytube:delete-pin']);

    expect(Video::query()->whereKey($video->id)->exists())->toBeTrue();

    $this->get("/watch/{$video->id}")
        ->assertInertia(fn (Assert $page) => $page->where('deletePin', ['configured' => false, 'unlocked' => false]));
});

it('rejects a missing or wrong PIN and keeps everything', function (): void {
    app(DeletePin::class)->set('2468');
    [$channel] = makeChannelWithVideo('guarded');

    $this->delete("/channels/{$channel->id}")->assertSessionHasErrors(['pin' => 'Введите PIN.']);
    $this->delete("/channels/{$channel->id}", ['pin' => '1111'])->assertSessionHasErrors(['pin' => 'Неверный PIN.']);

    expect(Channel::query()->whereKey($channel->id)->exists())->toBeTrue();
});

it('stores only a hash of the PIN', function (): void {
    app(DeletePin::class)->set('2468');

    $stored = DB::table('settings')->where('key', 'delete_pin_hash')->value('value');

    expect($stored)->not->toContain('2468')
        ->and(Hash::check('2468', $stored))->toBeTrue();
});

it('remembers a correct PIN for a while, then asks again', function (): void {
    app(DeletePin::class)->set('2468');
    $first = pinVideo();
    $second = pinVideo();
    $third = pinVideo();

    $this->delete("/videos/{$first->id}", ['pin' => '2468'])->assertSessionHasNoErrors();

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('deletePin.unlocked', true));

    // В течение UNLOCK_MINUTES — без PIN.
    $this->delete("/videos/{$second->id}")->assertSessionHasNoErrors();

    $this->travel(DeletePin::UNLOCK_MINUTES + 1)->minutes();

    $this->delete("/videos/{$third->id}")->assertSessionHasErrors('pin');
    expect(Video::query()->whereKey($third->id)->exists())->toBeTrue();
});

it('locks out after too many wrong attempts, even with the right PIN', function (): void {
    app(DeletePin::class)->set('2468');
    $video = pinVideo();

    foreach (range(1, 5) as $attempt) {
        $this->delete("/videos/{$video->id}", ['pin' => '0000'])->assertSessionHasErrors(['pin' => 'Неверный PIN.']);
    }

    $this->delete("/videos/{$video->id}", ['pin' => '2468'])
        ->assertSessionHasErrors(['pin' => 'Слишком много неверных попыток. Попробуйте через 10 мин.']);

    expect(Video::query()->whereKey($video->id)->exists())->toBeTrue();

    $this->travel(11)->minutes();

    $this->delete("/videos/{$video->id}", ['pin' => '2468'])->assertSessionHasNoErrors();
    expect(Video::query()->whereKey($video->id)->exists())->toBeFalse();
});

it('sets, validates and clears the PIN from the console', function (): void {
    $pin = app(DeletePin::class);

    $this->artisan('mytube:delete-pin', ['pin' => '12'])->assertFailed();
    $this->artisan('mytube:delete-pin', ['pin' => 'abcd'])->assertFailed();
    expect($pin->isConfigured())->toBeFalse();

    $this->artisan('mytube:delete-pin', ['pin' => '975310'])->assertSuccessful();
    expect($pin->isConfigured())->toBeTrue();

    $this->artisan('mytube:delete-pin')
        ->expectsQuestion('New PIN (4–8 digits)', '1357')
        ->expectsQuestion('Repeat the PIN', '1357')
        ->assertSuccessful();

    $this->artisan('mytube:delete-pin')
        ->expectsQuestion('New PIN (4–8 digits)', '1357')
        ->expectsQuestion('Repeat the PIN', '7531')
        ->assertFailed();

    $this->artisan('mytube:delete-pin', ['--clear' => true])->assertSuccessful();
    expect($pin->isConfigured())->toBeFalse();
});
