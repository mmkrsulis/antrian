document.querySelectorAll('.service').forEach(button=>button.addEventListener('click',async()=>{
  document.querySelectorAll('.service').forEach(x=>x.disabled=true);
  try { const response=await fetch('/api/tickets',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':QUEUE.csrf},body:JSON.stringify({service_id:button.dataset.id})}); const body=await response.json(); if(!response.ok) throw new Error(body.error); location.href='/ticket/'+body.data.public_id; }
  catch(error){ alert(error.message); document.querySelectorAll('.service').forEach(x=>x.disabled=false); }
}));
document.querySelectorAll('.kiosk-service').forEach(button=>button.addEventListener('click',async()=>{
  document.querySelectorAll('.kiosk-service').forEach(x=>x.disabled=true);
  try { const response=await fetch('/api/tickets',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':QUEUE.csrf},body:JSON.stringify({service_id:button.dataset.id})}); const body=await response.json(); if(!response.ok) throw new Error(body.error); location.href='/ticket/'+body.data.public_id; }
  catch(error){ alert(error.message); document.querySelectorAll('.kiosk-service').forEach(x=>x.disabled=false); }
}));
const kioskClock=document.querySelector('#kiosk-clock');if(kioskClock){const updateClock=()=>kioskClock.textContent=new Date().toLocaleTimeString('id-ID',{hour12:false});updateClock();setInterval(updateClock,1000)}
