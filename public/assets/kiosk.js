let kioskCsrf=QUEUE.csrf;
async function refreshKioskSession(){const response=await fetch('/api/kiosk/session',{cache:'no-store',credentials:'same-origin'});const body=await response.json();if(!response.ok||!body.csrf)throw new Error(body.error||'Sesi kiosk tidak dapat diperbarui.');kioskCsrf=body.csrf;return kioskCsrf}
async function issueKioskTicket(serviceId,retry=true){const response=await fetch('/api/tickets',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':kioskCsrf},body:JSON.stringify({service_id:serviceId})});const body=await response.json().catch(()=>({error:'Respons server tidak valid.'}));if(response.status===419&&retry){await refreshKioskSession();return issueKioskTicket(serviceId,false)}if(!response.ok)throw new Error(body.error||'Tiket tidak dapat dibuat.');return body.data}
function bindKioskButtons(selector){document.querySelectorAll(selector).forEach(button=>button.addEventListener('click',async()=>{document.querySelectorAll(selector).forEach(x=>x.disabled=true);try{const ticket=await issueKioskTicket(button.dataset.id);location.href='/ticket/'+ticket.public_id}catch(error){alert(error.message);document.querySelectorAll(selector).forEach(x=>x.disabled=false)}}))}
bindKioskButtons('.service');
if(new URLSearchParams(location.search).get('directprint')==='1')localStorage.setItem('reka-direct-print','1');
bindKioskButtons('.kiosk-service');
setInterval(()=>refreshKioskSession().catch(()=>{}),10*60*1000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshKioskSession().catch(()=>{})});
const kioskClock=document.querySelector('#kiosk-clock');if(kioskClock){const updateClock=()=>kioskClock.textContent=new Date().toLocaleTimeString('id-ID',{hour12:false,timeZone:window.APP_TIMEZONE||'Asia/Jakarta'});updateClock();setInterval(updateClock,1000)}
