'use strict';

const ChunkedUploads = (() => {
    const supported = typeof window.fetch === 'function'
        && typeof window.Promise === 'function'
        && typeof window.Blob === 'function' && typeof Blob.prototype.slice === 'function'
        && typeof window.XMLHttpRequest === 'function' && 'upload' in new XMLHttpRequest()
        && typeof window.AbortController === 'function';

    const STORAGE_KEY = 'chunked_uploads';
    const RESUME_TTL = 23 * 60 * 60 * 1000;
    const MAX_ATTEMPTS = 10;
    const BACKOFF_BASE = 1000;
    const BACKOFF_CAP = 60000;
    const STALL_TIMEOUT = 120000;
    const RETRYABLE = [0, 400, 408, 425, 429, 500, 502, 503, 504, 507];

    class UploadError extends Error {
        constructor(message, retryable = false, retryAfter = null) {
            super(message);
            this.retryable = retryable;
            this.retryAfter = retryAfter;
        }
    }

    const fileKey = (file) => `${file.name}\n${file.size}\n${file.lastModified}`;

    function loadResumable() {
        try {
            const all = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
            const now = Date.now();
            for (const k of Object.keys(all)) {
                if (!all[k] || now - all[k].created > RESUME_TTL) delete all[k];
            }
            return all;
        } catch (e) {
            return {};
        }
    }

    function saveResumable(all) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(all));
        } catch (e) {
            // private mode or full storage: uploads still work, they just cannot be resumed after a reload
        }
    }

    function remember(file, id) {
        const all = loadResumable();
        all[fileKey(file)] = { id, name: file.name, created: Date.now() };
        saveResumable(all);
    }

    function forget(file) {
        const all = loadResumable();
        delete all[fileKey(file)];
        saveResumable(all);
    }

    function sleep(ms, signal) {
        return new Promise((resolve, reject) => {
            if (signal.aborted) return reject(new DOMException('aborted', 'AbortError'));
            const t = setTimeout(resolve, ms);
            signal.addEventListener('abort', () => {
                clearTimeout(t);
                reject(new DOMException('aborted', 'AbortError'));
            }, { once: true });
        });
    }

    function backoff(attempt) {
        const d = Math.min(BACKOFF_CAP, BACKOFF_BASE * 2 ** attempt);
        return d / 2 + Math.random() * d / 2;
    }

    function retryDelay(error, attempt) {
        const s = parseInt(error.retryAfter ?? '', 10);
        return Number.isFinite(s) && s >= 0 ? Math.min(s, 600) * 1000 : backoff(attempt);
    }

    async function waitOnline(signal, onWait) {
        if (navigator.onLine !== false) return;
        onWait();
        await new Promise((resolve, reject) => {
            const done = () => {
                window.removeEventListener('online', done);
                signal.aborted ? reject(new DOMException('aborted', 'AbortError')) : resolve();
            };
            window.addEventListener('online', done);
            signal.addEventListener('abort', done, { once: true });
        });
    }

    // only one (or two) chunk requests at a time for the whole page
    const slots = {
        free: 1,
        queue: [],
        setLimit(n) {
            const extra = Math.max(1, Math.min(2, n | 0)) - this.limit;
            this.limit += extra;
            this.free += extra;
            this.wake();
        },
        limit: 1,
        acquire() {
            if (this.free > 0) {
                this.free--;
                return Promise.resolve();
            }
            return new Promise((resolve) => this.queue.push(resolve));
        },
        release() {
            this.free++;
            this.wake();
        },
        wake() {
            while (this.free > 0 && this.queue.length > 0) {
                this.free--;
                this.queue.shift()();
            }
        },
    };



    async function parseResponse(status, text, retryAfter) {
        let j = null;
        try {
            j = JSON.parse(text);
        } catch (e) {
            // e.g. a proxy error page
        }
        if (status >= 200 && status < 300 && j) return j.data;
        const message = j?.message ?? j?.data?.errors?.[0]?.error ?? (status ? `server answered ${status}` : 'network error');
        throw new UploadError(message, RETRYABLE.includes(status), retryAfter);
    }

    // a JSON request, retried while the error is temporary
    async function api(method, url, body, signal, report) {
        for (let attempt = 0; ; attempt++) {
            await waitOnline(signal, () => report('offline, waiting for the connection...'));
            try {
                const r = await fetch(url, {
                    method,
                    signal,
                    credentials: 'same-origin',
                    headers: body === undefined ? { Accept: 'application/json' } : { Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: body === undefined ? undefined : JSON.stringify(body),
                });
                return await parseResponse(r.status, await r.text(), r.headers.get('Retry-After'));
            } catch (e) {
                if (e.name === 'AbortError') throw e;
                const err = e instanceof UploadError ? e : new UploadError('network error', true);
                if (err.retryable && navigator.onLine === false) {
                    attempt--;
                    continue;
                }
                if (!err.retryable || attempt + 1 >= MAX_ATTEMPTS) throw err;
                const delay = retryDelay(err, attempt);
                report(`${err.message}, retrying in ${Math.ceil(delay / 1000)} s...`);
                await sleep(delay, signal);
            }
        }
    }

    function putChunk(url, blob, signal, onProgress) {
        return new Promise((resolve, reject) => {
            // an abort listener never fires for a signal that is aborted already
            if (signal.aborted) return reject(new DOMException('aborted', 'AbortError'));
            const xhr = new XMLHttpRequest();
            let stalled = false;
            let timer = null;
            const arm = () => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    stalled = true;
                    xhr.abort();
                }, STALL_TIMEOUT);
            };
            const cancel = () => xhr.abort();
            const finish = () => {
                clearTimeout(timer);
                signal.removeEventListener('abort', cancel);
            };

            xhr.open('PUT', url);
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.addEventListener('progress', (e) => {
                arm();
                onProgress(e.loaded);
            });
            xhr.addEventListener('load', () => {
                finish();
                parseResponse(xhr.status, xhr.responseText, xhr.getResponseHeader('Retry-After')).then(resolve, reject);
            });
            xhr.addEventListener('error', () => {
                finish();
                reject(new UploadError('network error', true));
            });
            xhr.addEventListener('abort', () => {
                finish();
                reject(stalled ? new UploadError('the connection stalled', true) : new DOMException('aborted', 'AbortError'));
            });
            signal.addEventListener('abort', cancel, { once: true });
            arm();
            xhr.send(blob);
        });
    }

    class FileUpload {
        constructor(file, options, queue) {
            this.file = file;
            this.options = options;
            this.controller = new AbortController();
            this.row = createFileUploadProgress([file]);
            this.sent = new Map();
            this.id = null;
            this.chunkSize = 0;

            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.title = 'cancel this upload';
            const img = document.createElement('img');
            img.src = '/static/img/icons/cross.png';
            img.alt = 'cancel';
            cancel.append(img);
            cancel.addEventListener('click', () => this.cancel());
            this.cancelButton = cancel;
            this.row.buttons.root.append(cancel);

            this.row.setProgress(0);
            this.status('waiting...', 'queue');
            queue.prepend(this.row.root);
        }

        status(text, attr = 'cl_progress') {
            if (!this.finished) this.row.setStatus(text, attr);
        }

        progress() {
            if (this.finished) return;
            let bytes = 0;
            for (const b of this.sent.values()) bytes += b;
            const percent = this.file.size > 0 ? Math.floor(bytes * 100 / this.file.size) : 100;
            this.row.setProgress(Math.min(percent, 99));
            this.status(`uploading... ${percent}% (${(bytes / 1048576).toFixed(1)} of ${(this.file.size / 1048576).toFixed(1)} MB)`);
        }

        // uploads every missing chunk; resolves with the upload id
        async run() {
            const signal = this.controller.signal;
            const report = (text) => this.status(text);
            let received = [];

            // continue an upload this browser started earlier?
            const known = loadResumable()[fileKey(this.file)];
            if (known) {
                try {
                    const s = await api('GET', `/post/upload/${known.id}`, undefined, signal, report);
                    if (s.state === 'uploading' && s.size === this.file.size) {
                        this.id = known.id;
                        this.chunkSize = s.chunk_size;
                        received = s.received;
                        this.status(`resuming: ${received.length} of ${s.chunks} parts are on the server`);
                    }
                } catch (e) {
                    if (e.name === 'AbortError') throw e;
                    // expired or unknown: start over
                }
            }

            if (this.id === null) {
                this.status('starting...');
                const r = await api('POST', '/post/upload', {
                    filename: this.file.name,
                    size: this.file.size,
                    content_type: this.file.type,
                    ...this.options,
                }, signal, report);
                this.id = r.id;
                this.chunkSize = r.chunk_size;
                slots.setLimit(r.parallel ?? 1);
                remember(this.file, this.id);
            }

            const count = Math.ceil(this.file.size / this.chunkSize);
            const missing = [];
            for (let n = 0; n < count; n++) {
                if (received.includes(n)) {
                    this.sent.set(n, Math.min(this.chunkSize, this.file.size - n * this.chunkSize));
                } else {
                    missing.push(n);
                }
            }
            this.progress();

            // at most two chunks of this file at once; the page-wide slots decide the real concurrency
            const worker = async () => {
                while (missing.length > 0) {
                    await this.sendChunk(missing.shift(), signal);
                }
            };
            try {
                await Promise.all([worker(), worker()]);
            } catch (e) {
                // stop the other worker too
                this.controller.abort();
                throw e;
            }
            return this.id;
        }

        async sendChunk(n, signal) {
            const start = n * this.chunkSize;
            const blob = this.file.slice(start, Math.min(start + this.chunkSize, this.file.size));
            for (let attempt = 0; ; attempt++) {
                await waitOnline(signal, () => this.status('offline, upload paused until the connection is back...'));
                await slots.acquire();
                let error = null;
                try {
                    await putChunk(`/post/upload/${this.id}/chunk/${n}`, blob, signal, (loaded) => {
                        this.sent.set(n, loaded);
                        this.progress();
                    });
                } catch (e) {
                    error = e;
                } finally {
                    slots.release();
                }

                if (error === null) {
                    this.sent.set(n, blob.size);
                    this.progress();
                    return;
                }
                this.sent.set(n, 0);
                if (error.name === 'AbortError' || !error.retryable) throw error;
                if (navigator.onLine === false) {
                    // lost the connection
                    attempt--;
                    continue;
                }
                if (attempt + 1 >= MAX_ATTEMPTS) throw error;
                const delay = retryDelay(error, attempt);
                this.status(`${error.message}, retrying in ${Math.ceil(delay / 1000)} s...`);
                await sleep(delay, signal);
            }
        }

        async complete(attach = []) {
            this.status('finishing...');
            const post = await api('POST', `/post/upload/${this.id}/complete`, attach.length ? { attach } : undefined, this.controller.signal, (t) => this.status(t));
            return post;
        }

        done(post) {
            this.finished = true;
            forget(this.file);
            const row = createUploadedFileRow(post);
            this.row.root.replaceWith(row.root);
            saveUploadedFile(post);
        }

        failed(e) {
            if (this.finished) return;
            this.cancelButton.remove();
            if (e.name === 'AbortError') {
                this.status('cancelled', 'abort');
                this.finished = true;
                return;
            }
            this.row.setProgress(100);
            this.status(`upload failed: ${e.message.replace(/\.$/, '')}. `, 'error');
            this.finished = true;

            const fallback = document.createElement('a');
            fallback.href = '/';
            fallback.textContent = 'The basic upload form still works.';
            fallback.addEventListener('click', (ev) => {
                ev.preventDefault();
                try {
                    sessionStorage.setItem('upload.basic', '1');
                } catch (err) {
                    // without storage the reload simply shows the enhanced form again
                }
                location.reload();
            });
            this.row.status.append(fallback);
        }

        cancel() {
            this.controller.abort();
            forget(this.file);
            if (this.id !== null) {
                fetch(`/post/upload/${this.id}`, { method: 'DELETE', keepalive: true, credentials: 'same-origin' }).catch(() => { });
            }
        }
    }



    function readOptions(form) {
        const data = new FormData(form);
        const checked = (name) => data.get(name) === '1';
        const options = {
            password: data.get('password') ?? '',
            description: data.get('description') ?? '',
            single_url: checked('single_url'),
            sign_post: checked('sign_post'),
            preserve_original_filename: checked('preserve_original_filename'),
            strip_exif_data: checked('strip_exif_data'),
        };
        if (data.has('expires_in')) options.expires_in = data.get('expires_in');
        return options;
    }

    async function start(files, form, queue) {
        const options = readOptions(form);
        const maxSize = parseInt(form.dataset.maxSize ?? '0', 10);
        const maxFiles = parseInt(form.dataset.maxFiles ?? '0', 10);

        const uploads = [];
        for (const file of files) {
            const u = new FileUpload(file, options, queue);
            if (maxSize > 0 && file.size > maxSize) {
                u.failed(new UploadError(`the file is larger than ${(maxSize / 1073741824).toFixed(0)} GB`));
                continue;
            }
            if (options.single_url && maxFiles > 0 && uploads.length >= maxFiles) {
                u.failed(new UploadError(`at most ${maxFiles} files can share one link`));
                continue;
            }
            uploads.push(u);
        }

        // every file goes into one post, completed together
        if (options.single_url && uploads.length > 1) {
            const results = await Promise.allSettled(uploads.map((u) => u.run()));
            const ok = uploads.filter((u, i) => results[i].status === 'fulfilled');
            uploads.forEach((u, i) => results[i].status === 'rejected' && u.failed(results[i].reason));
            if (ok.length === 0) return;
            try {
                const post = await ok[0].complete(ok.slice(1).map((u) => u.id));
                ok.slice(1).forEach((u) => {
                    forget(u.file);
                    u.row.root.remove();
                });
                ok[0].done(post);
            } catch (e) {
                ok.forEach((u) => u.failed(e));
            }
            return;
        }

        await Promise.all(uploads.map(async (u) => {
            try {
                await u.run();
                u.done(await u.complete());
            } catch (e) {
                u.failed(e);
            }
        }));
    }

    // the page shows the chunked limit, and reminds of uploads that can be continued
    function enhance(queue) {
        const limits = document.getElementById('upload-limits');
        if (limits && limits.dataset.chunkedText) limits.textContent = limits.dataset.chunkedText;

        const pending = Object.values(loadResumable());
        if (pending.length > 0 && queue) {
            const row = createFileUploadProgress(pending.map((p) => ({ name: p.name })));
            row.setProgress(100);
            row.setStatus(`unfinished: ${pending.map((p) => p.name).join(', ')}. Select the same file again to continue.`, 'queue');
            queue.prepend(row.root);
        }
    }

    function basicFormRequested() {
        try {
            return sessionStorage.getItem('upload.basic') === '1';
        } catch (e) {
            return false;
        }
    }

    return { supported, start, enhance, basicFormRequested };
})();
