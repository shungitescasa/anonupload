function setFavoriteIcon(file, btn, goodIcon, badIcon) {
    while (btn.firstChild) btn.firstChild.remove();
    const good = isFavoriteFile(file);
    if (good) {
        btn.append(goodIcon);
    } else {
        btn.append(badIcon);
    }
    return good;
}

function updateControlButtons(file) {
    const downloadButton = document.querySelector(".download-button");
    const fullsizeButton = document.querySelector(".full-size-button");
    if (!downloadButton || !fullsizeButton) return;

    const url = file.getAttribute("file-raw-url");
    const pathName = file.getAttribute("file-pathname");
    downloadButton.href = url;
    downloadButton.download = pathName;
    fullsizeButton.href = url;
}

window.addEventListener("DOMContentLoaded", () => {
    const buttons = document.getElementById("control-buttons");
    const feed = document.querySelector(".feed");
    const files = document.querySelectorAll(".feed .file-preview");
    if (!buttons || !feed || !files) return;

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            const isIntersecting = entry.isIntersecting > 0.9;
            if (isIntersecting) updateControlButtons(entry.target);

            const media = entry.target.querySelector("video, audio");

            if (!media) return;

            const canPause =
                typeof media.pause === "function" &&
                typeof media.play === "function";

            if (!canPause) return;

            if (isIntersecting) {
                if (media.dataset.autoPaused === "true") {
                    media.play().catch(() => { });
                    delete media.dataset.autoPaused;
                }
            } else {
                if (!media.paused) {
                    media.dataset.autoPaused = "true";
                    media.pause();
                }
            }
        });
    },
        {
            threshold: [0, 0.9]
        }
    );

    files.forEach(element => {
        observer.observe(element);
    });
});

window.addEventListener("DOMContentLoaded", () => {
    const feed = document.querySelector(".feed");
    const files = document.querySelectorAll(".file-preview");
    const buttons = document.getElementById("control-buttons");
    if (!buttons) return;

    const file = {
        id: feed.getAttribute("feed-id"),
        urls: {
            download_url: feed.getAttribute("feed-url")
        }
    };

    if (files.length === 1) {
        const firstFile = files[0];
        file.mime = firstFile.getAttribute("file-mime");
        file.size = firstFile.getAttribute("file-size");
        file.extension = firstFile.getAttribute("file-ext");
        file.urls.thumbnail_url = firstFile.getAttribute("file-thumb-url");
    } else {
        file.count = files.length;
    }

    // -- copy button
    if (navigator.clipboard) {
        const icon = document.createElement("img");
        icon.src = '/static/img/icons/copy.png';
        icon.alt = 'copy link';
        icon.title = 'copy link';

        const button = document.createElement("button");
        button.classList.add("button");
        button.append(icon);
        button.addEventListener("click", () => navigator.clipboard.writeText(file.urls.download_url));
        buttons.prepend(button);
    }

    // -- favorite button
    {
        const goodIcon = document.createElement("img");
        goodIcon.src = '/static/img/icons/star.png';
        goodIcon.alt = 'unfavorite';
        goodIcon.title = 'unfavorite this file';

        const badIcon = document.createElement("img");
        badIcon.src = '/static/img/icons/star-gray.png';
        badIcon.alt = 'favorite';
        badIcon.title = 'favorite this file';

        const favoriteButton = document.createElement("button");
        favoriteButton.classList.add("button");
        setFavoriteIcon(file, favoriteButton, goodIcon, badIcon);
        favoriteButton.addEventListener("click", () => {
            if (isFavoriteFile(file)) {
                removeFavoriteFile(file);
            } else {
                addFavoriteFile(file);
            }

            setFavoriteIcon(file, favoriteButton, goodIcon, badIcon);
        });
        buttons.append(favoriteButton);
    }
});

// live timestamp counting
function formatTimestamp(ts) {
    const diff = Math.floor(Date.now() - ts * 1000);

    const seconds = Math.floor(diff / 1000);
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);
    const months = Math.floor(days / 30);
    const years = Math.floor(days / 365);

    if (years === 0 && months === 0 && days === 0 && hours === 0 && minutes === 0) {
        return `${seconds} second${seconds !== 1 ? "s" : ""}`;
    } else if (years === 0 && months === 0 && days === 0 && hours === 0) {
        return `${minutes} minute${minutes !== 1 ? "s" : ""}`;
    } else if (years === 0 && months === 0 && days === 0) {
        return `${hours} hour${hours !== 1 ? "s" : ""}`;
    } else if (years === 0 && months === 0) {
        return `${days} day${days !== 1 ? "s" : ""}`;
    } else if (years === 0) {
        return `${months} month${months !== 1 ? "s" : ""}`;
    }

    return `${years} year${years !== 1 ? "s" : ""}`;
}

window.addEventListener("DOMContentLoaded", () => {
    const timestampElement = document.getElementById("post-timestamp");
    if (!timestampElement) return;

    const timestamp = timestampElement.getAttribute("timestamp");

    setInterval(() => {
        timestampElement.textContent = `uploaded ${formatTimestamp(timestamp)} ago`;
    }, 1000);
});