<?php
/**
 * Shared "working…" overlay for the SET:IQ / REQ:IQ plugin tabs.
 *
 * Every action here is a normal HTML form that the browser hands to PHP to
 * process server-side — pulling playlists, pushing the sequence list, or
 * importing the box's show can each take up to ~a minute (cloud round-trip
 * plus one FPP API call per playlist), leaving the page blank in the
 * meantime. This overlay fires the instant a form is submitted so it's
 * obvious something is happening, and clears itself when the next page
 * loads. The message is tailored to the button that was clicked.
 *
 * Pure inline CSS/JS, no external assets. Degrades gracefully: with JS off
 * the forms still submit exactly as before, just without the indicator.
 */
?>
<div id="setiq-busy" role="status" aria-live="polite" hidden>
  <div class="setiq-busy-card">
    <div class="setiq-busy-spinner" aria-hidden="true"></div>
    <div class="setiq-busy-bar"><span></span></div>
    <div class="setiq-busy-msg" id="setiq-busy-msg">Working...</div>
    <div class="setiq-busy-sub">This can take up to a minute. Please keep this page open.</div>
  </div>
</div>
<style>
#setiq-busy{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(15,17,21,.6)}
#setiq-busy[hidden]{display:none}
.setiq-busy-card{background:var(--iq-card,#fff);color:var(--iq-card-text,#1c1b19);border-radius:12px;padding:24px 28px;max-width:380px;width:90%;text-align:center;box-shadow:0 18px 50px -12px rgba(0,0,0,.55)}
.setiq-busy-spinner{width:34px;height:34px;margin:0 auto 14px;border-radius:50%;border:3px solid rgba(128,128,128,.3);border-top-color:var(--iq-set,#2faa5a);animation:setiq-busy-spin .8s linear infinite}
@keyframes setiq-busy-spin{to{transform:rotate(360deg)}}
.setiq-busy-bar{height:6px;border-radius:4px;background:rgba(128,128,128,.22);overflow:hidden;margin:4px 0 12px}
.setiq-busy-bar>span{display:block;height:100%;width:40%;border-radius:4px;background:var(--iq-set,#2faa5a);animation:setiq-busy-slide 1.1s ease-in-out infinite}
@keyframes setiq-busy-slide{0%{margin-left:-45%}100%{margin-left:100%}}
.setiq-busy-msg{font:700 15px system-ui,-apple-system,sans-serif}
.setiq-busy-sub{font:400 12.5px system-ui,-apple-system,sans-serif;opacity:.7;margin-top:6px}
</style>
<script>
(function(){
  var overlay=document.getElementById('setiq-busy');
  if(!overlay) return;
  var msg=document.getElementById('setiq-busy-msg');
  var pending='Working...';
  // The clicked submit button tailors the message.
  var btns=document.querySelectorAll('form button[type=submit]');
  for(var i=0;i<btns.length;i++){
    btns[i].addEventListener('click', function(){
      var name=this.name||'', val=(this.value||'').toLowerCase();
      pending = name==='pullone' ? 'Pulling this playlist...'
              : val==='sync'   ? 'Pushing your sequence list to IQ Studio...'
              : val==='pull'   ? 'Pulling playlists and schedules from SET:IQ...'
              : val==='import' ? 'Pushing playlists and schedules to SET:IQ...'
              : val==='check'  ? 'Checking SET:IQ for updates...'
              : val==='delete' ? 'Updating playlists...'
              : (val==='enable'||val==='disable'||val==='restart') ? 'Updating the REQ:IQ listener...'
              : 'Working...';
    });
  }
  var forms=document.querySelectorAll('form');
  for(var j=0;j<forms.length;j++){
    forms[j].addEventListener('submit', function(){
      msg.textContent=pending;
      overlay.hidden=false;
    });
  }
})();
</script>
