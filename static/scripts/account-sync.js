function readLocalSection(section) {
    return readJsonArray(ACCOUNT_LOCAL_SECTIONS[section]) ?? [];
}

function sameJson(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
}

function mergeAccountSection(remote, local, base) {
    const remoteIds = new Set(remote.map((x) => x.id));

    if (base === null) {
        return [...local.filter((x) => !remoteIds.has(x.id)), ...remote];
    }

    const baseItems = new Map(base.map((x) => [x.id, JSON.stringify(x)]));
    const localItems = new Map(local.map((x) => [x.id, x]));

    const removed = new Set(base.filter((x) => !localItems.has(x.id)).map((x) => x.id));
    const added = local.filter((x) => !baseItems.has(x.id) && !remoteIds.has(x.id));
    const changed = new Map(
        local
            .filter((x) => baseItems.has(x.id) && baseItems.get(x.id) !== JSON.stringify(x))
            .map((x) => [x.id, x])
    );

    return [
        ...added,
        ...remote.filter((x) => !removed.has(x.id)).map((x) => changed.get(x.id) ?? x)
    ];
}

window.addEventListener("DOMContentLoaded", async () => {
    if (!isAccountCryptoAvailable()) return;

    const sections = Object.keys(ACCOUNT_LOCAL_SECTIONS);
    let key = null;
    let signedOut = false;

    const fetchAccountData = async () => {
        const r = await fetch("/account/storage", {
            headers: { "Accept": "application/json" },
            cache: "no-store"
        }).then((r) => r.json());

        if (r.status_code === 401) {
            signedOut = true;
            await forgetAccount();
            return null;
        }

        if (r.status_code !== 200 || !r.data) {
            throw new Error(`${r.message} (${r.status_code})`);
        }

        return r.data;
    };

    const syncSections = async (names, data = null) => {
        data ??= await fetchAccountData();
        if (!data) return;

        const updated = [];
        const toSave = {};
        const rerun = [];

        for (const section of names) {
            const localKey = ACCOUNT_LOCAL_SECTIONS[section];
            const localRaw = localStorage.getItem(localKey);
            const local = readLocalSection(section);
            const base = readJsonArray(accountBaseKey(section));

            let remote = [];
            if (data[section]) {
                try {
                    remote = await decryptAccountData(key, data[section]);
                    if (!Array.isArray(remote)) throw new Error("not an array");
                } catch (err) {
                    console.error(`Failed to decrypt account ${section}:`, err);
                    continue;
                }
            }

            if (localStorage.getItem(localKey) !== localRaw) {
                rerun.push(section);
                continue;
            }

            const result = mergeAccountSection(remote, local, base);

            if (!sameJson(result, local)) {
                localStorage.setItem(localKey, JSON.stringify(result));
                updated.push(section);
            }

            if (data[section] ? !sameJson(result, remote) : result.length > 0) {
                toSave[section] = result;
            } else {
                localStorage.setItem(accountBaseKey(section), JSON.stringify(result));
            }
        }

        if (updated.length > 0) {
            window.dispatchEvent(new CustomEvent("account-data-load", { detail: { sections: updated } }));
        }

        if (Object.keys(toSave).length > 0) {
            const body = new URLSearchParams();
            for (const [section, value] of Object.entries(toSave)) {
                body.set(section, JSON.stringify(await encryptAccountData(key, value)));
            }

            const r = await fetch("/account/storage", {
                method: "POST",
                body,
                headers: { "Accept": "application/json" },
                // lets small saves finish when the page is being closed
                keepalive: body.toString().length < 60000
            }).then((r) => r.json());

            if (r.status_code !== 200) {
                throw new Error(`${r.message} (${r.status_code})`);
            }

            for (const [section, value] of Object.entries(toSave)) {
                localStorage.setItem(accountBaseKey(section), JSON.stringify(value));
            }
        }

        rerun.forEach(scheduleSync);
    };

    const dirty = new Set();
    let queue = Promise.resolve();

    function scheduleSync(section) {
        dirty.add(section);
        queue = queue.then(async () => {
            if (dirty.size === 0 || !key || signedOut) return;
            const names = [...dirty];
            dirty.clear();
            await syncSections(names);
        }).catch((err) => console.error("Failed to sync account data:", err));
    }

    window.addEventListener("local-data-change", (e) => {
        const section = sections.find((s) => ACCOUNT_LOCAL_SECTIONS[s] === e.detail?.key);
        if (section) scheduleSync(section);
    });

    try {
        key = await loadAccountKey();
    } catch (err) {
        console.error("Failed to load account key:", err);
    }
    if (!key) return;

    let data;
    try {
        data = await fetchAccountData();
    } catch (err) {
        console.error("Failed to fetch account data:", err);
        return;
    }
    if (!data) return;

    queue = queue.then(async () => {
        dirty.clear();
        await syncSections(sections, data);
    }).catch((err) => console.error("Failed to sync account data:", err));

    await queue;
    await applyAccountPreferences(data.preferences, data.current);

    async function applyAccountPreferences(saved, current) {
        if (!saved || !current) return;

        let preferences;
        try {
            preferences = await decryptAccountData(key, saved);
        } catch (err) {
            console.error("Failed to decrypt account preferences:", err);
            const status = document.getElementById("preferences-status");
            if (status) {
                status.textContent = "couldn't decrypt the preferences saved to your account (did your password change?). saving will overwrite them.";
                status.hidden = false;
            }
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

        // /account/save takes form fields, unchecked flags are left out like in an HTML form
        const body = new URLSearchParams({ _loaded: "1" });
        for (const [k, v] of Object.entries(updated)) {
            if (v === true) body.set(k, "1");
            else if (typeof v === "string") body.set(k, v);
        }

        try {
            const r = await fetch("/account/save", {
                method: "POST",
                body,
                headers: { "Accept": "application/json" }
            }).then((r) => r.json());

            if (r.status_code === 200) {
                // pending history & favorites saves finish first
                await queue;
                location.reload();
            }
        } catch (err) {
            console.error("Failed to apply account preferences:", err);
        }
    }
});
