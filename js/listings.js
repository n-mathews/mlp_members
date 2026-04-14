/**
 * @file listings.js
 * Meadow Lane Park — client-side filter/sort for homes-for-sale listings.
 * Works progressively: if JS is absent the full list is still visible.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.meadowLaneListings = {
    attach(context) {
      once('listings-filter', '.listings-filter-form', context).forEach((form) => {
        const grid    = document.querySelector('.listings-grid');
        const cards   = grid ? Array.from(grid.querySelectorAll('.listing-card')) : [];
        const counter = document.querySelector('.listings-count');

        if (!grid || !cards.length) return;

        // Read filter values and re-render.
        function applyFilters() {
          const maxPrice = parseInt(form.querySelector('[name="max_price"]')?.value || '0', 10);
          const minBeds  = parseInt(form.querySelector('[name="min_beds"]')?.value  || '0', 10);
          const status   = form.querySelector('[name="status"]')?.value || 'all';

          let visible = 0;

          cards.forEach((card) => {
            const price  = parseInt(card.dataset.price || '0', 10);
            const beds   = parseInt(card.dataset.beds  || '0', 10);
            const cardStatus = card.dataset.status || '';

            const priceOk  = !maxPrice || price <= maxPrice;
            const bedsOk   = !minBeds  || beds  >= minBeds;
            const statusOk = status === 'all' || cardStatus === status;

            const show = priceOk && bedsOk && statusOk;
            card.hidden = !show;
            if (show) visible++;
          });

          if (counter) {
            counter.textContent = Drupal.formatPlural(
              visible,
              '1 home found',
              '@count homes found'
            );
          }
        }

        form.addEventListener('change', applyFilters);
        form.addEventListener('input',  applyFilters);
        applyFilters();
      });
    },
  };

})(Drupal, once);
