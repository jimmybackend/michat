'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync(__dirname+'/../js/chat.js','utf8');
const a=src.indexOf('async function processSessionFileAction('),b=src.indexOf('// =====================================================================\n// INSPECTOR',a);
const calls=[],toasts=[];let refreshes=0;
const c={currentSessionId:7,sessionAttachments:[{files3_id:31,drive_linked:true}],FILE_ENDPOINTS:{indexFile:'index_session_file.php'},driveKnowledgeCsrf:'csrf',FormData,console:{error(){}},esc:x=>String(x).replace(/</g,'&lt;'),
 showToast:(...args)=>toasts.push(args),changeDriveKnowledge:async()=>{},loadSessionAttachments:async()=>{refreshes++;},
 fetch:async(url,options)=>{calls.push({url,options});return {ok:true,status:202,json:async()=>({ok:false,pending:true,error:'OCR en proceso'})};}};
vm.createContext(c);vm.runInContext(src.slice(a,b),c);
(async()=>{
 const button={innerHTML:'original',disabled:false};await c.processSessionFileAction('index_session_file.php',31,'Indexar',button);
 assert.equal(calls[0].url,'drive_file_analysis.php');assert.equal(calls[0].options.body.get('csrf'),'csrf');
 assert.equal(toasts[0][2],'info');assert.equal(refreshes,1);assert.equal(button.innerHTML,'original');assert(!button.disabled);
 c.fetch=async()=>({ok:false,status:422,json:async()=>({ok:false,error:'No hay texto extraíble'})});
 await c.processSessionFileAction('index_session_file.php',31,'Indexar',button);
 assert.equal(refreshes,2,'A failed attempt refreshes its persisted status');assert.equal(toasts[1][2],'danger');
 c.fetch=async()=>{c.currentSessionId=8;return {ok:true,json:async()=>({ok:true,message:'Texto disponible'})};};
 await c.processSessionFileAction('index_session_file.php',31,'Indexar',button);
 assert.equal(refreshes,2,'Late completion must not refresh another chat');
 console.log('Selected file actions: canonical retry, CSRF, pending OCR, failure state and session isolation passed');
})().catch(e=>{console.error(e);process.exit(1);});
