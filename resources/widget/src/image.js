// === CONSTANTS ===
// Client-side image validate + downscale BEFORE upload. Huge originals are slow, costly,
// and main-thread-blocking. We hard-reject by type/size pre-decode, then downscale the
// long edge to IMAGE_MAX_EDGE_PX (agreed with ai-openrouter) and re-encode smaller. The
// server re-validates; this just keeps the upload sane and the host UI responsive.

import {
  IMAGE_ACCEPTED_TYPES,
  IMAGE_HARD_MAX_BYTES,
  IMAGE_MAX_EDGE_PX,
  IMAGE_OUTPUT_TYPE,
  IMAGE_OUTPUT_QUALITY,
} from './constants.js';

export class ImageError extends Error {
  constructor(code) {
    super(code);
    this.code = code; // 'type' | 'size' | 'failed' — maps to i18n upload.errors.*
  }
}

/**
 * Validate + downscale a File. Returns a base64 data URL (the FIELD_PHOTO the backend
 * decodes via PhotoInput). Throws ImageError('type'|'size'|'failed') on a problem.
 */
export async function prepare(file) {
  if (!file || !IMAGE_ACCEPTED_TYPES.includes(file.type)) {
    throw new ImageError('type');
  }
  if (file.size > IMAGE_HARD_MAX_BYTES) {
    throw new ImageError('size');
  }

  try {
    const bitmap = await decode(file);
    const { width, height } = fitWithin(bitmap.width, bitmap.height, IMAGE_MAX_EDGE_PX);
    const dataUrl = drawToDataUrl(bitmap, width, height);
    if (typeof bitmap.close === 'function') bitmap.close();
    return dataUrl;
  } catch (e) {
    if (e instanceof ImageError) throw e;
    throw new ImageError('failed');
  }
}

/** Decode off the main thread where supported (createImageBitmap), else an <img> fallback. */
async function decode(file) {
  if (typeof createImageBitmap === 'function') {
    return createImageBitmap(file);
  }
  return new Promise((resolve, reject) => {
    const img = new Image();
    const objectUrl = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(objectUrl);
      resolve(img);
    };
    img.onerror = () => {
      URL.revokeObjectURL(objectUrl);
      reject(new ImageError('failed'));
    };
    img.src = objectUrl;
  });
}

/** Scale (w,h) down so the long edge is at most maxEdge; never upscale. */
function fitWithin(w, h, maxEdge) {
  const longEdge = Math.max(w, h);
  if (longEdge <= maxEdge) return { width: w, height: h };
  const ratio = maxEdge / longEdge;
  return { width: Math.round(w * ratio), height: Math.round(h * ratio) };
}

/** Draw the (down)scaled bitmap to a canvas and re-encode to a compact data URL. */
function drawToDataUrl(bitmap, width, height) {
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  if (!ctx) throw new ImageError('failed');
  ctx.drawImage(bitmap, 0, 0, width, height);
  return canvas.toDataURL(IMAGE_OUTPUT_TYPE, IMAGE_OUTPUT_QUALITY);
}
