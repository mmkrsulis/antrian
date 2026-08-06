let last=Number(localStorage.getItem('queue-last-event')||0),audioEnabled=true,speaking=false,queue=[],indonesianVoice=null,mediaSignature='',headerSignature='',mediaPlayback=null,announcementGeneration=0,activeAnnouncementAudio=null;
const number=document.querySelector('#number'),counter=document.querySelector('#counter'),recent=document.querySelector('#recent'),latestCalled=document.querySelector('#latest-called'),serviceLeft=document.querySelector('#service-left'),serviceRight=document.querySelector('#service-right'),mediaBox=document.querySelector('#display-media'),announcementPlayer=document.querySelector('#announcement-player'),audioButton=document.querySelector('#enable-display-audio');
const digitWords={0:'nol',1:'satu',2:'dua',3:'tiga',4:'empat',5:'lima',6:'enam',7:'tujuh',8:'delapan',9:'sembilan'};
const letterWords={A:'a',B:'be',C:'ce',D:'de',E:'e',F:'ef',G:'ge',H:'ha',I:'i',J:'je',K:'ka',L:'el',M:'em',N:'en',O:'o',P:'pe',Q:'ki',R:'er',S:'es',T:'te',U:'u',V:'ve',W:'we',X:'eks',Y:'ye',Z:'zet'};
const esc=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
const measureContext=document.createElement('canvas').getContext('2d');
function fitText(element,min,max,padding=36){if(!element?.parentElement||!measureContext)return;const style=getComputedStyle(element),available=Math.max(1,element.parentElement.clientWidth-padding);measureContext.font=`${style.fontWeight} ${max}px ${style.fontFamily}`;const required=Math.max(1,measureContext.measureText(element.textContent||'').width);element.style.fontSize=`${Math.max(min,Math.min(max,Math.floor(max*available/required)))}px`}
function fitCallStage(){requestAnimationFrame(()=>{fitText(number,18,48,42);fitText(counter,12,24,36)})}
function ticketInIndonesian(value){return String(value).toUpperCase().split('').filter(char=>char!=='-'&&char!==' ').map(char=>digitWords[char]??letterWords[char]??char).join(', ')}
function loadVoice(){const voices=speechSynthesis.getVoices();indonesianVoice=voices.find(v=>v.lang.toLowerCase()==='id-id')||voices.find(v=>v.lang.toLowerCase().startsWith('id'))||null}
function prepareAnnouncementPlayer(){announcementPlayer.defaultMuted=false;announcementPlayer.muted=false;announcementPlayer.volume=1}
function enableAudio(){audioEnabled=true;audioButton.hidden=true;audioButton.classList.remove('audio-error');loadVoice();prepareAnnouncementPlayer();announcementPlayer.src='/audio/id/simple_notification.wav';announcementPlayer.play().then(()=>{announcementPlayer.onended=()=>{announcementPlayer.onended=null;speakNext()}}).catch(()=>{audioEnabled=false;audioButton.hidden=false;audioButton.classList.add('audio-error');audioButton.textContent='Aktifkan Audio Panggilan'})}
loadVoice();speechSynthesis.onvoiceschanged=loadVoice;
audioButton.addEventListener('click',enableAudio);
document.querySelector('#start-fullscreen-display')?.addEventListener('click',async event=>{enableAudio();try{await document.documentElement.requestFullscreen()}catch{}event.currentTarget.remove()});
function finishAnnouncement(){document.querySelector('.display-center').classList.remove('announcing');speaking=false;speakNext()}
function browserSpeechFallback(item){const utter=new SpeechSynthesisUtterance(`Nomor antrean, ${ticketInIndonesian(item.ticket_number)}, silakan menuju ${item.counter_name||'loket pelayanan'}`);utter.lang='id-ID';if(indonesianVoice)utter.voice=indonesianVoice;utter.rate=.78;utter.pitch=1;utter.volume=1;utter.onend=()=>setTimeout(finishAnnouncement,2200);utter.onerror=()=>setTimeout(finishAnnouncement,2200);speechSynthesis.speak(utter)}
const recordedDigits={0:'nol',1:'satu',2:'dua',3:'tiga',4:'empat',5:'lima',6:'enam',7:'tujuh',8:'delapan',9:'sembilan'};
function recordedTokens(value){return String(value).toLowerCase().split('').filter(char=>/[a-z0-9]/.test(char)).map(char=>`/audio/id/${recordedDigits[char]||char}.wav`)}
function playRecordedAnnouncement(item,generation){const counterValue=String(item.counter_name||'').replace(/^loket\s*/i,'')||'pelayanan',clips=['/audio/id/simple_notification.wav','/audio/id/antrian.wav',...recordedTokens(item.ticket_number),'/audio/id/loket.wav',...recordedTokens(counterValue)];return new Promise((resolve,reject)=>{let index=0;const next=()=>{if(generation!==announcementGeneration){reject(new Error('cancelled'));return}if(index>=clips.length){activeAnnouncementAudio=null;resolve();return}prepareAnnouncementPlayer();announcementPlayer.src=clips[index++];activeAnnouncementAudio=announcementPlayer;announcementPlayer.onended=next;announcementPlayer.onerror=()=>reject(new Error('audio-file-error'));announcementPlayer.play().catch(reject)};next()})}
function interruptForRecall(item){announcementGeneration++;if(activeAnnouncementAudio){activeAnnouncementAudio.pause();activeAnnouncementAudio.currentTime=0;activeAnnouncementAudio=null}speechSynthesis.cancel();speaking=false;document.querySelector('.display-center').classList.remove('announcing');queue.unshift(item)}
function speakNext(){if(speaking||!queue.length||!audioEnabled)return;speaking=true;const item=queue.shift(),generation=++announcementGeneration;number.textContent=item.ticket_number;counter.textContent=(item.counter_name||'Loket').toUpperCase();fitCallStage();document.querySelector('.display-center').classList.add('announcing');playRecordedAnnouncement(item,generation).then(()=>{if(generation===announcementGeneration)setTimeout(()=>{if(generation===announcementGeneration)finishAnnouncement()},1800)}).catch(error=>{if(generation!==announcementGeneration||error.message==='cancelled')return;if(error.name==='NotAllowedError'){audioEnabled=false;speaking=false;queue.unshift(item);document.querySelector('.display-center').classList.remove('announcing');audioButton.hidden=false;return}browserSpeechFallback(item)})}
function serviceCard(item){return `<article class="display-service" style="--service:${esc(item.color)}"><h3>${esc(item.name)}</h3><div><span><b>${esc(item.current_number||'—')}</b><small>NOMOR</small></span><span><b>${esc(item.counter_name||'—')}</b><small>LOKET</small></span></div><footer>Jumlah antrean <b>${Number(item.waiting)||0}</b></footer></article>`}
function youtubeId(url){try{const parsed=new URL(url);if(parsed.hostname.includes('youtu.be'))return parsed.pathname.slice(1);if(parsed.hostname.includes('youtube.com'))return parsed.searchParams.get('v')||parsed.pathname.split('/').pop()}catch{}return''}
function savedMediaPlayback(signature){try{const state=JSON.parse(localStorage.getItem('display-media-playback')||'null');return state?.signature===signature?state:null}catch{return null}}
function persistMediaPlayback(){if(!mediaPlayback)return;const state={...mediaPlayback};if(state.type==='youtube'&&state.startedAt)state.time=(state.time||0)+(Date.now()-state.startedAt)/1000;delete state.startedAt;localStorage.setItem('display-media-playback',JSON.stringify(state))}
function renderMedia(media={}){
  const signature=JSON.stringify(media);
  if(signature===mediaSignature)return;
  persistMediaPlayback();
  mediaSignature=signature;
  mediaPlayback=null;
  mediaBox.innerHTML='';
  const playlist=Array.isArray(media.playlist)?media.playlist:[];
  const enabled=media.type==='playlist'?playlist.length>0:media.type!=='none'&&!!media.url;
  mediaBox.classList.toggle('has-media',enabled);
  document.querySelector('.display-center').classList.toggle('media-mode',enabled);
  if(!enabled)return;
  const saved=savedMediaPlayback(signature)||{};
  if(media.type==='youtube'){
    const id=youtubeId(media.url);
    if(!id)return;
    const elapsed=saved.savedAt?Math.max(0,(Date.now()-saved.savedAt)/1000):0;
    const start=Math.max(0,Math.floor(Number(saved.time||0)+elapsed));
    mediaPlayback={signature,type:'youtube',time:start,startedAt:Date.now()};
    mediaBox.innerHTML=`<iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?autoplay=1&mute=${media.muted?1:0}&controls=0&loop=1&playlist=${encodeURIComponent(id)}&start=${start}&playsinline=1&rel=0&iv_load_policy=3&disablekb=1" allow="autoplay; encrypted-media" referrerpolicy="strict-origin-when-cross-origin"></iframe>`;
    return;
  }
  const video=document.createElement('video');
  video.autoplay=true;
  video.loop=media.type!=='playlist';
  video.muted=!!media.muted;
  video.playsInline=true;
  video.controls=false;
  mediaBox.append(video);
  let index=media.type==='playlist'?Math.min(Number(saved.index||0),Math.max(playlist.length-1,0)):0;
  let resumeTime=Number(saved.time||0);
  mediaPlayback={signature,type:media.type,index,time:resumeTime,savedAt:Date.now()};
  const playCurrent=()=>{
    video.src=media.type==='playlist'?playlist[index]:media.url;
    video.addEventListener('loadedmetadata',()=>{
      if(resumeTime>0&&resumeTime<video.duration)video.currentTime=resumeTime;
      resumeTime=0;
      video.play().catch(()=>{});
    },{once:true});
  };
  video.addEventListener('timeupdate',()=>{mediaPlayback={signature,type:media.type,index,time:video.currentTime,savedAt:Date.now()}});
  if(media.type==='playlist'){
    video.addEventListener('ended',()=>{index=(index+1)%playlist.length;mediaPlayback.index=index;mediaPlayback.time=0;playCurrent()});
    video.addEventListener('error',()=>{if(playlist.length>1){index=(index+1)%playlist.length;mediaPlayback.index=index;mediaPlayback.time=0;playCurrent()}});
  }
  playCurrent();
}
function renderHeader(header={}){const signature=JSON.stringify(header);if(signature===headerSignature)return;headerSignature=signature;const element=document.querySelector('#display-header'),imageMode=header.mode==='image'&&!!header.image_url;element.classList.toggle('image-header',imageMode);element.style.backgroundImage=imageMode?`url("${String(header.image_url).replace(/["\\]/g,'')}")`:'';if(imageMode){const probe=new Image();probe.onload=()=>{if(probe.naturalHeight)element.style.setProperty('--header-aspect',(probe.naturalWidth/probe.naturalHeight).toFixed(4))};probe.src=header.image_url}document.querySelector('#header-title').textContent=header.title||'Reka Queue Management';document.querySelector('#header-subtitle').textContent=header.subtitle||'Sistem Antrean Digital'}
async function poll(){try{const r=await fetch(`/api/display/events?key=${encodeURIComponent(DISPLAY_KEY)}&after=${last}`,{cache:'no-store'});const j=await r.json();const freshEvents=j.events.filter(e=>e.id>last);if(freshEvents.length){const newest=freshEvents[freshEvents.length-1];last=newest.id;localStorage.setItem('queue-last-event',last);queue=[];interruptForRecall(newest)}const latest=j.recent?.[0];latestCalled.innerHTML=latest?`<b>${esc(latest.ticket_number)}</b><span>${esc(latest.counter_name)}</span>`:'<b>---</b><span>Menunggu panggilan</span>';if(latest&&!speaking){number.textContent=latest.ticket_number;counter.textContent=(latest.counter_name||'Loket').toUpperCase();fitCallStage()}recent.innerHTML=j.recent.slice(1,4).map(x=>`<div><b>${esc(x.ticket_number)}</b><span>${esc(x.counter_name)}</span></div>`).join('');const midpoint=Math.ceil((j.summary||[]).length/2);serviceLeft.innerHTML=(j.summary||[]).slice(0,midpoint).map(serviceCard).join('');serviceRight.innerHTML=(j.summary||[]).slice(midpoint).map(serviceCard).join('');renderMedia(j.media);renderHeader(j.header);document.querySelector('#footer-text').textContent=j.footer_text||'Mohon menunggu nomor antrean Anda dipanggil';speakNext()}catch(e){}setTimeout(poll,2000)}
poll();setInterval(()=>clock.textContent=new Date().toLocaleTimeString('id-ID',{hour12:false,timeZone:window.APP_TIMEZONE||'Asia/Jakarta'}),1000);setInterval(()=>{if(mediaPlayback){mediaPlayback.savedAt=Date.now();persistMediaPlayback()}},5000);addEventListener('beforeunload',persistMediaPlayback);
addEventListener('resize',fitCallStage);
