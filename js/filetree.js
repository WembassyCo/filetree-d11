/**
 * @file
 * Filetree module behaviors.
 */

(function (Drupal, once) {

  'use strict';

  /**
   * Filetree behavior.
   */
  Drupal.behaviors.filetree = {
    attach: function (context, settings) {
      once('filetree-init', '.filetree-wrapper', context).forEach(function (wrapper) {
        var multi = wrapper.dataset.multi === 'true';
        var controls = wrapper.dataset.controls === 'true';
        var animation = wrapper.dataset.animation === 'true';
        var animationSpeed = animation ? 'fast' : 0;

        // Expand/collapse sub-folder when clicking parent folder.
        var folders = wrapper.querySelectorAll('.filetree-type-directory');
        folders.forEach(function (folder) {
          var toggle = folder.querySelector('.filetree-folder-toggle');
          var folderName = folder.querySelector('.filetree-folder-name');

          // Click handler for folder toggle
          var clickHandler = function (e) {
            // Don't interfere with links
            if (e.target.tagName === 'A') {
              return;
            }

            e.preventDefault();
            e.stopPropagation();

            // If multiple folders not allowed, collapse other branches
            if (!multi) {
              var allFolders = wrapper.querySelectorAll('.filetree-type-directory');
              allFolders.forEach(function (otherFolder) {
                if (otherFolder !== folder && !folder.contains(otherFolder)) {
                  otherFolder.classList.remove('expanded');
                  var children = otherFolder.querySelector('.filetree-children');
                  if (children) {
                    if (animationSpeed) {
                      jQuery(children).hide(animationSpeed);
                    } else {
                      children.style.display = 'none';
                    }
                  }
                }
              });
            }

            // Toggle current folder
            var children = folder.querySelector('.filetree-children');
            if (children) {
              if (folder.classList.contains('expanded')) {
                folder.classList.remove('expanded');
                if (animationSpeed) {
                  jQuery(children).hide(animationSpeed);
                } else {
                  children.style.display = 'none';
                }
              } else {
                folder.classList.add('expanded');
                if (animationSpeed) {
                  jQuery(children).show(animationSpeed);
                } else {
                  children.style.display = 'block';
                }
              }
            }
          };

          if (toggle) {
            toggle.addEventListener('click', clickHandler);
          }
          if (folderName) {
            folderName.addEventListener('click', clickHandler);
          }
        });

        // Expand/collapse all controls
        if (controls) {
          var expandAll = wrapper.querySelector('.filetree-expand-all');
          var collapseAll = wrapper.querySelector('.filetree-collapse-all');

          if (expandAll) {
            expandAll.addEventListener('click', function (e) {
              e.preventDefault();
              var allFolders = wrapper.querySelectorAll('.filetree-type-directory');
              allFolders.forEach(function (folder) {
                folder.classList.add('expanded');
                var children = folder.querySelector('.filetree-children');
                if (children) {
                  if (animationSpeed) {
                    jQuery(children).show(animationSpeed);
                  } else {
                    children.style.display = 'block';
                  }
                }
              });
            });
          }

          if (collapseAll) {
            collapseAll.addEventListener('click', function (e) {
              e.preventDefault();
              var allFolders = wrapper.querySelectorAll('.filetree-type-directory');
              allFolders.forEach(function (folder) {
                folder.classList.remove('expanded');
                var children = folder.querySelector('.filetree-children');
                if (children) {
                  if (animationSpeed) {
                    jQuery(children).hide(animationSpeed);
                  } else {
                    children.style.display = 'none';
                  }
                }
              });
            });
          }
        }
      });
    }
  };

})(Drupal, once);
