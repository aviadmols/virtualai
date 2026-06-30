// === CONSTANTS ===
// The widget's i18n catalog. Keys mirror lang/en/widget.php <-> lang/he/widget.php 1:1
// (authored by product-ux-architect). Bundled inline (a few hundred bytes gzipped) so the
// widget needs zero extra round-trip for copy; the active locale comes from the bootstrap
// (data.site.locale). The host document `dir` is inherited; HE renders RTL.

const LOCALE_EN = 'en';
const LOCALE_HE = 'he';
const DEFAULT_LOCALE = LOCALE_EN;
const RTL_LOCALES = [LOCALE_HE];

const MESSAGES = {
  en: {
    'button.label': 'Tray On',
    'button.loading': 'Loading…',
    'modal.title': 'Tray On',
    'modal.close': 'Close',
    'upload.prompt': 'Add a photo of yourself',
    'upload.hint': 'A clear, well-lit photo works best',
    'upload.uploading': 'Uploading…',
    'upload.replace': 'Replace photo',
    'upload.remove': 'Remove',
    'upload.errors.type': 'Please use a JPG or PNG image',
    'upload.errors.size': 'That image is too large',
    'upload.errors.failed': 'Upload failed. Try again.',
    'height.label': 'Your height',
    'height.unit_cm': 'cm',
    'height.errors.range': 'Enter a height between :min and :max',
    'details.toggle': 'Add details (optional)',
    'details.body': 'Body type',
    'details.age': 'Age range',
    'details.gender': 'Gender',
    'details.angle': 'Photo angle',
    'consent.photo':
      'I agree to let Tray On use my photo to generate a virtual try-on of this product.',
    'consent.privacy_link': 'How we handle your photo',
    'consent.required': 'Please agree before we generate your try-on',
    'cta.generate': 'Generate my try-on',
    'cta.need_photo': 'Add a photo to continue',
    'cta.need_height': 'Add your height to continue',
    'cta.need_consent': 'Agree to the terms to continue',
    'loading.title': 'Creating your try-on…',
    'loading.sub': 'This takes a few seconds',
    'loading.timeout': 'This is taking longer than usual. Try again?',
    'result.title': "Here's your try-on",
    'result.error': "Something went wrong. You weren't charged — try again.",
    'result.regenerate': 'Try again',
    'result.change_photo': 'Change photo',
    'result.change_height': 'Change height',
    'result.add_to_cart': 'Add this to cart',
    'result.back': 'Back to product',
    'cart.added': 'Added to cart',
    'cart.error': "Couldn't add to cart — please add it on the page",
    'tries.exhausted_title': 'Sign up to keep trying',
    'tries.exhausted_body': 'Create a free account to continue generating try-ons',
    'signup.title': 'Quick sign-up',
    'signup.name': 'Full name',
    'signup.email': 'Email',
    'signup.phone_optional': 'Phone (optional)',
    'signup.why': 'We use this to save your try-ons and keep you updated',
    'signup.submit': 'Continue',
    'signup.errors.network': "Couldn't sign you up. Try again.",
    'state.out_of_credits': "Try-on isn't available right now",
    'state.rate_limited': 'Too many requests — please wait a moment and try again',
    'state.limit_reached': "You've used all your try-ons",
    'state.limit_reached_body': 'Thanks for trying! You have no more try-ons available right now.',
    'unavailable.title': "Try-on isn't available right now",
    'unavailable.body': 'Please check back soon',
    'errors.generic': 'Something went wrong. Please try again.',
    'errors.network': 'Check your connection and try again',
    // Pluralized free-tries chip (handled by tries()).
    'tries.zero': 'No free tries left',
    'tries.one': ':count free try left',
    'tries.many': ':count free tries left',
  },
  he: {
    'button.label': 'מדדו את זה',
    'button.loading': 'טוען…',
    'modal.title': 'מדדו את זה',
    'modal.close': 'סגירה',
    'upload.prompt': 'הוסיפו תמונה שלכם',
    'upload.hint': 'תמונה ברורה ומוארת תעבוד הכי טוב',
    'upload.uploading': 'מעלים…',
    'upload.replace': 'החלפת תמונה',
    'upload.remove': 'הסרה',
    'upload.errors.type': 'אנא השתמשו בתמונת JPG או PNG',
    'upload.errors.size': 'התמונה גדולה מדי',
    'upload.errors.failed': 'ההעלאה נכשלה. נסו שוב.',
    'height.label': 'הגובה שלכם',
    'height.unit_cm': 'ס"מ',
    'height.errors.range': 'הזינו גובה בין :min ל-:max',
    'details.toggle': 'הוספת פרטים (לא חובה)',
    'details.body': 'מבנה גוף',
    'details.age': 'טווח גיל',
    'details.gender': 'מגדר',
    'details.angle': 'זווית הצילום',
    'consent.photo': 'אני מאשר/ת ל-Tray On להשתמש בתמונה שלי כדי ליצור הדמיה של המוצר עליי.',
    'consent.privacy_link': 'איך אנחנו מטפלים בתמונה שלכם',
    'consent.required': 'יש לאשר לפני יצירת ההדמיה',
    'cta.generate': 'יצירת ההדמיה שלי',
    'cta.need_photo': 'הוסיפו תמונה כדי להמשיך',
    'cta.need_height': 'הוסיפו גובה כדי להמשיך',
    'cta.need_consent': 'אשרו את התנאים כדי להמשיך',
    'loading.title': 'יוצרים את ההדמיה שלכם…',
    'loading.sub': 'זה לוקח כמה שניות',
    'loading.timeout': 'זה לוקח יותר מהרגיל. לנסות שוב?',
    'result.title': 'הנה ההדמיה שלכם',
    'result.error': 'משהו השתבש. לא חויבתם — נסו שוב.',
    'result.regenerate': 'נסו שוב',
    'result.change_photo': 'החלפת תמונה',
    'result.change_height': 'שינוי גובה',
    'result.add_to_cart': 'הוספה לעגלה',
    'result.back': 'חזרה למוצר',
    'cart.added': 'נוסף לעגלה',
    'cart.error': 'לא הצלחנו להוסיף לעגלה — אנא הוסיפו מהדף',
    'tries.exhausted_title': 'הירשמו כדי להמשיך',
    'tries.exhausted_body': 'פתחו חשבון חינם כדי להמשיך ליצור הדמיות',
    'signup.title': 'הרשמה מהירה',
    'signup.name': 'שם מלא',
    'signup.email': 'אימייל',
    'signup.phone_optional': 'טלפון (לא חובה)',
    'signup.why': 'אנחנו משתמשים בזה כדי לשמור את ההדמיות ולעדכן אתכם',
    'signup.submit': 'המשך',
    'signup.errors.network': 'ההרשמה נכשלה. נסו שוב.',
    'state.out_of_credits': 'ההדמיה אינה זמינה כרגע',
    'state.rate_limited': 'יותר מדי בקשות — אנא המתינו רגע ונסו שוב',
    'state.limit_reached': 'ניצלת את כל ההדמיות שלך',
    'state.limit_reached_body': 'תודה שניסית! אין לך כרגע הדמיות נוספות זמינות.',
    'unavailable.title': 'ההדמיה אינה זמינה כרגע',
    'unavailable.body': 'אנא נסו שוב בקרוב',
    'errors.generic': 'משהו השתבש. אנא נסו שוב.',
    'errors.network': 'בדקו את החיבור ונסו שוב',
    'tries.zero': 'לא נותרו ניסיונות חינם',
    'tries.one': 'נותר :count ניסיון חינם',
    'tries.many': 'נותרו :count ניסיונות חינם',
  },
};

let activeLocale = DEFAULT_LOCALE;

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

/** The document `dir` the widget inherits/sets on its root. */
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

/** Pluralized free-tries chip (mirrors widget.tries.left's choice form). */
export function tries(count) {
  if (count <= 0) return t('tries.zero');
  if (count === 1) return t('tries.one', { count });
  return t('tries.many', { count });
}
