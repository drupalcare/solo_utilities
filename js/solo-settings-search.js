/**
 * @file
 * Filters the Solo settings navigation by section, field label or help text.
 */
((Drupal, once) => {
  const TEXT_SELECTOR =
    'summary, label, legend, h2, h3, h4, p, li, a, .description, .form-item__description, .vertical-tabs__menu-link';

  /**
   * Lowercases text and collapses its white space.
   *
   * @param {string} text
   *   The text.
   *
   * @return {string}
   *   The normalized text.
   */
  const normalize = (text) => text.toLowerCase().replace(/\s+/g, ' ').trim();

  /**
   * Reads the searchable text of one navigation item and its section.
   *
   * @param {HTMLElement} item
   *   The navigation item.
   *
   * @return {string}
   *   The normalized text.
   */
  const textOf = (item) => {
    const link = item.querySelector('.solo-nav__link');
    const id = link ? link.getAttribute('href').slice(1) : '';
    const section = id ? document.getElementById(id) : null;
    const words = section
      ? Array.from(section.querySelectorAll(TEXT_SELECTOR)).map(
          (element) => element.textContent,
        )
      : [];
    return normalize(`${item.textContent} ${words.join(' ')}`);
  };

  Drupal.behaviors.soloSettingsSearch = {
    attach(context) {
      once('solo-settings-search', '.solo-nav__menu', context).forEach(
        (menu) => {
          const entries = Array.from(
            menu.querySelectorAll('.solo-nav__item'),
          ).map((item) => ({ item, text: textOf(item) }));

          const box = document.createElement('div');
          box.className = 'solo-nav__searchbox';
          const input = document.createElement('input');
          input.type = 'search';
          input.className = 'solo-nav__search';
          input.placeholder = Drupal.t('Search settings...');
          input.setAttribute(
            'aria-label',
            Drupal.t('Filter settings by section name, field label or help text'),
          );
          const count = document.createElement('p');
          count.className = 'solo-nav__count';
          count.setAttribute('role', 'status');
          box.append(input, count);
          menu.querySelector(':scope > summary').after(box);

          const run = () => {
            const query = normalize(input.value);
            let matches = 0;
            entries.forEach(({ item, text }) => {
              const hit = query === '' || text.includes(query);
              item.hidden = !hit;
              matches += hit ? 1 : 0;
            });
            menu.querySelectorAll('.solo-nav__list').forEach((list) => {
              const visible = list.querySelector(
                ':scope > .solo-nav__item:not([hidden])',
              );
              list.hidden = !visible;
              const heading = list.previousElementSibling;
              if (heading && heading.matches('.solo-nav__group')) {
                heading.hidden = !visible;
              }
            });
            count.textContent =
              query === ''
                ? ''
                : Drupal.t('@count of @total sections', {
                    '@count': matches,
                    '@total': entries.length,
                  });
          };

          let timer;
          input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(run, 150);
          });
          input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && input.value !== '') {
              event.preventDefault();
              input.value = '';
              run();
            }
            if (event.key === 'Enter') {
              // Enter opens the first match instead of submitting the form.
              event.preventDefault();
              const first = entries.find(
                ({ item }) =>
                  !item.hidden && item.querySelector('.solo-nav__link'),
              );
              if (first) {
                first.item.querySelector('.solo-nav__link').click();
              }
            }
          });
        },
      );
    },
  };
})(Drupal, once);
