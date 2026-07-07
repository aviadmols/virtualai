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
    'button.busy': 'Creating…',
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
    'loading.sub': 'This takes a few seconds — you can close this and we\'ll let you know',
    'loading.timeout': 'This is taking longer than usual. Try again?',
    'notify.ready_title': 'Your try-on is ready',
    'notify.ready_sub': 'Tap to view it',
    'notify.failed_title': "Your try-on didn't finish",
    'notify.failed_sub': 'Tap to try again',
    'notify.opening': 'Opening your try-on…',
    'result.title': "Here's your try-on",
    'result.zoom_hint': 'Tap to zoom',
    'result.error': "Something went wrong. You weren't charged — try again.",
    'result.regenerate': 'Try again',
    'result.change_photo': 'Change photo',
    'result.change_height': 'Change height',
    'result.add_to_cart': 'Add this to cart',
    'result.back': 'Back to product',
    'gallery.title': 'Your previous try-ons',
    'gallery.viewing': 'Your try-on',
    'gallery.view': 'View this try-on',
    'gallery.back': 'Back',
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
    // --- Customer Club (floating banner + email one-time-code login) ---
    'club.banner_title': 'Join the club',
    'club.banner_sub': 'Save :percent% on every item',
    'club.banner_cta': 'Join',
    'club.close': 'Close',
    'club.email_title': 'Join the club',
    'club.email_sub': 'Enter your email and we\'ll send you a login code',
    'club.email_label': 'Email',
    'club.email_submit': 'Send me a code',
    'club.code_title': 'Enter your code',
    'club.code_sub': 'We sent a 6-digit code to :email',
    'club.code_label': 'Login code',
    'club.code_submit': 'Verify',
    'club.back': 'Back',
    'club.welcome': 'You\'re in! Club prices are now on.',
    'club.errors.email': 'Enter a valid email address',
    'club.errors.code': 'Enter the 6-digit code',
    'club.errors.throttled': 'A code was just sent — please wait a moment before asking again',
    'club.errors.send_failed': 'We couldn\'t send the code right now. Please try again in a moment.',
    'club.errors.invalid': 'That code isn\'t right. Try again.',
    'club.errors.expired': 'That code expired — request a new one',
    'club.errors.locked': 'Too many tries. Please wait a bit and start over.',
    'club.errors.network': 'Something went wrong. Try again.',
    'club.member_price': 'Club price',
  },
  he: {
    'button.label': 'מדדו את זה',
    'button.loading': 'טוען…',
    'button.busy': 'יוצרים…',
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
    'loading.sub': 'זה לוקח כמה שניות — אפשר לסגור ונעדכן אתכם כשמוכן',
    'loading.timeout': 'זה לוקח יותר מהרגיל. לנסות שוב?',
    'notify.ready_title': 'ההדמיה שלכם מוכנה',
    'notify.ready_sub': 'הקישו כדי לראות',
    'notify.failed_title': 'ההדמיה לא הושלמה',
    'notify.failed_sub': 'הקישו כדי לנסות שוב',
    'notify.opening': 'פותחים את ההדמיה…',
    'result.title': 'הנה ההדמיה שלכם',
    'result.zoom_hint': 'הקש להגדלה',
    'result.error': 'משהו השתבש. לא חויבתם — נסו שוב.',
    'result.regenerate': 'נסו שוב',
    'result.change_photo': 'החלפת תמונה',
    'result.change_height': 'שינוי גובה',
    'result.add_to_cart': 'הוספה לעגלה',
    'result.back': 'חזרה למוצר',
    'gallery.title': 'ההדמיות הקודמות שלכם',
    'gallery.viewing': 'ההדמיה שלכם',
    'gallery.view': 'צפייה בהדמיה',
    'gallery.back': 'חזרה',
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
    // --- מועדון הלקוחות (באנר צף + התחברות בקוד חד-פעמי במייל) ---
    'club.banner_title': 'הצטרפו למועדון',
    'club.banner_sub': 'חסכו :percent% על כל פריט',
    'club.banner_cta': 'הצטרפות',
    'club.close': 'סגירה',
    'club.email_title': 'הצטרפות למועדון',
    'club.email_sub': 'הזינו את האימייל ונשלח לכם קוד התחברות',
    'club.email_label': 'אימייל',
    'club.email_submit': 'שליחת קוד',
    'club.code_title': 'הזינו את הקוד',
    'club.code_sub': 'שלחנו קוד בן 6 ספרות אל :email',
    'club.code_label': 'קוד התחברות',
    'club.code_submit': 'אימות',
    'club.back': 'חזרה',
    'club.welcome': 'הצטרפתם! מחירי המועדון פעילים.',
    'club.errors.email': 'הזינו כתובת אימייל תקינה',
    'club.errors.code': 'הזינו את הקוד בן 6 הספרות',
    'club.errors.throttled': 'קוד נשלח זה עתה — אנא המתינו רגע לפני בקשה נוספת',
    'club.errors.send_failed': 'לא הצלחנו לשלוח את הקוד כרגע. אנא נסו שוב בעוד רגע.',
    'club.errors.invalid': 'הקוד שגוי. נסו שוב.',
    'club.errors.expired': 'הקוד פג תוקף — בקשו קוד חדש',
    'club.errors.locked': 'יותר מדי ניסיונות. אנא המתינו והתחילו מחדש.',
    'club.errors.network': 'משהו השתבש. נסו שוב.',
    'club.member_price': 'מחיר מועדון',
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
