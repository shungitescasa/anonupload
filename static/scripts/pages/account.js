window.addEventListener("DOMContentLoaded", async () => {
    const form = document.getElementById("preferences");
    const status = document.getElementById("preferences-status");
    if (!form) return;

    const showStatus = (text, error = false) => {
        status.textContent = text;
        status.classList.toggle("error", error);
        status.hidden = false;
    };

    const readPreferences = () => ({
        theme: form.elements.theme?.value,
        noloop: form.elements.noloop.checked,
        noautoplay: form.elements.noautoplay.checked
    });

    const cryptoAvailable = isAccountCryptoAvailable();
    const authorized = form.dataset.authorized !== undefined;

    if (cryptoAvailable && !authorized) {
        // signed out: forget the key of a previous session
        clearAccountKey().catch(() => { });
    }

    const logout = document.getElementById("logout");
    if (cryptoAvailable && logout) {
        logout.addEventListener("submit", async (e) => {
            e.preventDefault();
            try {
                await clearAccountKey();
            } catch (err) {
                console.error("Failed to clear account key:", err);
            }
            logout.submit();
        });
    }

    let key = null;
    if (cryptoAvailable && authorized && form.dataset.storage !== undefined) {
        try {
            key = await loadAccountKey();
        } catch (err) {
            console.error("Failed to load account key:", err);
        }

        if (!key) {
            showStatus("log out and sign in again to save preferences to your account.");
        }
    }

    // the theme stylesheet is applied on the server side, so the page reloads when it changes
    let theme = form.elements.theme?.value;

    const save = async () => {
        const body = new URLSearchParams(new FormData(form));
        if (key) {
            body.set("storage", JSON.stringify(await encryptAccountData(key, readPreferences())));
        }

        showStatus("saving...");
        const r = await fetch("/account/save", {
            method: "POST",
            body,
            headers: { "Accept": "application/json" }
        }).then((r) => r.json());

        if (r.status_code !== 200) {
            showStatus(r.message ?? `failed to save preferences (${r.status_code})`, true);
            return;
        }

        if (form.elements.theme && form.elements.theme.value !== theme) {
            theme = form.elements.theme.value;
            location.reload();
            return;
        }

        showStatus(r.message);
    };

    // saves run one after another, so the last change always wins
    let queue = Promise.resolve();
    const enqueueSave = () => {
        queue = queue.then(save).catch((err) => {
            console.error("Failed to save preferences:", err);
            showStatus("failed to save preferences.", true);
        });
    };

    form.addEventListener("change", enqueueSave);
    form.addEventListener("submit", (e) => {
        e.preventDefault();
        enqueueSave();
    });
});
