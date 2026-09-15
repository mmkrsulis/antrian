<button class="internal-chat-launcher" id="internal-chat-launcher" type="button" aria-label="Buka chat operator" aria-expanded="false" aria-controls="internal-chat-panel">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8M8 13h5"/></svg>
    <span class="internal-chat-badge" id="internal-chat-badge" hidden>0</span>
</button>
<section class="internal-chat-panel" id="internal-chat-panel" aria-label="Chat operator" hidden>
    <header><div><b>Pesan</b><small>Chat internal petugas</small></div><button type="button" id="internal-chat-close" aria-label="Tutup chat">×</button></header>
    <div class="internal-chat-list-view" id="internal-chat-list-view">
        <div class="internal-chat-list-heading"><b>Percakapan</b><span id="internal-chat-list-status">Menghubungkan…</span></div>
        <div class="internal-chat-conversations" id="internal-chat-conversations" role="list"></div>
    </div>
    <div class="internal-chat-thread-view" id="internal-chat-thread-view" hidden>
        <div class="internal-chat-thread-header"><button type="button" id="internal-chat-back" aria-label="Kembali ke daftar percakapan">‹</button><span class="internal-chat-thread-avatar" id="internal-chat-thread-avatar">G</span><div><b id="internal-chat-thread-name">Grup Semua</b><small id="internal-chat-thread-presence">Semua petugas</small></div></div>
        <div class="internal-chat-status" id="internal-chat-status">Menghubungkan…</div>
        <div class="internal-chat-messages" id="internal-chat-messages" role="log" aria-live="polite" aria-relevant="additions"></div>
        <form id="internal-chat-form"><label class="sr-only" for="internal-chat-input">Tulis pesan</label><textarea id="internal-chat-input" maxlength="1000" rows="1" placeholder="Tulis pesan…" required></textarea><button type="submit" aria-label="Kirim pesan"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4zM22 2 11 13"/></svg></button></form>
    </div>
</section>
<script>window.INTERNAL_CHAT={csrf:<?=json_encode(csrf_token())?>,userId:<?=json_encode((int)$chatUser['id'])?>};</script>
<script src="/assets/chat.js?v=<?=e((string)filemtime(__DIR__.'/../../public/assets/chat.js'))?>"></script>
