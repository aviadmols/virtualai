// === CONSTANTS ===
// The MODAL chunk's copy. EN is authoritative; HE mirrors it 1:1 (an empty HE value is a release
// blocker). Authored by product-ux-architect — this module transcribes, it does not invent.
//
// These keys ship with the modal, not with the core: a key the shopper cannot see yet is a byte
// the merchant's LCP paid for. i18n.extend() merges them in the moment the chunk lands.

export const MODAL_MESSAGES = {
  en: {
    'modal.close': 'Close',

    'upload.tile': 'Upload',
    'upload.prompt': 'Add a photo of yourself',
    'upload.hint': 'A clear, well-lit, full-body photo works best',
    'upload.replace': 'Change photo',
    'upload.uploading': 'Uploading…',
    'upload.errors.type': 'Please use a JPG, PNG or WebP image',
    'upload.errors.size': 'That image is too large',
    'upload.errors.failed': "We couldn't read that image. Try another one.",

    'height.label': 'Your height',
    'height.unit_cm': 'cm',
    'height.errors.range': 'Enter a height between :min and :max cm',

    'styles.label': 'Choose a style (optional)',

    'details.toggle': 'Add details (optional)',
    'details.body': 'Body type',
    'details.age': 'Age range',
    'details.gender': 'Gender',
    'details.angle': 'Photo angle',

    'consent.photo':
      'I agree to let Vsio use my photo to generate a virtual try-on of this product.',
    'consent.privacy_link': 'How we handle your photo',
    'consent.required': 'Please agree before we create your look',

    'cta.generate': 'Create my look',
    'cta.need_photo': 'Add a photo to continue',
    'cta.need_height': 'Add your height to continue',
    'cta.need_consent': 'Agree to the terms to continue',

    'loading.title': 'Creating your look…',
    'loading.sub': "This takes a few seconds. You can close this — we'll let you know.",
    'loading.timeout': "This is taking longer than usual. You weren't charged — try again?",

    'result.title': "Here's your look",
    'result.disclaimer': '* For visualization only. The actual fit may vary.',
    'result.zoom_hint': 'Tap to zoom',
    'result.regenerate': 'Create it again',
    'result.error': 'Something went wrong',
    'result.error_body': "You weren't charged. Let's try that again.",
    'result.add_to_cart': 'Add to cart',

    'gallery.title': 'Your looks',
    'gallery.view': 'View this look',
    'gallery.viewing': 'Your look',

    'save.action': 'Save Look',
    'save.saved': 'Saved',
    'save.already_saved': 'Saved to your looks',
    'save.signup_title': 'Save your looks',
    'save.signup_why':
      'Create a free account and your looks stay with you — on any device, any time.',

    'share.action': 'Share',
    'share.title': 'My Vsio look',
    'share.text': "Here's how :product looks on me.",
    'share.fallback_done': 'Image saved. Link copied.',
    'share.errors.failed': "We couldn't share that. Please try again.",

    'cart.adding': 'Adding…',
    'cart.added': 'Added to cart',
    'cart.error': "We couldn't add it to your cart — please add it from the product page.",
    'cart.errors.unavailable': "That option isn't available right now.",
    'cart.variant_changed': 'This look is of a different option than the one selected.',

    'tries.zero': 'No free tries left',
    'tries.one': ':count free try left',
    'tries.many': ':count free tries left',
    'tries.last': 'Last free try — sign up to keep creating looks',
    'tries.exhausted_title': 'Sign up to keep creating',
    'tries.exhausted_body': 'Create a free account to keep making looks.',

    'signup.title': 'Quick sign-up',
    'signup.name': 'Full name',
    'signup.email': 'Email',
    'signup.phone_optional': 'Phone (optional)',
    'signup.why': "We use this to keep your looks and let you know when they're ready.",
    'signup.consent': 'I agree to Vsio creating an account for me to save my looks.',
    'signup.submit': 'Continue',
    'signup.errors.network': "We couldn't sign you up. Please try again.",

    'state.out_of_credits': "Try-on isn't available right now",
    'state.rate_limited': 'Too many requests — please wait a moment and try again',
    'state.limit_reached': "You've used all your looks",
    'state.limit_reached_body':
      'Thanks for trying! You have no more looks available right now.',
    'unavailable.body': 'Please check back soon.',

    'errors.generic': 'Something went wrong. Please try again.',
    'errors.network': 'Check your connection and try again.',
  },

  he: {
    'modal.close': 'סגירה',

    'upload.tile': 'העלאה',
    'upload.prompt': 'הוסיפו תמונה שלכם',
    'upload.hint': 'תמונה ברורה, מוארת ובגוף מלא תעבוד הכי טוב',
    'upload.replace': 'החלפת תמונה',
    'upload.uploading': 'מעלים…',
    'upload.errors.type': 'אנא השתמשו בתמונת JPG, PNG או WebP',
    'upload.errors.size': 'התמונה גדולה מדי',
    'upload.errors.failed': 'לא הצלחנו לקרוא את התמונה. נסו אחרת.',

    'height.label': 'הגובה שלכם',
    'height.unit_cm': 'ס"מ',
    'height.errors.range': 'הזינו גובה בין :min ל-:max ס"מ',

    'styles.label': 'בחרו סגנון (לא חובה)',

    'details.toggle': 'הוספת פרטים (לא חובה)',
    'details.body': 'מבנה גוף',
    'details.age': 'טווח גיל',
    'details.gender': 'מגדר',
    'details.angle': 'זווית הצילום',

    'consent.photo': 'אני מאשר/ת ל-Vsio להשתמש בתמונה שלי כדי ליצור הדמיה של המוצר עליי.',
    'consent.privacy_link': 'איך אנחנו מטפלים בתמונה שלכם',
    'consent.required': 'יש לאשר לפני יצירת הלוק',

    'cta.generate': 'יצירת הלוק שלי',
    'cta.need_photo': 'הוסיפו תמונה כדי להמשיך',
    'cta.need_height': 'הוסיפו גובה כדי להמשיך',
    'cta.need_consent': 'אשרו את התנאים כדי להמשיך',

    'loading.title': 'יוצרים את הלוק שלכם…',
    'loading.sub': 'זה לוקח כמה שניות. אפשר לסגור — נעדכן אתכם.',
    'loading.timeout': 'זה לוקח יותר מהרגיל. לא חויבתם — לנסות שוב?',

    'result.title': 'הנה הלוק שלכם',
    'result.disclaimer': '* להמחשה בלבד. הגזרה בפועל עשויה להשתנות.',
    'result.zoom_hint': 'הקישו להגדלה',
    'result.regenerate': 'ליצור שוב',
    'result.error': 'משהו השתבש',
    'result.error_body': 'לא חויבתם. בואו ננסה שוב.',
    'result.add_to_cart': 'הוספה לעגלה',

    'gallery.title': 'הלוקים שלכם',
    'gallery.view': 'צפייה בלוק הזה',
    'gallery.viewing': 'הלוק שלכם',

    'save.action': 'שמירת הלוק',
    'save.saved': 'נשמר',
    'save.already_saved': 'נשמר ללוקים שלכם',
    'save.signup_title': 'שמרו את הלוקים שלכם',
    'save.signup_why': 'פתחו חשבון חינם והלוקים יישארו אתכם — בכל מכשיר, בכל זמן.',

    'share.action': 'שיתוף',
    'share.title': 'הלוק שלי ב-Vsio',
    'share.text': 'ככה :product נראה עליי.',
    'share.fallback_done': 'התמונה נשמרה. הקישור הועתק.',
    'share.errors.failed': 'לא הצלחנו לשתף. אנא נסו שוב.',

    'cart.adding': 'מוסיפים…',
    'cart.added': 'נוסף לעגלה',
    'cart.error': 'לא הצלחנו להוסיף לעגלה — אנא הוסיפו מדף המוצר.',
    'cart.errors.unavailable': 'האפשרות הזו לא זמינה כרגע.',
    'cart.variant_changed': 'הלוק הזה מתייחס לאפשרות אחרת מזו שנבחרה.',

    'tries.zero': 'לא נותרו ניסיונות חינם',
    'tries.one': 'נותר :count ניסיון חינם',
    'tries.many': 'נותרו :count ניסיונות חינם',
    'tries.last': 'ניסיון חינם אחרון — הירשמו כדי להמשיך ליצור לוקים',
    'tries.exhausted_title': 'הירשמו כדי להמשיך',
    'tries.exhausted_body': 'פתחו חשבון חינם כדי להמשיך ליצור לוקים.',

    'signup.title': 'הרשמה מהירה',
    'signup.name': 'שם מלא',
    'signup.email': 'אימייל',
    'signup.phone_optional': 'טלפון (לא חובה)',
    'signup.why': 'אנחנו משתמשים בזה כדי לשמור את הלוקים ולעדכן אתכם כשהם מוכנים.',
    'signup.consent': 'אני מאשר/ת ל-Vsio לפתוח עבורי חשבון לשמירת הלוקים.',
    'signup.submit': 'המשך',
    'signup.errors.network': 'ההרשמה נכשלה. אנא נסו שוב.',

    'state.out_of_credits': 'המדידה אינה זמינה כרגע',
    'state.rate_limited': 'יותר מדי בקשות — אנא המתינו רגע ונסו שוב',
    'state.limit_reached': 'ניצלתם את כל הלוקים שלכם',
    'state.limit_reached_body': 'תודה שניסיתם! אין לכם כרגע לוקים נוספים זמינים.',
    'unavailable.body': 'אנא נסו שוב בקרוב.',

    'errors.generic': 'משהו השתבש. אנא נסו שוב.',
    'errors.network': 'בדקו את החיבור ונסו שוב.',
  },
};
