window.addEventListener("DOMContentLoaded", () => {
    if (navigator.clipboard) {
        const copyButtons = document.querySelectorAll(".copy");
        for (const copyButton of copyButtons) {
            const content = copyButton.innerHTML;
            const button = document.createElement("button");

            button.innerHTML = '<img src="/static/img/icons/copy.png" alt="Copy" />';
            button.addEventListener("click", () => {
                navigator.clipboard.writeText(content);
            });
            copyButton.parentElement.appendChild(button);
        }
    }
});