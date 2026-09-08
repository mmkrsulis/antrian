<button class="internal-chat-launcher" id="internal-chat-launcher" type="button" aria-label="Buka chat operator" aria-expanded="false" aria-controls="internal-chat-panel">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8M8 13h5"/></svg>
    <span class="internal-chat-badge" id="internal-chat-badge" hidden>0</span>
</button>
<section class="internal-chat-panel" id="internal-chat-panel" aria-label="Chat operator" hidden>
    <header><div><b>Chat Internal</b><small>Umum atau pesan langsung</small></div><button type="button" id="internal-chat-close" aria-label="Tutup chat">×</button></header>
    <label class="internal-chat-recipient"><span>Kirim ke</span><select id="internal-chat-recipient"><option value="0">Ruang Umum · semua petugas</option></select></label>
    <div class="internal-chat-status" id="internal-chat-status">Menghubungkan…</div>
    <div class="internal-chat-messages" id="internal-chat-messages" role="log" aria-live="polite" aria-relevant="additions"></div>
    <form id="internal-chat-form"><label class="sr-only" for="internal-chat-input">Tulis pesan</label><textarea id="internal-chat-input" maxlength="1000" rows="1" placeholder="Tulis pesan…" required></textarea><button type="submit" aria-label="Kirim pesan"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4zM22 2 11 13"/></svg></button></form>
</section>
<script>window.INTERNAL_CHAT={csrf:<?=json_encode(csrf_token())?>,userId:<?=json_encode((int)$chatUser['id'])?>};</script>
<script src="/assets/chat.js?v=<?=e((string)filemtime(__DIR__.'/../../public/assets/chat.js'))?>"></script>
