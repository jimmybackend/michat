'use strict';
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync(__dirname + '/../js/chat.js', 'utf8');
function section(start, end) {
  const a = source.indexOf(start), b = source.indexOf(end, a);
  assert(a >= 0 && b > a, start);
  return source.slice(a, b);
}
function deferred() { let resolve; const promise = new Promise(r => { resolve = r; }); return {promise, resolve}; }
function harness() {
  const requests = [], pending = new Map(), statuses = [], errors = [];
  const search = {value:'', autofilled:false, matches(){return this.autofilled;}};
  const list = {innerHTML:'', scrollTop:42, querySelector(){return null;}, querySelectorAll(selector) {
    if (selector !== '.sb-item') return [];
    return [...this.innerHTML.matchAll(/class="sb-item[^\"]*" data-id="(\d+)"/g)].map(m => ({
      getAttribute(){return m[1];}, querySelector(){return null;},
      addEventListener(type, fn){ if(type === 'click') this.click = fn; }
    }));
  }};
  // Retain the actual row click listeners installed by renderSessionsList.
  const query = list.querySelectorAll;
  list.querySelectorAll = function(selector) { const rows = query.call(this, selector); if(selector === '.sb-item') this.rows = rows; return rows; };
  const c = {
    sessions:[{id:'1',title:'Contrato',status:'open'},{id:2,title:'Impuestos',status:'open'}],
    sessionsRequestSerial:0, sessionSelectionSerial:0, sessionSearchQuery:'', currentSessionId:null,
    currentSessionStatus:'open', compareFilesSelected:[], currentMemoryScope:null, hasMoreSessions:false,
    el:{sbChatSearch:search,search,sbChatList:list,title:{textContent:''},messages:{innerHTML:''}},
    API:{messages:'messages',sessions:'sessions'}, URLSearchParams, console:{error:e=>errors.push(e)},
    setTimeout:fn=>fn(), setStatus:s=>statuses.push(s), getUserId:()=>'',
    esc:s=>String(s).replace(/</g,'&lt;').replace(/>/g,'&gt;'),
    toJSONorThrow:JSON.parse, syncTraceExplorerButton(){},updateCurrentSessionState(){},updateChatFilesDrawerContext(){},
    selectProject:async()=>{},discardEmptySession:async()=>false,
    loadSessionAttachments:async()=>{},loadAttachmentRagMode:async()=>{},wireCodeCopyButtons(){},
    renderMessages(messages){c.el.messages.innerHTML = messages.map(x=>x.content).join('|');},
    fetch(url) { requests.push(url); const key = new URLSearchParams(url.split('?')[1]).get('session_id');
      const d = deferred(); pending.set(key,d); return d.promise; }
  };
  vm.createContext(c);
  vm.runInContext(section('async function loadSessions()', '    async function discardEmptySession'), c);
  vm.runInContext(section('    async function selectSession(id)', '    window.selectSessionChat1'), c);
  function reply(id, content, ok=true) { pending.get(String(id)).resolve({ok,status:ok?200:500,text:async()=>JSON.stringify(ok?{ok:true,messages:[{content}]}:{ok:false,error:content})}); }
  return {c,search,list,requests,pending,reply,statuses,errors};
}
(async()=>{
  let h=harness(); h.c.renderSessionsList();
  assert.equal(h.list.rows.length,2);
  h.search.value='soporte@example.invalid'; h.c.renderSessionsList();
  assert.equal(h.list.rows.length,2,'Delayed DOM autofill must not hide conversations');
  h.search.value='contrato'; h.c.handleSessionSearchInput({});
  assert.equal(h.list.rows.length,1,'Explicit search still filters');
  assert.equal(h.requests.length,0,'Search must not refetch or replace the session collection');
  h.search.autofilled=true; h.search.value='soporte@example.invalid'; h.c.handleSessionSearchInput({});
  assert.equal(h.search.value,''); assert.equal(h.list.rows.length,2,'Autofill input events must not filter');
  h.search.autofilled=false;
  h.list.rows[0].click({target:{closest:()=>null}});
  assert.equal(h.c.currentSessionId,1); assert.equal(h.c.el.title.textContent,'Contrato','String ids resolve their real title');
  assert.equal(h.list.rows.length,2,'Click preserves the conversation list');
  h.reply(1,'Contenido real'); await new Promise(r=>setImmediate(r));
  assert.equal(h.c.el.messages.innerHTML,'Contenido real','Actual row listener loads and renders messages');

  h=harness(); const first=h.c.selectSession(1), second=h.c.selectSession(2);
  h.reply(2,'Segundo'); await second; h.reply(1,'Primero atrasado'); await first;
  assert.equal(h.c.el.messages.innerHTML,'Segundo','Slow previous response cannot overwrite the selected chat');
  assert.equal(h.c.currentSessionId,2);

  h=harness(); const stale=h.c.selectSession(1), latest=h.c.selectSession(2);
  h.reply(1,'<script>old error</script>',false); await stale;
  assert(!h.c.el.messages.innerHTML.includes('old error'),'Stale errors cannot replace the selected chat');
  assert.notEqual(h.statuses.at(-1),'','Stale request cannot clear current loading status');
  h.reply(2,'Actual'); await latest;

  h=harness(); h.c.selectProject=async()=>{throw new Error('project unavailable');};
  const independent=h.c.selectSession(1); h.reply(1,'Messages survive'); await independent;
  assert.equal(h.c.el.messages.innerHTML,'Messages survive','Project metadata failure must not block messages');
  h=harness(); const failure=h.c.selectSession(1); h.reply(1,'<img src=x onerror=alert(1)>',false); await failure;
  assert(h.c.el.messages.innerHTML.includes('&lt;img'),'Error details must be escaped');
  assert.equal(h.list.rows.length,2,'Message failure must preserve the sidebar');

  const page=fs.readFileSync(__dirname+'/../chat.php','utf8');
  assert(page.includes("hash_file('sha256', __DIR__ . '/js/chat.js')"),'Script cache key must follow its actual contents');
  assert(/type="search" id="sbChatSearch"/.test(page),'Search field must not look like a login field');
  assert(!source.includes("el.search.addEventListener('input', () => loadSessions())"),'No second network listener for the same search input');
  console.log('Conversation selection: autofill, row click, content, list preservation, rapid clicks, stale errors, project failure and cache invalidation passed');
})().catch(e=>{console.error(e);process.exit(1);});
