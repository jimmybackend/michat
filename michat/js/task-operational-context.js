(function(root,factory){
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  else root.TaskOperationalContext=api;
})(typeof globalThis!=='undefined'?globalThis:this,function(){
  'use strict';
  const terminal=new Set(['completed','failed','cancelled']);
  function parseUtc(value){
    if(!value)return null;
    const normalized=/[zZ]|[+-]\d\d:?\d\d$/.test(value)?value:String(value).replace(' ','T')+'Z';
    const date=new Date(normalized);
    return Number.isNaN(date.getTime())?null:date;
  }
  function dependencySatisfied(dependency){
    return dependency.condition==='terminal_any'
      ? terminal.has(dependency.depends_on_status)
      : dependency.depends_on_status==='completed';
  }
  function dateContext(task,now=new Date()){
    const scheduled=parseUtc(task.scheduled_at),due=parseUtc(task.due_at);
    return{
      scheduled,
      due,
      scheduledFuture:Boolean(scheduled&&scheduled>now&&!terminal.has(task.status)),
      overdue:Boolean(due&&due<now&&!terminal.has(task.status)),
    };
  }
  function situation(task,dependencies=[]){
    const current=task.current_step||null;
    if(task.status==='waiting_user')return{key:'human',label:'Requiere acción humana'};
    if(task.status==='waiting_dependency'){
      if(current?.step_type==='wait')return{key:'wait',label:'Espera temporal'};
      if(dependencies.length===0||dependencies.some(dependency=>!dependencySatisfied(dependency)))return{key:'blocked',label:dependencies.length?'Bloqueada por dependencias':'Bloqueada por dependencia o condición'};
      return{key:'waiting',label:'Dependencias satisfechas; esperando reanudación'};
    }
    if(task.status==='running')return{key:'active',label:'En ejecución'};
    if(task.status==='ready')return{key:'ready',label:'Ejecutable'};
    if(task.status==='pending')return{key:'pending',label:'Pendiente'};
    return{key:'terminal',label:task.status};
  }
  const boardColumns=[
    {key:'pending',label:'Pendientes',statuses:['pending','ready']},
    {key:'running',label:'En ejecución',statuses:['running']},
    {key:'human',label:'Requiere acción',statuses:['waiting_user']},
    {key:'waiting',label:'Esperando / bloqueadas',statuses:['waiting_dependency']},
    {key:'completed',label:'Completadas',statuses:['completed']},
    {key:'stopped',label:'Fallidas / canceladas',statuses:['failed','cancelled']},
    {key:'other',label:'Otros',statuses:[]},
  ];
  function boardColumn(status){
    return boardColumns.find(column=>column.statuses.includes(status))?.key||'other';
  }
  function groupBoard(tasks){
    const grouped=Object.fromEntries(boardColumns.map(column=>[column.key,[]]));
    for(const task of tasks)grouped[boardColumn(task.status)].push(task);
    return grouped;
  }
  return{parseUtc,dependencySatisfied,dateContext,situation,boardColumns,boardColumn,groupBoard};
});
