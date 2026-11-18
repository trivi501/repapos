# RepaPOS Conector

Programa chico para Windows que detecta el teléfono conectado por USB y le pasa a RepaPOS la **marca, el modelo y la serie**.
En RepaPOS: **Reparaciones → Nueva → ➕ Nuevo dispositivo → 🔌 Detectar teléfono conectado por USB**.

## Instalar en la PC del mostrador

1. Doble clic en `compilar.bat` (usa el compilador de C# que ya trae Windows; no instala nada). Sale `RepaPOS-Conector.exe`.
2. Ábrelo: queda junto al reloj. Para que abra solo al encender la PC, pon un acceso directo en `shell:startup`.
3. Para probarlo sin RepaPOS: clic derecho en el icono → *Ver teléfonos conectados*, o abre http://127.0.0.1:8765/telefonos.

Solo escucha en la misma PC (127.0.0.1:8765); nadie de la red puede leerlo.

## Qué lee de cada teléfono

| Teléfono | Qué se necesita | Qué da |
|---|---|---|
| Android por MTP | Desbloqueado y en "Transferir archivos" | Marca, modelo comercial (p. ej. Galaxy A52s 5G) y serie |
| Android con depuración USB | `adb` instalado y aceptar "Permitir depuración USB" | Además el modelo de fábrica (SM-A528B) y la versión de Android |
| iPhone encendido | iTunes o "Dispositivos Apple" instalado; desbloqueado | Modelo (iPhone 13…) e iOS. La serie y el IMEI, si el iPhone confía en la PC |
| iPhone en Recovery | Nada más | Modelo por su chip, serie (SRNM) y ECID |
| iPhone en DFU | Nada más | Modelo por su chip y ECID (en DFU no se ve la serie) |
| iPhone restaurándose | — | Solo lo muestra; no le habla para no estorbar la restauración |

El IMEI de los Android no se puede leer por MTP: se captura a mano (`*#06#`).
