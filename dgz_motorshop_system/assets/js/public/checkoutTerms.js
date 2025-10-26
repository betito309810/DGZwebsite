(function () {
  const storageKey = 'dgz_checkout_terms_ack_v1';

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function show(el) {
    if (!el) return;
    el.removeAttribute('hidden');
    document.documentElement.classList.add('checkout-terms-open');
    document.body.style.overflow = 'hidden';
    const first = el.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    first && first.focus && first.focus();
  }

  function hide(el) {
    if (!el) return;
    el.setAttribute('hidden', '');
    document.documentElement.classList.remove('checkout-terms-open');
    document.body.style.overflow = '';
  }

  function enable(btn) { if (btn) { btn.disabled = false; btn.classList.remove('is-disabled'); } }
  function disable(btn) { if (btn) { btn.disabled = true; btn.classList.add('is-disabled'); } }

  document.addEventListener('DOMContentLoaded', function () {
    const form = qs('.checkout-form form');
    const overlay = qs('#checkoutTermsOverlay');
    const accept = qs('#checkoutTermsAccept');
    const scrollRegion = qs('[data-checkout-terms-scroll]');

    if (!form || !overlay || !accept || !scrollRegion) {
      return;
    }

    let accepted = false;
    try {
      accepted = (window.sessionStorage && window.sessionStorage.getItem(storageKey) === 'accepted');
    } catch (e) { accepted = false; }

    disable(accept);

    function onScroll() {
      const reachedBottom = scrollRegion.scrollTop + scrollRegion.clientHeight >= scrollRegion.scrollHeight - 4;
      if (reachedBottom) {
        enable(accept);
        scrollRegion.removeEventListener('scroll', onScroll);
      }
    }

    scrollRegion.addEventListener('scroll', onScroll);
    onScroll();

    accept.addEventListener('click', function () {
      try { window.sessionStorage && window.sessionStorage.setItem(storageKey, 'accepted'); } catch (e) {}
      accepted = true;
      hide(overlay);
      // Intentionally DO NOT auto-submit; user must click submit again.
    });

    // Capture phase so we run before other submit guards
    form.addEventListener('submit', function (ev) {
      if (accepted) {
        return; // allow other validators to run
      }
      ev.preventDefault();
      show(overlay);
    }, true);
  });
})();

