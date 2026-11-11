window.addEventListener("DOMContentLoaded", () => {
    const controls = document.getElementById("approval-controls");
    if (!controls) return;

    const postId = controls.dataset.postId;
    const nextUrl = controls.dataset.nextUrl || "/system/approval";
    const status = document.getElementById("approval-status");
    const buttons = controls.querySelectorAll("button[data-action]");

    function setBusy(busy) {
        buttons.forEach((b) => b.disabled = busy);
    }

    function setStatus(text, isError = false) {
        if (!status) return;
        status.textContent = text;
        status.classList.toggle("error", isError);
    }

    async function send(method, url, params) {
        setBusy(true);
        setStatus("processing...");

        const body = new URLSearchParams(params);
        const options = {
            method,
            headers: { "Accept": "application/json" }
        };

        if (method === "DELETE") {
            url += `?${body}`;
        } else {
            options.headers["Content-Type"] = "application/x-www-form-urlencoded";
            options.body = body;
        }

        try {
            const response = await fetch(url, options);
            const json = await response.json().catch(() => null);

            if (!response.ok) {
                throw new Error(json?.message ?? `Request failed (${response.status})`);
            }

            window.location.href = nextUrl;
        } catch (e) {
            setStatus(e.message, true);
            setBusy(false);
        }
    }

    const actions = {
        delete: () => {
            if (!confirm(`Delete post ${postId}?`)) return;
            send("DELETE", "/post/delete", { id: postId });
        },
        ban: () => {
            const reason = prompt(`Ban reason for post ${postId}:`);
            if (reason === null) return;
            send("POST", "/post/ban", { id: postId, reason: reason.trim() });
        },
        visibility: (button) => {
            send("PATCH", "/post/edit", { id: postId, visibility: button.dataset.visibility });
        }
    };

    buttons.forEach((button) => {
        button.addEventListener("click", () => actions[button.dataset.action]?.(button));
    });
});
