<?php
// Incluído no <head> de cada página — aplica dark ANTES de renderizar
?>
<script>
(function(){
    var c = document.cookie.split(';').reduce(function(a, x) {
        var p = x.trim().split('=');
        return p[0] === 'norion_tema' ? decodeURIComponent(p[1]) : a;
    }, null);
    if (!c) {
        c = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    if (c === 'dark') {
        document.documentElement.classList.add('dark');
    }
})();
</script>