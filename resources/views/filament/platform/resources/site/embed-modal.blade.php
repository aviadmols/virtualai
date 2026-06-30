{{-- P3 — the per-site install snippet, shown to a super-admin in a modal. Reuses the
     shared embed-code component; only the PUBLIC site_key is shown (widget_secret is
     never passed). No regenerate control here — key rotation is the merchant's action. --}}
<x-to.embed-code
    :siteKey="$siteKey"
    :scriptSrc="$scriptSrc"
/>
