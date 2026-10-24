'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync(__dirname+'/../js/chat.js','utf8');
const start=source.indexOf('async function deleteSessionAttachment(');
const end=source.indexOf('\nasync function uploadSessionFiles',start);
const code=source.slice(start,end);
(async()=>{
  const changes=[],withdrawals=[],refresh=[];
  const c={sessionAttachments:[{id:4,filename:'privado.txt'}],currentSessionId:7,
    confirm:()=>true,changeDriveKnowledge:async(...args)=>changes.push(args),
    loadSessionAttachments:async id=>refresh.push(id),setStatus:()=>{},showToast:()=>{},
    deleteProjectSource:async id=>withdrawals.push(id)};
  vm.createContext(c);vm.runInContext(code,c);
  await c.deleteSessionAttachment(4);
  assert.deepEqual(changes,[[4,'unlink',7]]);assert.deepEqual(refresh,[7]);
  c.sessionAttachments=[{id:5,filename:'proyecto.pdf',knowledge_origin:'project',project_source_id:88}];
  await c.deleteSessionAttachment(5);assert.deepEqual(withdrawals,[88]);assert.equal(changes.length,1);
  c.confirm=()=>false;c.sessionAttachments=[{id:4,filename:'privado.txt'}];
  await c.deleteSessionAttachment(4);assert.equal(changes.length,1);
  console.log('File removal UI: conversation detach, project withdrawal and cancellation passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
