window.addEventListener("load", () => {
    const volume = Number(localStorage.getItem("player-volume") || "0.45");

    const playbacks = document.querySelectorAll("video, audio");
    playbacks.forEach(playback => {
        playback.volume = volume;
        playback.addEventListener("volumechange", () => {
            localStorage.setItem("player-volume", playback.volume);
        });

        const source = playback.querySelector("source");
        if (source && !playback.canPlayType(source.type)) {
            const root = document.createElement("div");
            root.classList.add("unsupported-playback", "box");

            root.innerHTML = `
            <p>This file uses the ${source.type} format which your browser cannot play.</p>
            <p>
                You can download the file and watch it in a media player or
                <b>use a different browser</b> that supports this codec.
            `;

            playback.parentElement.append(root);
            playback.style.display = 'none';
        }
    });
});

window.addEventListener("DOMContentLoaded", () => {
    const feed = document.querySelector(".file-attachments");
    const items = [...feed.children];
    if (items.length <= 1) return;

    const backButton = document.querySelector('.feed-controller[data-action="back"]');
    const nextButton = document.querySelector('.feed-controller[data-action="next"]');

    function getCurrentIndex() {
        const feedRect = feed.getBoundingClientRect();

        return items.reduce((closestIndex, item, index) => {
            const distance = Math.abs(
                item.getBoundingClientRect().left - feedRect.left
            );

            const closestDistance = Math.abs(
                items[closestIndex].getBoundingClientRect().left - feedRect.left
            );

            return distance < closestDistance ? index : closestIndex;
        }, 0);
    }

    function scrollToIndex(index) {
        if (!items[index]) return;

        items[index].scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
            inline: 'start'
        });
    }

    function updateButtons() {
        const currentIndex = getCurrentIndex();

        backButton.disabled = currentIndex === 0;
        nextButton.disabled = currentIndex === items.length - 1;
    }

    function back() {
        const currentIndex = getCurrentIndex();

        if (currentIndex > 0) {
            scrollToIndex(currentIndex - 1);
        }
        updateButtons();
    }

    function next() {
        const currentIndex = getCurrentIndex();

        if (currentIndex < items.length - 1) {
            scrollToIndex(currentIndex + 1);
        }
        updateButtons();
    }

    backButton.addEventListener('click', back);
    nextButton.addEventListener('click', next);
    feed.addEventListener('scroll', updateButtons, { passive: true });

    updateButtons();

    backButton.style.display = 'block';
    nextButton.style.display = 'block';
    feed.style.overflowX = "hidden";
});