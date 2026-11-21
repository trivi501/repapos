# RepaPOS Conector

Programa chico para Windows que detecta el teléfono conectado por USB y le pasa a RepaPOS la **marca, el modelo y la serie**.
En RepaPOS: **Reparaciones → Nueva → ➕ Nuevo dispositivo → 🔌 Detectar teléfono conectado por USB**.

## Instalar en la PC del mostrador

**Lo más fácil:** en RepaPOS, Ajustes → Configuración → **⬇ Descargar RepaPOS-Conector.exe** (o el enlace del aviso cuando el conector
no responde). Si Windows dice "protegió su PC": Más información → Ejecutar de todas formas.

Para compilarlo tú:

1. Doble clic en `compilar.bat` (usa el compilador de C# que ya trae Windows; no instala nada). Sale `RepaPOS-Conector.exe`
   y una copia en `frontend/public/descargas`, que es la que se descarga desde la página (hay que recompilar el frontend).
2. Ábrelo: queda junto al reloj. Para que abra solo al encender la PC, pon un acceso directo en `shell:startup`.
3. Para probarlo sin RepaPOS: clic derecho en el icono → *Ver teléfonos conectados*, o abre http://127.0.0.1:8765/telefonos.

Solo escucha en la misma PC (127.0.0.1:8765); nadie de la red puede leerlo. Y solo le entrega los datos a RepaPOS:
a las páginas de esta PC (localhost) y a `repapos.tech`. Otro dominio se agrega, uno por renglón, en `origenes.txt`
junto al conector (por ejemplo `https://taller.midominio.com`).

### Usar RepaPOS desde el servidor (https://repapos.tech)

Chrome y Edge no dejan que un sitio de internet hable con programas de tu PC sin el permiso de **acceso a la red local**.
Si el permiso está negado, RepaPOS abre por un instante una ventanita del conector que lee el teléfono y se cierra sola.
Para que no salga esa ventanita: candado junto a la dirección → Configuración del sitio → **Acceso a la red local → Permitir**.
Si el navegador bloquea ventanas emergentes en repapos.tech, permítelas también.

## Qué lee de cada teléfono

| Teléfono | Qué se necesita | Qué da |
|---|---|---|
| Android por MTP | Desbloqueado y en "Transferir archivos" | Marca, modelo comercial (p. ej. Galaxy A52s 5G) y serie |
| Samsung encendido | Nada más (usa su "SAMSUNG Mobile USB Modem") | Además el modelo de fábrica (SM-A566E), el IMEI y el firmware |
| Android con depuración USB | Que algún programa tenga corriendo el servidor de adb | Además el modelo de fábrica (SM-A528B) y la versión de Android |
| iPhone encendido | iTunes o "Dispositivos Apple" instalado; desbloqueado | Modelo (iPhone 13…) e iOS |
| iPhone ya emparejado con esta PC | Que antes haya confiado en la PC (iTunes, 3uTools…) | Además la serie y el IMEI |
| iPhone en Recovery | Nada más | Modelo por su chip, serie (SRNM) y ECID |
| iPhone en DFU | Nada más | Modelo por su chip y ECID (en DFU no se ve la serie) |
| iPhone restaurándose | — | Solo lo muestra; no le habla para no estorbar la restauración |
| Samsung en Download (Odin) | Que Odin u otro programa no esté usando el puerto | Marca, modelo de fábrica y firmware (la serie y el IMEI no se ven en este modo) |
| MediaTek BROM/Preloader, Qualcomm EDL, Spreadtrum | — | Solo el modo y el puerto COM |

El IMEI de los demás Android no se puede leer por MTP: se captura a mano (`*#06#`).

**Samsung sin el conector:** en Chrome o Edge, la página de RepaPOS lee el Samsung directo por su puerto COM
(Web Serial) cuando el conector no está abierto. La primera vez el navegador pide elegir el puerto del Samsung y después lo recuerda.
Los iPhone y los demás Android sí necesitan el conector.

El conector nunca ejecuta `adb.exe`: le pregunta al servidor de adb que ya esté corriendo, para no cerrar el de otras herramientas.

Para la serie del iPhone, el conector usa el emparejamiento que Windows ya guardó en `C:\ProgramData\Apple\Lockdown`
(el mismo que usan iTunes y 3uTools); no crea emparejamientos nuevos ni manda nada fuera de la PC.
