<style>
    @keyframes page-content-enter {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    html.page-transition-enabled main {
        animation: page-content-enter 180ms ease-out both;
        transition: opacity 140ms ease;
    }

    html.page-transition-enabled main.page-transition-leaving {
        animation: none;
        opacity: 0;
        transition: opacity 140ms ease-in;
    }

    @media (prefers-reduced-motion: reduce) {
        html.page-transition-enabled main {
            animation: none;
            transition: none;
        }

        html.page-transition-enabled main.page-transition-leaving {
            opacity: 1;
            transition: none;
        }
    }
</style>
<script>
    document.documentElement.classList.add('page-transition-enabled');

    document.addEventListener('click', function (event) {
        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const link = event.target instanceof Element
            ? event.target.closest('a')
            : null;
        if (
            !link ||
            (link.target && link.target !== '_self') ||
            link.hasAttribute('download') ||
            link.relList.contains('external')
        ) {
            return;
        }

        const destination = new URL(link.href, window.location.href);
        if (
            destination.origin !== window.location.origin ||
            (
                destination.pathname === window.location.pathname &&
                destination.search === window.location.search
            )
        ) {
            return;
        }

        const main = document.querySelector('main');
        if (!main) {
            return;
        }

        event.preventDefault();
        main.classList.add('page-transition-leaving');
        window.setTimeout(function () {
            window.location.assign(destination.href);
        }, 140);
    });
</script>
