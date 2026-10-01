(function () {
  const STORAGE_KEY = 'mb_cookie_consent_v1';
  const ENABLE_REOPEN_BUTTON = false;
  const DEFAULTS = {
    necessary: true,
    analytics: false,
    marketing: false,
    timestamp: null,
    version: '2026-05-23'
  };

  function getBasePath() {
    const base = window.MB_LEGAL_BASE_PATH || './';
    return base.endsWith('/') ? base : base + '/';
  }

  function readConsent() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      return {
        ...DEFAULTS,
        ...parsed,
        necessary: true
      };
    } catch (_) {
      return null;
    }
  }

  function saveConsent(consent) {
    const payload = {
      ...DEFAULTS,
      ...consent,
      necessary: true,
      timestamp: new Date().toISOString()
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    window.dispatchEvent(new CustomEvent('mb:cookie-consent-updated', { detail: payload }));
    return payload;
  }

  function ensureStyles() {
    if (document.getElementById('mb-lgpd-style')) return;

    const style = document.createElement('style');
    style.id = 'mb-lgpd-style';
    style.textContent = "\n      .mb-cookie-banner {\n        position: fixed;\n        left: 16px;\n        right: 16px;\n        bottom: 16px;\n        z-index: 9999;\n        max-width: 980px;\n        margin: 0 auto;\n        background: #161412;\n        color: #f0ece8;\n        border: 1px solid #3a2f24;\n        border-radius: 12px;\n        padding: 16px;\n        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);\n        font-family: Arial, sans-serif;\n      }\n      .mb-cookie-banner h3 {\n        margin: 0 0 8px;\n        font-size: 16px;\n      }\n      .mb-cookie-banner p {\n        margin: 0 0 10px;\n        line-height: 1.45;\n        font-size: 14px;\n        color: #c8c0b6;\n      }\n      .mb-cookie-actions {\n        display: flex;\n        flex-wrap: wrap;\n        gap: 8px;\n        margin-top: 12px;\n      }\n      .mb-cookie-btn {\n        border: 1px solid #5b4a38;\n        background: #2a2218;\n        color: #f0ece8;\n        padding: 8px 12px;\n        border-radius: 8px;\n        cursor: pointer;\n        font-size: 13px;\n      }\n      .mb-cookie-btn.primary {\n        background: #d4a574;\n        color: #1b1814;\n        border-color: #d4a574;\n        font-weight: 700;\n      }\n      .mb-cookie-btn.link {\n        border: none;\n        background: transparent;\n        color: #d4a574;\n        text-decoration: underline;\n      }\n      .mb-cookie-preferences {\n        margin-top: 12px;\n        border-top: 1px solid #3a2f24;\n        padding-top: 12px;\n        display: none;\n      }\n      .mb-cookie-preferences.open {\n        display: block;\n      }\n      .mb-cookie-option {\n        display: flex;\n        align-items: center;\n        justify-content: space-between;\n        gap: 8px;\n        margin-bottom: 8px;\n        font-size: 14px;\n      }\n      .mb-cookie-links {\n        display: flex;\n        flex-wrap: wrap;\n        gap: 12px;\n        margin-top: 8px;\n      }\n      .mb-cookie-links a {\n        color: #d4a574;\n        text-decoration: none;\n        font-size: 13px;\n      }\n      .mb-cookie-links a:hover {\n        text-decoration: underline;\n      }\n      .mb-cookie-open-preferences {\n        position: fixed;\n        right: 14px;\n        bottom: 14px;\n        z-index: 9998;\n        border-radius: 999px;\n        border: 1px solid #5b4a38;\n        background: #161412;\n        color: #f0ece8;\n        padding: 10px 12px;\n        cursor: pointer;\n        font-size: 12px;\n      }\n      @media (max-width: 600px) {\n        .mb-cookie-banner {\n          left: 10px;\n          right: 10px;\n          bottom: 10px;\n          padding: 12px;\n        }\n      }\n    ";
    document.head.appendChild(style);
  }

  function createPreferencesButton(openBanner) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mb-cookie-open-preferences';
    btn.textContent = 'Cookies';
    btn.addEventListener('click', openBanner);
    document.body.appendChild(btn);
    return btn;
  }

  function buildBanner(existingConsent, onClose) {
    const base = getBasePath();
    const wrapper = document.createElement('div');
    wrapper.className = 'mb-cookie-banner';

    wrapper.innerHTML = [
      '<h3>Preferências de Cookies</h3>',
      '<p>Usamos cookies essenciais para segurança e login. Cookies opcionais (analíticos e marketing) só são ativados com seu consentimento.</p>',
      '<div class="mb-cookie-links">',
      '  <a href="' + base + 'politica-de-cookies.html" target="_blank" rel="noopener">Política de Cookies</a>',
      '  <a href="' + base + 'politica-de-privacidade.html" target="_blank" rel="noopener">Política de Privacidade</a>',
      '  <a href="' + base + 'termos-de-uso.html" target="_blank" rel="noopener">Termos de Uso</a>',
      '</div>',
      '<div class="mb-cookie-preferences" id="mb-cookie-preferences">',
      '  <label class="mb-cookie-option">',
      '    <span>Cookies essenciais (sempre ativos)</span>',
      '    <input type="checkbox" checked disabled>',
      '  </label>',
      '  <label class="mb-cookie-option">',
      '    <span>Cookies analíticos</span>',
      '    <input type="checkbox" id="mb-cookie-analytics" ' + ((existingConsent && existingConsent.analytics) ? 'checked' : '') + '>',
      '  </label>',
      '  <label class="mb-cookie-option">',
      '    <span>Cookies de marketing</span>',
      '    <input type="checkbox" id="mb-cookie-marketing" ' + ((existingConsent && existingConsent.marketing) ? 'checked' : '') + '>',
      '  </label>',
      '</div>',
      '<div class="mb-cookie-actions">',
      '  <button type="button" class="mb-cookie-btn link" id="mb-cookie-configure">Personalizar</button>',
      '  <button type="button" class="mb-cookie-btn" id="mb-cookie-reject">Rejeitar opcionais</button>',
      '  <button type="button" class="mb-cookie-btn primary" id="mb-cookie-accept">Aceitar todos</button>',
      '  <button type="button" class="mb-cookie-btn" id="mb-cookie-save" style="display:none">Salvar preferências</button>',
      '</div>'
    ].join('');

    const preferences = wrapper.querySelector('#mb-cookie-preferences');
    const btnConfigure = wrapper.querySelector('#mb-cookie-configure');
    const btnSave = wrapper.querySelector('#mb-cookie-save');

    btnConfigure.addEventListener('click', function () {
      preferences.classList.toggle('open');
      btnSave.style.display = preferences.classList.contains('open') ? 'inline-block' : 'none';
    });

    wrapper.querySelector('#mb-cookie-accept').addEventListener('click', function () {
      saveConsent({ analytics: true, marketing: true });
      onClose();
    });

    wrapper.querySelector('#mb-cookie-reject').addEventListener('click', function () {
      saveConsent({ analytics: false, marketing: false });
      onClose();
    });

    btnSave.addEventListener('click', function () {
      const analytics = !!wrapper.querySelector('#mb-cookie-analytics').checked;
      const marketing = !!wrapper.querySelector('#mb-cookie-marketing').checked;
      saveConsent({ analytics: analytics, marketing: marketing });
      onClose();
    });

    return wrapper;
  }

  function init() {
    ensureStyles();

    const state = {
      banner: null,
      prefButton: null
    };

    function syncPrefButtonVisibility() {
      if (!state.prefButton) return;
      if (!ENABLE_REOPEN_BUTTON) {
        state.prefButton.style.display = 'none';
        return;
      }
      state.prefButton.style.display = readConsent() ? 'none' : 'inline-block';
    }

    function closeBanner() {
      if (state.banner && state.banner.parentNode) {
        state.banner.parentNode.removeChild(state.banner);
      }
      state.banner = null;
      syncPrefButtonVisibility();
    }

    function openBanner() {
      if (state.banner && state.banner.parentNode) {
        state.banner.parentNode.removeChild(state.banner);
        state.banner = null;
      }
      const consent = readConsent();
      state.banner = buildBanner(consent, closeBanner);
      document.body.appendChild(state.banner);
      if (state.prefButton) {
        state.prefButton.style.display = 'none';
      }
    }

    window.mustabarberCookieConsent = {
      get: function () {
        return readConsent() || { ...DEFAULTS };
      },
      canUse: function (type) {
        const c = readConsent();
        if (!c) return type === 'necessary';
        return !!c[type];
      },
      openPreferences: openBanner
    };

    const consent = readConsent();
    if (ENABLE_REOPEN_BUTTON) {
      state.prefButton = createPreferencesButton(openBanner);
    }

    if (!consent) {
      openBanner();
    }

    syncPrefButtonVisibility();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
