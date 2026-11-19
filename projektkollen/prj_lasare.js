/**
 * ProjektKollen – prj_lasare.js
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Kameraläsare för QR-koder, byggd på en tidigare streckkodsläsare (siktesruta som motsvarar exakt
 * det avlästa området, avläsning ur beskuren bild i full upplösning, kontinuerlig fokus, zoom,
 * lampa, "Ta foto" som reserv och manuell inmatning). Används av provisionering.php och montering.php.
 *
 * Avkodare: inbyggd BarcodeDetector om den klarar QR (Chrome på Android), annars polyfillen
 * (barcode-detector, ZXing-C++/WebAssembly – Safari på iPhone). Polyfillen laddas av sidan.
 *
 * Användning:
 *   const l = PrjLasare.skapa({ onKod: function (text) { ... } });
 *   l.paus();       // sluta läsa (kameran fortsätter visa bild)
 *   l.fortsatt();   // läs igen; samma kod som nyss ignoreras en kort stund
 *   l.status('text');
 */
(function () {
    'use strict';

    const FORMAT = ['qr_code'];
    const SIKTE = 0.8;            // siktesrutan: 80 % × 80 % av den synliga bilden
    const SAMMA_KOD_MS = 2500;    // samma kod igen inom denna tid ignoreras

    function el(id) { return document.getElementById(id); }

    let detektor = null;
    async function hamtaDetektor() {
        if (detektor) return detektor;
        let Ctor = null;
        if ('BarcodeDetector' in window) {
            try {
                const f = await window.BarcodeDetector.getSupportedFormats();
                if (f.includes('qr_code')) Ctor = window.BarcodeDetector;
            } catch (e) {}
        }
        if (!Ctor && window.BarcodeDetectionAPI) Ctor = window.BarcodeDetectionAPI.BarcodeDetector;
        if (!Ctor) throw new Error('QR-läsaren kunde inte laddas');
        detektor = new Ctor({ formats: FORMAT });
        return detektor;
    }

    function forstaKod(koder) {
        for (const k of koder || []) {
            const v = String(k.rawValue || '').trim();
            if (v !== '') return v;
        }
        return null;
    }

    function skapa(inst) {
        const onKod = inst.onKod;
        let strom = null, spar = null, lasning = false, pausad = false, session = 0, timer = null, lampa = false;
        let senaste = '', senasteTid = 0;
        const duk = document.createElement('canvas');

        function status(t) { el('prj-lasare-status').textContent = t || ''; }

        function traff(text, kalla) {
            const nu = Date.now();
            if (kalla === 'kamera' && text === senaste && nu - senasteTid < SAMMA_KOD_MS) return false;
            senaste = text;
            senasteTid = nu;
            if (kalla !== 'manuell' && navigator.vibrate) navigator.vibrate(80);
            onKod(text, kalla);
            return true;
        }

        async function start() {
            if (strom) { pausad = false; return; }
            const s0 = ++session;
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                status('Kameran går inte att använda här. Använd "Ta foto" eller skriv in.');
                return;
            }
            status('Startar kameran…');
            try {
                await hamtaDetektor();
                const s = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }
                });
                if (s0 !== session) { s.getTracks().forEach(t => t.stop()); return; }
                strom = s;
            } catch (err) {
                status('Kameran kunde inte startas (' + (err.message || err) + '). Använd "Ta foto" eller skriv in.');
                return;
            }
            const video = el('prj-lasare-video');
            video.srcObject = strom;
            try { await video.play(); } catch (e) {}
            spar = strom.getVideoTracks()[0];
            lasning = true;
            pausad = false;
            el('prj-lasare-bild').classList.add('aktiv');
            el('prj-lasare-start').textContent = 'Stäng kameran';
            kontroller();
            status('Håll QR-koden i rutan, ca 10–20 cm bort.');
            loop(s0);
        }

        function stopp() {
            lasning = false;
            session++;
            clearTimeout(timer);
            if (strom) { strom.getTracks().forEach(t => t.stop()); strom = null; }
            spar = null;
            el('prj-lasare-video').srcObject = null;
            el('prj-lasare-bild').classList.remove('aktiv');
            el('prj-lasare-zoom-ram').hidden = true;
            el('prj-lasare-lampa').hidden = true;
            el('prj-lasare-start').textContent = 'Starta kameran';
        }

        async function loop(s0) {
            if (!lasning || s0 !== session) return;
            if (!pausad) {
                const video = el('prj-lasare-video');
                try {
                    const vw = video.videoWidth, vh = video.videoHeight;
                    const cw = video.clientWidth, ch = video.clientHeight;
                    if (vw && vh) {
                        let sx = 0, sy = 0, sw = vw, sh = vh;
                        if (cw && ch) {
                            const skala = Math.max(cw / vw, ch / vh);   // object-fit: cover
                            sw = Math.round(cw / skala * SIKTE);
                            sh = Math.round(ch / skala * SIKTE);
                            sx = Math.round((vw - sw) / 2);
                            sy = Math.round((vh - sh) / 2);
                        }
                        duk.width = sw;
                        duk.height = sh;
                        duk.getContext('2d').drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
                        const kod = forstaKod(await detektor.detect(duk));
                        if (kod && lasning && !pausad && s0 === session) traff(kod, 'kamera');
                    }
                } catch (e) {}
            }
            if (lasning && s0 === session) timer = setTimeout(() => loop(s0), 100);
        }

        function kontroller() {
            const caps = (spar && spar.getCapabilities) ? spar.getCapabilities() : {};
            if (caps.focusMode && caps.focusMode.includes('continuous')) {
                spar.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(() => {});
            }
            if (caps.zoom && caps.zoom.max > caps.zoom.min) {
                const z = el('prj-lasare-zoom');
                z.min = caps.zoom.min;
                z.max = Math.min(caps.zoom.max, 5);
                z.step = caps.zoom.step || 0.1;
                const start = Math.min(Math.max(caps.zoom.min, 1.5), Number(z.max));
                z.value = start;
                spar.applyConstraints({ advanced: [{ zoom: start }] }).catch(() => {});
                el('prj-lasare-zoom-ram').hidden = false;
            }
            if (caps.torch) {
                lampa = false;
                el('prj-lasare-lampa').textContent = 'Lampa';
                el('prj-lasare-lampa').hidden = false;
            }
        }

        async function bildKalla(fil) {
            try { return await createImageBitmap(fil); } catch (e) {
                return await new Promise((ok, fel) => {
                    const img = new Image();
                    img.onload = () => ok(img);
                    img.onerror = () => fel(new Error('bilden gick inte att öppna'));
                    img.src = URL.createObjectURL(fil);
                });
            }
        }

        async function lasFoto(fil) {
            const src = await bildKalla(fil);
            const w = src.width, h = src.height;
            for (const max of [0, 2000, 1200]) {
                let inmatning = src;
                if (max) {
                    if (Math.max(w, h) <= max) continue;
                    const s = max / Math.max(w, h);
                    const c = document.createElement('canvas');
                    c.width = Math.round(w * s);
                    c.height = Math.round(h * s);
                    c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
                    inmatning = c;
                }
                const kod = forstaKod(await detektor.detect(inmatning));
                if (kod) return kod;
            }
            return null;
        }

        function manuell() {
            const f = el('prj-lasare-text');
            const v = f.value.trim();
            if (v === '') { f.focus(); return; }
            f.value = '';
            traff(v, 'manuell');
        }

        el('prj-lasare-start').addEventListener('click', function () { strom ? stopp() : start(); });
        el('prj-lasare-ok').addEventListener('click', manuell);
        el('prj-lasare-text').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); manuell(); }
        });
        el('prj-lasare-zoom').addEventListener('input', function () {
            if (spar) spar.applyConstraints({ advanced: [{ zoom: Number(this.value) }] }).catch(() => {});
        });
        el('prj-lasare-lampa').addEventListener('click', function () {
            if (!spar) return;
            lampa = !lampa;
            spar.applyConstraints({ advanced: [{ torch: lampa }] }).catch(() => {});
            this.textContent = lampa ? 'Släck' : 'Lampa';
        });
        el('prj-lasare-foto').addEventListener('change', async function () {
            const fil = this.files && this.files[0];
            this.value = '';
            if (!fil) return;
            status('Letar efter QR-koden i fotot…');
            try {
                await hamtaDetektor();
                const kod = await lasFoto(fil);
                if (kod) { status(''); traff(kod, 'foto'); }
                else status('Hittade ingen QR-kod i fotot. Fota rakt framifrån så att koden fyller ungefär halva bredden.');
            } catch (err) {
                status('Fotot kunde inte läsas (' + (err.message || err) + ').');
            }
        });
        window.addEventListener('pagehide', stopp);

        return {
            start: start,
            stopp: stopp,
            paus: function () { pausad = true; },
            fortsatt: function () { pausad = false; },
            status: status
        };
    }

    window.PrjLasare = { skapa: skapa };
})();
