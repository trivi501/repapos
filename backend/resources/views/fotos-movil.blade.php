<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subir fotos · RepaPOS</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        :root { color-scheme: light dark; --acento: #0f9d8a; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #0b0f17; color: #e8ecf3; min-height: 100vh; }
        main { max-width: 480px; margin: 0 auto; padding: 24px 16px 40px; }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        p { color: #8a94a7; margin: 0 0 20px; font-size: 0.9rem; }
        label.boton { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 16px; border-radius: 12px; background: var(--acento); color: #fff; font-weight: 600; font-size: 1rem; cursor: pointer; }
        label.boton.secundario { background: transparent; border: 1px solid #2a3446; color: #e8ecf3; margin-top: 10px; }
        input[type=file] { position: absolute; opacity: 0; width: 1px; height: 1px; }
        #estado { margin-top: 18px; font-size: 0.9rem; min-height: 1.4em; }
        .galeria { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 16px; }
        .galeria img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; border: 1px solid #2a3446; }
        .error { color: #f87171; }
    </style>
</head>
<body>
<main>
    <h1>{{ $categoria }}</h1>
    <p>Toma o elige fotos del dispositivo. Aparecerán automáticamente en la computadora del taller.</p>

    <label class="boton">
        📷 Tomar foto
        <input id="camara" type="file" accept="image/*" capture="environment">
    </label>
    <label class="boton secundario">
        🖼️ Elegir de la galería
        <input id="galeria" type="file" accept="image/*" multiple>
    </label>

    <div id="estado" role="status" aria-live="polite"></div>
    <div class="galeria" id="miniaturas"></div>
</main>

<script>
    const URL_SUBIDA = @json($urlSubida);
    const estado = document.getElementById('estado');
    const miniaturas = document.getElementById('miniaturas');
    let subidas = 0;

    // Reduce la foto a 1920 px como máximo y la recomprime en JPEG para que suba rápido.
    async function comprimir(archivo) {
        try {
            const bitmap = await createImageBitmap(archivo);
            const escala = Math.min(1, 1920 / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * escala);
            canvas.height = Math.round(bitmap.height * escala);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', 0.82));
            return blob && blob.size < archivo.size ? new File([blob], archivo.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : archivo;
        } catch {
            return archivo;
        }
    }

    async function subir(archivos) {
        for (const original of archivos) {
            estado.className = '';
            estado.textContent = 'Subiendo ' + original.name + '…';
            const archivo = await comprimir(original);
            const datos = new FormData();
            datos.append('archivo', archivo);
            try {
                const respuesta = await fetch(URL_SUBIDA, { method: 'POST', body: datos, headers: { Accept: 'application/json' } });
                if (!respuesta.ok) throw new Error(respuesta.status === 403 ? 'El enlace expiró. Genera un nuevo QR.' : 'No se pudo subir la foto.');
                const img = document.createElement('img');
                img.src = URL.createObjectURL(archivo);
                img.alt = 'Foto subida';
                miniaturas.prepend(img);
                subidas++;
                estado.textContent = '✓ ' + subidas + (subidas === 1 ? ' foto subida' : ' fotos subidas');
            } catch (e) {
                estado.className = 'error';
                estado.textContent = e.message;
                return;
            }
        }
    }

    for (const id of ['camara', 'galeria']) {
        document.getElementById(id).addEventListener('change', (e) => {
            subir([...e.target.files]);
            e.target.value = '';
        });
    }
</script>
</body>
</html>
