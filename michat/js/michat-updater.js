(function(){
'use strict';
const open=document.getElementById('michat-about-open'),modal=document.getElementById('michat-about-modal');
if(!open||!modal)return;
const result=document.getElementById('michat-update-status'),details=document.getElementById('michat-update-details');
const check=document.getElementById('michat-update-check'),apply=document.getElementById('michat-update-apply');
const password=document.getElementById('michat-update-password');
let action='';
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
function show(s){result.textContent=s;}
open.addEventListener('click',()=>{modal.hidden=false;});
document.getElementById('michat-about-close')?.addEventListener('click',()=>{modal.hidden=true;});
async function read(response){const j=await response.json();if(!response.ok||!j.ok)throw new Error(j.error||'HTTP '+response.status);return j;}
check.addEventListener('click',async()=>{
 check.disabled=true;show('Consultando GitHub…');apply.hidden=true;
 try{
  const j=await read(await fetch('update.php',{credentials:'same-origin',cache:'no-store'}));
  details.textContent='Rama: '+j.branch+' | Instalado: '+j.local_commit.slice(0,12)+' | Disponible: '+j.remote_commit.slice(0,12)+' | Pendientes: '+j.behind;
  if(j.dirty)details.textContent+=' | Cambios de código: '+(j.dirty_files||[]).join(', ');
  if(j.dependency_changes||!j.dependencies_ready)details.textContent+=' | Dependencias requieren revisión manual';
  action=j.can_apply?'apply':'';
  apply.hidden=!action;
  apply.textContent='Actualizar ahora';
  show(!j.update_available?'MiChat está actualizado.':action?'Actualización disponible.':'Revisión manual requerida: cambios locales, dependencias o historial no seguro. No se utilizará stash.');
 }catch(e){show(e.message);}
 finally{check.disabled=false;}
});
apply.addEventListener('click',async()=>{
 if(!action||!password.value){show('Se requiere la contraseña de superusuario.');return;}
 const message='¿Actualizar código de MiChat desde origin/main sin mover dependencias?';
 if(!window.confirm(message))return;
 apply.disabled=true;show('Aplicando actualización…');
 try{
  const body=new URLSearchParams({csrf_token:csrf(),action,current_password:password.value});
  const j=await read(await fetch('update.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}));
  show(j.message||'Actualización aplicada.');details.textContent='Commit instalado: '+j.local_commit.slice(0,12);apply.hidden=true;password.value='';
 }catch(e){show(e.message);}
 finally{apply.disabled=false;}
});
})();
