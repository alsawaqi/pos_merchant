/**
 * LAUNCH-P4 B5 — shrink a photo in the browser before it is uploaded: the
 * longest side at most 800 px, saved as JPEG, about 300 KB or less (the
 * server refuses more than 500 KB or 1600 px). Transparent areas become
 * white. The pure helpers (fitWithin, qualitySteps) have no browser APIs so
 * the node tests can run them.
 */

export const MAX_SIDE = 800;
export const TARGET_BYTES = 300 * 1024;

/** The width and height that fit inside max × max, keeping the shape; never upscales. */
export function fitWithin(width: number, height: number, max: number = MAX_SIDE): { width: number; height: number } {
    if (width <= 0 || height <= 0) return { width: 1, height: 1 };
    const scale = Math.min(1, max / width, max / height);
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

/** JPEG qualities tried in turn until the file is small enough. */
export function qualitySteps(): number[] {
    return [0.85, 0.75, 0.65, 0.55, 0.45];
}

function loadImage(file: Blob): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('decode failed'));
        };
        img.src = url;
    });
}

function toJpeg(canvas: HTMLCanvasElement, quality: number): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('encode failed'))), 'image/jpeg', quality);
    });
}

/**
 * Resize and re-encode a picked photo. Tries lower JPEG qualities, then a
 * smaller size, until the file is at most targetBytes.
 */
export async function resizeToJpeg(file: Blob, maxSide: number = MAX_SIDE, targetBytes: number = TARGET_BYTES): Promise<Blob> {
    const img = await loadImage(file);
    let side = maxSide;
    let last: Blob | null = null;
    for (let attempt = 0; attempt < 4; attempt++) {
        const size = fitWithin(img.naturalWidth || img.width, img.naturalHeight || img.height, side);
        const canvas = document.createElement('canvas');
        canvas.width = size.width;
        canvas.height = size.height;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('no canvas');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size.width, size.height);
        ctx.drawImage(img, 0, 0, size.width, size.height);
        for (const quality of qualitySteps()) {
            last = await toJpeg(canvas, quality);
            if (last.size <= targetBytes) return last;
        }
        side = Math.round(side * 0.75);
    }
    if (last === null) throw new Error('encode failed');
    return last;
}
