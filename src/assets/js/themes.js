/* Theme loader and switcher
   - Reads `theme` from localStorage (values: "light" | "dark" | "")
   - Applies `theme-<name>` class to <body>
   - Exposes `switchTheme(name)` global to change theme
*/
(function(){
    var KEY = 'theme';
    var _extrasLoaded = false;
    function _scriptBase(){
        // derive base from this script's src so assets resolve when app is in subfolder
        var scripts = document.getElementsByTagName('script');
        for (var i=0;i<scripts.length;i++){
            var s = scripts[i];
            if (s.src && s.src.match(/themes\.js($|\?)/)){
                return s.src.replace(/themes\.js.*$/,'');
            }
        }
        return '';
    }

    function _loadExtras(){
        if (_extrasLoaded) return;
        var base = _scriptBase();
        var src = (base || '') + 'theme-dark-extras.js';
        var s = document.createElement('script'); s.src = src; s.async = true; s.id = 'theme-dark-extras-loader';
        document.body.appendChild(s);
        _extrasLoaded = true;
    }

    function _removeExtras(){
        var el = document.getElementById('theme-dark-extras-overlay');
        if (el) el.parentNode.removeChild(el);
        var loader = document.getElementById('theme-dark-extras-loader');
        if (loader) loader.parentNode.removeChild(loader);
        _extrasLoaded = false;
    }

    function apply(name){
        // remove existing theme-* classes
        document.body.className = document.body.className.replace(/\btheme-[^\s]+\b/g,'').trim();
        if (name) document.body.classList.add('theme-' + name);
        // lazy load extras for dark theme
        if (name === 'dark') {
            _loadExtras();
        } else {
            _removeExtras();
        }
    }

    window.switchTheme = function(name){
        if (!name) {
            localStorage.removeItem(KEY);
            apply('');
            return;
        }
        localStorage.setItem(KEY, name);
        apply(name);
    };

    // on load apply stored theme (or prefer system dark if nothing set)
    document.addEventListener('DOMContentLoaded', function(){
        var stored = localStorage.getItem(KEY);
        if (!stored) {
            // respect system preference by default
            try {
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    stored = 'dark';
                } else {
                    stored = 'light';
                }
            } catch (e) {
                stored = 'light';
            }
        }
        apply(stored);
    });
})();
