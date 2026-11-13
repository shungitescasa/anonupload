window.addEventListener("DOMContentLoaded", async () => {
    if (!isAccountCryptoAvailable()) return;

    const showStatus = (text) => {
        const status = document.getElementById("preferences-status");
        if (!status) return;
        status.textContent = text;
        status.hidden = false;
    };

    let key = null;
    try {
        key = await loadAccountKey();
    } catch (err) {
        console.error("Failed to load account key:", err);
    }
    if (!key) return;

    // /account/save takes form fields, unchecked flags are left out like in an HTML form
    const toForm = (values) => {
        const form = new URLSearchParams();
        for (const [k, v] of Object.entries(values)) {
            if (v === true) form.set(k, "1");
            else if (typeof v === "string") form.set(k, v);
        }
        return form;
    };

    const request = (method, body) => fetch("/account/save", {
        method,
        body: body && toForm(body),
        headers: { "Accept": "application/json" },
        cache: "no-store"
    }).then((r) => r.json());

    let response;
    try {
        response = await request("GET");
    } catch (err) {
        console.error("Failed to fetch account preferences:", err);
        return;
    }

    // signed out, forget the key of the previous session
    if (response.status_code === 401) {
        clearAccountKey().catch(() => { });
        return;
    }

    const { saved, current } = response.data ?? {};
    if (response.status_code !== 200 || !saved || !current) return;

    let preferences;
    try {
        preferences = await decryptAccountData(key, saved);
    } catch (err) {
        console.error("Failed to decrypt account preferences:", err);
        showStatus("couldn't decrypt the preferences saved to your account (did your password change?). saving will overwrite them.");
        return;
    }

    const updated = { ...current };
    if (typeof preferences.theme === "string") updated.theme = preferences.theme;
    updated.noloop = !!preferences.noloop;
    updated.noautoplay = !!preferences.noautoplay;

    if (Object.keys(updated).every((k) => updated[k] === current[k])) return;

    // apply each saved version once, so a value the server rejects (e.g. a removed theme) can't cause reload loops
    try {
        if (sessionStorage.getItem("account-preferences-applied") === saved.iv) return;
        sessionStorage.setItem("account-preferences-applied", saved.iv);
    } catch (err) { }

    try {
        const r = await request("POST", { ...updated, _loaded: true });
        if (r.status_code === 200) {
            location.reload();
        }
    } catch (err) {
        console.error("Failed to apply account preferences:", err);
    }
});
