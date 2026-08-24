
(function () {
  'use strict';
  var root = window;

  function start(manifest) {
    var Env = window.ECADashboardEnv;
    var Resolver = window.ECADashboardResolver;
    var Render = window.ECADashboardRender;
    var currentEnv = null;

    Render.initDelegated();

    function apply(env) {
      currentEnv = Env.normalize(env);
      var vm = Resolver.resolveDashboard(currentEnv, manifest);
      patchSettingsUrls(vm);
      Render.renderDashboard(vm);
      return vm;
    }

    function patchSettingsUrls(vm) {
      var urls = root.ECA_ADMIN_URLS || {};
      var cfg = root.ECA_DASHBOARD || {};

      var dashboard = urls.dashboard || cfg.dashboardUrl || '';
      if (!vm.sections) return;

      if (vm.sections.other && vm.sections.other.cards) {
        vm.sections.other.cards.forEach(function (card) {
          if (card.mode !== 'SETTINGS') return;
          var href = urls[card.id] || urls[card.driver] || dashboard;
          if (!href) return;
          card.actions.forEach(function (a) {
            if (a.kind === 'primary') { a.url = href; }
          });
        });
      }

      var cta = (root.ECA_MANIFEST && root.ECA_MANIFEST.strings && root.ECA_MANIFEST.strings.cta) || {};
      var settingsLabel = cta.openSettings || cta.settings || 'Open settings';
      var viewTemplateLabel = cta.viewTemplate || 'View Template';
      var editors = (root.ECA_ENV && root.ECA_ENV.editors) || {};
      ['listings', 'singlePage'].forEach(function (secKey) {
        var sec = vm.sections[secKey];
        if (!sec || !sec.tabs) return;
        sec.tabs.forEach(function (tab) {
          if (tab.mode !== 'HOWTO' || !tab.actions) return;

          var href = urls[tab.id] || urls[String(tab.id).toLowerCase()] || '';
          if (!href || (dashboard && href === dashboard)) return;

          if (tab.id === 'diviTpl' && !editors.divi5) return;
          var label = tab.id === 'diviTpl' ? viewTemplateLabel : settingsLabel;
          var icon = tab.id === 'diviTpl' ? 'welcome-widgets-menus' : 'admin-generic';
          var kind = tab.id === 'diviTpl' ? 'primary' : 'ghost';

          var already = tab.actions.some(function (a) { return a.settings === true; });
          if (already) {
            tab.actions.forEach(function (a) {
              if (a.settings) { a.url = href; a.label = label; a.icon = icon; a.kind = kind; }
            });
            return;
          }
          var insertAt = tab.actions.length;
          for (var i = 0; i < tab.actions.length; i++) {
            if (tab.actions[i].kind === 'accent') { insertAt = i; break; }
          }

          if (kind === 'primary') {
            for (var j = 0; j < tab.actions.length; j++) {
              if (tab.actions[j].kind === 'primary') { insertAt = j + 1; break; }
            }
          }
          tab.actions.splice(insertAt, 0, {
            kind: kind,
            label: label,
            icon: icon,
            url: href,
            external: false,
            settings: true
          });
        });
      });
    }

    function ajaxForm(fields) {
      var cfg = root.ECA_DASHBOARD || {};
      if (!cfg.ajaxUrl) return Promise.reject(new Error('Missing ECA_DASHBOARD.ajaxUrl'));
      var fd = new FormData();
      Object.keys(fields).forEach(function (key) { fd.append(key, fields[key]); });
      return fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); });
    }

    function setBusy(el, busy, label) {
      if (!el) return;
      if (busy) {
        if (!el._ecaHtml) el._ecaHtml = el.innerHTML;
        el.classList.add('is-busy');
        el.setAttribute('aria-busy', 'true');
        el.setAttribute('aria-disabled', 'true');
        el.setAttribute('data-eca-busy', '1');
        el.innerHTML = '<span class="dashicons dashicons-update" aria-hidden="true"></span> <span>' +
          (label || '') + '</span>';
      } else {
        el.classList.remove('is-busy');
        el.removeAttribute('aria-busy');
        el.removeAttribute('aria-disabled');
        el.removeAttribute('data-eca-busy');
        if (el._ecaHtml) {
          el.innerHTML = el._ecaHtml;
          el._ecaHtml = null;
        }
      }
    }

    function setFailed(el, message) {
      if (!el) return;
      el.classList.remove('is-busy');
      el.removeAttribute('aria-busy');
      el.removeAttribute('aria-disabled');
      el.removeAttribute('data-eca-busy');
      el._ecaHtml = null;
      el.innerHTML = '<span class="dashicons dashicons-warning" aria-hidden="true"></span> <span>' +
        (message || 'Failed — retry') + '</span>';
    }

    function activatePlugin(init) {
      var cfg = root.ECA_DASHBOARD || {};
      return ajaxForm({
        action: 'eca_dashboard_plugin_activate',
        security: cfg.noncePlugin,
        init: init
      }).then(function (res) {
        if (!res || !res.success) {
          throw new Error((res && res.data && res.data.message) || (cfg.labels && cfg.labels.failed) || 'Activation failed.');
        }

        if (res.data && res.data.env) {
          root.ECA_ENV = res.data.env;
        }
        return res;
      });
    }

    function installPluginOnly(slug) {
      var cfg = root.ECA_DASHBOARD || {};
      return ajaxForm({
        action: 'eca_dashboard_plugin_install',
        _ajax_nonce: cfg.nonceInstall,
        slug: slug
      }).then(function (res) {
        if (!res || !res.success) {
          var msg = (res && res.data && (res.data.errorMessage || res.data.message)) || (cfg.labels && cfg.labels.failed) || 'Installation failed.';
          throw new Error(typeof msg === 'string' ? msg : 'Installation failed.');
        }
        return (res.data && res.data.plugin) || '';
      });
    }

    function verifyAddons(ids) {
      var cfg = root.ECA_DASHBOARD || {};
      return ajaxForm({
        action: 'eca_dashboard_verify_addons',
        security: cfg.noncePlugin,
        addons: ids.join(',')
      }).then(function (res) {
        if (!res || !res.success) {
          throw new Error((res && res.data && res.data.message) || 'Verification failed.');
        }
        if (res.data && res.data.env) {
          root.ECA_ENV = res.data.env;
        }
        return res.data || {};
      });
    }

    function installPlugin(slug, fallbackInit) {
      return installPluginOnly(slug).then(function (pluginInit) {
        return activatePlugin(pluginInit || fallbackInit);
      });
    }

    var recommendedRun = {
      active: false,
      plugins: [],
      doneMessage: '',
      failed: []
    };

    function recLabels() {
      return (root.ECA_DASHBOARD && root.ECA_DASHBOARD.labels) || {};
    }

    function recIcon(state) {
      if (state === 'activated' || state === 'skipped') return 'yes';
      if (state === 'failed') return 'warning';
      if (state === 'installing' || state === 'activating') return 'update';
      return 'marker';
    }

    function recStatusText(state, labels, err) {
      if (state === 'pending') return labels.statusPending || 'Waiting';
      if (state === 'installing') return labels.statusInstalling || 'Installing...';
      if (state === 'activating') return labels.statusActivating || 'Activating...';
      if (state === 'activated') return labels.statusActivated || 'Activated';
      if (state === 'skipped') return labels.statusSkipped || 'Already active';
      if (state === 'failed') return (labels.statusFailed || 'Failed') + (err ? ': ' + err : '');
      return '';
    }

    function recProgressPct(state) {
      if (state === 'pending') return 0;
      if (state === 'installing') return 45;
      if (state === 'activating') return 80;
      if (state === 'activated' || state === 'skipped') return 100;
      return null;
    }

    function recommendedRowProgressHTML() {
      return '<div class="eca-recommended-list__progress" data-rec-progress>' +
        '<div class="eca-recommended-list__progress-track">' +
        '<div class="eca-recommended-list__progress-bar" data-rec-progress-bar style="width:0%"></div>' +
        '</div></div>';
    }

    function removeRecommendedRetry(modal) {
      if (!modal) return;
      var retryBtn = modal.querySelector('[data-eca-modal-retry]');
      if (retryBtn) retryBtn.remove();
    }

    function showRecommendedRetry(modal) {
      if (!modal || modal.querySelector('[data-eca-modal-retry]')) return;
      var actions = modal.querySelector('[data-eca-modal-actions]');
      var okBtn = modal.querySelector('[data-eca-modal-ok]');
      if (!actions || !okBtn) return;
      var labels = recLabels();
      var wrap = document.createElement('div');
      wrap.innerHTML = '<a href="#" role="button" class="eca-btn-ghost" data-eca-modal-retry>' +
        '<span class="dashicons dashicons-update" aria-hidden="true"></span> <span>' +
        (labels.modalRetry || 'Retry') + '</span></a>';
      var retryBtn = wrap.firstElementChild;
      if (retryBtn) actions.insertBefore(retryBtn, okBtn);
    }

    function getRecommendedModal() {
      return Render.ensureRecommendedModal ? Render.ensureRecommendedModal() : null;
    }

    function setRecommendedRow(id, state, errMsg) {
      var modal = getRecommendedModal();
      if (!modal) return;
      var row = modal.querySelector('[data-rec-id="' + id + '"]');
      if (!row) return;
      var labels = recLabels();
      row.className = 'eca-recommended-list__item' +
        (state === 'failed' ? ' is-failed' : '') +
        (state === 'installing' || state === 'activating' ? ' is-busy' : '') +
        (state === 'activated' || state === 'skipped' ? ' is-done' : '');
      var icon = row.querySelector('[data-rec-icon]');
      var status = row.querySelector('[data-rec-status]');
      if (icon) icon.innerHTML = '<span class="dashicons dashicons-' + recIcon(state) + '" aria-hidden="true"></span>';
      if (status) status.textContent = recStatusText(state, labels, errMsg);
      var progressBar = row.querySelector('[data-rec-progress-bar]');
      if (progressBar) {
        var pct = recProgressPct(state);
        if (pct !== null) {
          progressBar.style.width = pct + '%';
        }
        progressBar.className = 'eca-recommended-list__progress-bar' +
          (state === 'activated' || state === 'skipped' ? ' is-complete' : '') +
          (state === 'failed' ? ' is-failed' : '');
      }
    }

    function populateRecommendedModal(plugins) {
      var modal = getRecommendedModal();
      if (!modal) return;
      var list = modal.querySelector('[data-eca-recommended-list]');
      var summary = modal.querySelector('[data-eca-modal-summary]');
      var okBtn = modal.querySelector('[data-eca-modal-ok]');
      var labels = recLabels();
      if (!list) return;
      list.innerHTML = plugins.map(function (p) {
        var initial = p.action ? 'pending' : 'skipped';
        return '<li class="eca-recommended-list__item' + (initial === 'skipped' ? ' is-done' : '') + '" data-rec-id="' + p.id + '">' +
          '<span class="eca-recommended-list__icon" data-rec-icon aria-hidden="true">' +
          '<span class="dashicons dashicons-' + recIcon(initial) + '"></span></span>' +
          '<span class="eca-recommended-list__body">' +
          '<span class="eca-recommended-list__name">' + p.name + '</span>' +
          '<span class="eca-recommended-list__status" data-rec-status>' + recStatusText(initial, labels) + '</span>' +
          (p.action ? recommendedRowProgressHTML() : '') +
          '</span></li>';
      }).join('');
      if (summary) {
        summary.hidden = true;
        summary.className = 'eca-modal__summary';
        summary.textContent = '';
      }
      if (okBtn) {
        okBtn.setAttribute('disabled', 'disabled');
        okBtn.setAttribute('aria-disabled', 'true');
      }
      removeRecommendedRetry(modal);
    }

    function openRecommendedModal(plugins, doneMessage) {
      var modal = getRecommendedModal();
      if (!modal) return;
      recommendedRun.plugins = plugins.slice();
      recommendedRun.doneMessage = doneMessage || '';
      recommendedRun.failed = [];
      populateRecommendedModal(plugins);
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('eca-modal-open');
      runRecommendedQueue(false);
    }

    function closeRecommendedModal(reload) {
      var modal = getRecommendedModal();
      if (modal) {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
      }
      document.body.classList.remove('eca-modal-open');
      recommendedRun.active = false;
      if (reload) {
        window.location.reload();
      }
    }

    function finishRecommendedModal(allOk) {
      var modal = getRecommendedModal();
      if (!modal) return;
      var summary = modal.querySelector('[data-eca-modal-summary]');
      var okBtn = modal.querySelector('[data-eca-modal-ok]');
      var labels = recLabels();
      recommendedRun.active = false;
      if (summary) {
        summary.hidden = false;
        if (allOk) {
          summary.className = 'eca-modal__summary is-success';
          summary.textContent = recommendedRun.doneMessage || labels.recommendedDone || 'All recommended add-ons have been installed and activated successfully.';
        } else {
          summary.className = 'eca-modal__summary is-error';
          summary.textContent = labels.failed || 'Some add-ons could not be installed or activated.';
        }
      }
      if (okBtn) {
        if (allOk) {
          okBtn.removeAttribute('disabled');
          okBtn.removeAttribute('aria-disabled');
        } else {
          okBtn.setAttribute('disabled', 'disabled');
          okBtn.setAttribute('aria-disabled', 'true');
        }
      }
      if (allOk) {
        removeRecommendedRetry(modal);
      } else if (recommendedRun.failed.length) {
        showRecommendedRetry(modal);
      }
    }

    function processRecommendedPlugin(plugin) {
      var labels = recLabels();
      if (!plugin.action) {
        setRecommendedRow(plugin.id, 'skipped');
        return Promise.resolve(true);
      }
      if (plugin.action === 'activate') {
        setRecommendedRow(plugin.id, 'activating');
        return activatePlugin(plugin.init).then(function () {
          return verifyAddons([plugin.id]).then(function (data) {
            var st = data.addons && data.addons[plugin.id];
            if (!st || st.freeStatus !== 'active') {
              throw new Error(labels.failed || 'Activation failed.');
            }
            setRecommendedRow(plugin.id, 'activated');
            return true;
          });
        });
      }
      setRecommendedRow(plugin.id, 'installing');
      return installPluginOnly(plugin.slug).then(function (pluginInit) {
        var init = pluginInit || plugin.init;
        setRecommendedRow(plugin.id, 'activating');
        return activatePlugin(init).then(function () {
          return verifyAddons([plugin.id]).then(function (data) {
            var st = data.addons && data.addons[plugin.id];
            if (!st || st.freeStatus !== 'active') {
              throw new Error(labels.failed || 'Installation failed.');
            }
            plugin.init = st.freeInit || init;
            plugin.action = null;
            setRecommendedRow(plugin.id, 'activated');
            return true;
          });
        });
      });
    }

    function runRecommendedQueue(retryFailedOnly) {
      if (recommendedRun.active) return;
      recommendedRun.active = true;
      var modal = getRecommendedModal();
      if (modal) {
        var summary = modal.querySelector('[data-eca-modal-summary]');
        var okBtn = modal.querySelector('[data-eca-modal-ok]');
        if (summary) summary.hidden = true;
        if (okBtn) {
          okBtn.setAttribute('disabled', 'disabled');
          okBtn.setAttribute('aria-disabled', 'true');
        }
        removeRecommendedRetry(modal);
      }
      var queue = recommendedRun.plugins.filter(function (p) {
        if (retryFailedOnly) {
          return recommendedRun.failed.indexOf(p.id) !== -1;
        }
        return !!p.action;
      });
      if (retryFailedOnly) {
        queue.forEach(function (p) { setRecommendedRow(p.id, 'pending'); });
      }
      recommendedRun.failed = [];
      var chain = Promise.resolve();
      queue.forEach(function (plugin) {
        chain = chain.then(function () {
          return processRecommendedPlugin(plugin).then(function () {

          }).catch(function (err) {
            recommendedRun.failed.push(plugin.id);
            setRecommendedRow(plugin.id, 'failed', err && err.message ? err.message : '');
          });
        });
      });
      chain.then(function () {
        var allOk = recommendedRun.failed.length === 0;
        finishRecommendedModal(allOk);
        if (allOk && root.ECA_ENV) {
          apply(root.ECA_ENV);
        }
      });
    }

    function showActionSuccess(btn, action) {
      var cfg = root.ECA_DASHBOARD || {};
      var labels = cfg.labels || {};
      var addon = btn.getAttribute('data-addon') || '';
      var isPro = btn.getAttribute('data-pro') === '1';
      var gsMap = cfg.getStarted || {};
      var gs = gsMap[addon] || cfg.dashboardUrl || '#';
      var host = btn.parentNode;
      var done = isPro
        ? (labels.donePro || 'Pro activated.')
        : (action === 'activate'
          ? (labels.doneActivate || 'Activated.')
          : (labels.doneInstall || 'Installed & activated.'));

      btn.outerHTML = '<a href="' + String(gs).replace(/"/g, '&quot;') + '" class="eca-btn-primary" data-eca-get-started>' +
        '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span> <span>' +
        (labels.getStarted || 'Get Started') + '</span></a>';

      var newBtn = host && host.querySelector('[data-eca-get-started]');
      if (newBtn) {
        var note = document.createElement('em');
        note.className = 'eca-action-note';
        note.textContent = done;
        host.insertBefore(note, newBtn);
      }
    }

    function bindDynamicActions() {
      if (root._ecaDashboardDynamicActionsReady) return;
      root._ecaDashboardDynamicActionsReady = true;

      document.addEventListener('click', function (e) {
        var okBtn = e.target.closest('[data-eca-modal-ok]');
        if (okBtn && !okBtn.hasAttribute('disabled') && okBtn.getAttribute('aria-disabled') !== 'true') {
          e.preventDefault();
          closeRecommendedModal(true);
          return;
        }

        var retryBtn = e.target.closest('[data-eca-modal-retry]');
        if (retryBtn) {
          e.preventDefault();
          runRecommendedQueue(true);
          return;
        }

        if (e.target.closest('[data-eca-modal-close]') && !recommendedRun.active) {
          e.preventDefault();
          closeRecommendedModal(false);
          return;
        }

        var btn = e.target.closest('[data-eca-action]');
        if (!btn || btn.classList.contains('is-busy') || btn.getAttribute('data-eca-busy') === '1') return;
        var action = btn.getAttribute('data-eca-action');
        if (action !== 'install' && action !== 'activate' && action !== 'recommended-open') return;
        e.preventDefault();
        var cfg = root.ECA_DASHBOARD || {};
        var labels = cfg.labels || {};

        if (action === 'recommended-open') {
          if (recommendedRun.active) return;
          var rawPlugins = btn.getAttribute('data-plugins') || '[]';
          var plugins = [];
          try { plugins = JSON.parse(rawPlugins); } catch (err) { plugins = []; }
          if (!Array.isArray(plugins) || !plugins.length) return;
          openRecommendedModal(plugins, btn.getAttribute('data-done-message') || '');
          return;
        }

        var slug = btn.getAttribute('data-slug') || '';
        var init = btn.getAttribute('data-init') || '';
        setBusy(btn, true, action === 'install' ? (labels.installing || 'Installing & activating…') : (labels.activating || 'Activating…'));
        var work = action === 'install' ? installPlugin(slug, init) : activatePlugin(init);
        work.then(function () {
          showActionSuccess(btn, action);
        }).catch(function (err) {
          root.console && root.console.error && root.console.error(err);
          setFailed(btn, labels.failedRetry || labels.failed || 'Failed — retry');
        });
      });
    }

    root.ECADashboardActions = {
      dismissReview: function (addon) {
        var cfg = root.ECA_DASHBOARD || {};
        if (!addon || !cfg.ajaxUrl) return;
        ajaxForm({
          action: 'eca_dashboard_review_dismiss',
          security: cfg.nonceDismiss,
          addon: addon
        }).catch(function () {});
      }
    };

    apply(Env.buildEnv());
    bindDynamicActions();
  }

  function boot() {
    if (window.ECA_MANIFEST) { start(window.ECA_MANIFEST); return; }
    fetch('assets/eca-dashboard-manifest.json', { cache: 'no-cache' })
      .then(function (r) { return r.json(); })
      .then(start)
      .catch(function (err) {
        var m = document.getElementById('eca-dash-root');
        if (m) m.innerHTML = '<p style="color:#b91c1c;padding:1rem">Could not load dashboard manifest: ' + err + '</p>';
      });
  }

  if (document.readyState !== 'loading') boot();
  else document.addEventListener('DOMContentLoaded', boot);
}());
