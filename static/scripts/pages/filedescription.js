window.addEventListener("DOMContentLoaded", () => {
    const button = document.querySelector(".feed-description-opener");
    const description = document.querySelector(".feed-description");
    if (!button || !description) return;

    description.classList.add("hidden");
    button.classList.remove("hidden");

    button.addEventListener("click", () => {
        description.classList.remove("hidden");
        button.remove();
    });

    // counting description lines
    const lines = description.textContent.trim().split("\n");
    button.textContent = `${lines.length} line(s) omitted. click to expand.`;
});