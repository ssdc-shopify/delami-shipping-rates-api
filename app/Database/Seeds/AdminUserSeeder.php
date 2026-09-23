<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;

class AdminUserSeeder extends Seeder
{
    public function run()
    {
        $email    = env('ADMIN_EMAIL', 'admin@delamibrands.com');
        $password = env('ADMIN_PASSWORD', '');

        if ($password === '') {
            $password = bin2hex(random_bytes(8));
            echo "Generated admin password: {$password}\n";
            echo "(set ADMIN_EMAIL / ADMIN_PASSWORD in .env before seeding to choose your own)\n";
        }

        $users = auth()->getProvider();

        if ($users->findByCredentials(['email' => $email]) !== null) {
            echo "Admin user {$email} already exists — skipping.\n";

            return;
        }

        $user = new User([
            'username' => 'admin',
            'email'    => $email,
            'password' => $password,
        ]);
        $users->save($user);

        $user = $users->findById($users->getInsertID());
        $user->addGroup('admin');
        $user->activate();

        echo "Admin user created: {$email}\n";
    }
}
