<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class EnsureAdminUser extends Command
{
    protected $signature = 'ftth:ensure-admin {--email=admin@ftth.local : Email za novi administratorski račun} {--name=Administrator : Ime novog računa}';

    protected $description = 'Create an initial administrator account only when no users exist';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->info('Korisnički račun već postoji; ništa nije promijenjeno.');

            return self::SUCCESS;
        }

        $email = filter_var($this->option('email'), FILTER_VALIDATE_EMAIL);
        if (! $email) {
            $this->error('Opcija --email mora sadržavati ispravnu email adresu.');

            return self::FAILURE;
        }

        $password = Str::password(20);
        User::create([
            'name' => trim((string) $this->option('name')) ?: 'Administrator',
            'username' => 'admin',
            'email' => $email,
            'password' => $password,
        ]);

        $this->warn('Kreiran je početni administratorski račun.');
        $this->line("Email: {$email}");
        $this->line("Privremena lozinka: {$password}");
        $this->warn('Sačuvaj lozinku na sigurno mjesto.');

        return self::SUCCESS;
    }
}
