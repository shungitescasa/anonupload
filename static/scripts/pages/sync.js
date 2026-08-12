window.addEventListener("load", () => {
    const form = document.getElementById("sync-data");
    const codeInput = document.getElementById("code");
    const status = document.getElementById("status");
    if (!isSyncAvailable() || !form || !codeInput || !status) return;

    form.style.display = 'flex';

    // -- saving the data
    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        status.classList.remove("error");
        status.textContent = 'saving...';
        const code = codeInput.value;
        const id = await hashSyncCode(code);

        const data = {};

        Object.entries(localStorage).map(entry => {
            data[entry[0]] = JSON.parse(entry[1]);
        });

        status.textContent = 'encrypting...';
        const body = await encryptJson(data, code);
        body.keyid = id;

        fetch('/sync', {
            method: 'POST',
            body: JSON.stringify(body),
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        }).then((r) => {
            if (r.status !== 200 && r.headers.get("Content-Type") !== 'application/json') {
                return Promise.reject(`${r.status} (${r.statusText})`);
            }
            return r.json();
        }).then((j) => {
            if (j.status_code !== 200) {
                return Promise.reject(`${j.message} (${j.status_code})`);
            }

            status.textContent = 'saved!';
        }).catch((err) => {
            status.classList.add("error");
            status.textContent = err;
        });
    });

    // -- loading the data
    form.addEventListener("reset", async (e) => {
        e.preventDefault();
        const replace = confirm("By confirming, you will overwrite the current data with the incoming data.");
        if (!replace) {
            return;
        }

        status.classList.remove("error");
        status.textContent = 'loading...';
        const code = codeInput.value;
        const id = await hashSyncCode(code);

        fetch(`/sync?id=${id}`, {
            headers: {
                'Accept': 'application/json'
            }
        }).then((r) => {
            if (r.status !== 200 && r.headers.get("Content-Type") !== 'application/json') {
                return Promise.reject(`${r.status} (${r.statusText})`);
            }
            return r.json();
        }).then(async (j) => {
            if (j.status_code !== 200) {
                return Promise.reject(`${j.message} (${j.status_code})`);
            }

            const data = await decryptJson(j.data, code);

            Object.entries(data).map((entry) => {
                localStorage.setItem(entry[0], JSON.stringify(entry[1]));
            });

            status.textContent = 'loaded!';
        }).catch((err) => {
            status.classList.add("error");
            status.textContent = err;
        });
    });
});