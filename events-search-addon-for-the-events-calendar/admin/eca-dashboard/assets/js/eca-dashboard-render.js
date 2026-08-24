
(function (root) {
  'use strict';

  function ic(name) { return name ? '<span class="dashicons dashicons-' + name + '" aria-hidden="true"></span>' : ''; }
  function at(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;'); }
  function interp(s, map) { return String(s == null ? '' : s).replace(/\{(\w+)\}/g, function (_, k) { return map[k] != null ? map[k] : '{' + k + '}'; }); }

  var currentVM = null;

  function settingsHref(ctx) {
    if (!ctx || ctx.mode !== 'SETTINGS') return '';
    var urls = root.ECA_ADMIN_URLS || {};
    var cfg = root.ECA_DASHBOARD || {};
    var dashboard = urls.dashboard || cfg.dashboardUrl || '';
    var key = ctx.driver || ctx.id;
    return urls[ctx.id] || urls[key] || dashboard || '';
  }

  function btn(a, ctx) {
    var cls = a.kind === 'primary' ? 'eca-btn-primary' : a.kind === 'accent' ? 'eca-btn-accent' : 'eca-btn-ghost';
    var t = a.external ? ' target="_blank" rel="noopener"' : '';
    var dyn = a.action ? ' data-eca-action="' + at(a.action) + '" data-addon="' + at(a.addon) + '" data-slug="' + at(a.slug) + '" data-init="' + at(a.init) + '"' + (a.pro ? ' data-pro="1"' : '') : '';
    var href = a.url;

    if (ctx && ctx.mode === 'SETTINGS' && a.kind === 'primary') {
      var resolved = settingsHref(ctx);
      if (resolved) href = resolved;
    }
    return '<a href="' + at(href) + '"' + t + dyn + ' class="' + cls + '">' +
      (a.icon ? ic(a.icon) + ' ' : '') + '<span>' + a.label + '</span></a>';
  }

  function actionsHTML(actions, ownedNote, ctx) {
    var out = '';
    if (ownedNote) {
      out += '<em class="eca-pro-owned__note">' + ownedNote + '</em>';
    }
    out += (actions || []).map(function (a) { return btn(a, ctx); }).join('');
    return out;
  }

  function heroHTML(h) {
    return '<section class="eca-hero eca-hero--compact" aria-labelledby="eca-hero-title">' +
      '<div class="eca-hero__inner">' +
      '<div class="eca-hero__copy">' +
      '<h1 id="eca-hero-title">' + h.title + '</h1><p>' + h.desc + '</p>' +
      '</div></div></section>';
  }

  function recommendedCardHTML(rec) {
    if (!rec || !rec.show || !rec.plugins || !rec.plugins.length) return '';
    var data = at(JSON.stringify(rec.plugins));
    return '<section class="eca-recommended-card" aria-labelledby="eca-recommended-title">' +
      '<div class="eca-recommended-card__copy">' +
      '<h2 id="eca-recommended-title" class="eca-recommended-card__title">' + rec.title + '</h2>' +
      '<p class="eca-recommended-card__desc">' + rec.desc + '</p>' +
      '</div>' +
      '<div class="eca-recommended-card__cta">' +
      '<a href="#" role="button" class="eca-btn-primary eca-recommended-card__btn"' +
      ' data-eca-action="recommended-open"' +
      ' data-plugins="' + data + '"' +
      ' data-done-message="' + at(rec.doneMessage || '') + '">' +
      ic('download') + ' <span>' + rec.ctaLabel + '</span></a>' +
      '</div></section>';
  }

  function recommendedModalShellHTML() {
    var labels = (root.ECA_DASHBOARD && root.ECA_DASHBOARD.labels) || {};
    return '<div id="eca-recommended-modal" class="eca-modal" hidden aria-hidden="true">' +
      '<div class="eca-modal__backdrop" data-eca-modal-close tabindex="-1" aria-hidden="true"></div>' +
      '<div class="eca-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="eca-recommended-modal-title">' +
      '<h2 id="eca-recommended-modal-title" class="eca-modal__title">' + (labels.recommendedTitle || 'Recommended Add-ons') + '</h2>' +
      '<ul class="eca-recommended-list" data-eca-recommended-list></ul>' +
      '<p class="eca-modal__summary" data-eca-modal-summary hidden></p>' +
      '<div class="eca-modal__actions" data-eca-modal-actions>' +
      '<a href="#" role="button" class="eca-btn-primary" data-eca-modal-ok disabled aria-disabled="true">' + ic('yes') + ' <span>' + (labels.modalOk || 'OK') + '</span></a>' +
      '</div></div></div>';
  }

  function ensureRecommendedModal() {
    var modal = document.getElementById('eca-recommended-modal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.innerHTML = recommendedModalShellHTML();
    var node = modal.firstElementChild;
    document.body.appendChild(node);
    return node;
  }

  var REVIEW_KEY = 'eca-review-dismissed';
  function getDismissedReviews() {
    var list = [];
    if (currentVM && currentVM.review && currentVM.review.dismissed) {
      list = list.concat(currentVM.review.dismissed);
    }
    try { list = list.concat(JSON.parse(root.localStorage.getItem(REVIEW_KEY)) || []); } catch (e) {}
    return list.filter(function (id, index) { return id && list.indexOf(id) === index; });
  }
  function dismissReview(id) {
    var list = getDismissedReviews();
    if (list.indexOf(id) === -1) { list.push(id); }
    try { root.localStorage.setItem(REVIEW_KEY, JSON.stringify(list)); } catch (e) {}
    if (root.ECADashboardActions && root.ECADashboardActions.dismissReview) {
      root.ECADashboardActions.dismissReview(id);
    }
  }
  function reviewStripHTML(info, copy) {
    return '<span class="eca-review__icon" aria-hidden="true">' + ic(copy.icon) + '</span>' +
      '<div class="eca-review__copy"><span class="eca-review__title">' + interp(copy.title, { addon: info.name }) + '</span>' +
      '<span class="eca-review__desc">' + copy.desc + '</span></div>' +
      '<a href="' + at(info.url) + '" target="_blank" rel="noopener" class="eca-btn-primary eca-review__cta">' + ic(copy.icon) + ' <span>' + copy.cta + '</span></a>' +
      '<a href="#" role="button" class="eca-review__dismiss" aria-label="Dismiss review request">' + ic('no-alt') + '</a>';
  }

  function activeReviewTarget() {
    if (!currentVM || !currentVM.review) return null;
    var rootEl = document.getElementById('eca-dash-root');
    var activePanel = rootEl && rootEl.querySelector('.eca-dash-panel.is-active[data-dash-panel]');
    if (!activePanel) return null;
    var key = activePanel.dataset.dashPanel;
    var sec = key === 'listings' ? currentVM.sections.listings
      : key === 'single-page' ? currentVM.sections.singlePage : null;
    if (!sec) return null;
    var activeTabPanel = activePanel.querySelector('.eca-workflow-tabpanel.is-active');
    var tabId = activeTabPanel ? activeTabPanel.dataset.tabPanel : sec.defaultTab;
    var tab = null;
    for (var i = 0; i < sec.tabs.length; i++) { if (sec.tabs[i].id === tabId) { tab = sec.tabs[i]; break; } }
    if (!tab || tab.mode !== 'HOWTO') return null;
    if (!currentVM.review.addons[tab.driver]) return null;
    return tab.driver;
  }
  function updateReview() {
    var rootEl = document.getElementById('eca-dash-root');
    if (!rootEl) return;
    var existing = document.getElementById('eca-review');
    if (existing) existing.remove();
    var target = activeReviewTarget();
    if (!target || getDismissedReviews().indexOf(target) !== -1) return;
    var el = document.createElement('div');
    el.id = 'eca-review';
    el.className = 'eca-review';
    el.setAttribute('data-addon', target);
    el.innerHTML = reviewStripHTML(currentVM.review.addons[target], currentVM.review.copy);
    var premium = rootEl.querySelector('.eca-premium-block');
    if (premium) rootEl.insertBefore(el, premium); else rootEl.appendChild(el);
  }

  function jobTabsHTML(jobs, defaultSection) {
    return '<div class="eca-dash-jobtabs" role="tablist" aria-label="What do you want to do?">' +
      jobs.map(function (j) {
        var sel = j.id === defaultSection;
        return '<a href="#" class="eca-dash-jobtab" role="tab" id="eca-jobtab-' + j.id + '" data-dash-tab="' + j.id + '"' +
          ' aria-selected="' + sel + '" aria-controls="eca-panel-' + j.id + '"' + (sel ? '' : ' tabindex="-1"') + '>' +
          '<span class="eca-dash-jobtab__icon" aria-hidden="true">' + ic(j.icon) + '</span>' +
          '<span class="eca-dash-jobtab__text"><span class="eca-dash-jobtab__title">' + j.label + '</span>' +
          '<span class="eca-dash-jobtab__sub">' + j.sub + '</span></span></a>';
      }).join('') + '</div>';
  }

  function tabStripHTML(key, tab, isDefault) {
    var pro = tab.proBadge ? ' <span class="eca-wf-tab__pro">Pro</span>' : '';
    return '<a href="#" class="eca-workflow-tab" role="tab" id="eca-wtab-' + key + '-' + tab.id + '"' +
      ' aria-controls="eca-wpanel-' + key + '-' + tab.id + '" data-tab="' + tab.id + '"' +
      ' aria-selected="' + (isDefault ? 'true' : 'false') + '" tabindex="' + (isDefault ? '0' : '-1') + '">' +
      '<img src="' + at(tab.icon) + '" alt="" class="eca-wf-tab__img">' +
      '<span class="eca-wf-tab__text"><span class="eca-wf-tab__name">' + tab.label + pro + '</span>' +
      '<span class="eca-wf-tab__desc">' + tab.tabDesc + '</span></span>' +
      '<span class="eca-wf-tab__check" aria-hidden="true">' + ic('yes') + '</span></a>';
  }

  function panelBodyHTML(tab) {
    var s = '';
    if (tab.steps) {
      s += '<div><h4 class="eca-wf-steps__title">' + tab.steps.title + '</h4><ol class="eca-wf-steps">' +
        tab.steps.items.map(function (li) { return '<li>' + li + '</li>'; }).join('') + '</ol></div>';
    }
    if (tab.install) {
      var tone = tab.install.tone === 'pro' ? ' eca-wf-install--pro' : ' eca-wf-install--free';
      s += '<div class="eca-wf-install' + tone + '"><img src="' + at(tab.install.logo) + '" alt="" class="eca-wf-install__logo">' +
        '<div class="eca-wf-install__body"><h4 class="eca-wf-install__title">' + tab.install.title + '</h4>' +
        '<p class="eca-wf-install__text">' + tab.install.text + '</p></div></div>';
    }
    s += '<div class="eca-wf-actions">' + actionsHTML(tab.actions, tab.ownedNote) + '</div>';

    if (tab.note) {
      s += '<p class="eca-wf-note">' + tab.note.text + '</p>';
    }
    return s;
  }

  function tabPanelHTML(key, tab, isDefault) {
    return '<div class="eca-workflow-tabpanel' + (isDefault ? ' is-active' : '') + '" data-tab-panel="' + tab.id + '"' +
      ' role="tabpanel" id="eca-wpanel-' + key + '-' + tab.id + '" aria-labelledby="eca-wtab-' + key + '-' + tab.id + '">' +
      '<div class="eca-wf-split">' +
      '<div class="eca-wf-video" data-youtube-id="' + at(tab.video.youtubeId) + '">' +
      '<img src="' + at(tab.video.thumb) + '" alt="" class="eca-wf-video__thumb">' +
      '<a href="#" role="button" class="eca-wf-video__play" data-wf-play aria-label="Play walkthrough"></a>' +
      '<span class="eca-wf-video__label">' + ic('video-alt3') + ' Walkthrough</span>' +
      '<div class="eca-wf-video__frame" data-wf-frame></div></div>' +
      '<div class="eca-wf-split__main">' + panelBodyHTML(tab) + '</div>' +
      '</div></div>';
  }

  function workflowPanelHTML(key, section, tablistLabel, isActive) {
    var def = section.defaultTab;
    var strip = '';
    if (section.showNav) {
      strip = '<div class="eca-workflow-tabs" role="tablist" aria-label="' + at(tablistLabel) + '">' +
        section.tabs.map(function (t) { return tabStripHTML(key, t, t.id === def); }).join('') + '</div>';
    }
    var panels = section.tabs.map(function (t) { return tabPanelHTML(key, t, t.id === def); }).join('');
    return '<div class="eca-dash-panel' + (isActive ? ' is-active' : '') + '" id="eca-panel-' + key + '"' +
      ' role="tabpanel" aria-labelledby="eca-jobtab-' + key + '" data-dash-panel="' + key + '"' + (isActive ? '' : ' hidden') + '>' +
      '<article class="eca-workflow-card" data-card="' + key + '">' +
      '<div class="eca-wf-head"><div><h3 class="eca-wf-head__title">' + section.head.title + '</h3>' +
      '<p class="eca-wf-head__desc">' + section.head.desc + '</p></div></div>' +
      '<div class="eca-wf-body">' + strip + '<div class="eca-workflow-tabpanels">' + panels + '</div></div>' +
      '</article></div>';
  }

  function pillHTML(p) {
    if (!p) return '';
    if (p.kind === 'active') return '<span class="eca-pill eca-pill-active">' + ic('yes') + ' ' + p.label + '</span>';
    if (p.kind === 'free') return '<span class="eca-pill eca-pill-free">' + p.label + '</span>';
    if (p.kind === 'pro') return '<span class="eca-pill eca-pill-pro">' + p.label + '</span>';
    return '<span class="eca-pill eca-pill-pro-only">' + p.label + '</span>';
  }
  function utilityCardHTML(c) {
    return '<article class="eca-utility-card">' +
      '<span class="eca-utility-card__icon" aria-hidden="true"><img src="' + at(c.icon) + '" alt=""></span>' +
      '<div class="eca-utility-card__name">' + c.label + ' ' + pillHTML(c.pill) + '</div>' +
      '<p class="eca-utility-card__desc">' + c.desc + '</p>' +
      (c.note ? '<span class="eca-utility-card__note">' + ic('visibility') + ' ' + c.note + '</span>' : '') +
      '<div class="eca-utility-card__actions">' + actionsHTML(c.actions, c.ownedNote, c) + '</div></article>';
  }
  function otherPanelHTML(section, isActive) {
    return '<div class="eca-dash-panel' + (isActive ? ' is-active' : '') + '" id="eca-panel-other"' +
      ' role="tabpanel" aria-labelledby="eca-jobtab-other" data-dash-panel="other"' + (isActive ? '' : ' hidden') + '>' +
      '<div class="eca-wf-head"><div><h3 class="eca-wf-head__title">' + section.head.title + '</h3>' +
      '<p class="eca-wf-head__desc">' + section.head.desc + '</p></div></div>' +
      '<div class="eca-utility-grid">' + section.cards.map(utilityCardHTML).join('') + '</div></div>';
  }

  function proCardHTML(c) {
    var primary;
    if (c.activateAction) {
      primary = btn(c.activateAction);
    } else {
      primary = '<a href="' + at(c.proUrl) + '" target="_blank" rel="noopener" class="eca-btn-accent">' + ic('star-filled') + ' <span>Get Pro</span></a>';
    }
    return '<article class="eca-pro-card">' +
      '<span class="eca-pro-card__icon"><img src="' + at(c.icon) + '" alt=""></span>' +
      '<div class="eca-pro-card__body">' +
      '<h3 class="eca-pro-card__name">' + c.name + '</h3>' +
      '<p class="eca-pro-card__desc">' + c.desc + '</p>' +
      '<div class="eca-pro-card__actions">' +
      primary +
      '<a href="' + at(c.demoUrl) + '" target="_blank" rel="noopener" class="eca-btn-ghost">' + ic('external') + ' <span>View Demo</span></a>' +
      '</div></div></article>';
  }
  function premiumHTML(p) {
    if (!p.show || !p.cards.length) return '';

    return '<section class="eca-section eca-premium-block" aria-labelledby="eca-premium-title">' +
      '<div class="eca-section__header"><h2 id="eca-premium-title">' + p.title + '</h2>' +
      '<span class="eca-pill eca-pill-pro">Pro Addons</span></div>' +
      '<div class="eca-premium-grid" data-count="' + p.cards.length + '">' +
      p.cards.map(proCardHTML).join('') + '</div></section>';
  }

  function activateMethodTab(card, name) {
    card.querySelectorAll('.eca-workflow-tab[role="tab"]').forEach(function (t) {
      var m = t.dataset.tab === name;
      t.setAttribute('aria-selected', m ? 'true' : 'false');
      t.setAttribute('tabindex', m ? '0' : '-1');
    });
    card.querySelectorAll('.eca-workflow-tabpanel').forEach(function (pnl) {
      pnl.classList.toggle('is-active', pnl.dataset.tabPanel === name);
    });
    updateReview();
  }
  function wireMethodTabs() {
    document.querySelectorAll('.eca-workflow-card[data-card]').forEach(function (card) {
      var tabs = card.querySelectorAll('.eca-workflow-tab[role="tab"]');
      tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) { e.preventDefault(); activateMethodTab(card, tab.dataset.tab); });
        tab.addEventListener('keydown', function (e) {
          var list = Array.prototype.slice.call(tabs);
          var idx = list.indexOf(document.activeElement), next = idx;
          if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = (idx + 1) % list.length;
          else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = (idx - 1 + list.length) % list.length;
          else if (e.key === 'Home') next = 0;
          else if (e.key === 'End') next = list.length - 1;
          else return;
          e.preventDefault();
          activateMethodTab(card, list[next].dataset.tab);
          list[next].focus();
        });
      });
    });
  }

  function wireJobTabs() {
    var jobtabs = Array.prototype.slice.call(document.querySelectorAll('.eca-dash-jobtab[role="tab"]'));
    var panels = document.querySelectorAll('.eca-dash-panel[data-dash-panel]');
    function activateJob(name) {
      jobtabs.forEach(function (t) {
        var on = t.dataset.dashTab === name;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.setAttribute('tabindex', on ? '0' : '-1');
      });
      panels.forEach(function (pnl) {
        var on = pnl.dataset.dashPanel === name;
        pnl.classList.toggle('is-active', on);
        pnl.hidden = !on;
      });
      updateReview();
    }
    jobtabs.forEach(function (tab) {
      tab.addEventListener('click', function (e) { e.preventDefault(); activateJob(tab.dataset.dashTab); });
      tab.addEventListener('keydown', function (e) {
        var idx = jobtabs.indexOf(document.activeElement), next = idx;
        if (idx === -1) return;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = (idx + 1) % jobtabs.length;
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = (idx - 1 + jobtabs.length) % jobtabs.length;
        else if (e.key === 'Home') next = 0;
        else if (e.key === 'End') next = jobtabs.length - 1;
        else return;
        e.preventDefault();
        activateJob(jobtabs[next].dataset.dashTab);
        jobtabs[next].focus();
      });
    });
  }

  var delegatedReady = false;
  function initDelegated() {
    if (delegatedReady) return;
    delegatedReady = true;
    document.addEventListener('click', function (e) {
      var rd = e.target.closest('.eca-review__dismiss');
      if (rd) {
        e.preventDefault();
        var box = rd.closest('.eca-review');
        if (box) { dismissReview(box.getAttribute('data-addon')); box.remove(); }
        return;
      }
      var video = e.target.closest('.eca-wf-video');
      if (!video || video.classList.contains('is-playing')) return;
      var id = video.getAttribute('data-youtube-id');
      var frame = video.querySelector('[data-wf-frame]');
      if (!id || !frame) return;
      e.preventDefault();
      frame.innerHTML = '<iframe src="https://www.youtube.com/embed/' + id +
        '?autoplay=1&rel=0&modestbranding=1" title="Walkthrough video" frameborder="0" ' +
        'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
      video.classList.add('is-playing');
    });
  }

  function setHref(id, url) { var a = document.getElementById(id); if (a && url) a.href = url; }

  function renderDashboard(vm) {
    currentVM = vm;

    if (vm.header) {
      setHref('eca-header-support', vm.header.supportUrl);
      setHref('eca-header-docs', vm.header.docsUrl);
    }
    var rootEl = document.getElementById('eca-dash-root');
    if (!rootEl) return;
    rootEl.innerHTML =
      heroHTML(vm.hero) +
      recommendedCardHTML(vm.recommendedAddons) +
      jobTabsHTML(vm.jobs, vm.defaultSection) +
      '<div class="eca-dash-panels">' +
        workflowPanelHTML('listings', vm.sections.listings, 'Listings creation method', vm.defaultSection === 'listings') +
        workflowPanelHTML('single-page', vm.sections.singlePage, 'Single page design method', vm.defaultSection === 'single-page') +
        otherPanelHTML(vm.sections.other, vm.defaultSection === 'other') +
      '</div>' +
      premiumHTML(vm.premium);
    wireMethodTabs();
    wireJobTabs();
    updateReview();
  }

  root.ECADashboardRender = {
    renderDashboard: renderDashboard,
    initDelegated: initDelegated,
    ensureRecommendedModal: ensureRecommendedModal
  };
}(window));
