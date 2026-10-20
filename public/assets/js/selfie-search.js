/* Camera stays in the browser. Only the normal CSRF-protected PHP form uploads a file. */
(() => {
    'use strict';
    document.querySelectorAll('[data-selfie-form]').forEach((form) => {
        const fileInput = form.querySelector('[data-selfie-file]');
        const openButton = form.querySelector('[data-camera-open]');
        const panel = form.querySelector('[data-camera-panel]');
        const video = form.querySelector('[data-camera-video]');
        const captureButton = form.querySelector('[data-camera-capture]');
        const closeButton = form.querySelector('[data-camera-close]');
        const preview = form.querySelector('[data-selfie-preview]');
        const status = form.querySelector('[data-camera-status]');
        const countdown = form.querySelector('[data-camera-countdown]');
        const autoToggle = form.querySelector('[data-camera-auto]');
        const oval = form.querySelector('.ph-camera-oval');
        if (!fileInput || !openButton || !panel || !video || !captureButton || !closeButton || !preview || !status) return;

        let stream = null;
        let previewUrl = null;
        let generation = 0;
        let ready = false;
        let capturing = false;
        let readyTimer = null;
        let startupTimer = null;
        let autoTimer = null;
        let detector = null;
        let autoSession = 0;
        const stopAuto = () => {
            autoSession += 1;
            clearTimeout(autoTimer);
            if (detector) detector.close();
            detector = null;
            if (oval) oval.classList.remove('ph-face-aligned');
        };
        const startAuto = async () => {
            stopAuto();
            if (!autoToggle?.checked || !ready || capturing) return;
            const session = autoSession;
            const current = () => session === autoSession && ready && !capturing && autoToggle.checked;
            announce('Menyiapkan deteksi wajah otomatis di perangkat… Tombol manual tetap tersedia.');
            try {
                const { createLocalDetector, StableFace } = await import(form.dataset.autoModule);
                if (!current()) return;
                const local = await createLocalDetector();
                if (!current()) { local.close(); return; }
                detector = local;
                const tracker = new StableFace();
                let lastVideoTime = -1;
                const tick = () => {
                    if (!current()) return;
                    try {
                        if (!hasFrame() || lastVideoTime === video.currentTime) {
                            tracker.reset();
                            if (oval) oval.classList.remove('ph-face-aligned');
                        } else {
                            lastVideoTime = video.currentTime;
                            const result = local.detectForVideo(video, performance.now());
                            const state = tracker.check(result.detections, video.videoWidth, video.videoHeight, performance.now());
                            if (oval) oval.classList.toggle('ph-face-aligned', !!state.aligned);
                            announce(state.message);
                            if (state.capture) { void capturePhoto(true); return; }
                        }
                        autoTimer = setTimeout(tick, 250);
                    } catch (_) {
                        stopAuto();
                        announce('Deteksi otomatis tidak tersedia. Gunakan tombol Ambil foto atau unggah dari perangkat.');
                    }
                };
                tick();
            } catch (_) {
                if (!current()) return;
                stopAuto();
                announce('Deteksi otomatis tidak tersedia di browser ini. Gunakan tombol Ambil foto atau unggah dari perangkat.');
            }
        };
        const announce = (message) => { status.textContent = message; };
        const releasePreview = () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
            preview.removeAttribute('src');
            preview.hidden = true;
        };
        const stopCamera = () => {
            stopAuto();
            generation += 1;
            clearTimeout(readyTimer);
            clearTimeout(startupTimer);
            readyTimer = null;
            ready = false;
            capturing = false;
            if (countdown) { countdown.hidden = true; countdown.textContent = ''; }
            if (stream) stream.getTracks().forEach((track) => track.stop());
            stream = null;
            video.pause();
            video.srcObject = null;
            panel.hidden = true;
            openButton.disabled = false;
            captureButton.disabled = true;
            captureButton.textContent = 'Menyiapkan kamera…';
        };
        const hasFrame = () => stream && stream.active && !video.paused
            && video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA
            && video.videoWidth > 0 && video.videoHeight > 0;
        const prepareCapture = () => {
            if (!hasFrame() || ready || readyTimer !== null || capturing) return;
            const expectedGeneration = generation;
            // Allow exposure/focus to settle after the first playable frame.
            readyTimer = setTimeout(() => {
                readyTimer = null;
                if (expectedGeneration !== generation || !hasFrame()) return;
                ready = true;
                clearTimeout(startupTimer);
                captureButton.disabled = false;
                captureButton.textContent = 'Ambil foto · 3 detik';
                announce('Kamera siap. Posisikan wajah di dalam oval, lalu pilih Ambil foto. Foto diambil setelah hitung mundur 3 detik.');
                void startAuto();
            }, 1000);
        };
        ['loadeddata', 'canplay', 'playing'].forEach((event) => video.addEventListener(event, prepareCapture));
        video.addEventListener('waiting', () => {
            if (!stream || capturing) return;
            stopAuto();
            clearTimeout(readyTimer);
            readyTimer = null;
            ready = false;
            captureButton.disabled = true;
            captureButton.textContent = 'Menyiapkan kamera…';
            announce('Menunggu gambar kamera kembali siap…');
        });
        const showPreview = (file) => {
            releasePreview();
            if (file && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                previewUrl = URL.createObjectURL(file);
                preview.src = previewUrl;
                preview.hidden = false;
            }
        };
        preview.addEventListener('error', () => {
            releasePreview();
            announce('Pratinjau tidak dapat dibaca. Coba berkas JPEG, PNG, atau WebP lain.');
        });
        fileInput.addEventListener('change', () => {
            stopCamera();
            const file = fileInput.files && fileInput.files[0];
            showPreview(file);
            announce(file ? 'Foto dipilih. Periksa pratinjau dan persetujuan sebelum menyimpan.' : 'Belum ada foto dipilih.');
        });

        let canAssignFile = false;
        try {
            const transfer = new DataTransfer();
            canAssignFile = !!transfer.items && typeof File === 'function';
        } catch (_) {
            // Unsupported browsers keep the regular file picker.
        }
        const cameraSupported = window.isSecureContext && navigator.mediaDevices
            && typeof navigator.mediaDevices.getUserMedia === 'function' && canAssignFile;
        if (!cameraSupported) {
            announce('Gunakan pilihan berkas. Kamera memerlukan HTTPS/localhost dan browser yang mendukung pengambilan foto.');
        } else {
            openButton.hidden = false;
        }

        openButton.addEventListener('click', async () => {
            stopCamera();
            const requestGeneration = generation;
            openButton.disabled = true;
            announce('Meminta izin kamera…');
            try {
                const requestedStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1280 } },
                    audio: false,
                });
                if (requestGeneration !== generation || document.hidden) {
                    requestedStream.getTracks().forEach((track) => track.stop());
                    return;
                }
                stream = requestedStream;
                stream.getVideoTracks().forEach((track) => track.addEventListener('ended', () => {
                    if (stream !== requestedStream) return;
                    stopCamera();
                    announce('Kamera terputus. Buka kamera kembali atau pilih foto dari perangkat.');
                }));
                video.srcObject = stream;
                panel.hidden = false;
                announce('Menyiapkan gambar kamera. Tunggu hingga tombol Ambil foto aktif…');
                startupTimer = setTimeout(() => {
                    if (requestGeneration !== generation || ready) return;
                    stopCamera();
                    announce('Kamera belum menghasilkan gambar. Buka kamera kembali atau gunakan unggahan berkas.');
                }, 15000);
                await video.play();
                if (requestGeneration !== generation) return;
                prepareCapture();
            } catch (_) {
                if (requestGeneration !== generation) return;
                stopCamera();
                announce('Kamera tidak dapat dibuka atau izin ditolak. Gunakan unggahan berkas sebagai alternatif.');
            }
        });
        closeButton.addEventListener('click', () => {
            stopCamera();
            announce('Kamera ditutup. Tidak ada foto yang dikirim.');
            openButton.focus();
        });
        const capturePhoto = async (automatic = false) => {
            if (!ready || capturing || !hasFrame()) {
                announce('Kamera belum siap. Tunggu hingga pratinjau terlihat.');
                return;
            }
            captureButton.disabled = true;
            capturing = true;
            stopAuto();
            const expectedGeneration = generation;
            const canvas = document.createElement('canvas');
            try {
                for (let seconds = automatic ? 0 : 3; seconds > 0; seconds -= 1) {
                    if (countdown) { countdown.hidden = false; countdown.textContent = String(seconds); }
                    captureButton.textContent = `Mengambil dalam ${seconds}…`;
                    announce(`Tahan posisi dan lihat kamera. Foto diambil dalam ${seconds} detik.`);
                    await new Promise((resolve) => setTimeout(resolve, 1000));
                    if (expectedGeneration !== generation) return;
                }
                if (!hasFrame()) throw new Error('unavailable');
                const scale = Math.min(1, 1600 / Math.max(video.videoWidth, video.videoHeight));
                canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
                canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
                const context = canvas.getContext('2d');
                if (!context) throw new Error('unavailable');
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                // Release hardware immediately after capture, including failed encodes.
                stopCamera();
                const captureGeneration = generation;
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
                if (captureGeneration !== generation) return;
                if (!blob) throw new Error('unavailable');
                const file = new File([blob], 'selfie-kamera.jpg', { type: 'image/jpeg', lastModified: Date.now() });
                const transfer = new DataTransfer();
                transfer.items.add(file);
                fileInput.files = transfer.files;
                if (!fileInput.files.length) throw new Error('unavailable');
                showPreview(file);
                announce('Foto kamera siap. Foto belum dikirim; berikan persetujuan lalu pilih Simpan.');
                openButton.focus();
            } catch (_) {
                stopCamera();
                announce('Foto kamera belum dapat digunakan. Silakan pilih foto dari perangkat.');
            } finally {
                // Drop canvas pixels as soon as conversion is complete.
                canvas.width = 0;
                canvas.height = 0;
            }
        };
        captureButton.addEventListener('click', () => { void capturePhoto(); });
        autoToggle?.addEventListener('change', () => {
            if (autoToggle.checked) void startAuto();
            else {
                stopAuto();
                if (ready && !capturing) announce('Mode manual aktif. Pilih Ambil foto untuk hitung mundur 3 detik.');
            }
        });
        form.addEventListener('submit', () => {
            stopCamera();
            releasePreview();
            announce('Menyimpan selfie secara privat. Tunggu proses selesai…');
        });
        window.addEventListener('pagehide', () => { stopCamera(); releasePreview(); });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stopCamera();
        });
    });
})();