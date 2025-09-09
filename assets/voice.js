(function(){
    function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
    ready(function(){
        if (!window.speechSynthesis || typeof window.SpeechSynthesisUtterance === 'undefined') {
            console.warn('[SAVEJSON] Speech synthesis not available.');
            return;
        }
        if (!window.SAVEJSON || !SAVEJSON.tldr) { return; }
        var btn = document.createElement('button');
        btn.id = 'savejson-voice-btn';
        btn.type = 'button';
        btn.setAttribute('aria-pressed', 'false');
        btn.setAttribute('aria-live', 'polite');
        btn.textContent = (SAVEJSON.labels && SAVEJSON.labels.listen) ? SAVEJSON.labels.listen : 'Listen to summary';
        var styles = document.createElement('style');
        styles.textContent = '#savejson-voice-btn{position:fixed;right:16px;bottom:16px;padding:10px 14px;border:none;border-radius:6px;box-shadow:0 2px 6px rgba(0,0,0,.2);cursor:pointer;z-index:99999}#savejson-voice-progress{position:fixed;right:16px;bottom:56px;height:4px;background:#ddd;width:200px;border-radius:2px;overflow:hidden}#savejson-voice-progress>span{display:block;height:100%;background:#3b82f6;width:0%}';
        document.head.appendChild(styles);
        var progress = document.createElement('div');
        progress.id = 'savejson-voice-progress';
        progress.innerHTML = '<span></span>';
        document.body.appendChild(progress);
        document.body.appendChild(btn);
        var bar = progress.querySelector('span');
        var speaking = false, paused = false, utterance = null;
        var text = (''+SAVEJSON.tldr).trim();
        function setProgress(p){ if (bar) { bar.style.width = Math.max(0, Math.min(100, p)) + '%'; } }
        function reset(){ speaking = false; paused = false; btn.setAttribute('aria-pressed','false'); btn.textContent = (SAVEJSON.labels && SAVEJSON.labels.listen) ? SAVEJSON.labels.listen : 'Listen to summary'; setProgress(0); }
        function speak(){
            if (!text) { return; }
            utterance = new SpeechSynthesisUtterance(text);
            if (SAVEJSON.lang) { utterance.lang = SAVEJSON.lang; }
            utterance.rate = 1.0; utterance.pitch = 1.0;
            utterance.onboundary = function(e){ if (typeof e.charIndex === 'number' && text.length) { setProgress((e.charIndex / text.length) * 100); } };
            utterance.onend = function(){ reset(); };
            speaking = true; paused = false; btn.setAttribute('aria-pressed','true'); btn.textContent = (SAVEJSON.labels && SAVEJSON.labels.stop) ? SAVEJSON.labels.stop : 'Stop';
            try { window.speechSynthesis.cancel(); } catch(e) {}
            window.speechSynthesis.speak(utterance);
        }
        function stop(){ try { window.speechSynthesis.cancel(); } catch(e) {} reset(); }
        function toggle(){
            if (!window.speechSynthesis || typeof window.SpeechSynthesisUtterance === 'undefined') {
                alert((SAVEJSON.labels && SAVEJSON.labels.unavailable) ? SAVEJSON.labels.unavailable : 'Speech synthesis is not available in this browser.'); return;
            }
            if (!speaking) { speak(); return; }
            // speaking
            if (!paused) {
                try { window.speechSynthesis.pause(); paused = true; btn.textContent = 'Resume'; } catch(e) { stop(); }
            } else {
                try { window.speechSynthesis.resume(); paused = false; btn.textContent = (SAVEJSON.labels && SAVEJSON.labels.stop) ? SAVEJSON.labels.stop : 'Stop'; } catch(e) { stop(); }
            }
        }
        btn.addEventListener('click', toggle);
    });
})();
