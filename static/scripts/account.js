const ACCOUNT_KEY_DB = "account";
const ACCOUNT_KEY_STORE = "keys";
const ACCOUNT_KEY_ID = "storage";

const ACCOUNT_LOCAL_SECTIONS = {
    history: "uploaded_files",
    favorites: "favorite-files"
};

function accountBaseKey(section) {
    return `account-base:${section}`;
}

function readJsonArray(key) {
    try {
        const value = JSON.parse(localStorage.getItem(key) ?? "null");
        return Array.isArray(value) ? value : null;
    } catch (err) {
        return null;
    }
}

async function forgetAccount() {
    await clearAccountKey();

    for (const [section, key] of Object.entries(ACCOUNT_LOCAL_SECTIONS)) {
        const base = readJsonArray(accountBaseKey(section));
        if (base === null) continue;

        localStorage.removeItem(accountBaseKey(section));

        if (JSON.stringify(readJsonArray(key) ?? []) === JSON.stringify(base)) {
            localStorage.removeItem(key);
        }
    }
}

function isAccountCryptoAvailable() {
    return window.crypto?.subtle !== undefined && window.indexedDB !== undefined;
}

async function deriveAccountKey(username, password) {
    const encoder = new TextEncoder();
    const salt = await crypto.subtle.digest(
        "SHA-256",
        encoder.encode(`anonupload-account:${username.trim().toLowerCase()}`)
    );

    const material = await crypto.subtle.importKey(
        "raw",
        encoder.encode(password),
        "PBKDF2",
        false,
        ["deriveKey"]
    );

    return crypto.subtle.deriveKey(
        { name: "PBKDF2", salt, iterations: 600000, hash: "SHA-256" },
        material,
        { name: "AES-GCM", length: 256 },
        false,
        ["encrypt", "decrypt"]
    );
}

function accountKeyStore(mode, action) {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open(ACCOUNT_KEY_DB, 1);
        open.onupgradeneeded = () => open.result.createObjectStore(ACCOUNT_KEY_STORE);
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
            const db = open.result;
            const tx = db.transaction(ACCOUNT_KEY_STORE, mode);
            const request = action(tx.objectStore(ACCOUNT_KEY_STORE));
            tx.oncomplete = () => {
                db.close();
                resolve(request.result ?? null);
            };
            tx.onerror = tx.onabort = () => {
                db.close();
                reject(tx.error);
            };
        };
    });
}

function saveAccountKey(key) {
    return accountKeyStore("readwrite", (s) => s.put(key, ACCOUNT_KEY_ID));
}

function loadAccountKey() {
    return accountKeyStore("readonly", (s) => s.get(ACCOUNT_KEY_ID));
}

function clearAccountKey() {
    return accountKeyStore("readwrite", (s) => s.delete(ACCOUNT_KEY_ID));
}

function bytesToBase64(bytes) {
    let s = "";
    for (const b of bytes) {
        s += String.fromCharCode(b);
    }
    return btoa(s);
}

function base64ToBytes(b64) {
    return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
}

async function encryptAccountData(key, value) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const data = await crypto.subtle.encrypt(
        { name: "AES-GCM", iv },
        key,
        new TextEncoder().encode(JSON.stringify(value))
    );

    return { iv: bytesToBase64(iv), data: bytesToBase64(new Uint8Array(data)) };
}

async function decryptAccountData(key, blob) {
    const data = await crypto.subtle.decrypt(
        { name: "AES-GCM", iv: base64ToBytes(blob.iv) },
        key,
        base64ToBytes(blob.data)
    );

    return JSON.parse(new TextDecoder().decode(data));
}
