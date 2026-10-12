<script>
    (() => {
        const disableDragging = element => {
            if (element instanceof HTMLElement || element instanceof SVGElement) {
                element.draggable = false;
            }
        };

        document.querySelectorAll('*').forEach(disableDragging);
        document.addEventListener('dragstart', event => event.preventDefault(), true);

        new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node instanceof HTMLElement || node instanceof SVGElement) {
                        disableDragging(node);
                        node.querySelectorAll('*').forEach(disableDragging);
                    }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    })();
</script>
