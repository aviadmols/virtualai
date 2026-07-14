// === CONSTANTS ===
// Share. `navigator.share({files})` needs the BYTES, not an <img> src — so we fetch the result
// through the same-origin bytes endpoint (GET /widget/v1/generations/{id}/image), which lives
// inside the signed widget API and therefore inherits the site_key + Origin allow-list and the
// per-origin CORS that lets a storefront read it as a Blob. No new public URL is minted, so Share
// adds no new privacy surface.
//
// Desktop has no share sheet, so we do not ship a dead end: we download the image and copy the
// product link, and say exactly that. A user who CANCELS the OS sheet gets silence — a "shared!"
// toast after someone cancelled is a lie.

import { SHARE_FILENAME } from '../constants.js';
import { warn } from '../dom.js';
import { state, api, t } from './bridge.js';

export const SHARE_OUTCOME = {
  shared: 'shared', // the OS sheet ran (or the user cancelled it) — say nothing
  fallback: 'fallback', // downloaded + link copied
  failed: 'failed',
};

/** Share the current look. Returns a SHARE_OUTCOME; never throws into the host page. */
export async function share(generationId, resultUrl) {
  const blob = await bytes(generationId, resultUrl);
  if (!blob) return SHARE_OUTCOME.failed;

  const file = toFile(blob);
  const text = t('share.text', { product: state.product?.name || '' });

  if (file && canShareFiles(file)) {
    try {
      await navigator.share({ files: [file], title: t('share.title'), text });
      return SHARE_OUTCOME.shared;
    } catch (e) {
      // The shopper dismissed the sheet. That is not a failure and gets no toast.
      if (e && e.name === 'AbortError') return SHARE_OUTCOME.shared;
      return SHARE_OUTCOME.failed;
    }
  }

  return fallback(blob);
}

/** The result bytes: the same-origin endpoint first, the signed media URL as a last resort. */
async function bytes(generationId, resultUrl) {
  if (generationId) {
    const blob = await api.getGenerationImageBlob(generationId, state.anonToken);
    if (blob) return blob;
  }

  // A cross-origin signed URL usually cannot be read as a Blob; if it can, take it.
  if (!resultUrl) return null;
  try {
    const response = await fetch(resultUrl, { mode: 'cors', credentials: 'omit' });
    if (!response.ok) return null;
    return await response.blob();
  } catch {
    return null;
  }
}

function toFile(blob) {
  try {
    return new File([blob], SHARE_FILENAME, { type: blob.type || 'image/png' });
  } catch {
    return null; // very old browsers have no File constructor -> the fallback path handles it
  }
}

function canShareFiles(file) {
  try {
    return (
      typeof navigator !== 'undefined' &&
      typeof navigator.share === 'function' &&
      typeof navigator.canShare === 'function' &&
      navigator.canShare({ files: [file] })
    );
  } catch {
    return false;
  }
}

/** No share sheet: save the image, copy the product link. One action, one honest message. */
async function fallback(blob) {
  let objectUrl = null;
  try {
    objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = SHARE_FILENAME;
    link.click();
  } catch {
    warn('share fallback: download failed');
    return SHARE_OUTCOME.failed;
  } finally {
    // Revoke on the next tick so the click has definitely started the download.
    if (objectUrl) setTimeout(() => URL.revokeObjectURL(objectUrl), 0);
  }

  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(location.href);
    }
  } catch {
    // The image still saved. A clipboard that refuses is not a failed share.
  }

  return SHARE_OUTCOME.fallback;
}
