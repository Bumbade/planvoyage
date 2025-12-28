/* Theme UI glue: syncs a #theme-select <select> with switchTheme()
   - Select values: 'auto' | 'light' | 'dark'
   - 'auto' removes stored choice so system preference is used
*/
(function(){
    function applySelect(){
        var sel = document.getElementById('theme-select');
        if (!sel) return;
        var stored = localStorage.getItem('theme');
        sel.value = stored || 'auto';
    }

    document.addEventListener('DOMContentLoaded', function(){
        var sel = document.getElementById('theme-select');
        if (!sel) return;
        applySelect();
        sel.addEventListener('change', function(){
            var v = sel.value;
            if (v === 'auto') {
                // remove stored theme and reapply (themes.js will respect system)
                switchTheme('');
            } else {
                switchTheme(v);
            }
        });

        // update select when body class changes externally
        var mo = new MutationObserver(function(){ applySelect(); });
        mo.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    });
})();
