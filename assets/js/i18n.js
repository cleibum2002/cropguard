// assets/js/i18n.js
// Reusable EN / TL / CEB language switcher.
// Each page defines its own `translations` object (const translations = { en:{}, tl:{}, ceb:{} })
// then loads this file to handle the switching + saving of the chosen language.

const I18N_STORAGE_KEY = 'cropguard_lang';

// Applies the chosen language to every element tagged with data-i18n / data-i18n-placeholder
function applyTranslations(lang) {
    if (typeof translations === 'undefined') return;
    const dict = translations[lang] || translations['en'];

    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        if (dict[key]) el.textContent = dict[key];
    });

    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.getAttribute('data-i18n-placeholder');
        if (dict[key]) el.setAttribute('placeholder', dict[key]);
    });

    document.querySelectorAll('.lang-link').forEach(el => {
        el.classList.toggle('lang-active', el.getAttribute('data-lang') === lang);
    });
}

// For JS-generated content (table rows, popups, etc.) — used in Part 2
function t(key) {
    const lang = localStorage.getItem(I18N_STORAGE_KEY) || 'en';
    const dict = (typeof translations !== 'undefined' && translations[lang])
        ? translations[lang]
        : (typeof translations !== 'undefined' ? translations['en'] : {});
    return dict[key] || key;
}

// Called by the language links (onclick="setLanguage('tl')")
function setLanguage(lang) {
    localStorage.setItem(I18N_STORAGE_KEY, lang);
    applyTranslations(lang);
    // Let the page re-render any dynamically-built content (tables, dropdowns, etc.)
    if (typeof onLanguageChange === 'function') onLanguageChange();
}

// Runs automatically on every page that loads this file
function initI18n() {
    const saved = localStorage.getItem(I18N_STORAGE_KEY) || 'en';
    const supported = (typeof translations !== 'undefined' && translations[saved]) ? saved : 'en';
    applyTranslations(supported);
}

document.addEventListener('DOMContentLoaded', initI18n);