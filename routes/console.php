<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('user:reset-password {email}', function (string $email) {
    $user = User::where('email', Str::lower($email))->first();
    if (! $user) {
        $this->error('Kullanıcı bulunamadı.');

        return 1;
    }
    $password = Str::password(12, symbols: false);
    $user->update(['password' => $password, 'must_change_password' => true]);
    $this->info("{$user->email} için geçici şifre: {$password}");
})->purpose('Kullanıcıya geçici şifre verir, açık oturumlarını kapatır');
