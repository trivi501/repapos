<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Model;

/** Opciones de cada taller guardadas en la base de datos (mensajes de WhatsApp, lada...). */
class Ajuste extends Model
{
    use DelTaller;

    public const MENSAJE_LISTO = "Hola {locatario}\nTu equipo *{modelo}* ya está LISTO\nTrabajo: {trabajo}{saldo}\n¡Ya puedes pasar por él!\n{taller}";
    public const MENSAJE_NO_QUEDO = "Hola {locatario}\nTe aviso que tu equipo *{modelo}* no quedó ({trabajo}). No tiene costo.\nPuedes pasar a recogerlo.\n{taller}";

    // clientes finales (órdenes de servicio)
    public const MENSAJE_ORDEN_LISTO = "Hola {cliente}\nTu equipo *{equipo}* (orden {folio}) ya está LISTO.{saldo}\nRecuerda traer tu nota para recogerlo.\nConsulta tu orden aquí: {enlace}\n{taller}";
    public const MENSAJE_ORDEN_NO_QUEDO = "Hola {cliente}\nTe aviso que tu equipo *{equipo}* (orden {folio}) no se pudo reparar. No tiene costo.\nPuedes pasar a recogerlo con tu nota.\n{taller}";
    public const MENSAJE_ORDEN_ESTATUS = "Hola {cliente}\nTu equipo *{equipo}* (orden {folio}) está: {estatus}.\nConsulta tu orden aquí: {enlace}\n{taller}";
    // ticket de la orden que se manda por WhatsApp (resumen de la nota de recepción)
    public const TICKET_WHATSAPP = "*{taller}*\nOrden N° {folio} · {fecha}\nCliente: {cliente}\nEquipo: {equipo}\nFalla: {falla}\n\nRevisión: {revision}\n\nTotal: {total}\nAnticipo: {anticipo}\n*Resta: {resta}*\n\nTu nota en PDF: {pdf}\nConsulta el estatus de tu reparación: {enlace}\nPresenta tu nota para recoger tu equipo.";
    // ventas de mostrador
    public const PIE_TICKET_VENTA = "¡Gracias por tu compra!\nAccesorios sin garantía por mal uso. Cambios solo con este ticket dentro de 7 días.";
    public const TICKET_VENTA_WHATSAPP = "*{taller}*\nTicket de venta N° {folio} · {fecha}\n\n{productos}{descuento}\n*Total: {total}* ({metodo})\n\nTu ticket en PDF: {pdf}\n\n{pie}";
    public const AVISO_RECOGER = 'ADVERTENCIA: solo puede recoger el equipo la persona que lo entregó.';
    public const TERMINOS_ORDEN = <<<'TXT'
No se entrega teléfono o equipo dejado a reparación sin la nota que corrobora haberlo dejado.
Si un equipo entra en almacenaje se le cobrará al cliente la cantidad de $50.00 pesos diarios hasta su recolección, y se le entregará en los 3 días laborales siguientes, ya que el almacenaje está aparte del taller.
Multa por pérdida de la nota: $200.00 pesos sin excepciones, y presentar identificación oficial para corroborar el nombre de la persona que dejó el equipo.
El cliente tiene máximo 15 días naturales para recoger su equipo desde que lo deja a reparación; si no lo recoge en ese lapso, el equipo pasa a almacenaje.
Después de 90 días naturales de haber dejado el equipo y haber pasado a almacenaje, los equipos pasan a venta sin excepción para cubrir los gastos del almacenaje.
Si el equipo presenta signos de humedad, no nos hacemos responsables por daños extras que se ocasionen al manipularlo y no aplica ningún tipo de garantía.
La garantía cubre 15 días naturales en cualquier reparación, siempre y cuando el equipo no presente signos de humedad, y solo en el área reparada. Cuenta desde que el equipo fue reparado, no desde que fue recolectado por el cliente.
En cambios de pantalla no aplica garantía si regresa mojada, con manchas, daño por presión, rota, raspada, sin dar imagen, cualquier daño en el touch y/o cualquier otro detalle que no tenía al momento de la entrega.
El cliente debe verificar el correcto funcionamiento del área que trajo a reparar al momento de recoger su equipo.
Bajo ninguna circunstancia negamos garantías si es que llegan a aplicar; contamos con 30 días naturales para aplicarla.
TXT;

    public $timestamps = false;
    protected $fillable = ['clave', 'valor'];

    public static function valor(string $clave, string $porDefecto = ''): string
    {
        return static::where('clave', $clave)->value('valor') ?? $porDefecto;
    }

    /** Lo mismo, pero de un taller en particular (páginas públicas, donde no hay usuario conectado). */
    public static function valorDe(int $tallerId, string $clave, string $porDefecto = ''): string
    {
        return static::withoutGlobalScopes()->where('taller_id', $tallerId)->where('clave', $clave)->value('valor') ?? $porDefecto;
    }

    public static function poner(string $clave, string $valor): void
    {
        static::updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }
}
