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
        btn.textContent = (SAVEJSON.labels && SAVEJSON.labels.listen) ? SAVEJSON.labels.listen : 'Listen to summary';
        var styles = document.createElement('style');
        styles.textContent = '#savejson-voice-btn{position:fixed;right:16px;bottom:16px;padding:10px 14px;border:none;border-radius:6px;box-shadow:0 2px 6px rgba(0,0,0,.2);cursor:pointer;z-index:99999}';
        document.head.appendChild(styles);
        document.body.appendChild(btn);
        var speaking = false, utterance = null;
        function speak(){
            if (!SAVEJSON.tldr) { return; }
            utterance = new SpeechSynthesisUtterance(SAVEJSON.tldr);
            if (SAVEJSON.lang) { utterance.lang = SAVEJSON.lang; }
            utterance.rate = 1.0; utterance.pitch = 1.0;
            utterance.onend = function(){ speaking = false; btn.textContent = (SAVEJSON.labels && SAVEJSON.labels.listen) ? SAVEJSON.labels.listen : 'Listen to summary'; };
            speaking = true; btn.textContent = (SAVEJSON.labels && SAVEJSON.labels.stop) ? SAVEJSON.labels.stop : 'Stop';
            window.speechSynthesis.speak(utterance);
        }
        function stop(){ try { window.speechSynthesis.cancel(); } catch(e) {} speaking = false; btn.textContent = (SAVEJSON.labels && SAVEJSON.labels.listen) ? SAVEJSON.labels.listen : 'Listen to summary'; }
        btn.addEventListener('click', function(){
            if (!window.speechSynthesis || typeof window.SpeechSynthesisUtterance === 'undefined') {
                alert((SAVEJSON.labels && SAVEJSON.labels.unavailable) ? SAVEJSON.labels.unavailable : 'Speech synthesis is not available in this browser.'); return;
            }
            if (!speaking) { speak(); } else { stop(); }
        });
    });
})();