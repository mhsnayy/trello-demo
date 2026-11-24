<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Tekrar çalıştırılabilir: var olan kullanıcıların şifresine dokunmaz, eksikleri ekler. */
class UserSeeder extends Seeder
{
    private const USERS = [
        // ad, e-posta, renk, yönetici mi (şifre burada değil, .env içindeki SEED_USER_PASSWORD)
        ['Yönetici', 'admin@ardic.com', '#0c66e4', true],
        ['Ayşe Yılmaz', 'ayse.yilmaz@ardic.com', '#ae2e24', false],
        ['Mehmet Kaya', 'mehmet.kaya@ardic.com', '#216e4e', false],
        ['Zeynep Demir', 'zeynep.demir@ardic.com', '#5e4db2', false],
    ];

    public function run(): void
    {
        $password = (string) config('app.seed_user_password');
        if (strlen($password) < 8) {
            throw new RuntimeException('.env içinde en az 8 karakterli SEED_USER_PASSWORD tanımlayın.');
        }

        foreach (self::USERS as [$name, $email, $color, $isAdmin]) {
            if (User::where('email', $email)->exists()) {
                continue;
            }
            User::create([
                'name' => $name,
                'email' => $email,
                'color' => $color,
                'is_admin' => $isAdmin,
                'password' => $password,
                'must_change_password' => true,
            ]);
        }
    }
}
