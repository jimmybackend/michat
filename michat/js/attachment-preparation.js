(function(root) {
  'use strict';
  // File processing precedes prompt compilation and final model generation.
  root.prepareAttachmentsForQuestion = async function(options) {
    return root.waitForAttachmentJobs(options);
  };
  root.waitForAttachmentJobs = async function(options, initialJobs) {
    const request = async (action, ids) => {
      const body = new URLSearchParams({action, session_id:String(options.sessionId), csrf:options.csrf,
        query:options.query, trace_id:options.traceId || '', job_ids:JSON.stringify(ids || [])});
      const response = await fetch('attachment_processing.php',{method:'POST',credentials:'same-origin',body});
      const data = await response.json();
      if(!response.ok || !data.ok) throw new Error(data.error || 'No se pudo preparar el archivo');
      return data;
    };
    let data = initialJobs ? {available:true,jobs:initialJobs} : await request('prepare', []);
    if(!data.available) return data;
    const started = Date.now();
    while((data.jobs || []).some(job => !['ready','partial'].includes(job.status))) {
      if(!options.isCurrent()) throw new Error('La tarea del archivo permanece guardada en su conversación.');
      const failed = data.jobs.find(job => ['failed','blocked'].includes(job.status));
      if(failed) throw new Error(failed.message || 'El procesamiento requiere revisión.');
      options.onProgress(data.jobs);
      if(Date.now()-started > 30*60*1000) throw new Error('La tarea sigue guardada. El proveedor aún no terminó; vuelve a consultar esta conversación.');
      await new Promise(resolve=>setTimeout(resolve,1500));
      data = await request('advance',data.jobs.map(job=>job.id));
    }
    options.onProgress(data.jobs || []);
    return data;
  };
})(window);
