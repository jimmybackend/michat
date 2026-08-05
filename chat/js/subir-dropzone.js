
Dropzone.autoDiscover = false;

const API = window.UPLOAD_API || 'api/upload.php';

const drop = new Dropzone("#dropzonePublico", {
  url: `${API}?mode=dropbox&action=init`,
  paramName: "file",
  addRemoveLinks: true,
  withCredentials: true,
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
  success: function(file, response) {
    console.log("✅ Archivo subido:", response);
    setTimeout(() => {
      if (typeof actualizarBloqueArchivos === 'function') actualizarBloqueArchivos();
    }, 500);
  },
  error: function(file, response) {
    console.error("❌ Error al subir:", response);
  }
});