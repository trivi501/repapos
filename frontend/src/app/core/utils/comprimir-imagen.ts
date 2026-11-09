const LADO_MAXIMO = 1920;
const CALIDAD_JPEG = 0.82;

/**
 * Reduce una foto a 1920 px en su lado mayor y la recomprime en JPEG.
 * Si el navegador no puede decodificarla (p. ej. HEIC fuera de Safari) o el
 * resultado no es más pequeño, devuelve el archivo original sin cambios.
 */
export async function comprimirImagen(archivo: File): Promise<File> {
  if (!archivo.type.startsWith('image/') || archivo.type === 'image/gif') return archivo;

  try {
    const bitmap = await createImageBitmap(archivo);
    const escala = Math.min(1, LADO_MAXIMO / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * escala);
    canvas.height = Math.round(bitmap.height * escala);
    canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    const blob = await new Promise<Blob | null>((resolver) => canvas.toBlob(resolver, 'image/jpeg', CALIDAD_JPEG));
    if (!blob || blob.size >= archivo.size) return archivo;

    return new File([blob], archivo.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
  } catch {
    return archivo;
  }
}
