/**
 * Common admin notice filter for Cool Plugins Events Addon.
 *
 * Runs only on allowed admin pages/body classes passed from PHP.
 * Data comes from localized object: ecsa_notice_filter
 */

jQuery(function ($) {
  if (typeof ecsa_notice_filter === 'undefined') {
    return;
  }

  var config = ecsa_notice_filter || {};
  var allowedBodyClasses = config.allowedBodyClasses || [];

  if (!allowedBodyClasses.length || !document.body) {
    return;
  }

  // Check if current admin screen/body has any of the allowed classes
  var shouldRun = allowedBodyClasses.some(function (className) {
    return document.body.classList.contains(className);
  });

  if (!shouldRun) {
    return;
  }

  // At this point we're on one of the target pages.
  // Remove all admin notices except our plugin-specific ones.
  document
    .querySelectorAll('.notice:not(.ect-required-plugin-notice)')
    .forEach(function (el) {
      el.remove();
    });
});

