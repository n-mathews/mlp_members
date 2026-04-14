/**
 * @file global.js
 * Meadow Lane Park — global Drupal behaviors.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Skip-link enhancement: move focus to #main-content on activation.
   */
  Drupal.behaviors.meadowLaneSkipLink = {
    attach(context) {
      once('skip-link', '.skip-link', context).forEach((link) => {
        link.addEventListener('click', (e) => {
          const target = document.getElementById('main-content');
          if (target) {
            e.preventDefault();
            target.setAttribute('tabindex', '-1');
            target.focus();
          }
        });
      });
    },
  };

  /**
   * Smooth scroll for in-page anchor links.
   */
  Drupal.behaviors.meadowLaneSmoothScroll = {
    attach(context) {
      once('smooth-scroll', 'a[href^="#"]', context).forEach((anchor) => {
        anchor.addEventListener('click', (e) => {
          const targetId = anchor.getAttribute('href').slice(1);
          const target = document.getElementById(targetId);
          if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        });
      });
    },
  };

  /**
   * Add .is-scrolled class to header when page is scrolled.
   * Useful for subtle nav shadow on scroll.
   */
  Drupal.behaviors.meadowLaneScrollHeader = {
    attach(context) {
      once('scroll-header', 'body', context).forEach(() => {
        const header = document.querySelector('.layout-header');
        if (!header) return;

        const onScroll = () => {
          header.classList.toggle('is-scrolled', window.scrollY > 10);
        };

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
      });
    },
  };

})(Drupal, once);
