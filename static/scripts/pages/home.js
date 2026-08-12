let cachedFiles = [];

window.addEventListener("DOMContentLoaded", () => {
    initOptions("form-upload");

    const form = document.getElementById("form-upload");
    const fileUploadElement = document.getElementById("upload-file");
    const submitButton = document.querySelector("#form-upload>button[type=\"submit\"]");
    const fakeUploadButton = document.getElementById("huge-upload-button");
    const fileUploadQueue = document.querySelector("#file-upload-queue tbody");
    if (!form || !fileUploadElement || !submitButton || !fakeUploadButton || !fileUploadQueue) return;

    // -- adjusting the upload form
    fileUploadElement.style.display = 'none';
    submitButton.style.display = 'none';
    fakeUploadButton.style.display = 'flex';
    document.querySelector("input[name=\"save_upload_list\"]").remove();

    fakeUploadButton.setAttribute("active", "true");

    fileUploadElement.removeAttribute("required");

    const hint = document.getElementById("upload-hint");
    if (hint) {
        hint.remove();
        fakeUploadButton.appendChild(hint);
        hint.textContent = "the upload will start immediately after selecting the file";
    }

    // -- fake upload button functionality
    fakeUploadButton.addEventListener("click", () => fileUploadElement.click());
    fakeUploadButton.addEventListener("dragleave", (ev) => {
        ev.preventDefault();
        fakeUploadButton.textContent = 'click, drop, or paste files here';
    });

    fakeUploadButton.addEventListener("dragover", (ev) => {
        ev.preventDefault();
        fakeUploadButton.textContent = 'drop files here';
    });

    fakeUploadButton.addEventListener("drop", (ev) => {
        ev.preventDefault();
        cachedFiles = [];
        fakeUploadButton.textContent = 'click, drop, or paste files here';
        if (ev.dataTransfer.items) {
            for (const item of ev.dataTransfer.items) {
                if (item.kind === "file") {
                    cachedFiles.push(item.getAsFile());
                }
            }
        }
        submitButton.click();
    });

    fileUploadElement.addEventListener("change", (ev) => {
        cachedFiles = [];
        cachedFiles.push(...fileUploadElement.files);
        submitButton.click();
    });

    // -- file upload via fetch
    form.addEventListener("submit", (ev) => {
        ev.preventDefault();
        const cf = cachedFiles;

        if (cf.length === 0) {
            return;
        }

        const f = new FormData(form);
        f.delete("file[]");
        for (const file of cachedFiles) {
            f.append("file[]", file);
        }

        fetch('/track?create', {
            headers: {
                'Accept': 'application/json'
            }
        })
            .then((r) => {
                if (r.status !== 201) {
                    return Promise.reject(`${r.status} ${r.statusText}`);
                }
                return r.json();
            })
            .then((j) => {
                f.set("track_id", j.data.id);
                const element = uploadData(f);
                fileUploadQueue.prepend(element.root);
            })
            .catch((err) => {
                const element = uploadData(f);
                fileUploadQueue.prepend(element.root);
            });

        cachedFiles = [];
    });

    document.addEventListener("paste", (e) => {
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        let files = [];

        for (const item of items) {
            if (item.kind === 'file') files.push(item.getAsFile());
        }

        if (files.length !== 0) {
            cachedFiles = files;
            submitButton.click();
        }
    });
});