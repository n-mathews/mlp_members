/**
 * @file gallery.js
 * Meadow Lane Park — photo gallery lightbox for home listing pages.
 *
 * Reads all images from .listing-gallery, builds a full-screen lightbox
 * with previous/next navigation, keyboard support, and a thumbnail strip.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.meadowLaneGallery = {
    attach(context) {
      once('listing-gallery', '.listing-gallery', context).forEach((gallery) => {
        const items = Array.from(gallery.querySelectorAll('.listing-gallery__item img'));
        if (!items.length) return;

        // ── Build lightbox DOM ──────────────────────────────
        const lb = document.createElement('div');
        lb.className = 'mlp-lightbox' + (items.length === 1 ? ' mlp-lightbox--single' : '');
        lb.setAttribute('role', 'dialog');
        lb.setAttribute('aria-modal', 'true');
        lb.setAttribute('aria-label', Drupal.t('Photo gallery'));

        lb.innerHTML = `
          <div class="mlp-lightbox__inner">
            <img class="mlp-lightbox__img" src="" alt="" />
          </div>
          <button class="mlp-lightbox__close" aria-label="${Drupal.t('Close gallery')}">&#x2715;</button>
          <button class="mlp-lightbox__prev" aria-label="${Drupal.t('Previous photo')}">&#x2039;</button>
          <button class="mlp-lightbox__next" aria-label="${Drupal.t('Next photo')}">&#x203A;</button>
          <div class="mlp-lightbox__thumbs"></div>
          <div class="mlp-lightbox__counter"></div>
        `;

        document.body.appendChild(lb);

        const lbImg     = lb.querySelector('.mlp-lightbox__img');
        const lbClose   = lb.querySelector('.mlp-lightbox__close');
        const lbPrev    = lb.querySelector('.mlp-lightbox__prev');
        const lbNext    = lb.querySelector('.mlp-lightbox__next');
        const lbThumbs  = lb.querySelector('.mlp-lightbox__thumbs');
        const lbCounter = lb.querySelector('.mlp-lightbox__counter');

        // Build thumbnail strip
        items.forEach((img, i) => {
          const thumb = document.createElement('img');
          thumb.src = img.src;
          thumb.alt = '';
          thumb.className = 'mlp-lightbox__thumb';
          thumb.addEventListener('click', () => goTo(i));
          lbThumbs.appendChild(thumb);
        });

        const thumbEls = Array.from(lbThumbs.querySelectorAll('.mlp-lightbox__thumb'));
        let current = 0;
        let previousFocus = null;

        // ── Open / close ────────────────────────────────────
        function open(index) {
          previousFocus = document.activeElement;
          current = index;
          render();
          lb.classList.add('is-open');
          document.body.style.overflow = 'hidden';
          lbClose.focus();
        }

        function close() {
          lb.classList.remove('is-open');
          document.body.style.overflow = '';
          if (previousFocus) previousFocus.focus();
        }

        function goTo(index) {
          current = (index + items.length) % items.length;
          render();
        }

        function render() {
          const img = items[current];
          // Use the largest available src (data-full-src if set, else src)
          lbImg.src = img.dataset.fullSrc || img.src;
          lbImg.alt = img.alt || '';
          lbCounter.textContent = items.length > 1
            ? `${current + 1} / ${items.length}`
            : '';
          thumbEls.forEach((t, i) => t.classList.toggle('is-active', i === current));
          // Scroll active thumb into view
          if (thumbEls[current]) {
            thumbEls[current].scrollIntoView({ inline: 'center', behavior: 'smooth' });
          }
        }

        // ── Event listeners ──────────────────────────────────
        // Open on grid click
        gallery.addEventListener('click', (e) => {
          const item = e.target.closest('.listing-gallery__item');
          if (!item) return;
          const idx = Array.from(gallery.querySelectorAll('.listing-gallery__item')).indexOf(item);
          if (idx >= 0 && idx < items.length) open(idx);
        });

        lbClose.addEventListener('click', close);
        lbPrev.addEventListener('click', () => goTo(current - 1));
        lbNext.addEventListener('click', () => goTo(current + 1));

        // Close on backdrop click
        lb.addEventListener('click', (e) => {
          if (e.target === lb) close();
        });

        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
          if (!lb.classList.contains('is-open')) return;
          switch (e.key) {
            case 'Escape':    close(); break;
            case 'ArrowLeft': goTo(current - 1); break;
            case 'ArrowRight':goTo(current + 1); break;
          }
        });

        // Touch swipe support
        let touchStartX = 0;
        lb.addEventListener('touchstart', (e) => { touchStartX = e.touches[0].clientX; }, { passive: true });
        lb.addEventListener('touchend', (e) => {
          const diff = touchStartX - e.changedTouches[0].clientX;
          if (Math.abs(diff) > 50) goTo(diff > 0 ? current + 1 : current - 1);
        });
      });
    },
  };

})(Drupal, once);
