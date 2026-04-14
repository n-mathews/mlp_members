/**
 * @file navigation.js
 * Meadow Lane Park — mobile navigation toggle.
 */
(function () {
  'use strict';

  function init() {
    var toggle = document.querySelector('.nav__toggle');
    var menu   = document.getElementById('primary-nav');

    if (!toggle || !menu) return;

    // Remove any data-once attribute set by a previous Drupal behavior
    // so we have clean sole ownership of this element.
    toggle.removeAttribute('data-once');

    // Replace the node to strip all previously attached event listeners.
    var fresh = toggle.cloneNode(true);
    toggle.parentNode.replaceChild(fresh, toggle);
    toggle = fresh;

    function open()  {
      toggle.setAttribute('aria-expanded', 'true');
      menu.setAttribute('data-open', 'true');
      menu.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    }

    function close() {
      toggle.setAttribute('aria-expanded', 'false');
      menu.removeAttribute('data-open');
      menu.classList.remove('is-open');
      document.body.style.overflow = '';
    }

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      toggle.getAttribute('aria-expanded') === 'true' ? close() : open();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });

    document.addEventListener('click', function (e) {
      if (
        menu.classList.contains('is-open') &&
        !menu.contains(e.target) &&
        !toggle.contains(e.target)
      ) {
        close();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
