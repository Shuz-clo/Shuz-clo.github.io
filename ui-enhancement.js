(() => {
    const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
    const root = document.documentElement;
    const viewTransitionsSupported = typeof document.startViewTransition === 'function';
    let activeTransitionId = 0;

    window.runUITransition = (update, transition = 'content') => {
        if (typeof update !== 'function') return;
        if (motionPreference.matches || !viewTransitionsSupported) {
            update();
            return;
        }

        const transitionId = String(++activeTransitionId);
        root.dataset.uiTransition = transition;
        root.dataset.uiTransitionId = transitionId;
        const cleanup = () => {
            if (root.dataset.uiTransitionId !== transitionId) return;
            delete root.dataset.uiTransition;
            delete root.dataset.uiTransitionId;
        };
        const viewTransition = document.startViewTransition(update);
        viewTransition.finished.then(cleanup, cleanup);
        return viewTransition;
    };

    if (motionPreference.matches || !('IntersectionObserver' in window)) return;

    const revealSelector = 'main > .card, .pg > .cd, #positions > .position, .recent-form, .section-table, .sheet-row';
    const revealedElements = new Set();
    let staggerIndex = 0;

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            const element = entry.target;
            element.classList.add('ui-visible');
            observer.unobserve(element);
        });
    }, { threshold: 0.01, rootMargin: '0px 0px -24px 0px' });

    function observeElement(element) {
        if (revealedElements.has(element) || element.closest('#tables-wrapper')) return;
        revealedElements.add(element);
        element.classList.add('ui-reveal');
        element.style.setProperty('--ui-stagger', `${Math.min(staggerIndex++, 6) * 55}ms`);
        observer.observe(element);
    }

    function scan(node) {
        if (!(node instanceof Element) || node.closest('#tables-wrapper')) return;
        if (node.matches(revealSelector)) observeElement(node);
        node.querySelectorAll(revealSelector).forEach(observeElement);
    }

    document.querySelectorAll(revealSelector).forEach(observeElement);

    const mutations = new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(scan));
    });
    mutations.observe(document.body, { childList: true, subtree: true });
})();
