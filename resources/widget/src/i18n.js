// === CONSTANTS ===
// The widget's i18n catalog. Copy is authored by product-ux-architect; EN is authoritative
// and HE mirrors it 1:1 (an empty HE value is a release blocker). Bundled inline so the
// widget needs zero extra round-trip for copy; the active locale comes from the bootstrap
// (data.site.locale — the manual per-site setting; there is no auto-detection).
//
// SPLIT: only the CORE keys (the brand, the trigger, the HUD) live in the always-on bundle.
// Every modal/club key ships with its own lazy chunk and is merged in via extend() when that
// chunk lands. A key nobody can see yet is a byte the merchant's LCP paid for.

const LOCALE_EN = 'en';
const LOCALE_HE = 'he';
const DEFAULT_LOCALE = LOCALE_EN;
const RTL_LOCALES = [LOCALE_HE];

const MESSAGES = {
  en: {
    // A brand name is not translated — the wordmark is Latin in both locales.
    'brand.wordmark': 'TRAY ON',
    'button.label': 'Try it on',
    'button.loading': 'Loading…',
    'button.busy': 'Creating…',
    'hud.idle_title': 'Your Vsio looks',
    'hud.idle_sub': ':count ready to view',
    'hud.thinking_title': 'Creating your look',
    'hud.thinking_sub': "Keep browsing — we'll tell you when it's ready",
    'hud.ready_title': 'Your look is ready',
    'hud.ready_sub': 'Tap to view it',
    'hud.failed_title': "Your look didn't finish",
    'hud.failed_sub': "You weren't charged. Tap to try again.",
    'hud.unavailable_title': "Try-on couldn't load",
    'hud.unavailable_sub': 'Check your connection and tap to retry',
    'hud.close': 'Dismiss',
  },
  he: {
    'brand.wordmark': 'TRAY ON',
    'button.label': 'מדדו את זה',
    'button.loading': 'טוען…',
    'button.busy': 'יוצרים…',
    'hud.idle_title': 'הלוקים שלכם ב-Vsio',
    'hud.idle_sub': ':count מוכנים לצפייה',
    'hud.thinking_title': 'יוצרים את הלוק שלכם',
    'hud.thinking_sub': 'אפשר להמשיך לגלוש — נעדכן כשיהיה מוכן',
    'hud.ready_title': 'הלוק שלכם מוכן',
    'hud.ready_sub': 'הקישו כדי לראות',
    'hud.failed_title': 'הלוק לא הושלם',
    'hud.failed_sub': 'לא חויבתם. הקישו כדי לנסות שוב.',
    'hud.unavailable_title': 'לא הצלחנו לטעון את המדידה',
    'hud.unavailable_sub': 'בדקו את החיבור והקישו לניסיון חוזר',
    'hud.close': 'סגירה',
  },
};

let activeLocale = DEFAULT_LOCALE;

/** Merge a lazy chunk's catalog in ({ en: {...}, he: {...} }). Called once per chunk, on load. */
export function extend(messages) {
  for (const locale of Object.keys(messages)) {
    MESSAGES[locale] = { ...(MESSAGES[locale] || {}), ...messages[locale] };
  }
}

/** Set the active locale from the bootstrap (falls back to EN for an unknown value). */
export function setLocale(locale) {
  activeLocale = MESSAGES[locale] ? locale : DEFAULT_LOCALE;
}

export function getLocale() {
  return activeLocale;
}

/** True when the active locale renders right-to-left. */
export function isRtl() {
  return RTL_LOCALES.includes(activeLocale);
}

/** The `dir` the widget sets on its shadow roots; HE mirrors via logical properties. */
export function dir() {
  return isRtl() ? 'rtl' : 'ltr';
}

/** Translate a key with optional :placeholder replacements; falls back to EN, then the key. */
export function t(key, replacements = {}) {
  const table = MESSAGES[activeLocale] || MESSAGES[DEFAULT_LOCALE];
  let message = table[key] ?? MESSAGES[DEFAULT_LOCALE][key] ?? key;

  for (const [name, value] of Object.entries(replacements)) {
    message = message.replaceAll(`:${name}`, String(value));
  }

  return message;
}

/**
 * The free-tries choice form. On the LAST try the copy stops being a nudge and says what
 * happens next ("Last free try — sign up to keep creating looks") — the shopper is never
 * surprised by the signup wall.
 */
export function tries(count) {
  if (count <= 0) return t('tries.zero');
  if (count === 1) return t('tries.last');
  return t('tries.many', { count });
}
