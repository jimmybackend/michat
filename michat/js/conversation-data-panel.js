(function () {
  'use strict';
  class ConversationDataPanel {
    constructor(getContext) {
      this.getContext = getContext;
      this.serial = 0;
      this.dialog = document.getElementById('conversationDataDialog');
      this.list = document.getElementById('conversationDataList');
      this.status = document.getElementById('conversationDataStatus');
      this.scope = document.getElementById('conversationDataScope');
      this.preview = document.getElementById('conversationDataPreview');
      this.more = document.getElementById('conversationDataMore');
      document.getElementById('conversationDataOpen')?.addEventListener('click', () => this.open());
      document.getElementById('conversationDataClose')?.addEventListener('click', () => this.dialog.close());
      document.getElementById('conversationDataRefresh')?.addEventListener('click', () => this.load());
      this.scope?.addEventListener('change', () => this.load());
      this.more?.addEventListener('click', () => this.load(this.nextOffset));
      this.dialog?.addEventListener('close', () => { this.serial++; this.preview.hidden = true; });
    }
    url(mode, fileId, offset = 0) {
      const params = new URLSearchParams({session_id: this.context.id, scope: this.scope.value, mode, offset});
      if (fileId) params.set('file_id', fileId);
      return 'conversation_data.php?' + params;
    }
    async open() {
      this.context = this.getContext();
      this.scope.value = 'session';
      this.scope.options[1].disabled = !this.context.project;
      document.getElementById('conversationDataTitle').textContent = 'Archivos y resultados · ' + (this.context.title || 'Conversación');
      this.dialog.showModal();
      await this.load();
    }
    async load(offset = 0) {
      const ticket = ++this.serial;
      this.preview.hidden = true;
      this.more.hidden = true;
      if (!offset) this.list.replaceChildren();
      this.status.textContent = this.context?.id ? 'Consultando archivos…' : 'Selecciona una conversación primero.';
      if (!this.context?.id) return;
      try {
        const response = await fetch(this.url('list', null, offset), {cache: 'no-store'});
        const data = await response.json();
        if (ticket !== this.serial || !this.dialog.open) return;
        if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo consultar el catálogo.');
        for (const file of data.files) this.render(file);
        this.nextOffset = data.next_offset;
        this.more.hidden = data.next_offset === null;
        this.status.textContent = `${data.total} archivos. ${data.warnings.join(' ')}` + (data.total ? '' : ' Aún no hay archivos disponibles en este alcance.');
      } catch (error) {
        if (ticket === this.serial) this.status.textContent = error.message;
      }
    }
    render(file) {
      const row = document.createElement('article'); row.className = 'conversation-data-file';
      const name = document.createElement('strong'); name.textContent = file.name;
      const meta = document.createElement('div'); meta.className = 'text-muted small';
      const bytes = Math.max(0, Number(file.size_bytes) || 0), units = ['B','KB','MB','GB','TB','PB','EB'];
      const unit = bytes ? Math.min(Math.floor(Math.log(bytes)/Math.log(1024)), units.length-1) : 0;
      const size = (bytes/1024**unit).toLocaleString('es-MX',{maximumFractionDigits:2}) + ' ' + units[unit];
      const label = file.role === 'result' ? 'Resultado · ' + (file.kind || 'JSON') : file.role === 'generated' ? 'Archivo generado' : file.origin === 'project' ? 'Fuente del proyecto' : 'Adjunto';
      meta.textContent = `${label} · ${size}` + (file.session_id ? ` · Conversación #${file.session_id}` : '') + (file.source_file_id ? ` · Origen #${file.source_file_id}` : '');
      const path = document.createElement('details'), summary = document.createElement('summary'), key = document.createElement('small');
      summary.textContent = 'Ruta del archivo'; key.textContent = file.key; path.append(summary, key);
      const read = document.createElement('button'); read.type = 'button'; read.className = 'btn btn-sm btn-outline-primary mr-2'; read.textContent = 'Ver / leer';
      const textExtensions = /\.(json|txt|md|csv|tsv|log|srt|vtt|xml|ya?ml|php|js|ts|py|sql|html|css|sh|ini|conf)$/i;
      if (textExtensions.test(file.key)) read.addEventListener('click', () => this.read(file));
      else read.addEventListener('click', () => window.open(this.url('read',file.id), '_blank', 'noopener'));
      const download = document.createElement('a'); download.className = 'btn btn-sm btn-outline-secondary'; download.textContent = 'Descargar';
      download.href = this.url('download',file.id); download.target = '_blank'; download.rel = 'noopener';
      row.append(name,meta,path,read,download); this.list.append(row);
    }
    async read(file) {
      const ticket = ++this.serial;
      this.preview.hidden = false; this.preview.textContent = 'Leyendo ' + file.name + '…';
      try {
        const response = await fetch(this.url('read',file.id), {cache:'no-store'}), data = await response.json();
        if(ticket !== this.serial || !this.dialog.open) return;
        if(!response.ok || !data.ok) throw new Error(data.error || 'No se pudo leer el archivo.');
        let text = data.text;
        if(/\.json$/i.test(file.key) && !data.partial) { try { text = JSON.stringify(JSON.parse(text),null,2); } catch (_) {} }
        this.preview.textContent = data.name + (data.partial ? '\nVista previa parcial: primer MB. Descarga el archivo para leerlo completo.' : '') + '\n\n' + text;
        this.preview.scrollIntoView({block:'nearest'});
      } catch(error) { if(ticket === this.serial) this.preview.textContent = error.message; }
    }
  }
  window.ConversationDataPanel = ConversationDataPanel;
})();
