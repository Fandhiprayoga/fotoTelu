/* Local framing aid only. The server still validates every saved selfie. */
export class StableFace {
    constructor() { this.reset(); }
    reset() { this.since = null; this.anchor = null; this.samples = 0; this.lastSeen = null; }
    check(faces, width, height, now) {
        if (this.lastSeen !== null && (now - this.lastSeen > 750 || now < this.lastSeen)) this.reset();
        this.lastSeen = now;
        const reject = (message) => { this.reset(); return { capture: false, message }; };
        if (faces.length !== 1) return reject(faces.length ? 'Lebih dari satu wajah terlihat. Pastikan hanya Anda di depan kamera.' : 'Mencari wajah… Hadap lurus dan gunakan cahaya dari depan.');
        const b = faces[0].boundingBox;
        if (!b || ![b.originX, b.originY, b.width, b.height, width, height].every(Number.isFinite)
            || width <= 0 || height <= 0 || b.width <= 0 || b.height <= 0) return reject('Posisikan seluruh wajah di dalam oval.');
        const x = (b.originX + b.width / 2) / width;
        const y = (b.originY + b.height / 2) / height;
        const w = b.width / width;
        const h = b.height / height;
        if (w < 0.18 || h < 0.25) return reject('Dekatkan wajah ke kamera hingga memenuhi panduan oval.');
        if (w > 0.65 || h > 0.85 || b.originX < 0 || b.originY < 0
            || b.originX + b.width > width || b.originY + b.height > height) return reject('Mundur sedikit agar seluruh wajah terlihat.');
        if (Math.abs(x - 0.5) > 0.12 || Math.abs(y - 0.5) > 0.15) return reject('Geser wajah ke tengah panduan oval.');
        if (!this.anchor || Math.abs(x - this.anchor.x) > 0.035 || Math.abs(y - this.anchor.y) > 0.035
            || Math.abs(w - this.anchor.w) > 0.05 || Math.abs(h - this.anchor.h) > 0.05) {
            this.since = now;
            this.anchor = { x, y, w, h };
            this.samples = 0;
        }
        this.samples += 1;
        const remaining = Math.max(0, 2000 - (now - this.since));
        return { capture: remaining === 0 && this.samples >= 6,
            message: `Satu wajah terlihat. Tahan posisi… ${Math.max(1, Math.ceil(remaining / 1000))} detik.`, aligned: true };
    }
}

export async function createLocalDetector() {
    const { FaceDetector, FilesetResolver } = await import('../vendor/mediapipe/vision_bundle.mjs');
    const files = await FilesetResolver.forVisionTasks(new URL('../vendor/mediapipe/wasm', import.meta.url).href);
    return FaceDetector.createFromOptions(files, {
        baseOptions: { modelAssetPath: new URL('../vendor/mediapipe/blaze_face_short_range.tflite', import.meta.url).href, delegate: 'CPU' },
        runningMode: 'VIDEO', minDetectionConfidence: 0.7,
    });
}