# i18n catalog — en / he (1:1)

> The canonical key catalog for **every** user-facing string. English is authoritative
> and complete; **Hebrew mirrors every key 1:1** — a missing HE mirror is a release
> blocker. `lang/en/*.php` + `lang/he/*.php` mirror this catalog exactly; the dotted
> keys below map to nested array keys (e.g. `widget.result.regenerate` →
> `lang/{locale}/widget.php` → `['result' => ['regenerate' => …]]`).

## Scar-tissue prevention (from `docs/TROUBLESHOOTING.md` i18n/RTL category)

The i18n/RTL category is currently empty, but the agent's known scars are baked in
here so they cannot recur:

1. **No EN key ships without its HE mirror.** Every row in this catalog has both columns filled. A row with an empty HE cell is incomplete and blocks release. (`troubleshooting-archivist` records any HE-mirror miss here.)
2. **`he` is a complete file, never a partial overlay.** Every key present in `en` is present in `he`. A key added to `en` later must be added to `he` in the same change.
3. **RTL is a flip, not a redesign.** Strings carry no directional baked-in assumptions; layout mirrors via logical properties. Directional copy (e.g. "next →") uses logical wording; the arrow is mirrored by CSS, not the string.
4. **Hebrew display uses `--to-track-he` (2px), not the Latin 5px** — extreme tracking breaks Hebrew kerning (noted per surface in `design-tokens.md`).
5. **Counts use `trans_choice`** (not string concatenation) so plural rules differ correctly per locale.
6. **Currency / numbers / dates are locale-formatted**, never hardcoded `$` — the catalog stores the *template* (`:amount`), the formatter supplies the symbol/locale.
7. **Interpolation is named (`:count`, `:amount`, `:product`)**, never positional.

## Two string systems — kept separate

This catalog is the **`__()` / widget-lookup** system. The Super-Admin **prompt**
control plane is a **separate** system: prompts are DB-managed text substituted with
`strtr()` (never `Blade::render()`, never `__()`). Do not add prompt text to this
catalog.

---

# Admin catalog (Filament — `__()`)

## `nav.*`

| Key | en | he |
|---|---|---|
| `nav.dashboard` | Dashboard | לוח בקרה |
| `nav.sites` | Sites | אתרים |
| `nav.products` | Products | מוצרים |
| `nav.leads` | Leads | לידים |
| `nav.credits` | Credits | קרדיטים |
| `nav.settings` | Settings | הגדרות |
| `nav.platform` | Platform | פלטפורמה |

## `states.*` (shared empty/loading/error)

| Key | en | he |
|---|---|---|
| `states.loading` | Loading… | טוען… |
| `states.empty` | Nothing here yet | אין כאן עדיין כלום |
| `states.no_data` | No data | אין נתונים |
| `states.no_results` | No results match your filters | אין תוצאות התואמות את הסינון |
| `states.load_failed` | Couldn't load this. Try again. | לא הצלחנו לטעון. נסו שוב. |
| `states.retry` | Retry | נסו שוב |

## `actions.*`

| Key | en | he |
|---|---|---|
| `actions.save` | Save | שמירה |
| `actions.cancel` | Cancel | ביטול |
| `actions.confirm` | Confirm | אישור |
| `actions.delete` | Delete | מחיקה |
| `actions.edit` | Edit | עריכה |
| `actions.working` | Working… | מעבד… |
| `actions.dismiss` | Dismiss | סגירה |
| `actions.clear_filters` | Clear filters | ניקוי סינון |
| `actions.confirm.delete_title` | Delete this? | למחוק את זה? |
| `actions.confirm.delete_body` | This can't be undone. | אי אפשר לבטל פעולה זו. |

## `dashboard.*`

| Key | en | he |
|---|---|---|
| `dashboard.title` | Overview | סקירה |
| `dashboard.kpi.balance` | Credit balance | יתרת קרדיטים |
| `dashboard.kpi.generations_today` | Try-ons today | תמונות היום |
| `dashboard.kpi.generations_total` | Try-ons total | סך התמונות |
| `dashboard.kpi.leads` | Leads | לידים |
| `dashboard.kpi.conversion` | Add-to-cart rate | אחוז הוספה לעגלה |
| `dashboard.kpi.delta_up` | up :pct% | עלייה של :pct% |
| `dashboard.kpi.delta_down` | down :pct% | ירידה של :pct% |

## `sites.*`

| Key | en | he |
|---|---|---|
| `sites.title` | Sites | אתרים |
| `sites.add` | Add site | הוספת אתר |
| `sites.field.domain` | Domain | דומיין |
| `sites.field.name` | Display name | שם תצוגה |
| `sites.field.origins` | Allowed origins | דומיינים מורשים |
| `sites.empty` | Add your first site to get started | הוסיפו אתר ראשון כדי להתחיל |
| `sites.errors.duplicate` | A site with this domain already exists | כבר קיים אתר עם דומיין זה |
| `sites.errors.invalid_domain` | Enter a valid domain | הזינו דומיין תקין |

## `scan.*` (PDP ingestion)

| Key | en | he |
|---|---|---|
| `scan.title` | Review scanned product | בדיקת מוצר שנסרק |
| `scan.paste_prompt` | Paste a product page URL | הדביקו כתובת של עמוד מוצר |
| `scan.action.scan` | Scan | סריקה |
| `scan.scanning` | Reading your product page… | קוראים את עמוד המוצר… |
| `scan.confidence.high` | High confidence | ביטחון גבוה |
| `scan.confidence.medium` | Please confirm | נא לאשר |
| `scan.confidence.low` | Low confidence — please review | ביטחון נמוך — נא לבדוק |
| `scan.confidence.none` | Not detected — add it manually | לא זוהה — הוסיפו ידנית |
| `scan.field.title` | Product title | שם המוצר |
| `scan.field.price` | Price | מחיר |
| `scan.field.description` | Description | תיאור |
| `scan.field.variants` | Variants | וריאציות |
| `scan.field.dimensions` | Physical dimensions | מידות פיזיות |
| `scan.selector.add_to_cart` | "Add to cart" button | כפתור "הוספה לעגלה" |
| `scan.selector.product_image` | Product image | תמונת המוצר |
| `scan.selector.title` | Title element | רכיב הכותרת |
| `scan.selector.price` | Price element | רכיב המחיר |
| `scan.selector.variations` | Variations element | רכיב הוריאציות |
| `scan.selector.detected` | Detected selector | סלקטור שזוהה |
| `scan.selector.manual` | Enter selector manually | הזינו סלקטור ידנית |
| `scan.selector.pick` | Pick on page | בחירה מהעמוד |
| `scan.selector.test` | Test selector | בדיקת סלקטור |
| `scan.selector.test_ok` | Matches an element | תואם לרכיב בעמוד |
| `scan.selector.test_fail` | No match found | לא נמצאה התאמה |
| `scan.action.rescan` | Re-scan | סריקה מחדש |
| `scan.action.confirm` | Confirm product | אישור המוצר |
| `scan.blocked.reason` | Review the flagged fields before confirming | בדקו את השדות המסומנים לפני האישור |
| `scan.firstgen.test` | Test on a live product page | בדיקה על עמוד מוצר חי |
| `scan.firstgen.success` | Your widget is live | הווידג'ט שלכם פעיל |
| `scan.firstgen.error` | The widget didn't load — see setup help | הווידג'ט לא נטען — ראו עזרה בהתקנה |
| `scan.errors.unreachable` | We couldn't reach that URL | לא הצלחנו לגשת לכתובת |
| `scan.errors.not_pdp` | That doesn't look like a product page | זה לא נראה כמו עמוד מוצר |
| `scan.errors.failed` | The scan failed. Try again. | הסריקה נכשלה. נסו שוב. |

## `embed.*`

| Key | en | he |
|---|---|---|
| `embed.title` | Install code | קוד התקנה |
| `embed.copy` | Copy | העתקה |
| `embed.copied` | Copied | הועתק |
| `embed.regenerate` | Regenerate key | יצירת מפתח חדש |
| `embed.regenerate_warning` | The old key will stop working immediately | המפתח הישן יפסיק לעבוד מיד |
| `embed.install_hint` | Paste this before </body> on your product pages | הדביקו לפני </body> בעמודי המוצר |
| `embed.errors.regenerate` | Couldn't regenerate the key. Try again. | יצירת המפתח נכשלה. נסו שוב. |

## `leads.*`

| Key | en | he |
|---|---|---|
| `leads.title` | Leads | לידים |
| `leads.col.name` | Name | שם |
| `leads.col.email` | Email | אימייל |
| `leads.col.phone` | Phone | טלפון |
| `leads.col.status` | Status | סטטוס |
| `leads.col.tries` | Tries used | ניסיונות |
| `leads.col.last_attempt` | Last attempt | ניסיון אחרון |
| `leads.empty` | No leads yet | אין עדיין לידים |
| `leads.history.empty` | No try-ons yet | אין עדיין תמונות |
| `leads.history.col.product` | Product | מוצר |
| `leads.history.col.variant` | Variant | וריאציה |
| `leads.history.col.result` | Result | תוצאה |
| `leads.history.col.when` | When | מתי |
| `leads.history.purged` | Image removed (retention) | התמונה הוסרה (מדיניות שמירה) |

## `credits.*`

| Key | en | he |
|---|---|---|
| `credits.balance` | Credit balance | יתרת קרדיטים |
| `credits.ledger.empty` | No transactions yet | אין עדיין תנועות |
| `credits.ledger.col.date` | Date | תאריך |
| `credits.ledger.col.type` | Type | סוג |
| `credits.ledger.col.amount` | Amount | סכום |
| `credits.ledger.col.balance_after` | Balance after | יתרה לאחר |
| `credits.ledger.col.reference` | Reference | אסמכתא |
| `credits.buy.title` | Buy credits | רכישת קרדיטים |
| `credits.buy.amount` | Amount | סכום |
| `credits.buy.confirm` | Continue to payment | המשך לתשלום |
| `credits.buy.pending` | Redirecting to payment… | מעבירים לתשלום… |
| `credits.buy.success` | Credits added | הקרדיטים נוספו |
| `credits.buy.errors.failed` | Payment didn't complete. No charge was made. | התשלום לא הושלם. לא בוצע חיוב. |

## `merchant.credit.*` (banner)

| Key | en | he |
|---|---|---|
| `merchant.credit.low` | You're running low on credits | יתרת הקרדיטים שלכם נמוכה |
| `merchant.credit.empty` | You're out of credits. Buy more to keep generating try-ons. | נגמרו הקרדיטים. רכשו עוד כדי להמשיך ליצור תמונות. |
| `merchant.credit.buy_cta` | Buy credits | רכישת קרדיטים |

## `status.*` (the §5 badge map)

| Key | en | he |
|---|---|---|
| `status.generation.pending` | Pending | ממתין |
| `status.generation.processing` | Processing | בעיבוד |
| `status.generation.succeeded` | Succeeded | הצליח |
| `status.generation.failed` | Failed | נכשל |
| `status.generation.cancelled` | Cancelled | בוטל |
| `status.ledger.grant` | Grant | זיכוי |
| `status.ledger.purchase` | Purchase | רכישה |
| `status.ledger.charge` | Charge | חיוב |
| `status.ledger.refund` | Refund | החזר |
| `status.ledger.adjustment` | Adjustment | התאמה |
| `status.credit.low` | Low | נמוך |
| `status.credit.empty` | Out of credits | אזל |
| `status.lead.new` | New | חדש |
| `status.lead.generated` | Generated | יצר תמונה |
| `status.lead.added_to_cart` | Added to cart | הוסיף לעגלה |
| `status.lead.purchased` | Purchased | רכש |
| `status.lead.incomplete` | Incomplete | לא הושלם |

## `platform.*` (Super-Admin control plane)

| Key | en | he |
|---|---|---|
| `platform.title` | Platform control | ניהול פלטפורמה |
| `platform.models.title` | AI models | מודלים |
| `platform.models.col.model_id` | Model ID | מזהה מודל |
| `platform.models.col.operation` | Operation | פעולה |
| `platform.models.col.default` | Default | ברירת מחדל |
| `platform.models.col.fallback` | Fallback | גיבוי |
| `platform.models.col.cost_hint` | Cost hint | עלות משוערת |
| `platform.prompts.title` | Prompts | פרומפטים |
| `platform.prompts.field.scope` | Scope | היקף |
| `platform.prompts.field.operation` | Operation | פעולה |
| `platform.prompts.field.product_type` | Product type | סוג מוצר |
| `platform.prompts.field.system` | System prompt | פרומפט מערכת |
| `platform.prompts.field.user` | User prompt | פרומפט משתמש |
| `platform.prompts.field.version` | Version | גרסה |
| `platform.operations.title` | AI operations | פעולות AI |
| `platform.operations.field.quality` | Image quality | איכות תמונה |
| `platform.operations.field.aspect` | Aspect ratio | יחס תצוגה |
| `platform.operations.field.retention` | Retention | מדיניות שמירה |
| `platform.operations.field.multiplier` | Credit multiplier | מכפיל קרדיט |
| `platform.accounts.title` | Accounts | חשבונות |
| `platform.sites.title` | Sites | אתרים |
| `platform.credits.grant` | Grant credits | הענקת קרדיטים |
| `platform.credits.adjust` | Adjust balance | התאמת יתרה |
| `platform.resolver.preview` | Preview resolved | תצוגה מקדימה של ההכרעה |
| `platform.resolver.winner` | Winning prompt | פרומפט נבחר |
| `platform.resolver.trace` | Resolution order | סדר הכרעה |
| `platform.resolver.fellthrough` | Fell through to global | נפל לברירת מחדל גלובלית |

---

# Widget catalog (storefront — widget lookup)

## `widget.button.*` / `widget.modal.*`

| Key | en | he |
|---|---|---|
| `widget.button.label` | Tray On | מדדו את זה |
| `widget.button.loading` | Loading… | טוען… |
| `widget.modal.title` | Tray On | מדדו את זה |
| `widget.modal.close` | Close | סגירה |

## `widget.upload.*`

| Key | en | he |
|---|---|---|
| `widget.upload.prompt` | Add a photo of yourself | הוסיפו תמונה שלכם |
| `widget.upload.hint` | A clear, well-lit photo works best | תמונה ברורה ומוארת תעבוד הכי טוב |
| `widget.upload.uploading` | Uploading… | מעלים… |
| `widget.upload.replace` | Replace photo | החלפת תמונה |
| `widget.upload.remove` | Remove | הסרה |
| `widget.upload.errors.type` | Please use a JPG or PNG image | אנא השתמשו בתמונת JPG או PNG |
| `widget.upload.errors.size` | That image is too large | התמונה גדולה מדי |
| `widget.upload.errors.failed` | Upload failed. Try again. | ההעלאה נכשלה. נסו שוב. |

## `widget.height.*` / `widget.details.*`

| Key | en | he |
|---|---|---|
| `widget.height.label` | Your height | הגובה שלכם |
| `widget.height.unit_cm` | cm | ס"מ |
| `widget.height.unit_in` | in | אינץ' |
| `widget.height.errors.range` | Enter a height between :min and :max | הזינו גובה בין :min ל-:max |
| `widget.details.toggle` | Add details (optional) | הוספת פרטים (לא חובה) |
| `widget.details.body` | Body type | מבנה גוף |
| `widget.details.age` | Age range | טווח גיל |
| `widget.details.gender` | Gender | מגדר |
| `widget.details.angle` | Photo angle | זווית הצילום |

## `widget.consent.*` / `widget.privacy.*` (explicit — never vague)

| Key | en | he |
|---|---|---|
| `widget.consent.photo` | I agree to let Tray On use my photo to generate a virtual try-on of this product. | אני מאשר/ת ל-Tray On להשתמש בתמונה שלי כדי ליצור הדמיה של המוצר עליי. |
| `widget.consent.privacy_link` | How we handle your photo | איך אנחנו מטפלים בתמונה שלכם |
| `widget.consent.retention` | Your photo is kept for :days days, then deleted. | התמונה שלכם נשמרת :days ימים ואז נמחקת. |
| `widget.consent.required` | Please agree before we generate your try-on | יש לאשר לפני יצירת ההדמיה |

## `widget.cta.*` (generate gate)

| Key | en | he |
|---|---|---|
| `widget.cta.generate` | Generate my try-on | יצירת ההדמיה שלי |
| `widget.cta.need_photo` | Add a photo to continue | הוסיפו תמונה כדי להמשיך |
| `widget.cta.need_height` | Add your height to continue | הוסיפו גובה כדי להמשיך |
| `widget.cta.need_consent` | Agree to the terms to continue | אשרו את התנאים כדי להמשיך |

## `widget.loading.*`

| Key | en | he |
|---|---|---|
| `widget.loading.title` | Creating your try-on… | יוצרים את ההדמיה שלכם… |
| `widget.loading.sub` | This takes a few seconds | זה לוקח כמה שניות |
| `widget.loading.cancel` | Cancel | ביטול |
| `widget.loading.timeout` | This is taking longer than usual. Try again? | זה לוקח יותר מהרגיל. לנסות שוב? |

## `widget.result.*` / `widget.cart.*`

| Key | en | he |
|---|---|---|
| `widget.result.title` | Here's your try-on | הנה ההדמיה שלכם |
| `widget.result.low_quality` | Not quite right? Try again for a better result. | לא מדויק? נסו שוב לתוצאה טובה יותר. |
| `widget.result.error` | Something went wrong. You weren't charged — try again. | משהו השתבש. לא חויבתם — נסו שוב. |
| `widget.result.regenerate` | Try again | נסו שוב |
| `widget.result.change_photo` | Change photo | החלפת תמונה |
| `widget.result.change_height` | Change height | שינוי גובה |
| `widget.result.add_to_cart` | Add this to cart | הוספה לעגלה |
| `widget.result.back` | Back to product | חזרה למוצר |
| `widget.cart.added` | Added to cart | נוסף לעגלה |

## `widget.gallery.*`

| Key | en | he |
|---|---|---|
| `widget.gallery.title` | Your try-ons | ההדמיות שלכם |
| `widget.gallery.empty` | Your try-ons will appear here | ההדמיות שלכם יופיעו כאן |
| `widget.gallery.error` | Couldn't load your try-ons | לא הצלחנו לטעון את ההדמיות |
| `widget.gallery.open` | View full size | תצוגה מלאה |
| `widget.gallery.add_to_cart` | Add to cart | הוספה לעגלה |
| `widget.gallery.regenerate` | Try again | נסו שוב |
| `widget.gallery.delete` | Delete | מחיקה |
| `widget.gallery.delete_confirm` | Delete this try-on? | למחוק את ההדמיה? |

## `widget.tries.*` (free-tries / lead gate — states the consequence)

| Key | en | he |
|---|---|---|
| `widget.tries.left` | `{0} No free tries left\|{1} :count free try left\|[2,*] :count free tries left` | `{0} לא נותרו ניסיונות חינם\|{1} נותר :count ניסיון חינם\|[2,*] נותרו :count ניסיונות חינם` |
| `widget.tries.last` | Last free try — after this, a quick sign-up keeps you going | ניסיון חינם אחרון — לאחר מכן הרשמה קצרה תאפשר להמשיך |
| `widget.tries.exhausted_title` | Sign up to keep trying | הירשמו כדי להמשיך |
| `widget.tries.exhausted_body` | Create a free account to continue generating try-ons | פתחו חשבון חינם כדי להמשיך ליצור הדמיות |
| `widget.tries.unlimited` | Unlimited try-ons | הדמיות ללא הגבלה |
| `widget.tries.gated` | Thanks for signing up — try-ons are limited here | תודה על ההרשמה — מספר ההדמיות מוגבל כאן |

## `widget.signup.*` (lead capture)

| Key | en | he |
|---|---|---|
| `widget.signup.title` | Quick sign-up | הרשמה מהירה |
| `widget.signup.name` | Full name | שם מלא |
| `widget.signup.email` | Email | אימייל |
| `widget.signup.phone` | Phone | טלפון |
| `widget.signup.phone_optional` | Phone (optional) | טלפון (לא חובה) |
| `widget.signup.why` | We use this to save your try-ons and keep you updated | אנחנו משתמשים בזה כדי לשמור את ההדמיות ולעדכן אתכם |
| `widget.signup.consent` | I agree to the terms and privacy policy | אני מאשר/ת את התנאים ומדיניות הפרטיות |
| `widget.signup.submit` | Continue | המשך |
| `widget.signup.errors.email_taken` | This email is already registered | האימייל הזה כבר רשום |
| `widget.signup.errors.network` | Couldn't sign you up. Try again. | ההרשמה נכשלה. נסו שוב. |
| `widget.signup.success` | You're all set | הכול מוכן |

## `widget.unavailable.*` (shopper out-of-credit — blame-free)

| Key | en | he |
|---|---|---|
| `widget.unavailable.title` | Try-on isn't available right now | ההדמיה אינה זמינה כרגע |
| `widget.unavailable.body` | Please check back soon | אנא נסו שוב בקרוב |

## `widget.errors.*` (generic)

| Key | en | he |
|---|---|---|
| `widget.errors.generic` | Something went wrong. Please try again. | משהו השתבש. אנא נסו שוב. |
| `widget.errors.network` | Check your connection and try again | בדקו את החיבור ונסו שוב |

---

## RTL notes (per surface — anything not a pure logical-property flip)

| Surface | RTL note |
|---|---|
| Widget modal | Close affordance + back arrows mirror (`scaleX(-1)` or swapped SVG). Eyebrow tracking uses `--to-track-he` (2px) for the HE display moment. |
| Gallery slider | Scroll direction flips — the newest tile starts at the inline-start (right in HE). |
| Height input | cm/in toggle sits at inline-end; placement mirrors automatically with logical properties. |
| Admin tables | Column order flips; numeric/currency cells keep `text-align: end` so digits align. |
| Credit ledger amounts | `:amount` formatted by the locale currency formatter (₪ vs $ per locale); the `±` sign and color (success/danger) are direction-agnostic. |
| Free-tries chip | `:count` via `trans_choice`; HE plural rules differ (1 / 2 / many) — the catalog encodes all three forms. |
| Dates / relative time | "2 hours ago" via the locale formatter, never a concatenated string. |

## Mirror-integrity rule (for `admin-design-system` + `widget-embed`)

When `lang/en/*` and `lang/he/*` are generated from this catalog, a CI/spec check must
assert **key-set equality** between the two locales per file. Any key present in one
and absent in the other is a release blocker. New keys are added to **both** locales in
the same change — never EN-only.
