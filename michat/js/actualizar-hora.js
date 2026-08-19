/**
 * actualizar-hora.js
 * Actualiza el reloj del footer mostrando fecha y hora en tiempo real.
 * Se ejecuta cada segundo usando setInterval.
 */

/**
 * Actualiza el elemento 'relojFooter' con la fecha y hora actual en formato mexicano (es-MX).
 * Si el elemento no existe, la función sale silenciosamente sin error.
 */
function actualizarHoraFooter() {
  const ahora = new Date();
  const fecha = ahora.toLocaleDateString('es-MX');
  const hora = ahora.toLocaleTimeString('es-MX');
  const reloj = document.getElementById('relojFooter');
  if (reloj) {
    reloj.innerHTML = `<strong>${fecha} ${hora}</strong>`;
  }
}

// Ejecutar la actualización cada 1000ms (1 segundo)
setInterval(actualizarHoraFooter, 1000);
// Ejecución inicial inmediata para evitar retraso de 1 segundo
actualizarHoraFooter();
