function renderFileHistory(fileHistory) {
    fileHistory.replaceChildren();

    const files = getUploadedFiles();

    for (const file of files) {
        file.save = () => saveFile(file, files, "uploaded_files");
        fileHistory.append(createFile(file));
    }

    if (files.length == 0) {
        const message = document.createElement("p");
        message.classList.add("message");
        message.innerHTML = "you haven't uploaded any files yet. <a href='/'>maybe it's time to fix that? :)</a>";
        fileHistory.append(message);
    }
}

window.addEventListener("DOMContentLoaded", () => {
    const fileHistory = document.getElementById("file-history");
    if (!fileHistory) {
        return;
    }

    renderFileHistory(fileHistory);

    // newest history from the account
    window.addEventListener("account-data-load", (e) => {
        if (e.detail.sections.includes("history")) renderFileHistory(fileHistory);
    });
});
