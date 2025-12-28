/* Optional dark-theme extras: subtle vignette and reduced-brightness overlay
   This file is safe to load always; it activates only when `theme-dark` is present on <body>.
*/
(function(){
    var STYLE_ID = 'theme-dark-extras-style';
    var OVERLAY_ID = 'theme-dark-extras-overlay';

    function createStyle(){
        if (document.getElementById(STYLE_ID)) return;
        var css = '\n' +
            'body.theme-dark .dark-vignette { position: fixed; pointer-events: none; inset: 0; mix-blend-mode: multiply; opacity: 0.08; background: radial-gradient(circle at 50% 20%, rgba(0,0,0,0) 30%, rgba(0,0,0,0.6) 100%); z-index: 9998; }\n' +
            'body.theme-dark .dark-dim { position: fixed; pointer-events: none; inset: 0; background: linear-gradient(rgba(0,0,0,0.02), rgba(0,0,0,0.02)); z-index: 9997; }\n' +
            '';
        var s = document.createElement('style');
        s.id = STYLE_ID;
        s.appendChild(document.createTextNode(css));
        document.head.appendChild(s);
    }

    function addExtras(){
        if (document.getElementById(OVERLAY_ID)) return;
        var wrap = document.createElement('div');
        wrap.id = OVERLAY_ID;
        wrap.className = 'theme-dark-extras';
        var vign = document.createElement('div'); vign.className = 'dark-vignette';
        var dim = document.createElement('div'); dim.className = 'dark-dim';
        wrap.appendChild(dim);
        wrap.appendChild(vign);
        document.body.appendChild(wrap);
    }

    function removeExtras(){
        var el = document.getElementById(OVERLAY_ID);
        if (el) el.parentNode.removeChild(el);
    }

    function update(){
        if (document.body.classList.contains('theme-dark')) {
            createStyle();
            addExtras();
        } else {
            removeExtras();
        }
    }

    document.addEventListener('DOMContentLoaded', function(){
        update();
        // observe body class changes to toggle extras dynamically
        var mo = new MutationObserver(function(){ update(); });
        mo.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    });
})();
