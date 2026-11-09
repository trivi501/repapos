<?php

namespace Database\Seeders;

use App\Models\Taller;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $taller = Taller::create(['nombre' => 'Taller de pruebas', 'estado' => Taller::CORTESIA]);

        foreach ([
            ['Administrador', 'admin@tallercelulares.test', 'Administrador'],
            ['Recepcionista', 'recepcion@tallercelulares.test', 'Recepcionista'],
            ['Tecnico', 'tecnico@tallercelulares.test', 'Tecnico'],
        ] as [$nombre, $correo, $rol]) {
            User::factory()->create([
                'name' => $nombre, 'email' => $correo, 'password' => 'password', 'taller_id' => $taller->id,
            ])->assignRole($rol);
        }
    }
}
