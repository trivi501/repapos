<?php

use App\Models\Taller;
use App\Models\User;
use App\Support\Respaldo;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:crear-taller
        {--superadmin : El administrador será también dueño del sistema (ve el panel de todos los talleres)}
        {--cortesia : Acceso gratis sin vencimiento (por ejemplo, tu propio taller)}', function () {
    $nombreTaller = $this->ask('Nombre del taller');
    $nombre = $this->ask('Nombre del administrador');
    $email = $this->ask('Correo del administrador (con él inicia sesión)');
    $password = $this->secret('Contraseña (mínimo 8 caracteres)');
    if (strlen((string) $password) < 8) {
        $this->error('La contraseña debe tener al menos 8 caracteres.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Ya existe un usuario con ese correo.');

        return 1;
    }
    $taller = DB::transaction(function () use ($nombreTaller, $nombre, $email, $password) {
        $taller = Taller::create([
            'nombre' => $nombreTaller,
            'estado' => $this->option('cortesia') ? Taller::CORTESIA : Taller::PRUEBA,
            'prueba_hasta' => today()->addDays(config('venta.dias_prueba')),
        ]);
        $u = User::create(['taller_id' => $taller->id, 'name' => $nombre, 'email' => $email, 'password' => $password]);
        $u->assignRole($this->option('superadmin') ? [User::ROL_ADMIN, User::ROL_SUPERADMIN] : [User::ROL_ADMIN]);

        return $taller;
    });
    $this->info("Taller #{$taller->id} \"{$taller->nombre}\" creado ({$taller->estadoTexto()}). Administrador: {$email}"
        .($this->option('superadmin') ? ' — dueño del sistema.' : '.'));
})->purpose('Crear un taller con su administrador');

Artisan::command('app:talleres', function () {
    $this->table(['#', 'Taller', 'Estado', 'Usuarios', 'Teléfonos'], Taller::withCount('usuarios')->orderBy('id')->get()->map(fn ($t) => [
        $t->id, $t->nombre, $t->estadoTexto(), $t->usuarios_count, DB::table('telefonos')->where('taller_id', $t->id)->count(),
    ]));
})->purpose('Ver los talleres registrados');

Artisan::command('app:respaldo', function () {
    $this->info('Respaldo creado: '.Respaldo::crear());
})->purpose('Respaldar todos los datos (todos los talleres) en storage/app/respaldos');

Artisan::command('app:preparar-produccion', function () {
    // Las cuentas de prueba tienen contraseñas conocidas y las sesiones son de la PC de donde vienen los datos.
    $cuentas = User::withoutGlobalScopes()
        ->where(fn ($q) => $q->where('email', 'like', '%@tallercelulares.test')->orWhere('email', 'like', '%@local.test'))
        ->get();
    foreach ($cuentas as $cuenta) {
        $cuenta->forceFill(['password' => Str::random(48)])->save();
    }
    $sesiones = DB::table('personal_access_tokens')->delete();

    $this->info("Cuentas de prueba bloqueadas: {$cuentas->count()}. Sesiones cerradas: {$sesiones}.");
})->purpose('Bloquea las cuentas de prueba y cierra todas las sesiones');

// Elimina fotos en borrador que nunca se asociaron a una orden (más de 24 h).
Schedule::command('model:prune')->daily();

// Respaldo automático todos los días (requiere el programador de Laravel)
Schedule::command('app:respaldo')->dailyAt('23:30');
