(function () {
  'use strict';

  /**
   * Simple filetree toggle functionality - w3schools style.
   */
  Drupal.behaviors.filetreeSimple = {
    attach: function (context, settings) {
      // Find all carets (folder toggles)
      var toggler = document.querySelectorAll('.filetree-simple .caret');
      
      for (var i = 0; i < toggler.length; i++) {
        toggler[i].addEventListener('click', function () {
          // Toggle the nested list
          var nested = this.parentElement.querySelector('.nested');
          if (nested) {
            nested.classList.toggle('active');
          }
          // Rotate the caret
          this.classList.toggle('caret-down');
        });
      }
    }
  };

})(jQuery);
