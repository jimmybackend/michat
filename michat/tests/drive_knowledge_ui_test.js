'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const src = fs.readFileSync(__dirname + '/../js/chat.js','utf8');
const start = src.indexOf('// Drive links reuse the existing S3 object.');
const end = src.indexOf('// === FIN DEL ARCHIVO',start);
assert(start > 0 && end > start && src.indexOf('}); // cierra DOMContentLoaded') > end, 'Drive events live within chat closure');
const handlers = {};
const results = {textContent:'',children:[],replaceChildren(){this.children=[];},append(x){this.children.push(x);}};
const nodes = {driveKnowledgeSearch:{addEventListener(e,f){handlers[e]=f;}},driveKnowledgeResults:results,driveKnowledgeQuery:{value:'contrato'}};
const calls=[]; let refreshes=0;
const context = {
  document:{getElementById(id){return nodes[id];},createElement(){return {addEventListener(e,f){this.click=f;}};}},
  currentSessionId:7, URLSearchParams, FormData,
  fetch:async(url,options)=>{calls.push({url,options});return {ok:true,json:async()=>options.method==='POST'?{ok:true}:{ok:true,csrf:'test-token',files:[{id_:31,Nombre:'contrato.php',Ruta:'Data/'}]}};},
  loadSessionAttachments:async()=>{refreshes++;},showToast(){throw new Error('Unexpected toast');}
};
vm.createContext(context);
vm.runInContext(src.slice(start,end),context);
(async()=>{
  await handlers.submit({preventDefault(){}});
  assert.strictEqual(results.children.length,1);
  assert(results.children[0].textContent.includes('contrato.php'));
  await results.children[0].click();
  assert.strictEqual(calls[1].options.body.get('file_id'),'31');
  assert.strictEqual(calls[1].options.body.get('csrf'),'test-token');
  assert.strictEqual(refreshes,1);
  context.currentSessionId=8;
  await results.children[0].click();
  assert.strictEqual(calls.length,2,'Old-session click cannot link into current conversation');
  await context.unlinkDriveKnowledge(31);
  assert.strictEqual(calls[2].options.body.get('action'),'unlink');
  assert.strictEqual(refreshes,2);
  console.log('Drive knowledge UI: search, link, CSRF, unlink and session isolation passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
