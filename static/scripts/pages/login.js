window.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("login");
    if (!form || !isAccountCryptoAvailable()) return;

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        form.querySelector('button[type="submit"]').disabled = true;

        try {
            const key = await deriveAccountKey(form.elements.username.value, form.elements.password.value);
            await saveAccountKey(key);
        } catch (err) {
            console.error("Failed to derive account key:", err);
        }

        form.submit();
    });
});
