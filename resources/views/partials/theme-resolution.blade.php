<script>
    (function () {
        var stored = null;
        try {
            stored = localStorage.getItem('temari-theme');
        } catch (e) {}
        var resolved = stored === 'light' || stored === 'dark'
            ? stored
            : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.dataset.theme = resolved;
        document.documentElement.style.colorScheme = resolved;
    })();
</script>
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b1017">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#f1f5f8">
