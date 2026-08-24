'use strict';
const assert=require('node:assert/strict');
const context=require('../js/task-operational-context.js');
const tasks=[
  {public_id:'pending',status:'pending',project_id:null,session_id:null,current_step:null},
  {public_id:'ready',status:'ready'},
  {public_id:'running',status:'running'},
  {public_id:'human',status:'waiting_user'},
  {public_id:'dependency',status:'waiting_dependency'},
  {public_id:'completed',status:'completed'},
  {public_id:'failed',status:'failed'},
  {public_id:'cancelled',status:'cancelled'},
  {public_id:'unknown',status:'future_unknown'},
];
const original=JSON.stringify(tasks),grouped=context.groupBoard(tasks);
assert.deepEqual(Object.fromEntries(context.boardColumns.map(column=>[column.key,grouped[column.key].length])),{
  pending:2,running:1,human:1,waiting:1,completed:1,stopped:2,other:1,
});
assert.equal(grouped.other[0].status,'future_unknown');
assert.equal(grouped.pending[0].project_id,null);
assert.equal(grouped.pending[0].session_id,null);
assert.equal(grouped.pending[0].current_step,null);
assert.equal(JSON.stringify(tasks),original,'agrupar no muta Tasks ni estados');
console.log('PASS Task board grouping behavior');
