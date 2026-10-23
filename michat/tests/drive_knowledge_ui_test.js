'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync(__dirname+'/../js/chat.js','utf8');
const start=src.indexOf('// Drive links reuse the existing S3 object.'),end=src.indexOf('// === FIN DEL ARCHIVO',start);
assert(start>0&&end>start);
function node(){return {value:'',checked:false,children:[],textContent:'',handlers:{},addEventListener(e,f){this.handlers[e]=f;},append(x){this.children.push(x);},replaceChildren(){this.children=[];}};}
const nodes={};for(const name of ['Open','Search','More','Submit','Query','Extensions','From','To','Ai','Results','Filters','Analysis'])nodes['driveKnowledge'+name]=node();
nodes.driveKnowledgeQuery.value='contratos PDF';
const calls=[],toasts=[];let refreshes=0;
const c={document:{getElementById:id=>nodes[id],createElement:node},currentSessionId:7,URLSearchParams,FormData,
  closeChatFilesDrawer(){},loadSessions:async()=>{},createSession:async()=>({id:9}),selectSession:async id=>{c.currentSessionId=id;},esc:x=>x,showToast:(...x)=>toasts.push(x),loadSessionAttachments:async()=>{refreshes++;},
  fetch:async(url,options={})=>{calls.push({url,options});
    if(url==='drive_file_analysis.php')return {ok:true,json:async()=>({ok:true,filename:'contrato.pdf',summary:'Resumen guardado',coverage:'partial',reused:true,interaction_count:2,chunks:1,sidecar_saved:true})};
    const search=options.body?.get('action')==='search_ai'||url.includes('?');
    return {ok:true,json:async()=>search?{ok:true,csrf:'csrf',files:[{id_:31,Nombre:'contrato.pdf',Ruta:'Data/',Tamano:123,Fecha:'2026-10-01'}],filters:{extensions:['pdf']},has_more:true,next_cursor:31,plan:'server-plan'}:{ok:true}};
  }};
vm.createContext(c);vm.runInContext(src.slice(start,end),c);
(async()=>{
  assert(nodes.driveKnowledgeOpen.handlers.click&&nodes.driveKnowledgeSearch.handlers.submit);
  await c.searchDriveMetadata(false);
  assert.equal(calls.length,1);assert(calls[0].url.includes('drive_knowledge.php?'));
  const row=nodes.driveKnowledgeResults.children[0],button=row.children[1];
  assert(row.children[0].textContent.includes('contrato.pdf'));
  assert(!calls.some(x=>x.url==='drive_file_analysis.php'),'Browsing must not analyze/index');
  await button.handlers.click();
  assert.equal(calls[1].options.body.get('file_id'),'31');assert.equal(calls[1].options.body.get('csrf'),'csrf');
  assert.equal(calls[2].url,'drive_file_analysis.php');assert.equal(refreshes,1);
  assert(nodes.driveKnowledgeAnalysis.textContent.includes('reutilizado'));
  assert(nodes.driveKnowledgeAnalysis.children[0].href.includes('action=download'));
  c.currentSessionId=8;await button.handlers.click();assert.equal(calls.length,3,'Stale search cannot attach into another chat');
  await c.unlinkDriveKnowledge(31);assert.equal(calls[3].options.body.get('action'),'unlink');
  nodes.driveKnowledgeAi.checked=true;await c.searchDriveMetadata(false);
  assert.equal(calls[4].options.method,'POST');assert.equal(calls[4].options.body.get('action'),'search_ai');
  await c.searchDriveMetadata(true);assert(calls[5].url.includes('plan=server-plan'));assert(!calls[5].options.method,'Paging reuses filters without another AI POST');
  c.currentSessionId=null;await c.searchDriveMetadata(false);assert.equal(calls.length,6,'AI requires an authenticated conversation for usage attribution');
  nodes.driveKnowledgeAi.checked=false;await c.searchDriveMetadata(false);assert.equal(calls.length,7,'Literal browsing works without a conversation');
  const newChatButton=nodes.driveKnowledgeResults.children[0].children[1];
  await newChatButton.handlers.click();assert.equal(c.currentSessionId,9);const afterCreate=calls.length;
  await newChatButton.handlers.click();assert(calls.length>afterCreate,'Results from browsing before chat creation remain selectable in the auto-created chat');
  assert.equal(toasts.length,0);
  assert(!src.slice(start,end).includes('renderMessages('),'Drive browser never reloads chat');
  console.log('Drive metadata browser: modal, metadata-only search, CSRF, explicit analysis, cache download, pagination and session isolation passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
