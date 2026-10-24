'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync(__dirname+'/../js/attachment-preparation.js','utf8');
async function run(responses,current=true,initialJobs){
 const calls=[],progress=[];
 const c={window:{},URLSearchParams,Date,JSON,Error,setTimeout:fn=>fn(),
  fetch:async(url,options)=>{calls.push({url,options});return {ok:true,json:async()=>responses.shift()};}};
 vm.createContext(c);vm.runInContext(source,c);
 let error=null;
 try{const options={sessionId:7,csrf:'token',query:'Analiza "video.mp4"',traceId:'trace-attachment-0001',isCurrent:()=>current,onProgress:jobs=>progress.push(jobs)};
 await (initialJobs?c.window.waitForAttachmentJobs(options,initialJobs):c.window.prepareAttachmentsForQuestion(options));}
 catch(e){error=e;}
 return {calls,progress,error};
}
(async()=>{
 const result=await run([{ok:true,available:true,jobs:[{id:5,status:'waiting',message:'AWS'}]},{ok:true,available:true,jobs:[{id:5,status:'pending',message:'Embeddings'}]},{ok:true,available:true,jobs:[{id:5,status:'ready',message:'Listo'}]}]);
 assert(!result.error);assert.equal(result.calls.length,3);assert.equal(result.calls[0].options.body.get('action'),'prepare');assert.equal(result.calls[1].options.body.get('job_ids'),'[5]');assert.equal(result.progress.length,3);
 const failure=await run([{ok:true,available:true,jobs:[{id:5,status:'failed',message:'Proveedor falló'}]}]);assert(failure.error.message.includes('Proveedor'));
 const partial=await run([{ok:true,available:true,jobs:[{id:5,status:'partial',message:'Cobertura parcial'}]}]);assert(!partial.error);assert.equal(partial.calls.length,1);
 const stale=await run([{ok:true,available:true,jobs:[{id:5,status:'waiting'}]}],false);assert(stale.error);assert.equal(stale.calls.length,1);
 const manual=await run([{ok:true,available:true,jobs:[{id:9,status:'ready'}]}],true,[{id:9,status:'pending',phase:'extract'}]);
 assert(!manual.error);assert.equal(manual.calls.length,1);assert.equal(manual.calls[0].options.body.get('action'),'advance');assert.equal(manual.calls[0].options.body.get('job_ids'),'[9]');
 console.log('Attachment preparation: durable wait, automatic resume, errors, partial evidence and session isolation passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
