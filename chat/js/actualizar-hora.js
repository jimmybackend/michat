
function actualizarHoraFooter() {
  const ahora = new Date();
  const fecha = ahora.toLocaleDateString('es-MX');
  const hora = ahora.toLocaleTimeString('es-MX');
  const reloj = document.getElementById('relojFooter');
  if (reloj) {
    reloj.innerHTML = `<strong>${fecha} ${hora}</strong>`;
  }
}
setInterval(actualizarHoraFooter, 1000);
actualizarHoraFooter();
