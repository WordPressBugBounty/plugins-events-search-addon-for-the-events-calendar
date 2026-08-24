
(function (root) {
  'use strict';

  var ADDON_IDS = ['esb', 'widgets', 'divi', 'spb', 'speakers', 'search', 'countdown'];

  function defaultEnv() {
    return { addons: { esb: { free: true } }, editors: { elementor: true }, dismissed: {}, hostAddonSlug: 'esb' };
  }

  function normalize(env) {
    env = env || {};
    var out = {
      addons: {},
      editors: {},
      dismissed: env.dismissed || [],
      hostAddonSlug: env.hostAddonSlug || 'eca',
      canManagePlugins: env.canManagePlugins !== false,
      anyRelatedProPresent: !!env.anyRelatedProPresent
    };
    var a = env.addons || {};
    ADDON_IDS.forEach(function (id) {
      var t = a[id] || {};
      out.addons[id] = {
        free: !!t.free,
        pro: !!t.pro,
        freeStatus: t.freeStatus || (t.free ? 'active' : 'absent'),
        proStatus: t.proStatus || (t.pro ? 'active' : 'absent'),
        freeInit: t.freeInit || '',
        proInit: t.proInit || '',
        freeSlug: t.freeSlug || '',
        proSlug: t.proSlug || ''
      };
    });
    var e = env.editors || {};
    out.editors = {
      elementor: !!e.elementor,
      divi: !!e.divi,
      divi5: !!e.divi5,
      bricks: !!e.bricks,
      wpbakery: !!e.wpbakery,
      classicEditor: e.classicEditor === true,
      blockEditor: e.blockEditor !== false
    };

    if (!out.editors.elementor) { out.addons.widgets.free = false; out.addons.widgets.pro = false; }
    if (!out.editors.divi) { out.addons.divi.free = false; out.addons.divi.pro = false; }
    if (root.ECA_DASHBOARD && typeof root.ECA_DASHBOARD.canManagePlugins !== 'undefined') {
      out.canManagePlugins = !!root.ECA_DASHBOARD.canManagePlugins;
    }
    return out;
  }

  function buildEnv() {
    if (root.ECA_ENV) return normalize(root.ECA_ENV);
    return normalize(defaultEnv());
  }

  root.ECADashboardEnv = {
    buildEnv: buildEnv, normalize: normalize,
    defaultEnv: defaultEnv, ADDON_IDS: ADDON_IDS
  };
}(window));
