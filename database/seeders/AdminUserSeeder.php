<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'id' => 'usr-admin-001',
                'name' => 'Administrador Tema',
                'email' => 'admin@tema.com.pe',
                'role' => 'admin',
                'active' => true,
                'two_factor_enabled' => true,
            ],
            [
                'id' => 'usr-ops-001',
                'name' => 'Operaciones Tema',
                'email' => 'operaciones@tema.com.pe',
                'role' => 'operaciones',
                'active' => true,
                'two_factor_enabled' => true,
            ],
            [
                'id' => 'usr-audit-001',
                'name' => 'Auditoría Tema',
                'email' => 'seguridad@tema.es',
                'role' => 'auditoria',
                'active' => true,
                'two_factor_enabled' => true,
            ],
        ];

        foreach ($users as $user) {
            AdminUser::query()->updateOrCreate(
                ['email' => $user['email']],
                $user
            );
        }
    }
}
