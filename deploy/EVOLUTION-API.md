# WhatsApp por código QR (Evolution API)

[Evolution API](https://github.com/EvolutionAPI/evolution-api) es open source (Apache 2.0). Vincula el WhatsApp del taller
escaneando un código QR, igual que WhatsApp Web, y RepaPOS manda los mensajes directo, sin abrir WhatsApp.

## Antes de empezar

- **No es oficial.** WhatsApp puede bloquear el número si se abusa: mensajes masivos, promociones o muchos reportes.
  Para avisos del taller (equipo listo, tickets, corte al dueño) a clientes que ya te conocen, el riesgo es bajo.
- **Usa un número aparte** del principal del negocio, por si lo bloquean.
- El celular debe tener WhatsApp activo y entrar a internet de vez en cuando, como con WhatsApp Web.
- Si prefieres lo oficial, en Configuración → WhatsApp también está la opción "Meta (API oficial)".

## 1. Instalar en el VPS (una sola vez)

**VPS** (como root)
```bash
bash /var/www/repapos/deploy/instalar-evolution.sh
```

El script:
- instala Docker si no está (con los paquetes de la distribución);
- levanta Evolution API y su base de datos en `/opt/repapos-evolution`, escuchando **solo en 127.0.0.1:8085**
  (no queda expuesta a internet ni toca nginx);
- genera una clave al azar y la pone en el `.env` de RepaPOS (`EVOLUTION_URL` y `EVOLUTION_API_KEY`).

Si el puerto 8085 ya lo usa otra app: `EVOLUTION_PORT=8086 bash /var/www/repapos/deploy/instalar-evolution.sh`

## 2. Vincular el WhatsApp del taller

1. En RepaPOS: **Configuración → WhatsApp: envío directo → Evolution API (código QR)**.
2. **Vincular con código QR**.
3. En el celular del taller: WhatsApp → menú ⋮ → **Dispositivos vinculados** → **Vincular un dispositivo**, y escanea el código.
4. Cuando diga "Vinculado", captura el **WhatsApp del dueño** (recibe el corte de caja), marca **Activar** y **Guardar**.

Desde ahí, los botones de WhatsApp envían directo. Cada taller vincula su propio número.

## Si algo falla

| Problema | Qué hacer |
|---|---|
| "El servidor todavía no tiene Evolution API instalada" | Corre el paso 1 |
| El QR no aparece o no vincula | WhatsApp cambió de versión. Busca la versión actual de WhatsApp Web (por ejemplo en los *issues* de Evolution API), ponla en `/opt/repapos-evolution/.env` como `CONFIG_SESSION_PHONE_VERSION=2.3000.xxxxxxxxxx` y corre `cd /opt/repapos-evolution && docker compose up -d` |
| "El WhatsApp del taller se desconectó" | Se cerró la sesión desde el celular: vuelve a vincular con el QR |
| Ver qué pasa | `cd /opt/repapos-evolution && docker compose logs --tail 80 evolution` |
| Actualizar Evolution | Cambia `EVOLUTION_IMAGE` en `/opt/repapos-evolution/.env` a la versión nueva y corre `docker compose pull && docker compose up -d` |

## Quitarlo

```bash
cd /opt/repapos-evolution && docker compose down
```
Agrega `-v` si también quieres borrar las sesiones guardadas. Luego quita `EVOLUTION_URL` y `EVOLUTION_API_KEY`
del `.env` de RepaPOS y corre `cd /var/www/repapos/backend && sudo -u repapos php artisan config:cache`.
