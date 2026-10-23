'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const page=fs.readFileSync(__dirname+'/../dashboard_viewer.php','utf8');
const a=page.indexOf('const monthFilter ='),b=page.indexOf('// Cargar al iniciar',a);
const container={innerHTML:''},canvas={},filter={value:'2026-10',addEventListener(){}};
const sandbox={document:{body:{style:{}},getElementById:id=>id==='dashboard-content'?container:id==='monthFilter'?filter:canvas,
  createElement(){return {textContent:'',get innerHTML(){return this.textContent.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}};}},window:{},URL,console};
vm.createContext(sandbox);vm.runInContext(page.slice(a,b),sandbox);
sandbox.renderDashboard({tokens:{total:150,cost:1.244567,by_phase:{respond:125,scout:25}},models:[{model_id:'unknown-model',usage_count:1,total_input:100,total_output:25,total_cost:1.234567,phases:{respond:{count:1}}}],sessions:[],ladder:[],cost_note:'Estimación registrada',warnings:['Detalle opcional no disponible']});
assert(container.innerHTML.includes('$1.244567'));
assert(container.innerHTML.includes('$1.234567'));
assert(container.innerHTML.includes('unknown-model'));
assert(container.innerHTML.includes('scout'));
assert(container.innerHTML.includes('Estimación registrada'));
assert(container.innerHTML.includes('Detalle opcional no disponible'));
assert(!container.innerHTML.includes('undefined'));
// No Chart global is provided: a failed CDN must not hide the cost tables.
console.log('Dashboard UI: recorded costs, unknown models, all phases, warnings and unavailable chart CDN passed');
