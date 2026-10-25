'use strict';
const assert=require('assert'),vm=require('vm'),fs=require('fs');
function node(){return {children:[],handlers:{},textContent:'',hidden:false,open:false,value:'session',options:[{},{}],addEventListener(e,fn){this.handlers[e]=fn;},append(...items){this.children.push(...items);},replaceChildren(){this.children=[];},showModal(){this.open=true;},close(){this.open=false;this.handlers.close?.();},scrollIntoView(){}};}
const nodes={},calls=[];for(const key of ['Dialog','List','Status','Scope','Preview','More','Open','Close','Refresh','Title'])nodes['conversationData'+key]=node();
let scope={id:50,title:'Prueba',project:500},fail=false,partial=false;
const ctx={window:{open:(url)=>calls.push(url)},document:{getElementById:id=>nodes[id],createElement:node},URLSearchParams,
 fetch:async url=>{calls.push(url);return {ok:!fail,json:async()=>fail?{ok:false,error:'Acceso revocado'}:url.includes('mode=read')?{ok:true,name:'result.json',text:'{"dato":"<script>mal</script>"}',partial}:{ok:true,total:1,files:[{id:99,name:'<img src=x onerror=alert(1)>.json',key:'Data/result.json',size_bytes:1048576,role:'result',kind:'transcription',source_file_id:7}],next_offset:null,warnings:[]}};}};
vm.createContext(ctx);vm.runInContext(fs.readFileSync(__dirname+'/../js/conversation-data-panel.js','utf8'),ctx);
(async()=>{
 const panel=new ctx.window.ConversationDataPanel(()=>scope);
 await panel.open();assert(nodes.conversationDataDialog.open);assert(calls[0].includes('session_id=50'));assert(calls[0].includes('scope=session'));
 const row=nodes.conversationDataList.children[0];assert.equal(row.children[0].textContent,'<img src=x onerror=alert(1)>.json');assert(!row.children[0].innerHTML);assert(row.children[1].textContent.includes('1 MB'));
 assert(row.children[4].href.includes('mode=download'));await row.children[3].handlers.click();assert(nodes.conversationDataPreview.textContent.includes('<script>mal</script>'));assert(!nodes.conversationDataPreview.innerHTML,'JSON is rendered as inert text');
 partial=true;await panel.read({id:99,key:'Data/result.json',name:'result.json'});assert(nodes.conversationDataPreview.textContent.includes('parcial'));
 nodes.conversationDataScope.value='project';await nodes.conversationDataScope.handlers.change();assert(calls.at(-1).includes('scope=project'));
 fail=true;await panel.load();assert.equal(nodes.conversationDataStatus.textContent,'Acceso revocado');assert.equal(nodes.conversationDataList.children.length,0,'Failed refresh clears stale files');
 scope={id:52,project:null};fail=false;await panel.open();assert(nodes.conversationDataScope.options[1].disabled);assert.equal(nodes.conversationDataScope.value,'session');
 scope={id:null};await panel.open();assert(nodes.conversationDataStatus.textContent.includes('Selecciona'));
 assert(!calls.some(x=>/analysis|processing|index_session|semantic/.test(x)),'Opening and reading panel never starts preparation');
 console.log('OK conversation data panel: scope, read/download, inert JSON, units, revocation and no processing');
})().catch(e=>{console.error(e);process.exitCode=1;});
