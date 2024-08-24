((Drupal) => {
  'use strict';

  Drupal.behaviors.ColorSchemesRules = {
    attach: function(context, settings) {
      const categorySelect = context.querySelector('#edit-theme-category');
      const themeSelect = context.querySelector('#edit-predefined-theme');

      if (!categorySelect || !themeSelect) {
        return;
      }

      // Get the stored value from the form state
      const storedValue = themeSelect.getAttribute('data-default-value');

      // Initialize once
      if (categorySelect.getAttribute('data-solo-theme-settings-processed') !== 'true') {
        categorySelect.setAttribute('data-solo-theme-settings-processed', 'true');

        // Clone original options
        const initialOptions = Array.from(themeSelect.options);

        // Function to filter theme options based on selected category
        const filterThemeOptions = (selectedCategory) => {
          themeSelect.innerHTML = '';

          if (selectedCategory !== 'none') {
            let selectedOptionExists = false;

            initialOptions.forEach((option) => {
              if (option.value.startsWith(selectedCategory + '|')) {
                themeSelect.appendChild(option.cloneNode(true));

                // Check if the stored value matches this option's suffix
                const optionSuffix = option.value.split('|')[1];
                if (optionSuffix === storedValue) {
                  selectedOptionExists = true;
                }
              }
            });

            // Re-select the previously selected value if it exists in the new filtered list
            if (selectedOptionExists) {
              themeSelect.value = `${selectedCategory}|${storedValue}`;
            }
          }
        };

        // Filter options on category change
        categorySelect.addEventListener('change', function() {
          filterThemeOptions(this.value);
        });

        // Filter options based on the default value or current value on page load
        filterThemeOptions(categorySelect.value);
      }
    }
  };

  Drupal.behaviors.soloUtilitiesAjaxOperations = {
    attach: function (context, settings) {
      // Hide all elements with the class .disable.dropbutton__item
      const disableLinks = context.querySelectorAll('.disable.dropbutton__item');

      disableLinks.forEach(link => {
        link.style.display = 'none';
      });
    }
  };

})(Drupal);
