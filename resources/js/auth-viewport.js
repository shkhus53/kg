/**
 * KG Attendance — keyboard-aware auth form (login/register).
 *
 * Scope: only runs on a form marked [data-keyboard-aware-form]. Every other
 * page is unaffected.
 *
 * Problem this fixes: the guest layout centers its card with a fixed-height
 * flex box (`min-h-screen` + `justify-center`). On Android (mobile Chrome
 * and, notably, the app's own WebView wrapper), opening the virtual
 * keyboard shrinks the VISUAL viewport but not the LAYOUT viewport that
 * flex centering is computed against — so the lower half of the form
 * (typically the Password field) ends up sitting behind the keyboard with
 * no way to scroll to it, since the page was never scrollable to begin
 * with.
 *
 * Fix: native platform APIs only, no new dependency.
 *  1. Watch `window.visualViewport` (falls back gracefully where it's
 *     unavailable, e.g. older WebViews) to detect a keyboard-sized shrink,
 *     and toggle a class on <html> that switches the guest layout from
 *     "centered" to "top-aligned + scrollable" — see the `.kg-kbd-open`
 *     rule in app.css. This is what actually makes scrolling possible at
 *     all; it is not a padding hack, it changes the layout mode.
 *  2. On focusing any field in the form, after the keyboard's open
 *     animation has had time to settle, check whether the field is still
 *     within the visible visual-viewport region and scroll it into view
 *     only if it is not — avoiding scroll-jank on fields that are already
 *     visible.
 */
(function () {
    const form = document.querySelector('[data-keyboard-aware-form]');
    if (!form) return;

    const root = document.documentElement;
    let resizeTimer = null;

    function markKeyboardState() {
        if (!window.visualViewport) return;

        // A shrink of more than ~15% of the layout viewport height is
        // treated as "keyboard open" — a debounced heuristic, since
        // Android doesn't fire a dedicated keyboard-open/close event.
        const shrunk = window.visualViewport.height < window.innerHeight * 0.85;
        root.classList.toggle('kg-kbd-open', shrunk);
    }

    function scheduleViewportCheck() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(markKeyboardState, 100);
    }

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', scheduleViewportCheck);
        window.visualViewport.addEventListener('scroll', scheduleViewportCheck);
    }

    function isFieldHidden(field) {
        if (!window.visualViewport) return true; // no API to check — assume worst case, scrollIntoView is cheap

        const rect = field.getBoundingClientRect();
        const visibleTop = window.visualViewport.offsetTop;
        const visibleBottom = visibleTop + window.visualViewport.height;

        return rect.top < visibleTop || rect.bottom > visibleBottom;
    }

    function bringFieldIntoView(field) {
        // Give the keyboard's open animation time to finish before
        // measuring/scrolling — acting mid-animation causes visible jank.
        setTimeout(() => {
            if (isFieldHidden(field)) {
                field.scrollIntoView({block: 'center', behavior: 'smooth'});
            }
        }, 250);
    }

    form.querySelectorAll('input').forEach((field) => {
        field.addEventListener('focus', () => {
            markKeyboardState();
            bringFieldIntoView(field);
        });
    });

    // Enter on the ITS field moves to Password instead of doing nothing —
    // a small native-feeling touch, not a validation or auth change.
    const itsField = form.querySelector('#its_number');
    const passwordField = form.querySelector('#password');
    if (itsField && passwordField) {
        itsField.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                passwordField.focus();
            }
        });
    }
})();
