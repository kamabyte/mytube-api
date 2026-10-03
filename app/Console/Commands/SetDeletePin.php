<?php

namespace App\Console\Commands;

use App\Support\DeletePin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

#[Signature('mytube:delete-pin
    {pin? : New PIN, 4–8 digits. Omit to type it in hidden}
    {--clear : Remove the PIN: deleting from the web becomes impossible}'
)]
#[Description('Set the PIN the web client asks before deleting channels and videos')]
class SetDeletePin extends Command
{
    public function handle(DeletePin $deletePin): int
    {
        if ($this->option('clear')) {
            $deletePin->clear();
            $this->components->info('The PIN was removed: deleting from the web is disabled until a new one is set.');

            return self::SUCCESS;
        }

        $pin = $this->argument('pin');

        if ($pin === null) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Pass the PIN as an argument when the command runs non-interactively.');

                return self::FAILURE;
            }

            $pin = password(
                label: 'New PIN (4–8 digits)',
                validate: fn (string $value) => DeletePin::isValidFormat($value) ? null : 'Use 4 to 8 digits.',
            );

            $repeated = password(label: 'Repeat the PIN');

            if ($repeated !== $pin) {
                $this->components->error('The PINs do not match, nothing was changed.');

                return self::FAILURE;
            }
        }

        if (! DeletePin::isValidFormat((string) $pin)) {
            $this->components->error('The PIN must be 4 to 8 digits.');

            return self::FAILURE;
        }

        $deletePin->set((string) $pin);
        $this->components->info('The PIN was saved. The web client asks for it before deleting channels and videos.');

        return self::SUCCESS;
    }
}
