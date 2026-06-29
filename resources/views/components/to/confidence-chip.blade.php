{{--
    A4 — Confidence chip. The scan field/selector confidence indicator. Its own
    visual scale (high / medium / low / not_detected — the §5 scan scale), kept
    SEPARATE from the status-badge machine: a confidence level is not a status.
    The tone class keys straight off the bucketed level from the ScanReview
    contract; the label is the contract's confidence_i18n_key (scan.confidence.*).

    Props:
      level     high|medium|low|not_detected (ConfidenceLevel->level)
      labelKey  the contract's confidence_i18n_key (scan.confidence.*)

    TOKENS: scan-review.css (.to-conf--{level}). i18n: the passed labelKey.
--}}
@props([
    'level',
    'labelKey',
])
<span {{ $attributes->class(['to-conf', 'to-conf--' . $level]) }}>
    <span class="to-conf__dot" aria-hidden="true"></span>
    {{ __($labelKey) }}
</span>
